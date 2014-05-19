<?php

/* Copyright 2011 BBC.
 *
 *  Licensed under the Apache License, Version 2.0 (the "License");
 *  you may not use this file except in compliance with the License.
 *  You may obtain a copy of the License at
 *
 *      http://www.apache.org/licenses/LICENSE-2.0
 *
 *  Unless required by applicable law or agreed to in writing, software
 *  distributed under the License is distributed on an "AS IS" BASIS,
 *  WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 *  See the License for the specific language governing permissions and
 *  limitations under the License.
 */

/* Trove matching module for BBC Programmes */

uses('curl', 'searchengine');

if(!defined('REDUX_API_URL')) define('REDUX_API_URL', 'http://reduxdb.projectreith.com/');

//if(!defined('REDUX_API_URL')) define('REDUX_API_URL', 'http://reduxapi.projectreith.com/');

class ReduxClient extends GenericWebSearch
{
	public $baseUri;
	protected $cookieJar;
	protected $accept;

	public function __construct($uri = null, $cookieJar = null)
	{
		if(!strlen($uri))
		{
			$uri = REDUX_API_URL;
		}
		$this->baseUri = $uri;
		if(substr($this->baseUri, -1) != '/')
		{
			$this->baseUri .= '/';
		}
		$uri = $this->baseUri . 'search?subsearch=1&q=%s';
		if(!strlen($cookieJar))
		{
			$cookieJar = INSTANCE_ROOT . 'cookiejar.txt';
		}
		$this->cookieJar = $cookieJar;
		$this->accept = array('application/json', 'text/javascript');
		parent::__construct($uri);
	}

	public function broadcast($service_or_diskref, $date = null, $time = null, $useCached = false)
	{
		if($date === null && $time === null)
		{
			$uri = '/programme/' . $service_or_diskref;
		}
		else if($date !== null && $time !== null)
		{
			$uri = '/programme/' . $service_or_diskref . '/' . $date . '/' . $time;
		}
		else
		{
			return null;
		}
		if($useCached)
		{
			$uri .= '/cached';
		}
		return $this->fetch($uri);
	}

	protected function curl($uri)
	{
		echo "[$uri]\n";
		$c = parent::curl($uri);
		if(file_exists($this->cookieJar))
		{
			$c->cookieFile = $this->cookieJar;
		}
		$c->cookieJar = $this->cookieJar;
		return $c;
	}

	public function absolute($uri)
	{
		if(strncmp($uri, 'http:', 5) && strncmp($uri, 'https:', 6))
		{
			while(substr($uri, 0, 1) == '/')
			{
				$uri = substr($uri, 1);
			}
			$uri = $this->baseUri . $uri;
		}
		return $uri;
	}

	protected function fetch($uri)
	{		
		$uri = $this->absolute($uri);
		$c = $this->curl($uri);
		$buf = $c->exec();
		if(!strlen($buf) || ($buf[0] != '{' && $buf[0] != '['))
		{
			return null;
		}
		$buf = json_decode($buf, true);
		if(!is_array($buf))
		{
			return null;
		}
		return $buf;
	}

	protected function headers()
	{
		$a = array(
			'User-Agent: Spindle//ReduxClient',
			'Accept: ' . implode(',', $this->accept),
			);
		return $a;
	}		
}

class TroveBBCProgsMatcher extends TroveMatcher
{
	/* Our mapping source URI */
	public $source = 'http://www.bbc.co.uk/programmes/';

	protected $subs;

	public function __construct($trove)
	{
		parent::__construct($trove);
		$this->redux = new ReduxClient();
	}

	public function evaluate($entity, TroveMap $map)
	{
		$exact = $map['exactMatch'];
		$set = RDFSet::setFromInstances(RDF::rdf.'about', $exact);
		$set = $set->uris();
		foreach($set as $u)
		{
			if(!strncmp($u, $this->redux->baseUri, strlen($this->redux->baseUri)))
			{
				return;
			}
			if(!strncmp($u, $this->source, strlen($this->source)))
			{
				return;
			}
		}
		$set = RDFSet::setFromInstances(Trove::$labelPredicates, $exact);
		if(!count($set))
		{
			$this->debug("can't match set because it has no title");
			return;
		}
		$searchTerms = '"' . $set->lang(null, true) . '"';
		$result = $this->redux->query($searchTerms);
		if(is_array($result) && isset($result['results']) && count($result['results']))
		{
			foreach($result['results'] as $prog)
			{
				if(!$object = $this->model->ingestRDF($this->redux->absolute($prog['canonical'])))
				{
					/* Trove::ingestRDF already warns if it fails */
					continue;
				}
				
				$map->addObject($object, null, 'closeMatch', 50, null, false);				
				break;
			}
		}
	}
		
	public function performMatching($stubUuid, &$objects, &$extraData)
	{
		$exact = $this->objectsMatching($objects, 'exactMatch', $this->source, true);
		$willSkip = false;
		foreach($exact as $match)
		{
			if($match->kind == 'time')
			{
				$willSkip = true;
			}
		}
		if($willSkip)
		{
			/* Don't attmept heuristic matches on times */
			$this->model->mapResource($stubUuid, null, $this->source);
			return true;
		}
		$set = RDFSet::setFromInstances(Trove::$labelPredicates, $exact);
		if(!count($set))
		{
			trigger_error("can't match stub $stubUuid because it has no title", E_USER_WARNING);
			return;
		}
		$unquotedTerms = $set->lang(null, true);
		$searchExtra = '';
		if(!strlen($unquotedTerms))
		{
			return;
		}
		$this->debug("searchTerms=" . $unquotedTerms);
		$results = $this->subs->query($unquotedTerms);
		$matches = array();
		if(is_array($results) && isset($results['list']))
		{
			$i = 0;
			foreach($results['list'] as $prog)
			{
				$progInfo = $this->redux->fetch($prog['diskRef'], null, null, true);
				if(!is_array($progInfo))
				{
					continue;
				}
				$c = new CurlCache(REDUX_SUBTITLES_URL . 'subtitles/' . $prog['diskRef'] . '.xml');
				$c->returnTransfer = true;
				$subs = $c->exec();
				$match = 'noMatch';
				$confidence = 0;
				$inst = new RDFInstance();
				$title = $progInfo['title'];
				$synopsis = $progInfo['description'];
				if(isset($progInfo['episode_title']))
				{
					$title = $progInfo['episode_title'];
				}
				if(isset($progInfo['episode_short_synopsis']))
				{
					$synopsis = $progInfo['episode_short_synopsis'];
				}
				$inst->{RDF::dcterms.'description'}[] = new RDFString(strip_tags($subs), 'en-GB');
				$inst->{RDF::dcterms.'title'}[] = new RDFString($title, 'en-GB');
				$inst->{'http://purl.org/ontology/po/short_synopsis'}[] = new RDFString($synopsis, 'en-GB');
//				print_r($inst);
				if(!defined('DEBUG_SIMILARITY')) define('DEBUG_SIMILARITY', true);
				echo "Evaluating: " . $title . " - " . $prog['diskRef'] . "\n";
				$this->evaluateSimilarity($stubUuid, $objects, $inst, $match, $confidence);
				if($match == 'noMatch')
				{
					echo "match=$match, confidence=$confidence\n";
					continue;
				}
				$k = sprintf('%03d-%03d', $confidence, $i);
				$matches[$k] = array('match' => $match, 'confidence' => $confidence, 'prog' => $prog, 'info' => $progInfo);
				$i++;
			}
		}
		return null;
		/*
		$classes = RDFSet::setFromInstances('rdf:type', $exact)->values();
		foreach($classes as $class)
		{
			$class = strval($class);
			if(isset($this->keyClasses[$class]))
			{
				$searchExtra .= ' ' . $this->keyClasses[$class];
			}
		}
		$results = $this->search->query($searchTerms . $searchExtra);
		if(!is_array($results) || !$results['count'])
		{
			$results = array('count' => 0, 'list' => array());
		}
		if(!is_array($results) || !$results['count'])
		{
			return;
		}
		$list = array_slice($results['list'], 0, WIKIPEDIA_MATCH_RESULTS);
		$results = $this->search->query($unquotedTerms . $searchExtra);
		if(!is_array($results) || !$results['count'])
		{
			$results = array('count' => 0, 'list' => array());
		}
		$list = array_merge($list, array_slice($results['list'], 0, WIKIPEDIA_MATCH_RESULTS));
		$resultSet = array();
		$i = 0;
		while(count($list))
		{
			$result = array_shift($list);
			if(strpos($result['title'], '(disambiguation)') !== false)
			{
				continue;
			}
			$docUri = $result['uri'];
//			$this->debug("docUri=" . $docUri);
			$realUri = $this->determineUri($docUri);
			if($realUri === false)
			{
				return;
			}
			if(!$object = $this->model->ingestRDF($docUri, false, $realUri))
			{
				continue;
			}
			$match = 'noMatch';
			$confidence = 0;
			$this->evaluateSimilarity($stubUuid, $objects, $object, $match, $confidence);
			$k = sprintf('%04d-%04d', $confidence, $i);
			$resultSet[$k] = array('match' => $match, 'confidence' => $confidence, 'realUri' => $realUri, 'docUri' => $docUri, 'title' => $object->title(), 'object' => $object);
			$i++;
		}
		krsort($resultSet);
//		print_r($resultSet);
//		die();
		$resource = array_shift($resultSet);
		if($resource)
		{
			$realUri = $resource['realUri'];
			$docUri = $resource['docUri'];
			$title = $resource['title'];
			$match = $resource['match'];
			$confidence = $resource['confidence'];
			
			$resoures = array();
			$resources[] = (strlen($realUri) ? $realUri : $docUri);
			$this->evaluateSameAs($stubUuid, $resource['object'], $resources);
//			echo "$stubUuid: $docUri [$match] = $confidence%\n";
			$this->model->mapResource($stubUuid, $resources, $this->source, $title, $match, $confidence);
		}
		else
		{
			$this->model->mapResource($stubUuid, null, $this->source);
		}
		return true;
		*/
	}

	protected function determineUri(&$docUri)
	{
		$c = new CurlCache($docUri);
		$c->noBody = true;
		$c->followLocation = true;
		$c->headers = array('Accept: application/rdf+xml, application/json, */*');
		$c->exec();
		$info = $c->info;
		if(!isset($info['cacheFile']))
		{
			sleep(1);
		}
		if($info['http_code'] != 200 || strcmp($info['content_type'], 'application/rdf+xml'))
		{
			$docUri = null;
			return false;
		}
		$base = strval($this->base);
		$docUri = $info['url'];
		/* Ensure the URL is a public one, even if we run a local copy of dbpedialite */
		if(!strncmp($docUri, $base, strlen($base)))
		{
			$realUri = 'http://dbpedialite.org' . substr($docUri, strlen($base));
		}
		else
		{
			$realUri = null;
		}
		return $realUri;
	}
}


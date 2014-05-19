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

/* Trove matching module for Wikipedia */

uses('curl', 'searchengine');

/* The number of results from each query to attempt to evaluate
 * Note that the matcher performs two separate queries (one quoted, one
 * unquoted), and so the total number of documents to be retrieved and
 * evaluated will be 2 * WIKIPEDIA_MATCH_RESULTS - $num_excluded, where
 * $num_excluded is the number of documents which are excluded (currently
 * only disambiguation pages are explicitly excluded, but this may change
 * in the future.
 */
if(!defined('WIKIPEDIA_MATCH_RESULTS')) define('WIKIPEDIA_MATCH_RESULTS', 5);

class TroveWikipediaMatcher extends TroveMatcher
{
	/* If an entity belongs to one of the classes on the left, the term on
	 * the right is appended to the query.
	 */
	protected $keyClasses = array(
		'http://purl.org/theatre#Venue' => 'venue',
		'http://xmlns.com/foaf/0.1/Person' => 'person',
		);
	/* The owl:sameAs whitelist */
	protected $whitelist = array(
		'http://dbpedia.org/resource/',
		'http://rdf.freebase.com/ns/',
		'http://www.bbc.co.uk/programmes/',
		'http://www.bbc.co.uk/nature/',
		'http://data.nytimes.com/',
		);
	/* The dbpedialite search engine instance */
	protected $search;
	/* The base URL of dbpedialite.org (overridden via DBPEDIALITE_URI) */
	protected $base;
	/* Our mapping source URI */
	public $source = 'http://dbpedialite.org/';

	public function __construct($trove)
	{
		parent::__construct($trove);
		$this->search = SearchEngine::connect('dbplite:///');
		/* Determine the base URI for dbpedialite that we've been
		 * configured to use.
		 */
		$base = new URL(DBPEDIALITE_URI);
		$base->query = null;
		$base->fragment = null;
		$p = strrpos($base->path, '/search.json');
		$base->path = substr($base->path, 0, $p);
		if(substr($base->path, -1) == '/')
		{
			$base->path = substr($base->path, 0, -1);
		}
		$this->base = $base;
	}

	public function evaluate($entity, TroveMap $map)
	{
		$exact = $map['exactMatch'];
		$dbplthings = 'http://dbpedialite.org/things/';
		$dbplcats = 'http://dbpedialite.org/categories/';
		$dbpcbase = 'http://dbpedia.org/resource/Category:';
		$dbpbase = 'http://dbpedia.org/resource/';
		$base = strval($this->base) . '/things/';
		$willSkip = false;
		$kind = $this->model->firstObject($entity)->kind;
		foreach($exact as $match)
		{
			$first = $this->model->firstObject($match);
			if($first->kind == 'time' || $first->kind == 'publisher')
			{
				/* Skip if there're no explicit matches */
				$willSkip = true;
			}
			$subjects = $first->subjects();
			$sameAs = $first['owl:sameAs']->uris();
			foreach($sameAs as $uri)
			{
				if(!in_array($uri, $subjects))
				{
					$subjects[] = $uri;
				}
			}
			foreach($subjects as $same)
			{
//				echo "Found $same attached to $match\n";
				if(!strncmp($same, $dbplcats, strlen($dbplcats)))
				{
					return $this->mapDbpediaLiteId($map, substr($same, $dbplcats), 'exactMatch', 100, $kind);
				}
				else if(!strncmp($same, $dbpcbase, strlen($dbpcbase)))
				{
					return $this->mapDbpediaLiteTitle($map, 'Category:' . substr($same, strlen($dbpcbase)), 'exactMatch', 100, $kind);
				}
				else if(!strncmp($same, $dbplthings, strlen($dbplthings)))
				{
					$docUri = $base . substr($same, strlen($dbplthings));
					return $this->mapDbpediaLiteUri($map, $docUri, 'exactMatch', 100, $kind);
				}
				else if(!strncmp($same, $dbpbase, strlen($dbpbase)))
				{
					$docUri = 'http://dbpedialite.org/titles/' . substr($same, strlen($dbpbase));
					return $this->mapDbpediaLiteTitle($map, $docUri, 'exactMatch', 100, $kind);
				}
			}
		}
		if($willSkip)
		{
			/* Don't attmept heuristic matches on times */
//			$this->model->mapResource($stubUuid, null, $this->source);
			return true;
		}
		$set = RDFSet::setFromInstances(Trove::$labelPredicates, $exact);
		if(!count($set))
		{
			trigger_error("can't match stub $stubUuid because it has no title", E_USER_WARNING);
			return;
		}
		$searchTerms = '"' . $set->lang(null, true) . '"';
		$unquotedTerms = $set->lang(null, true);
		$searchExtra = '';
		if(!strlen($unquotedTerms))
		{
			return;
		}
		$this->debug("searchTerms=" . $searchTerms);
		$classes = RDFSet::setFromInstances(RDF::rdf.'type', $exact)->uris();
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
			$realUri = $this->determineUri($docUri);
			if($realUri === false)
			{
				return;
			}
			if(!$object = $this->model->ingestRDF($docUri, false, $realUri))
			{
				/* Trove::ingestRDF already warns if it fails */
				continue;
			}
			$firstObj = $this->model->firstObject($object);
			$match = 'noMatch';
			$confidence = 0;
			$this->evaluateSimilarity(null, $map, $object, $match, $confidence);
			$k = sprintf('%04d-%04d', $confidence, $i);
			$resultSet[$k] = array('match' => $match, 'confidence' => $confidence, 'realUri' => $realUri, 'docUri' => $docUri, 'title' => $firstObj->title(), 'object' => $object);
			$this->debug($confidence, $firstObj->title(), $realUri);
			$i++;
		}
		krsort($resultSet);
		$resource = array_shift($resultSet);
		if($resource)
		{
			$this->mapDbpediaLiteUri($map, $resource['docUri'], $resource['match'], $resource['confidence'], $kind);
		}
		return true;
	}

	protected function mapDbpediaLiteId(TroveMap $map, $id, $type = 'exactMatch', $confidence = 100, $kind = null)
	{
		$this->mapDbpediateLiteUri($map, 'http://dbpedialite.org/things/' . $id, $type, $confidence, $kind);
		$this->mapDbpediateLiteUri($map, 'http://dbpedialite.org/categories/' . $id, $type, $confidence, $kind);
	}
	
	protected function mapDbpediaLiteTitle(TroveMap $map, $title, $type = 'exactMatch', $confidence = 100, $kind = null)
	{
		static $catbase = 'Category:';

		$this->mapDbpediaLiteUri($map, 'http://dbpedialite.org/titles/' . $title, $type, $confidence, $kind);
		if(!strncmp($title, $catbase, strlen($catbase)))
		{
			$this->mapDbpediaLiteUri($map, 'http://dbpedialite.org/titles/' . substr($title, strlen($catbase)), $type, $confidence, $kind);
		}
	}

	protected function mapDbpediaLiteUri(TroveMap $map, $docUri, $type = 'exactMatch', $confidence = 100, $kind = null)
	{
		if($kind == 'publisher' && $type = 'exactMatch')
		{
			$type = 'closeMatch';
		}
		$this->debug('Mapping to', $docUri);
		if(($realUri = $this->determineUri($docUri)) === false)
		{
			return false;
		}
		if(strpos($docUri, '#') === false)
		{
			/* Ensure dbpedialite URIs always include the fragment */
			$docUri .= '#id';
		}
		$resources = array();
		if($object = $this->model->ingestRDF($docUri, false, $realUri))
		{
			$object = $this->model->firstObject($object);
			if(!is_object($object))
			{
				print_r($object);
				die("--- mapDbpediaLiteUri: not an object ---\n");
				continue;
			}
			$title = strval($object->title());
			$map->addObject($object, null, $type, $confidence, null, false);
			return true;
		}
		$this->debug('Failed to ingest RDF from', $docUri);
		return false;
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


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

/* Trove matching module for Geonames */

require_once(dirname(__FILE__) . '/model.php');

if(!defined('GEONAMES_BASE_URL')) define('GEONAMES_BASE_URL', 'http://sws.geonames.org/');

class TroveGeonamesMatcher extends TroveMatcher
{
	protected $geonames;
	public $source = 'http://sws.geonames.org/#org';

	public function __construct($trove)
	{
		parent::__construct($trove);
		$this->geonames = Geonames::getInstance();
		if(isset($this->geonames) && !isset($this->geonames->db))
		{
			unset($this->geonames);
		}
		if($this->geonames === null)
		{
			trigger_error('Cannot match against Geonames because no database is available', E_USER_NOTICE);
			return;
		}
	}
	public function evaluate($entity, TroveMap $map)
	{
		if(!isset($this->geonames))
		{
			return;
		}
		$exact = $map['exactMatch'];
		$classes = RDFSet::setFromInstances('rdf:type', $exact);
		$classes->removeValueString(RDF::rdf.'Description');
		$classUris = $classes->uris();
		$kind = $this->model->kindOfStubForEntity($entity);
		if($kind !== null && $kind !== 'place')
		{
			return;
		}
		$labels = RDFSet::setFromInstances(array(RDF::skos.'prefLabel', RDF::foaf.'name', RDF::gn.'name', RDF::rdfs.'label', RDF::dc.'title'), $exact)->strings();
		$matches = array();
		$c = 0;
		foreach($labels as $l)
		{
			$exactMatch = false;
			$geoNameId = $this->matchLabel($l, $exactMatch, $depth);
			if(strlen($geoNameId))
			{
				$c++;
				$k = sprintf('%04d-%04d-%04d', $depth, strlen($l), $c);
				$matches[$k] = array('id' => $geoNameId, 'exact' => $exactMatch, 'depth' => $depth, 'label' => $l);
			}
		}
		krsort($matches);
//		print_r($matches);
		$match = array_shift($matches);
		if($match)
		{
			return $this->mapGeonamesEntry($map, $match);
		}
	}

	protected function mapGeonamesEntry(TroveMap $map, $match)
	{
//			print_r($match);
		$geoNameId = $match['id'];
		$exactMatch = $match['exact'];
		$docUri = GEONAMES_BASE_URL . $geoNameId . '/';
		$realUri = 'http://sws.geonames.org/' . $geoNameId . '/';
		$this->debug('Mapped to', $docUri, '(' . $realUri . ')');
		if(!($object = $this->model->ingestRDF($docUri, false, $realUri)))
		{
			/* Trove::ingestRDF() already warns if it fails */
			return;
		}
		if($exactMatch)
		{			
			$map->add($realUri, $this->source, 'exactMatch', 100);
			return true;
		}
//		$uuids = array();
//		$parent = $this->recursivelyLocateParents($object, $uuids);

//				$this->model->mapResource($stubUuid, $realUri, $this->source, null, 'exactMatch', 100);
//			return true;
//		}
//			$this->model->mapResource($stubUuid, null, $this->source);
//			$stub = $this->model->generateStubForResource($docUri, $this->source, null, $realUri);
//			echo "GeonamesMatcher: gn:locatedIn stub is $stub\n";
//			echo "GeonamesMatcher: gn:locatedIn entity is " . (is_array($object) ? $object[0]->uuid : $object->uuid) . "\n";
//		if(strlen($stub))
//		{
//			$extraData['http://www.geonames.org/ontology#locatedIn'][] = array('type' => 'uri', 'value' => '/places/' . $stub . '#place');
//			if(!in_array($stub, $extraData['tags']))
//			{
//				$extraData['tags'][] = $stub;
//			}
//		}
		return true;
	}

	protected function recursivelyLocateParents($object, &$uuids)
	{
		static $predicates = array(
			'http://www.geonames.org/ontology#parentFeature',
			'http://www.geonames.org/ontology#parentADM3',
			'http://www.geonames.org/ontology#parentADM2',
			'http://www.geonames.org/ontology#parentADM1',
			'http://www.geonames.org/ontology#parentCountry',
			);
		static $base = 'http://sws.geonames.org/';
		static $stack = array();
		static $depth = 0;

		$depth++;
		$parent = null;
		$stack[] = strval($object);
		foreach($predicates as $pred)
		{
			if(isset($object->{$pred}))
			{
				foreach($object->{$pred} as $value)
				{
					if($value instanceof RDFURI)
					{
						$uri = strval($value);
						if(strncmp($uri, $base, strlen($base)))
						{
							continue;
						}
						if(in_array($uri, $stack))
						{
							continue;
						}
						$this->debug($depth . ':', $object, $pred, $uri);
						$realUri = $uri;
						$uri = GEONAMES_BASE_URL . substr($uri, strlen($base));
						if(!($entity = $this->model->ingestRDF($uri, false, $realUri)))
						{
							continue;
						}
						$stub = $this->model->stubForResource($uri);
						if($stub === null && strcmp($realUri, $uri))
						{
							$stub = $this->model->stubForResource($realUri);
						}
						if($stub === null)
						{
							$this->recursivelyLocateParents($entity, $uuids);
							$stub = $this->model->generateStubForResource($uri, $this->source, null, $realUri, false);
							$this->debug($depth . ': Generated stub', $stub, 'for', $uri);
						}
						else
						{
							$this->debug($depth . ': Located stub', $stub, 'for', $uri);
						}
						if(!in_array($stub, $uuids))
						{
							$uuids[] = $stub;
						}
						if(($obj = $this->model->objectForUuid($stub)))
						{
							$this->debug($depth . ': Have object', $stub);
							if(isset($obj->structuralRefs))
							{
								foreach($obj->structuralRefs as $ref)
								{
									if(!in_array($ref, $uuids))
									{
										$uuids[] = $ref;
									}
								}
							}
						}
						else
						{
							$this->debug($depth . ': Warning: geonames resource ' . $uri . ' with stub ' . $stub . ' has not been evaluated yet');
						}
					}
				}
			}
		}
		array_pop($stack);
		$depth--;
	}

	protected function matchLabel($label, &$exactMatch, &$depth)
	{
		$exactMatch = false;
		$depth = 0;
		$l = explode(';', str_replace(',', ';', $label));
		$state = $last = null;
//		print_r($l);
		$c = 0;
		while(count($l))
		{
			$c++;
			$part = array_pop($l);
			$part = trim($part);
			if(!strlen($part))
			{
				continue;
			}
			if($this->tryMatchPlace($state, $part))
			{
				$this->debug($c, 'Matched', $part, 'against', $state['geonameid']);
				$last = $state;
				if(!count($l))
				{
					$exactMatch = true;
				}
			}
			else
			{
				$this->debug($c, 'Failed to match', $part);
				break;
			}
		}
		if($state)
		{
			$depth = $state['depth'];
			return $state['geonameid'];
		}
	}

	protected function tryMatchPlace(&$state, $part)
	{
		static $initialAttempts = array(
			array('feature_class' => 'T', 'feature_code' => 'ISL'),
			array('feature_class' => 'A', 'feature_code' => 'ADM1', 'country_code' => 'GB'),
			array('feature_class' => 'A', 'feature_code' => 'ADM1'),
			array('feature_class' => 'A', 'feature_code' => 'ADM2', 'country_code' => 'GB', 'admin1_code' => 'ENG'),
			array('feature_class' => 'A', 'feature_code' => 'ADM2', 'country_code' => 'GB', 'admin1_code' => 'NIR'),
			array('feature_class' => 'A', 'feature_code' => 'ADM2', 'country_code' => 'GB', 'admin1_code' => 'SCT'),
			array('feature_class' => 'A', 'feature_code' => 'ADM2', 'country_code' => 'GB', 'admin1_code' => 'WLS'),
			array('feature_class' => 'P', 'country_code' => 'GB', 'admin1_code' => 'ENG', 'admin2_code' => 'GLA'),
			array('country_code' => 'GB', 'admin1_code' => 'ENG', 'admin2_code' => 'GLA'),	
			array('feature_class' => 'A', 'feature_code' => 'ADM3', 'country_code' => 'GB'),
			array('feature_class' => 'A', 'feature_code' => 'ADM4', 'country_code' => 'GB'),
			array('feature_class' => 'P', 'feature_code' => 'PPLA', 'country_code' => 'GB', 'admin1_code' => 'ENG'),
			array('feature_class' => 'P', 'feature_code' => 'PPLA', 'country_code' => 'GB', 'admin1_code' => 'NIR'),
			array('feature_class' => 'P', 'feature_code' => 'PPLA', 'country_code' => 'GB', 'admin1_code' => 'SCT'),
			array('feature_class' => 'P', 'feature_code' => 'PPLA', 'country_code' => 'GB', 'admin1_code' => 'WLS'),
			array('feature_class' => 'P', 'feature_code' => 'PPLA', 'country_code' => 'GB'),
			);
		static $whitelist = array('feature_class', 'feature_code', 'admin1_code', 'admin2_code', 'admin3_code', 'admin4_code', 'country_code');

		if($state)
		{
			switch($state['feature_code'])
			{
			case 'ADM1':
				$state['feature_code'] = 'ADM2';
				$attempts = array($state);
				$state['feature_class'] = 'P';
				$state['feature_code'] = null;
				$attempts[] = $state;
				$state['feature_class'] = 'S';
				$state['feature_code'] = null;
				$attempts[] = $state;
				break;
			case 'ADM2':
				$state['feature_code'] = 'ADM3';
				$attempts = array($state);
				$state['feature_class'] = 'P';
				$state['feature_code'] = null;
				$attempts[] = $state;
				$state['feature_class'] = 'S';
				$state['feature_code'] = null;
				$attempts[] = $state;
				break;
			case 'ADM3':
				$state['feature_code'] = 'ADM4';
				$attempts = array($state);
				$state['feature_class'] = 'P';
				$state['feature_code'] = null;
				$attempts[] = $state;
				$state['feature_class'] = 'S';
				$state['feature_code'] = null;
				$attempts[] = $state;
				break;
			case 'ADM4':
				$state['feature_class'] = 'P';
				$state['feature_code'] = null;
				$attempts = array($state);
				$state['feature_class'] = 'S';
				$state['feature_code'] = null;
				$attempts[] = $state;
				break;
			default:
				if($state['feature_class'] == 'P')
				{
					$state['feature_class'] = null;
					$attempts = array($state);
				}
				else
				{
					return;			   
				}
			}
		}
		else
		{
			$attempts = $initialAttempts;
		}
		foreach($attempts as $try)
		{
			$query = array();
			foreach($whitelist as $key)
			{
				if(isset($try[$key]))
				{
					$query[$key] = $try[$key];
				}
			}
			$query['name'] = $part;
//			print_r($query);
			if(($row = $this->geonames->geoname($query)))
			{
				$state = $row;
				$state['depth'] = empty($try['depth']) ? 1 : ($try['depth'] + 1);
				$state['_query'] = $part;
				return true;
			}
			if(is_array($state) && $state['country_code'] == 'GB' && $state['depth'] == 1 && strncasecmp($state['_query'], 'Greater ', 8))
			{
//				echo "*** Trying " . $part . " within Greater " . $state['_query'] . " ***\n";
				/* Handle the common 'London' => 'Greater London'-style confusion */
				$xstate = null;
				if($this->tryMatchPlace($xstate, 'Greater ' . $try['_query']) &&
				   $this->tryMatchPlace($xstate, $part))
				{
					$state = $xstate;
					return true;
				}
			}
		}
	}
}

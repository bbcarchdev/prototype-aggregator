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

class TroveInternalMatcher extends TroveMatcher
{
	protected $search;

	public function __construct($trove)
	{
		parent::__construct($trove);
		if(defined('TROVE_SEARCH') && TROVE_SEARCH !== null)
		{
			$this->search = SearchEngine::connect(TROVE_SEARCH);
		}
		else
		{
			trigger_error(get_class($this) . ": No search engine (TROVE_SEARCH) is available", E_USER_WARNING);
		}
	}

	public function evaluate($entity, TroveMap $map)
	{
		$first = $this->model->firstObject($entity);
		$kind = $first->kind;
		$this->debug('Evaluating', $first, '(' . $kind . ')');
		$exact = $map['exactMatch'];
		$set = RDFSet::setFromInstances(Trove::$labelPredicates, $entity);
		if(!count($set))
		{
			$this->model->debug("Warning: can't match $entity because it has no label");
			return;
		}
		$searchTerms = '"' . $set->first() . '"';
		$searchExtra = '';
		$query = array();
		$query['text'] = $searchTerms . $searchExtra;
		$results = $this->search->query($query);
		if(!is_array($results) || !count($results['list']))
		{
			$this->debug('No matches for ' . $searchTerms);
			return;
		}
		$index = 0;
		$matches = array();
		foreach($results['list'] as $entry)
		{
			if(!strcmp($entry['uuid'], $entity->uuid))
			{
				continue;
			}
			$matchObjects = $this->model->resourceMapForUuid($entry['uuid']);
			$match = 'noMatch';
			$confidence = 0;
			foreach($matchObjects['exactMatch'] as $exact)
			{
				if(!is_array($exact))
				{
					$exact = array($exact);
				}
				$this->evaluateSimilarity(null, $map, $exact, $match, $confidence);
				if($kind == 'publisher' && $match == 'exactMatch')
				{
					$match = 'closeMatch';
				}
				$k = sprintf('%04d-%04d', $confidence, 9999 - $index);
				$entry['object_uuid'] = $exact[0]->uuid;
				$entry['title'] = $exact[0]->title();
				$entry['match'] = $match;
				$entry['confidence'] = $confidence;
				$entry['resource'] = strval($exact[0]);
				if($entry['match'] == 'exactMatch')
				{
					$map->add($entry['resource'], null, $entry['match'], $entry['confidence'], $entry['title']);
				}
				else
				{
					$map->add('urn:uuid:' . $entry['uuid'], null, $entry['match'], $entry['confidence'], $entry['title']);
				}
				$matches[$k] = $entry;
				$index++;
			}
		}
		krsort($matches);
//		print_r($matches);
	}
	
	public function performMatching($stubUuid, &$objects, &$extraData, $kind = null, $parent = null, $returnMatch = false)
	{
		if(!$this->search)
		{
			return;
		}
		if(!isset($objects['exactMatch']) || !count($objects['exactMatch']))
		{
			return;
		}
		$this->model->debug('[Attempting to match', $stubUuid, 'against internal objects]');
		$set = RDFSet::setFromInstances(Trove::$labelPredicates, $objects['exactMatch']);
		if(!count($set))
		{
			$this->model->debug("Warning: can't match stub $stubUuid because it has no title");;
			return;
		}
		$searchTerms = '"' . $set->first() . '"';
		$unquotedTerms = $set->first();
		$searchExtra = '';
		$query = array();
		if(isset($kind))
		{
			$query['kind'] = $kind;
		}
		if(isset($parent))
		{
			$query['parent'] = $parent;
		}
		$query['text'] = $searchTerms . $searchExtra;
		$results = $this->search->query($query);
		if(!is_array($results) || !count($results['list']))
		{
			$query['text'] = $unquotedTerms . $searchExtra;
			$results = $this->search->query($query);
		}
		if(!is_array($results) || !count($results['list']))
		{
			return;
		}
		$scores = array();
		foreach($results['list'] as $result)
		{			
			$children = $this->model->mappingsByTypeForUuid($result['uuid']);
			$matchObjects = $this->model->objectsForMappings($children);			
			$match = 'noMatch';
			$confidence = 0;			
			$this->evaluateSimilarity($stubUuid, $matchObjects, $objects['exactMatch'], $match, $confidence);
//			echo "Result: " . $result['uuid'] . " match = $match, confidence = $confidence\n";
			if($match != 'exactMatch')
			{
				continue;
			}
			$scores[$result['uuid']] = sprintf('%03d', $confidence);
		}
		asort($scores);
		if($returnMatch)
		{	   
			if($stubUuid === null || !isset($scores[$stubUuid]))
			{
				$cscore = 0;
			}
			else
			{
				$cscore = $scores[$stubUuid];
			}
			foreach($set as $score => $uuid)
			{
				if($score > $cscore)
				{
					return $uuid;
				}
			}			
			return null;			   
		}
	}
}


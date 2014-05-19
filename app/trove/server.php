<?php

/**
 * The Trove model -- database-driven implementation
 *
 * This is the programmatic interface to Trove -- Spindle's aggregation
 * engine.
 *
 * @package TroveAggregator The Spindle data aggregator
 * @author BBC
 * @year 2011
 * @license http://www.apache.org/licenses/LICENSE-2.0
 */

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

require_once(dirname(__FILE__) . '/model.php');

/**
 * Base class for server implementations of the Spindle aggregator.
 */
abstract class TroveServer extends Trove
{
	/* The owl:sameAs recursion whitelist */
	public static $whitelist = array(
		'http://dbpedia.org/resource/',
		'http://dbpedialite.org/',
		'http://rdf.freebase.com/ns/',
		'http://www.bbc.co.uk/programmes/',
		'http://www.bbc.co.uk/nature/',
		'http://www.bbc.co.uk/music/',
		'http://data.nytimes.com/',
		'http://data.ordnancesurvey.co.uk/',
		'http://sws.geonames.org/',
		);

	abstract public function needsMapping($uuid, $kind, $needsMapping = true, $nonce = null);
	abstract public function resetEvaluationStatus($kind = null);
	abstract public function pendingEvaluationSet($limit = 200, $kind = null);
	abstract public function defaultStubForUuid($uuid);
	abstract public function localDepictions($uuid);
	abstract protected function setStubData($stub, $objects, $lazy);
	abstract public function stubForResource($uri);
	abstract public function stubForUuid($uuid);
	abstract public function allStubsForResource($uri, $uuid = null);

	abstract public function queueIngest($stubUuid, $uri, $data = null, $alwaysQueue = false);
	abstract public function ingestQueueUuid();
	abstract public function pendingIngestResourcesForUuid($uuid);
	abstract public function ingestQueueEntriesForUri($uri);
	abstract public function removeIngestQueueEntry($uuid, $uri);
	abstract public function objectForOwner($uri, $keyFingerprint, $distinguishedName, $certData);

	abstract public function mapResource($uuid, $resource, $source = null, $title = null, $type = 'exactMatch', $confidence = 100, $priority = 50, $local = false);
	abstract protected function removeMapping($stubUuid, $resource);
	abstract public function mappingsForUuid($uuid, $type = null, $reverse = false);
	abstract public function applyMappings($stubUuid, $map, $previousStub, $relatedObjectUuid);

	public function objectForUUID($uuid, $owner = null, $kind = null, $firstOnly = false, $args = null)
	{
		if(($p = parent::objectForUUID($uuid, $owner, $kind, $firstOnly, $args)) !== null)
		{
			return $p;
		}
		if(!empty($args['permitRedirect']))
		{
			if(($u = $this->entityHavingDefaultStubOf($uuid)))
			{
				die('the resource ' .  $u . ' has a default stub of ' . $uuid);
			}
		}
	}

	/**
	 * Return a TroveMap object, optionally populated by a set of mappings
	 * for a given UUID.
	 *
	 * The mappings should be as returned by Trove::mappingsForUuid()
	 *
	 * @task Entity mappings
	 */
	public function resourceMap($mappings = null, $stubUuid = null)
	{
		$map = new TroveWriteableMap($this, $mappings, $stubUuid);
		return $map;
	}

	/**
	 * Immediately re-evaluate all of the entities attached to a given stub
	 *
	 * @task Evaluation
	 */
	public function evaluateEntitiesOfStub($stub)
	{
		$stub = $this->uuidOfObject($stub);
		$children = $this->mappingsForUuid($stub);
		$regenerateList = array($stub);
		$didEvaluate = array();
		$this->debug('Evaluating entities mapped to stub', $stub);
		foreach($children as $entity)
		{
			if(!strlen($entity['resource_uuid']))
			{
				continue;
			}
			if($this->isTransientMapping($entity))
			{
				/* Remove any leftover entries */
				if(isset($entity['object_uuid']) && strlen($entity['object_uuid']) &&
				   !strcmp($entity['object_uuid'], $entity['resource_uuid']))
				{
					$this->removeMappingsAttaching($entity['uuid'], $entity['object_uuid']);
				}
				continue;
			}			
			$newStub = $this->evaluateEntity($entity['resource_uuid'], $stub, false);
			$didEvaluate[] = $entity['resource_uuid'];
			if(!in_array($newStub, $regenerateList))
			{
				$regenerateList[] = $newStub;
			}
		}
		foreach($regenerateList as $uuid)
		{
			$this->generateStub($uuid);
		}
		return $didEvaluate;
	}

	/**
	 * Determine whether the specified entity is transient.
	 *
	 * A transient entity is one which exists only as a result of the
	 * evaluation process. A stub consisting solely of transient entities
	 * should be removed.
	 *
	 * @internal
	 * @task Evaluation
	 */
	protected function isTransientMapping($entity)
	{
		static $transientSources = array(
			'http://sws.geonames.org/#org',
			'http://sws.geonames.org/',
			'http://dbpedialite.org/',
			'http://dbpedia.org/',
			'http://rdf.freebase.com/',
			);
		if(isset($entity['object_uuid']) && strlen($entity['object_uuid']) &&
		   strcmp($entity['resource_uuid'], $entity['object_uuid']))
		{
			return true;
		}
		if(!isset($entity['object_uuid']) && in_array($entity['source'], $transientSources))
		{
			return true;
		}
	}

	/**
	 * Immediately re-evaluate a particular entity.
	 *
	 * @task Evaluation
	 */
	public function evaluateEntity($entity, $currentStub = null, $regenerate = true)
	{
		$entityUuid = $this->uuidOfObject($entity);
		$defaultStub = $this->defaultStubForUuid($entityUuid);
		$first = $this->firstObject($entity);
		if(!is_object($first))
		{
			$entity = $this->objectForUuid($entityUuid, null, null, true);
			$first = $this->firstObject($entity);			
		}
		$entityKind = $this->kindOfStubForEntity($entity);
		$this->debug('****** Entity', $first, 'is a', ($entityKind !== null ? $entityKind : '<wildcard>'), $entityUuid, '******');
		$map = $this->resourceMap();
		$map->setRelatedObjectUuid($entityUuid);
		$map->addObject($entity);

		$helpers = $this->matchingHelpers();
		foreach($helpers as $helper)
		{
			$this->debug('Evaluating', $this->uuidOfObject($first), $first, 'using', get_class($helper));
			$helper->evaluate($first, $map);
		}
		$map->expandReferences(array($this, 'locateEquivalentObject'));
		$newStub = null;
		$queue = array(); /* List of IRIs which should be queued for ingest */
		$matches = array(); /* List of matching objects, keyed by creation date */
		if(strlen($defaultStub))
		{
			$matches['zzz'] = $defaultStub;
		}
		$exactList = $map['exactMatch']->map();
		$this->debug('***** Looking for existing stubs *****');
		foreach($exactList as $exact)
		{
			$this->debug($exact['resource']);
			if(!strcmp($exact['resource_uuid'], $entityUuid)) continue;
			if(isset($exact['resource_uuid']))
			{
				if(($d = $this->objectForUuid($exact['resource_uuid'], null, null, true)) !== null)
				{
					$this->debug('Found', $d->uuid, 'for', $exact['resource']);
				}
				else
				{
					$this->debug('Failed to locate', $exact['resource_uuid'], 'for', $exact['resource']);
				}
			}
			else
			{
				if(($d = $this->objectForIri($exact['resource'], null, null, true)) !== null)
				{
					$this->debug('Found', $d->uuid, 'for', $exact['resource']);
					$exact['resource_uuid'] = $d->uuid;
				}
				else
				{
					$this->debug('Failed to locate data for', $exact['resource'], 'queueing for ingest');
					$queue[] = $exact;
					continue;
				}
			}
			$exactKind = $this->kindOfStubForEntity($d);
//			$this->debug('exactKind =', $exactKind, ' - entityKind =', $entityKind);
			if($exactKind !== null && $entityKind !== null)
			{
				if(strcmp($exactKind, $entityKind))
				{
					print_r($exact);
					$this->debug('Skipping', $exact['resource'], 'because of a type mismatch');
					continue;
				}
			}
			$stubs = $this->allStubsForResource($exact['resource'], $exact['resource_uuid']);
			foreach($stubs as $s)
			{
//				$this->debug('Stub of', $exact['resource_uuid'], 'is', $s['uuid']);
				$matches[$d['created'] . '-' . $s['created'] . '-' . $s['uuid'] . '-' . $exact['resource_uuid']] = $s['uuid'];
			}
/*			if(strlen($stub) && strcmp($stub, $currentStub))
			{
				$this->debug('Matched stub for ' . $exact['resource'] . ' is ' . $stub);
				$newStub = $stub;
				break;
			}
			break; */
		}
		if(count($matches))
		{			
			ksort($matches);		
			$this->debug('Matching stub list:');
			print_r($matches);			
			$newStub = array_shift($matches);
		}
		else
		{
			$newStub = $defaultStub;
		}
//		$this->debug('New stub is', $newStub, 'default is', $defaultStub, 'current is', strlen($currentStub) ? $currentStub : 'unset or unknown');
		$map->setUuid($newStub);
		$map->apply();
//		$this->debug('***** Evaluation of', $entityUuid, 'complete *****');
		if($regenerate && strlen($currentStub) && strcmp($newStub, $currentStub))
		{
			/* Remove the child from the old stub */
			$this->removeMapping($currentStub, 'urn:uuid:' . $entityUuid);
			/* Cause the old stub to be regenerated */
			$this->generateStub($currentStub);
		}
		if($regenerate)
		{
			$this->generateStub($newStub);
		}
		return $newStub;
	}


	/**
	 * @internal
	 */
	public function locateEquivalentObject($map, $entry, $entity, $equivalentUri)
	{
		$whitelisted = false;
		foreach(self::$whitelist as $base)
		{
			if(0 == strncmp($equivalentUri, $base, strlen($base)))
			{
				$whitelisted = true;
				break;
			}
		}
		if(!$whitelisted)
		{
			$this->debug($equivalentUri, 'is not a whitelisted URI');
			return false;
		}
		if(($obj = $this->objectForIri($equivalentUri)))
		{
			return $obj;
		}
		$data = array('uuid' => $entity->uuid, 'reevaluate' => $this->uuidOfObject($entity));
		$this->pushUri($equivalentUri, null, false, $data);		
		return false;
	}

	/**
	 * Given a stub UUID, re-generate the stub based upon the associated
	 * entitities.
	 *
	 * @task Evaluation
	 */
	public function generateStub($stub, $objects = null, $lazy = null)
	{
		if(!strlen($stub))
		{
			trigger_error('Invalid stub UUID "' . $stub . '" passed to ' . get_class($this) . '::generateStub()', E_USER_NOTICE);
			return false;
		}
		$this->debug('Generating stub', $stub);
/*		$data = $this->dataForUuid($stub, null, null, true);
		if($data !== null && !$this->isStub($data))
		{
			$theStub = $this->stubForUuid($stub);
			$this->debug($stub, 'is not a stub UUID, generating', $theStub, 'instead');
			return $this->generateStub($theStub);
			} */
		$children = $this->mappingsForUuid($stub);
		$remaining = 0;
		foreach($children as $entity)
		{
			if(!strlen($entity['resource_uuid']))
			{
				continue;
			}
			if($this->isTransientMapping($entity))
			{
				continue;
			}
			$remaining++;
		}
		if(!$remaining)
		{
			$this->debug('Stub', $stub, 'has no remaining non-transient entities');
			/* XXX TODO: Handle tombstones sanely */
			$this->deleteObjectWithUUID($stub);
			return;
		}
		$this->debug('Generating stub with UUID ' . $stub);
		if($objects === null)
		{		
			$objects = $this->resourceMapForUuid($stub);
		}
		return $this->setStubData($stub, $objects, $lazy);
	}

	/**
	 * Obtain an instance of the full-text search engine.
	 *
	 * @internal
	 * @task Utilities
	 */
	protected function searchEngine()
	{
		if(TROVE_SEARCH === null)
		{
			return null;
		}
		if($this->search === null)
		{
			uses('searchengine');
			$this->search = SearchEngine::connect(TROVE_SEARCH);
		}
		return $this->search;
	}

	/**
	 * Obtain an instance of the full-text search engine's indexing
	 * interface.
	 *
	 * @internal
	 * @task Utilities
	 */
	protected function searchIndexer()
	{
		if(TROVE_SEARCH === null)
		{
			return null;
		}
		if($this->indexer === null)
		{
			uses('searchengine');
			$this->indexer = SearchIndexer::connect(TROVE_SEARCH);
			$this->indexer->begin();
		}
		return $this->indexer;
	}

	/**
	 * Return instances of all of the matching helper classes.
	 *
	 * @task Utilities
	 */
	public function matchingHelpers()
	{
		global $TROVE_MATCHING_MODULES;

		if(!isset($this->matchingHelpers))
		{
			$this->matchingHelpers = array();
			if(!isset($TROVE_MATCHING_MODULES) || !is_array($TROVE_MATCHING_MODULES))
			{
				$TROVE_MATCHING_MODULES = array();
			}
			$TROVE_MATCHING_MODULES[] = array(
				'name' => 'trove',
				'class' => 'TroveInternalMatcher',
				'file' => 'matchers/internal.php',
				);
			foreach($TROVE_MATCHING_MODULES as $k => $mod)
			{
				if(!is_array($mod))
				{
					$mod = array('name' => $mod, 'class' => 'Trove' . $mod . 'Matcher', 'file' => 'matcher.php');
					$TROVE_MATCHING_MODULES[$k] = $mod;
				}
				if(!isset($mod['fromRoot']))
				{
					$mod['fromRoot'] = true;
				}
				Loader::load($mod);
				$class = $mod['class'];
				$this->matchingHelpers[$class] = new $class($this);
			}
		}
		return $this->matchingHelpers;
	}

	/**
	 * Return instances of all of the generator classes.
	 *
	 * @task Utilities
	 */
	public function generators()
	{
		global $TROVE_GENERATORS;

		if(!isset($this->generators))
		{
			$this->generators = array();
			if(isset($TROVE_GENERATORS) && is_array($TROVE_GENERATORS))
			{
				foreach($TROVE_GENERATORS as $k => $mod)
				{
					if(!is_array($mod))
					{
						$mod = array('name' => $mod, 'class' => 'Trove' . $mod . 'Generator', 'file' => 'generators.php');
						$TROVE_GENERATORS[$k] = $mod;
					}
					if(!isset($mod['fromRoot']))
					{
						$mod['fromRoot'] = true;
					}
					Loader::load($mod);
					$class = $mod['class'];
					$this->generators[$class] = new $class($this);
				}
			}
		}
		return $this->generators;
	}
	/**
	 * Attempt to determine the source of a particular resource, if it's
	 * not specified.
	 * For most schemes, this is just the base URL of the source - that is,
	 * the path is set to '/', and the query and fragment are removed.
	 *
	 * @internal
	 * @task Ingesting resources
	 */
	public function determineSourceOfResource($resource)
	{
		if(is_array($resource))
		{
			$resource = array_shift($resource);
		}
		if(!strlen($resource))
		{
			return null;
		}
		if(!strncmp($resource, 'urn:uuid:', 9))
		{
			return 'trove';
		}
		if(!strncmp($resource, 'urn:x-time:', 11))
		{
			return 'time';
		}
		$info = new URL($resource);
		$info->path = '/';
		$info->query = null;
		$info->fragment = null;
		return strval($info);
	}

	/**
	 * Locate, or generate if required, the UUID of the stub mapped to the
	 * specified resource. If no mapping exists, it will be added.
	 *
	 * @task Ingesting resources
	 */
	public function generateStubForResource($uri, $source = null, $title = null, $realUri = null, $lazy = null, $regenerate = false)
	{
//		$this->debug('uri=' . $uri, 'kind=' . $kind, 'source=' . $source, 'title=' . $title, 'realUri=' . $realUri, 'lazy=' . $lazy, 'regenerate=' . $regenerate);
		$stub = $this->stubForResource($uri);
		if(strlen($stub) && !$regenerate)
		{
			return $stub;
		}
		if(!strlen($stub) && strlen($realUri))
		{
			$stub = $this->stubForResource($realUri);
			if(strlen($stub) && !$regenerate)
			{
				return $stub;
			}
		}
		if(!strlen($stub) && ($obj = $this->objectForIri($uri, null, null, true)) !== null)
		{
			if(isset($obj->iri) && is_array($obj->iri))
			{
				foreach($obj->iri as $resource)
				{
					$stub = $this->stubForResource($resource);
					if(strlen($stub))
					{
						if($regenerate)
						{
							break;
						}
						return $stub;
					}
				}
			}
		}
		if(!strlen($stub))
		{
			$stub = UUID::generate();
		}
		if(!strlen($realUri))
		{
			$realUri = $uri;
		}
		$this->mapResource($stub, $realUri, $source, $title);
		$nonce = $this->needsMapping($stub, 'graph');
		if($this->generateStub($stub, null, $lazy))
		{
			$this->needsMapping($stub, 'graph', false, $nonce);
		}
		return $stub;
	}	

	/**
	 * High-level interface for ingesting resources, which can optionally be
	 * evaluated after ingest and separately, can trigger regeneration of
	 * specific stubs.
	 *
	 * if $evaluate is true, the resulting graph object will be evaluated
	 * after ingest as a stand-alone entity.
	 *
	 * @task Ingesting resources
	 */
	public function pushUri($uri, $canonicalUri = null, $evaluate = true, $data = null, $fetchImmediately = false)
	{
		if($data !== null && !is_array($data))
		{
			$data = array('regenerate' => array($data));
		}
		else if($data === null)
		{
			$data = array();
		}
		if($fetchImmediately)
		{
			$result = $this->ingestRDF($uri, true, $canonicalUri, false);
			if($result === null)
			{
				return false;
			}
			$uuid = $this->uuidOfObject($result);
			if($evaluate)
			{
				$this->debug('Ingested ' . $uri . ' as ' . $uuid);
				$this->evaluateEntity($uuid);
			}
			if(!empty($data['regenerate']))
			{
				foreach($data['regenerate'] as $genUuid)
				{
					$this->generateStub($uuid);
				}
			}
			return $uuid;
		}		
		if($evaluate)
		{
			$data['method'] = 'evaluateEntity';
		}
		if(isset($data['uuid']))
		{
			$uuid = $data['uuid'];
		}
		else
		{
			$uuid = UUID::nil();
		}
		return $this->queueIngest($uuid, $uri, $data, true);
	}			

	/**
	 * Perform a query against the database.
	 * 
	 * Overrides Store::query() to invoke $this->fulltextQuery() if
	 * $query['text'] is specified or if only a full-text database is
	 * available.
	 *
	 * @task Queries
	 */
	public function query($query)
	{
		if($this->debug !== false)
		{
			ob_start();
			print_r($query);
			$this->debug(ob_get_clean());
		}
		if(isset($query['text']) || !isset($this->db))
		{
			return $this->fulltextQuery($query);
		}
		return parent::query($query);
	}

	/**
	 * Perform a full-text query.
	 *
	 * $query must be the full-text query string, or an array where
	 * $query['text'] is the full-text query string.
	 *
	 * @internal
	 * @task Queries
	 */
	public function fulltextQuery($query)
	{
		if(($search = $this->searchEngine()) === null)
		{
			return null;
		}
		$rs = $search->query($query);
		$rs['storableClass'] = 'TroveObject';
		foreach($rs['list'] as $k => $entry)
		{
			$rs['list'][$k] = $this->dataForUUID($entry['uuid']);
		}
		$set = new StaticStorableSet($this, $rs);
		return $set;
	}

}

/**
 * Subclass of TroveMap which handles updates to the map.
 */

class TroveWriteableMap extends TroveMap
{
	/**
	 * Add a resource to the map
	 */
	public function add($resource, $source, $type = 'exactMatch', $confidence = 100, $title = null, $local = false, $priority = 50, $object = null, $relatedObjectUuid = null)
	{
		if(!is_array($resource))
		{
			$resource = array($resource);
		}
		if($relatedObjectUuid === null)
		{
			$relatedObjectUuid = $this->objectUuid;
		}
		if($relatedObjectUuid === null)
		{
			trigger_error('Cannot add a new item to a TroveMap without a related object UUID', E_USER_ERROR);
			return;
		}
		$resource_uuid = $this->model->uuidOfObject($object);
		foreach($resource as $res)
		{
			$res = strval($res);
			if($source === null)
			{
				$source = $this->model->determineSourceOfResource($res);
			}
			else
			{
				$source = strval($source);
			}
			if(!strlen($resource_uuid))
			{
				$resource_uuid = $this->model->uuidForIri($res);
			}
			$entry = array(
				'uuid' => $this->uuid,
				'source' => $source,
				'type' => $type,
				'resource' => $res,
				'confidence' => $confidence,
				'title' => $title,
				'local' => $local,
				'priority' => $priority,
				'resource_uuid' => $resource_uuid,
				'object_uuid' => $relatedObjectUuid,
				);
			$k = sprintf('%04d-%03d-%04d', $entry['priority'], 100 - $entry['confidence'], count($this->map));
			$this->map[$k] = $entry;
			if($object !== null)
			{
				if(is_object($object) || (isset($object[0]) && is_object($object[0])))
				{
					$this->objects[$res] = $object;					
				}
			}
			$resource_uuid = null;
			$object = null;
		}
		ksort($this->map);
	}

	/**
	 * Add a resource to the map given its object
	 */
	public function addObject($object, $source = null, $type = 'exactMatch', $confidence = 100, $title = null, $local = false, $priority = 50, $relatedObjectUuid = null)
	{
		if(is_array($object) && isset($object[0]))
		{
			$first = $object[0];
		}
		else
		{
			$first = $object;
		}
		$resource = strval($first);
		if($source === null)
		{
			$source = $first['trove:publisher']->first();
		}
		if($title === null)
		{
			$title = $first->title();
		}
		return $this->add($resource, $source, $type, $confidence, $title, $local, $priority, $object, $relatedObjectUuid);
	}

	/**
	 * Apply the changes made to a set
	 *
	 * XXX Should trigger an error if called on a filtered set
	 */
	public function apply()
	{
		$this->model->applyMappings($this->uuid, $this->map, $this->previousStub, $this->objectUuid);
		$this->previousStub = $this->uuid;
		foreach($this->map as $k => $mapping)
		{
			$this->map[$k]['uuid'] = $this->uuid;
		}
		return true;
	}

	/**
	 * Attempt to expand all of the references in the map to include
	 * equivalents (i.e., those named via owl:sameAs).
	 *
	 * $callback takes the form:
	 *   function callback($map, $entry, $entity, $equivalentUri)
	 *
	 * The callback must return an object or a UUID of an object
	 * if $equivalentUri should be added to the map.
	 */
	public function expandReferences($callback)
	{
		static $equivalentPredicates = array(
			'http://www.w3.org/2002/07/owl#sameAs',
			);
		foreach($this->map as $k => $entry)
		{
			if(!($entity = $this->objectForEntry($entry)))
			{
				continue;
			}
			$first = $this->model->firstObject($entity);
			if(!$first || !is_object($first))
			{
				continue;
			}
			foreach($equivalentPredicates as $predicate)
			{
				$uris = $first[$predicate]->uris();
				foreach($uris as $equivalentUri)
				{
					$result = call_user_func($callback, $this, $entry, $entity, $equivalentUri);
					$uuid = $this->model->uuidOfObject($result);
					if($uuid === null || $uuid === false)
					{
						continue;
					}
					$this->add($equivalentUri, null, $entry['type'], $entry['confidence'], null, false, $entry['priority'], null, $entry['object_uuid']);
				}
			}
		}
	}
}
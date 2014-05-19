<?php

/**
 * Trove References Generator
 *
 * Generates stub-to-stub references for all of the internal references
 * with known predicates, as well as the SKOS stub-to-entity references.
 *
 * @year 2011
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

/**
 * TroveRefsGenerator is responsible for generating two kinds of referencing
 * information: stub-to-stub references, which reflect the relationships
 * between exactly-matching entities and other entities within the Trove,
 * and stub-to-entity references, which reflect the mappings between the
 * stub and its entities.
 *
 * Currently TroveRefsGenerator::$stubRefPredicates lists the "known" set of
 * predicates which are used for stub-to-stub references.
 */
class TroveRefsGenerator extends TroveGenerator
{
	/**
	 * List of known predicates which, when encountered and have objects which
	 * are within the Trove store, cause stub-to-stub references to be
	 * generated.
	 */
	public static $stubRefPredicates = array(
		'http://purl.org/dc/terms/creator',
		'http://purl.org/dc/terms/publisher',
		'http://purl.org/dc/terms/isPartOf',
		'http://purl.org/dc/terms/subject',
		'http://purl.org/dc/terms/hasVersion',
		'http://purl.org/dc/terms/isVersionOf',
		'http://purl.org/theatre#performance_of',
		'http://purl.org/theatre#production_of',
		'http://purl.org/theatre#part_of_season',
		'http://purl.org/theatre#put_on_by',
		'http://purl.org/theatre#venue',
		'http://projectreith.com/ns/trove/publisher',
		'http://www.geonames.org/ontology#locatedIn',
		'http://www.geonames.org/ontology#parentADM1',
		'http://www.geonames.org/ontology#parentADM2',
		'http://www.geonames.org/ontology#parentADM3',
		'http://www.geonames.org/ontology#parentADM4',
		'http://www.geonames.org/ontology#parentFeature',
		'http://www.geonames.org/ontology#parentCountry',
		'http://purl.org/ontology/po/series',
		'http://purl.org/ontology/po/brand',
		'http://purl.org/ontology/po/episode',
		'http://purl.org/ontology/po/version',
		'http://purl.org/ontology/po/category',
		'http://purl.org/ontology/po/broadcast',
		'http://www.w3.org/2008/05/skos#broader' => 'http://purl.org/dc/terms/isPartOf',
		'http://www.w3.org/2008/05/skos#narrower',
		'http://www.w3.org/2004/02/skos/core#broader' => 'http://purl.org/dc/terms/isPartOf',
		'http://www.w3.org/2004/02/skos/core#narrower',
		);

	public static $inverseRefPredicates = array(
		'http://purl.org/ontology/po/version' => 'http://purl.org/dc/terms/isPartOf',
		'http://purl.org/ontology/po/episode' => 'http://purl.org/dc/terms/isPartOf',
		'http://purl.org/ontology/po/series' => 'http://purl.org/dc/terms/isPartOf',
		'http://purl.org/ontology/po/clip' => 'http://purl.org/dc/terms/isPartOf',
		'http://www.w3.org/2004/02/skos/core#narrower' => 'http://www.w3.org/2008/05/skos#broader',
		'http://www.w3.org/2004/02/skos/core#broader' => 'http://www.w3.org/2008/05/skos#narrower',
		'http://purl.org/dc/terms/hasVersion' => 'http://purl.org/dc/terms/isVersionOf',
		'http://purl.org/dc/terms/isVersionOf' => 'http://purl.org/dc/terms/hasVersion',
		'http://purl.org/NET/c4dm/event.owl#factor' => null,
		'http://purl.org/NET/c4dm/event.owl#product' => null,
		);

	/**
	 * Parentage predicates, in order of preference (i.e., the predicate
	 * appearing first is most preferred)
	 */
	public static $parentRefPredicates = array(
		'event' => array(
			'http://purl.org/theatre#production_of',			
			),
		'thing' => array(
			'!http://purl.org/ontology/po/version',
			),
		'place' => array(
			'http://www.geonames.org/ontology#parentFeature',			
			'http://www.geonames.org/ontology#parentADM4',
			'http://www.geonames.org/ontology#parentADM3',
			'http://www.geonames.org/ontology#parentADM2',
			'http://www.geonames.org/ontology#parentADM1',
			'http://www.geonames.org/ontology#parentCountry',
			),
		'person' => array(
			),
		'collection' => array(
			'http://purl.org/dc/terms/isPartOf',
			'http://www.w3.org/2008/05/skos#broader',
			'http://www.w3.org/2004/02/skos/core#broader',
			'!http://www.w3.org/2008/05/skos#narrower',
			'!http://www.w3.org/2004/02/skos/core#narrower',
			),
		);

	public static $superiorPredicates = array(
		'http://purl.org/dc/terms/isPartOf',
		'http://purl.org/dc/terms/isVersionOf',
		);

	/**
	 * Generate reference information for the stub.
	 */
	public function generate($stubUuid, TroveMap $objects, &$stubSet)
	{
		$data =& $stubSet[0];

		if(!isset($data['structuralRefs']))
		{
			$data['structuralRefs'] = array();
		}
		if(!isset($data['relatedStubs']))
		{
			$data['relatedStubs'] = array();
		}

		/* Generate stub-to-stub references for specific predicates
		 * (e.g., dct:creator)
		 */
		foreach(self::$stubRefPredicates as $predicate => $storeAs)
		{
			if(is_numeric($predicate))
			{
				$predicate = $storeAs;
			}
			$uris = array();
			if(isset($data[$predicate]))
			{
				foreach($data[$predicate] as $value)
				{
					if(isset($value['type']) && $value['type'] == 'uri' && !in_array($value['value'], $uris))
					{
						$uris[] = $value['value'];
					}
				}
			}
			$set = RDFSet::setFromInstances($predicate, $objects['exactMatch']);
			$set = $set->uris();
			if(count($set))
			{
				foreach($set as $uri)
				{
					$uri = strval($uri);
					if(!in_array($uri, $uris))
					{
						$uris[] = $uri;
					}
				}
			}
			foreach($uris as $uri)
			{
				$this->addStubRefToData($stubUuid, $data, $storeAs, $uri, $objects);
			}
		}
		foreach(self::$inverseRefPredicates as $predicate => $inverse)
		{
//			$this->debug('inverseRefPredicates', $predicate);
			$uris = array();
			foreach($objects['exactMatch'] as $matches)
			{
				if(is_array($matches))
				{
					$first = array_shift($matches);
					foreach($matches as $match)
					{
						if(isset($match->{$predicate}))
						{
							foreach($match->{$predicate} as $value)
							{
								if($value instanceof RDFURI && $first->hasSubject($value))
								{
									$u = strval($match);
									$uris[] = strval($match);
									break;
								}
							}
						}
					}
				}				
			}
			foreach($uris as $uri)
			{
				$this->addStubRefToData($stubUuid, $data, $inverse, $uri, $objects);
			}
		}
		/* Add each of the matching objects using SKOS matching predicates */
		foreach($this->model->matchTypes as $key => $predicate)
		{
			unset($data[$predicate]);
			if($key == 'noMatch')
			{
				continue;
			}
			$list = $objects[$key];
			if($list !== null && count($list))
			{
				$data[$predicate] = array();
				foreach($list as $obj)
				{
					if($key != 'exactMatch')
					{
						if(!$this->model->isStub($obj))
						{
							$obj = $this->model->firstObject($obj);
							$uuid = $this->model->stubForUuid($obj->uuid);
							if(!strlen($uuid))
							{
								$this->debug('No stub exists for', $obj->uuid, 'yet, queuing for evaluation');
								$this->model->needsMapping($obj->uuid, $obj->kind);
								continue;
							}
							$relStub = $this->model->objectForUuid($uuid);
							if(!strlen($relStub))
							{
								$this->debug('Found stub UUID', $uuid, 'but no object');
								$this->model->needsMapping($uuid, 'stub');
								continue;
							}
							$obj = $relStub;
						}
					}					
					$obj = $this->model->firstObject($obj);
					if($obj->uuid == $stubUuid)
					{
						continue;
					}
					$subject = $obj->subject()->asArray();
					$this->addUriToData($data, $predicate, $subject['value']);
				}
			}
		}
		/* Create stub-to-stub references for each of the structural refs */
		foreach($objects['exactMatch'] as $exact)
		{
			$exact = $this->model->firstObject($exact);
//			$this->debug('exactMatch', $exact);
			if(isset($exact->structuralRefs) && count($exact->structuralRefs))
			{
				foreach($exact->structuralRefs as $refUuid)
				{
					if(($stub = $this->model->stubObjectForIri('urn:uuid:' . $refUuid)) !== null)
					{
						$stub = $this->model->firstObject($stub);
						if(!in_array($stub->uuid, $data['structuralRefs']))
						{
							$data['structuralRefs'][] = $stub->uuid;
						}
						if(!in_array($stub->uuid, $data['relatedStubs']))
						{
							$this->model->dirty($stub->uuid, $stub->kind, $stub->uuid, true);
							$data['relatedStubs'][] = $stub->uuid;
						}
					}
					else
					{
						$this->debug('No stub could be found for structural reference', $refUuid);
					}
				}
			}
		}

		/* Determine the parent of this object, if any */
		$parentStub = $this->determineParent($objects, $data['kind'],
											 $data['structuralRefs'], $data);
		if($parentStub)
		{
			$this->debug('Setting parent to', $parentStub);
			$data['parent'] = $parentStub->uuid;
		}
		else if($data['kind'] == 'collection' && !$data['isPublisher'])
		{
//			$this->debug('Entity is a collection and not a publisher');
			if(count($data['publisherUuids']))
			{
				$data['parent'] = $data['publisherUuids'][0];
				if(($parentStub = $this->model->objectForUuid($data['parent'], null, null, true)) !== null)
				{
					$data[Trove::trove . 'parent'] = array($parentStub->subject()->asArray());
					$this->debug('Setting parent to', $parentStub);
				}
			}
		}		
	}

	protected function addStubRefToData($newStubUuid, &$data, $predicate, $uri, $objects)
	{
//		$this->debug('addStubRefToData', $uri);
		if(($stubObject = $this->model->stubObjectForIri($uri)) !== null)
		{
			$stubObject = $this->model->firstObject($stubObject);
			$stubUuid = $stubObject->uuid;
			if(!strcmp($stubUuid, $newStubUuid))
			{
//				$this->debug('Ignoring reference to', $uri, 'as it is part of this stub');
				return;
			}
			$u = $stubObject->troveUri();
			$this->addUriToData($data, $predicate, $u);
			if(!in_array($stubUuid, $data['relatedStubs']))
			{
				$data['relatedStubs'][] = $stubObject->uuid;
				/* Mark the target stub as dirty so that its inbound
				 * reference counts will be updated.
				 */
				$this->model->dirty($stubObject->uuid, $stubObject->kind, $stubObject->uuid, true);
			}
			if(!in_array($stubUuid, $data['tags']))
			{
				/* Add the stub we're referencing to the
				 * object's tags, so that it can be easily
				 * queried.
				 */
				$data['tags'][] = $stubUuid;
			}
		}
		else
		{
			$this->possiblyIngest($uri, $objects, $newStubUuid);
		}
	}

	protected function possiblyIngest($uri, $objects, $newStubUuid)
	{
		if(($obj = $this->model->objectForIri($uri, null, null, true)))
		{
			$this->debug($uri, 'has been ingested but not yet evaluated; marking as needing mapping');
			$this->model->needsMapping($obj->uuid, $obj->kind);
			return;
		}
		/* If, and only if, URI is on the same domain as one of the exactMatch
		 * objects we already have, then queue the URI for ingest.
		 */
		$u = new URL($uri);
		$ingest = false;
		$source = null; /* This isn't strictly source, just a matching object */
		foreach(TroveServer::$whitelist as $base)
		{
			if(!strncmp($uri, $base, strlen($base)))
			{
				$ingest = true;
				$source = $newStubUuid;
				break;
			}
		}
		if(!$ingest)
		{
			foreach($objects['exactMatch'] as $exact)
			{
				$list = $this->model->firstObject($exact)->subjects();
				foreach($list as $l)
				{
					if($u->scheme == $l->scheme &&
					   $u->port == $l->port &&
					   $u->host == $l->host)
					{
						$ingest = true;
						$source = $exact;
						break 2;
					}
				}
			}
		}
		if(!$ingest)
		{
			return;
		}
//		$this->debug($uri, 'has not yet been ingested; queueing for', $newStubUuid);
		/* $newStubUuid will be re-generated when the URI is ingested */
		$data = array();
		$data['uuid'] = $newStubUuid;
		$data['regenerate'] = array($newStubUuid);
		$this->model->pushUri($uri, null, true, $data);
	}

	/**
	 * Attempt to determine the stub which is the parent of the given
	 * (non-stub) object set.
	 */
	protected function determineParent($objects, $kind, &$refList, &$data)
	{
		if(!isset($objects['exactMatch']) || !count($objects['exactMatch']))
		{
//			$this->debug('determineParent:', 'No exactMatch entities');
			return null;
		}
		$theParent = null;
		/* If there's explicit parentage, use that */
		foreach($objects['exactMatch'] as $match)
		{
			$match = $this->model->firstObject($match);
			if(isset($match->parent))
			{
				$stubUuid = $this->model->stubForUuid($match->parent);
				if(strlen($stubUuid) && ($parentStub = $this->model->objectForUuid($stubUuid)))
				{
					if($theParent === null)
					{
						$theParent = $parentStub;
					}
					else if(!in_array($stubUuid, $refList))
					{
						$refList[] = $stubUuid;
					}
				}
			}
		}
		if(isset(self::$parentRefPredicates[$kind]))
		{
			$parentRefPredicates = self::$parentRefPredicates[$kind];
		}
		else
		{
//			$this->debug('determineParent:', 'No parentRefPredicates for kind', $kind);
			return null;
		}
		$stub = $this->attemptSuperiorMatch($objects, $kind, $refList, $data, $parentRefPredicates);
		if($stub !== null && $theParent === null)
		{
			$theParent = $stub;
		}
		$stub = $this->attemptSuperiorMatch($objects, $kind, $refList, $data, self::$superiorPredicates);
		return $theParent;
	}

	protected function attemptSuperiorMatch($objects, $kind, &$refList, &$data, $predicateList)
	{
		$theParent = null;
		/* Try each of the parent-referencing predicates in turn */
		foreach($predicateList as $predicate)
		{
			if(substr($predicate, 0, 1) == '!')
			{
				$this->matchInverseParent($objects, $kind, $refList, substr($predicate, 1), $theParent, $data);
				continue;
			}
			$set = RDFSet::setFromInstances($predicate, $objects['exactMatch'])->uris();
			if(count($set))
			{
				foreach($set as $uri)
				{
					$u = strval($uri);
					$f = false;
					foreach($objects['exactMatch'] as $match)
					{
						if(!strcmp($this->model->firstObject($match), $u))
						{
							/* Skip parentage assertions which refer to an
							 * object we're considered the same as
							 */
							$f = true;
							break;
						}
					}
					if($f)
					{
						continue;
					}
					if(($parentStub = $this->addParent($objects, $kind, $refList, $u, $data)))
					{
						if($theParent === null)
						{
							$theParent = $parentStub;
						}
					}
				}
			}
		}
		return $theParent;
	}

	protected function addParent($objects, $kind, &$refList, $parentUri, &$data)
	{
		assert(is_array($refList));
		$parentUri = strval($parentUri);
		if(($parentStub = $this->model->stubObjectForIri($parentUri)) !== null)
		{
			$this->debug('Matched parent', $parentUri, 'with stub UUID', $parentStub->uuid);
			if(!in_array($parentStub->uuid, $data['superior']))
			{
				$data['superior'][] = $parentStub->uuid;
			}
			if(!in_array($parentStub->uuid, $refList))
			{
				echo "Adding " . $parentStub->uuid . "to reference list\n";
				$refList[] = $parentStub->uuid;
			}
			$data[Trove::trove . 'parent'][] = $parentStub->subject()->asArray();
			/* Add the structural references of the parent to the child */
			if(isset($parentStub->structuralRefs))
			{
				foreach($parentStub->structuralRefs as $ref)
				{
					if(!in_array($ref, $refList))
					{
						$refList[] = $ref;
					}
				}
			}
		}
		else
		{
			$this->debug('Could not find a stub for potential parent', $parentUri);
			$this->possiblyIngest($parentUri, $objects, $data['uuid']);
		}
		return $parentStub;
	}

	protected function matchInverseParent($objects, $kind, &$refList, $predicate, &$theParent, &$data)
	{
		if(!is_array($refList))
		{
			trigger_error('Assertion failed: $refList is not an array', E_USER_ERROR);
		}
		foreach($objects['exactMatch'] as $matches)
		{
			if(is_array($matches))
			{
				$first = array_shift($matches);
				foreach($matches as $match)
				{
					if(isset($match->{$predicate}))
					{
						foreach($match->{$predicate} as $value)
						{
							if($value instanceof RDFURI && $first->hasSubject($value))
							{
								$this->debug("$predicate is set on $match => $value");
								if(($parentStub = $this->addParent($objects, $kind, $refList, $match, $data)) !== null)
								{
									$data[Trove::trove.'inboundRef'][] = array(
										'type' => 'uri',
										'value' => strval($parentStub),
										);
									$u = $this->model->uuidOfObject($parentStub);
									if(!in_array($u, $data['relatedStubs']))
									{
										$data['relatedStubs'][] = $u;
									}
									if(!in_array($u, $data['tags']))
									{
										$data['tags'][] = $u;
									}
									if($theParent === null)
									{
										$theParent = $parentStub;
									}
								}
							}
						}
					}
				}
			}
		}
	}
}

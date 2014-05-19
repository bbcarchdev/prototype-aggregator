<?php

/**
 * Trove Type Generator
 *
 * Determines basic type information for stubs.
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
 * TroveTypeGenerator fills in the rdf:type property on a stub, based upon
 * the types in all of the exactly-matching entities. In doing so, it
 * also sets the stub's internal 'kind' property. The stub should have its
 * internal 'isPublisher' property set to true prior to entry to indicate
 * that it relates to a publisher entity.
 */
class TroveTypeGenerator extends TroveGenerator
{
	/**
	 * Generate the type information for a stub.
	 */
	public function generate($stubUuid, TroveMap $objects, &$stubSet)
	{
		$data =& $stubSet[0];

		/* Determine what the publisher of this stub is, or if this is
		 * itself a publisher.
		 */
		$entityUuids = array();
		$publisherUuids = array();
		$publisher = false;
		foreach($objects['exactMatch'] as $exact)
		{
			$publisherUuid = null;
			$exact = $this->model->firstObject($exact);
			$entityUuids[] = $exact->uuid;
			if($exact->kind == 'publisher' || $exact->kind == 'user')
			{
				/* This is a stub for a publisher itself */
				$publisher = true;
				$this->debug('exactly-matching entity', $exact->uuid, 'is a publisher');
			}
			else if(isset($exact->publisher))
			{
				$pub = $this->model->objectForUuid($exact->publisher, null, null, true);
				if($this->model->isStub($pub))
				{
					$publisherUuid = $pub->uuid;
				}
				else
				{
					$publisherUuid = $this->model->stubForUuid($exact->publisher);
				}
				if(strlen($publisherUuid) && !in_array($publisherUuid, $publisherUuids))
				{
					$publisherUuids[] = $publisherUuid;
				}
			}
			else if(isset($exact->{Trove::trove.'publisher'}))
			{
				$this->debug('Warning:', $exact->uuid, 'is missing a publisher internal property but has a trove:publisher');
				foreach($exact->{Trove::trove.'publisher'} as $pub)
				{
					if($pub instanceof RDFURI)
					{
						$publisherUuid = $this->model->stubForResource($pub);
						if(strlen($publisherUuid) && !in_array($publisherUuid, $publisherUuids))
						{
							$publisherUuids[] = $publisherUuid;
						}
						else if(!strlen($publisherUuid))
						{
							$this->debug('Warning: Unable to locate stub for', $pub);
						}
					}
				}
			}
		}
		if($publisher)
		{
			$publisherUuids = array();
		}
		/* Add each of the publisher object UUIDs to the object's tags and to
		 * the indexed data.
		 */
		foreach($publisherUuids as $uuid)
		{
			if(strlen($uuid))
			{
				$data['tags'][] = $uuid;
				$data['_index']['publisher'][] = $uuid;
			}
		}
		$data['isPublisher'] = $publisher;
		$data['publisherUuids'] = $publisherUuids;
		/* Aggregate the rdf:type values from all of the exactly-matching
		 * entities.
		 */
		$classes = RDFSet::setFromInstances(RDF::rdf.'type', $objects['exactMatch']);
		$classes->removeValueString(RDF::rdf.'Description');
		$data[RDF::rdf.'type'] = $classes->asArray();
		$data['kind'] = null;
		/* If this is not a publisher stub, try to find out what sort of
		 * stub it should be and set $data['kind'] accordingly.
		 */
		if(empty($publisher))
		{
			$classUris = $classes->uris();
			$data['kind'] = $this->model->kindOfStubForClassList($classUris, true);
			if(!strlen($data['kind']))
			{
				trigger_error("Failed to determine kind of stub to use; defaulting to 'thing'.\n  Classes: $classes", E_USER_NOTICE);
				$data['kind'] = 'thing';
			}
		}
		else
		{			
			$this->debug($stubUuid, 'is a publisher, setting type to "collection"');
			$data['kind'] = 'collection';
		}
		/* Set the type of the stub to trove:<Kind> (e.g., trove:Person) */
		$className = strtoupper(substr($data['kind'], 0, 1)) . strtolower(substr($data['kind'], 1));
		$this->debug('Object is a ' . $data['kind']);			
		array_unshift($data[RDF::rdf.'type'], array('type' => 'uri', 'value' => Trove::trove. $className));		
	}
}
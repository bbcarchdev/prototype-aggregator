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

require_once(dirname(__FILE__) . '/server.php');

/**
 * Implementation of the Spindle aggregator using a relational database store.
 *
 */
class TroveDB extends TroveServer
{
	/**
	 * Generate and set the raw data for a stub.
	 *
	 * @task Evaluation
	 * @internal
	 */
	protected function setStubData($stub, $objects, $lazy)
	{
		$data = array();
		$data['tags'] = array();
		$data['relatedStubs'] = array();
		$data['structuralRefs'] = array();
		$data['superior'] = array();
		$data['kind'] = 'graph';
		$data['uuid'] = $stub;
		$data['iri'] = array();
		$data['parent'] = null;
		$data['visible'] = 'Y';
		$data['_index'] = array('_fullText' => array(), 'publisher' => array());
		$stubSet = array($data);
		$data =& $stubSet[0];
		$generators = $this->generators();
		foreach($generators as $genClass => $generator)
		{
			$this->debug('Populating data of', $data['uuid'], 'using', $genClass);
			$generator->generate($data['uuid'], $objects, $stubSet);
		}		
		$data[Trove::trove . 'outboundReferenceCount'] = array(
			array('value' => count($data['relatedStubs']), 'type' => XMLNS::xsd . 'integer'),
			);
		if(count($stubSet) == 1)
		{
			$stubSet = $data;
		}
		return $this->setData($stubSet, null, $lazy);
	}
	
	/**
	 * Mark an object as needing mapping.
	 *
	 * @task Evaluation
	 */
	public function needsMapping($uuid, $kind, $needsMapping = true, $nonce = null)
	{
		$this->db->perform(array($this, '_performNeedsMapping'), array($uuid, $kind, $needsMapping, $nonce));
	}

	/**
	 * Mark an object as needing mapping (transaction callback).
	 *
	 * @internal
	 * @task Evaluation
	 */
	public function _performNeedsMapping($db, $info)
	{
		$uuid = $info[0];
		$kind = $info[1];
		$needsMapping = $info[2];
		$nonce = $info[3];
		if($db->row('SELECT "uuid" FROM {object_mapstatus} WHERE "uuid" = ?', $uuid))
		{
			if(strlen($nonce))
			{
				$db->exec('UPDATE {object_mapstatus} SET "dirty" = ?, "kind" = ? WHERE "uuid" = ? AND "nonce" = ?', ($needsMapping ? 'Y' : 'N'), $kind, $uuid, $nonce);
			}
			else
			{
				$nonce = substr(md5($uuid . microtime(true) . rand()), 0, 16);
				$db->exec('UPDATE {object_mapstatus} SET "dirty" = ?, "kind" = ?, "nonce" = ? WHERE "uuid" = ?', ($needsMapping ? 'Y' : 'N'), $kind, $nonce, $uuid);
			}
		}
		else
		{
			if(!strlen($nonce))
			{
				$nonce = substr(md5($uuid . microtime(true) . rand()), 0, 16);
			}
			$db->insert('object_mapstatus', array(
									   'uuid' => $uuid,
									   'kind' => $kind,
									   'nonce' => $nonce,
									   'dirty' => ($needsMapping ? 'Y' : 'N'),
									   ));
		}
		return $nonce;
	}

	/**
	 * Reset the map-evaluation status of all objects.
	 *
	 * @task Evaluation
	 */
	public function resetEvaluationStatus($kind = null)
	{
		$queryArgs = array('Y');
		$q = 'UPDATE {object_mapstatus} SET "dirty" = ?';
		if(is_array($kind))
		{
			$x = array();
			foreach($kind as $k)
			{
				$x[] = '?';
				$queryArgs[] = $k;
			}
			if(count($x))
			{
				$q .= ' WHERE "kind" IN (' . implode(', ', $x) . ')';
			}
		}
		$this->db->vexec($q, $queryArgs);
	}

	/**
	 * Return the set of objects requiring evaluation.
	 *
	 * @task Evaluation
	 */
	public function pendingEvaluationSet($limit = 200, $kind = null)
	{	   
		$queryArgs = array('Y');
		$q = 'SELECT * FROM {object_mapstatus} WHERE "dirty" = ?';
		if(is_array($kind))
		{
			$x = array();
			foreach($kind as $k)
			{
				$x[] = '?';
				$queryArgs[] = $k;
			}
			if(count($x))
			{
				$q .= ' AND "kind" IN (' . implode(', ', $x) . ')';
			}
		}
		$q .= ' LIMIT ' . intval($limit);
		return $this->db->queryArray($q, $queryArgs);
	}

	/**
	 * Return the default stub UUID for an object, generating one if
	 * necessary.
	 *
	 * @task Evaluation
	 */
	public function defaultStubForUuid($uuid)
	{		
		if(($stub = $this->db->value('SELECT "stub" FROM {object_default_stub} WHERE "uuid" = ?', $uuid)) !== null)
		{
			return $stub;
		}
		$stub = $this->stubForUuid($uuid);
		if(!strlen($stub))
		{
			$stub = UUID::generate();
			$this->debug('Generated new default stub', $stub, 'for UUID', $uuid);
		}
		/* XXX Transaction-safety */
		$this->db->insert('object_default_stub', array('uuid' => $uuid, 'stub' => $stub));
		return $stub;
	}

	/**
	 * Return the UUID of the entity which has the default stub of this UUID
	 *
	 * @task Evaluation
	 */
	public function entityHavingDefaultStubOf($uuid)
	{
		return $this->db->value('SELECT "uuid" FROM {object_default_stub} WHERE "stub" = ?', $uuid);
	}

	/**
	 * Return all of the locally-assigned depictions for an entity.
	 *
	 * @internal
	 * @task Evaluation
	 */
	public function localDepictions($uuid)
	{
		$list = array();
		$rows = $this->db->rows('SELECT * FROM {local_depiction} WHERE "uuid" = ? ORDER BY "sequence"', $uuid);
		foreach($rows as $r)
		{
			$list[] = $r['resource'];
		}
		return $list;
	}
	
	/**
	 * Determine and return an array describing the current aggregator status
	 *
	 * @task Utilities
	 **/
	public function status()
	{
		$info = array();
		if(!$this->db)
		{
			$info['status'] = 'Offline (no database connection)';
			return $info;
		}
		$info['status'] = 'Online';
		$info['explanation'] = array(
			'"mapstatus" describes the state of the evaluation engine. The figures under "dirty" are the number of entities which are awaiting evaluation by the aggregator.',
			'"indexstatus" describes the state of the navigational and full-text indexes. The figures relate to the number of objects (of any sort, including stubs) which are awaiting basic and full-text indexing and the number for which this has already been completed.',
			'"store" relates to the state of the object store itself, and covers all objects, including stubs. The "total" figure is the total number of objects, of any kind, which are stored in some way. The broken-down totals are reliant on objects having been indexed at least once, so if the figures add up to less than the "total" figure, the difference is the number of objects yet to be indexed for the first time.',
			);
		$info['mapstatus'] = array(
			'dirty' => array('total' => $this->db->value('SELECT COUNT(*) FROM {object_mapstatus} WHERE "dirty" = ?', 'Y')),
			'clean' => array('total' => $this->db->value('SELECT COUNT(*) FROM {object_mapstatus} WHERE "dirty" = ?', 'N')),
			'total' => array(),
			);
		$info['mapstatus']['total']['total'] = $info['mapstatus']['dirty']['total'] + $info['mapstatus']['clean']['total'];
		$kinds = $this->db->rows('SELECT DISTINCT "kind" FROM {object_mapstatus}');
		foreach($kinds as $kind)
		{
			$kind = $kind['kind'];
			$d = $info['mapstatus']['dirty'][$kind] = $this->db->value('SELECT COUNT(*) FROM {object_mapstatus} WHERE "kind" = ? AND "dirty" = ?', $kind, 'Y');
			$c = $info['mapstatus']['clean'][$kind] = $this->db->value('SELECT COUNT(*) FROM {object_mapstatus} WHERE "kind" = ? AND "dirty" = ?', $kind, 'N');
			$info['mapstatus']['total'][$kind] = $d + $c;
		}
		$info['indexstatus'] = array(
			'clean' => $this->db->value('SELECT COUNT(*) FROM {object} WHERE "dirty" = ?', 'N'),
			'dirty' => $this->db->value('SELECT COUNT(*) FROM {object} WHERE "dirty" = ?', 'Y'),
			);
		$info['store'] = array(
			'total' => $this->db->value('SELECT COUNT(*) FROM {object}'),
			);
		$kinds = $this->db->rows('SELECT DISTINCT "kind" FROM {object_base}');
		foreach($kinds as $kind)
		{
			$kind = $kind['kind'];
			$info['store'][$kind] = $this->db->value('SELECT COUNT(*) FROM {object_base} WHERE "kind" = ?', $kind);
		}
		$info['ingest']['resources'] = $this->db->value('SELECT COUNT(*) FROM {fetch_queue}');
		$info['ingest']['stubs'] = $this->db->value('SELECT COUNT(DISTINCT "uuid") FROM {fetch_queue}');
		return $info;
	}

	/**
	 * Entirely delete an object.
	 *
	 * @task Utilities
	 */
	public function deleteObjectWithUUID($uuid)
	{
		if(!parent::deleteObjectWithUUID($uuid))
		{
			return false;
		}
		$this->db->exec('DELETE FROM {object_browse} WHERE "uuid" = ?', $uuid);
		$this->db->exec('DELETE FROM {object_structrefs} WHERE "from" = ?', $uuid);
		$this->db->exec('DELETE FROM {object_mapping} WHERE "uuid" = ?', $uuid);
		$this->db->exec('DELETE FROM {object_mapstatus} WHERE "uuid" = ?', $uuid);
		$this->db->exec('DELETE FROM {object_type} WHERE "uuid" = ?', $uuid);
		$this->db->exec('DELETE FROM {local_mapping} WHERE "uuid" = ?', $uuid);
		$this->db->exec('DELETE FROM {local_tags} WHERE "uuid" = ?', $uuid);
		return true;
	}
	
	/**
	 * Queue the ingest of some RDF
	 *
	 * @task Ingesting resources
	 */
	public function queueIngest($stubUuid, $uri, $data = null, $alwaysQueue = false)
	{
		$uri = strval($uri);
		assert(substr($uri, 0, 1) != '<');
		if(!$alwaysQueue && $this->db->row('SELECT "uuid" FROM {fetch_queue} WHERE "uuid" = ? AND "uri" = ?', $stubUuid, $uri))
		{
			return;
		}
		$this->debug('Queueing ingest of', $uri, 'for', $stubUuid);
		$this->db->exec('DELETE FROM {fetch_queue} WHERE "uuid" = ? AND "uri" = ?', $stubUuid, $uri);
		if(is_array($data))
		{
			$data = json_encode($data);
		}
		else
		{
			$data = null;
		}			   
		$this->db->insert('fetch_queue',
						  array(
							  'uuid' => $stubUuid,
							  'uri' => $uri,
							  '@when' => $this->db->now(),
							  'callback' => $data,
							  ));
	}

	/**
	 * Return a list of all ingest queue entries matching the specified
	 * URI.
	 *
	 * @task Ingesting resources
	 */
	public function ingestQueueEntriesForUri($uri)
	{
		return $this->db->query('SELECT * FROM {fetch_queue} WHERE "uri" = ? AND "when" <= NOW()', $uri);
	}

	/**
	 * Remove an ingest queue entry
	 *
	 * @task Ingesting reosurces
	 */
	public function removeIngestQueueEntry($uuid, $uri)
	{
		$this->db->exec('DELETE FROM {fetch_queue} WHERE "uuid" = ? AND "uri" = ?', $uuid, $uri);
	}

	/**
	 * Push back an ingest queue entry
	 *
	 * @task Ingesting reosurces
	 */
	public function delayIngestQueueEntry($uuid, $uri)
	{
		$this->db->exec('UPDATE {fetch_queue} SET "when" = ? WHERE "uuid" = ? AND "uri" = ?', strftime('%Y-%m-%d %H:%M:%S', time() + 600), $uuid, $uri);
	}

	/**
	 * Add an entity to the trove database.
	 *
	 * This method is a wrapper around $this->setData() and is invoked
	 * by provider modules, either directly or via an HTTP method.
	 *
	 * $this->setData() should only be invoked by code needing to
	 * manipulate the object store directly.
	 *
	 * @task Ingesting resources
	 */
	public function push($what, $whom = null, $lazy = TROVE_PUSH_IS_LAZY)
	{
		if(isset($what[0]))
		{
			foreach($what as $k => $obj)
			{
				if(is_object($obj))
				{
					$a = $what->asArray();
					$what[$k] = $a['value'];
				}
			}
			$object =& $what[0];
		}
		else if(is_object($what))
		{
			$what = $what->asArray();
			$what = $what['value'];
			$object =& $what;
		}
		else if(isset($what['type']) && $what['type'] == 'node')
		{
			/* Handle the result of RDFInstance::asArray() being passed to push() directly */
			$what = $what['value'];
			$object =& $what;
		}
		if(!isset($object['kind']))
		{
			$object['kind'] = 'graph';
		}
		if($object['kind'] == 'publisher')
		{
			$whom = null;
		}
		else
		{
			if($whom === null)
			{
				trigger_error('Cannot push object with no owner', E_USER_NOTICE);
				return null;
			}
			if(is_array($whom))
			{
				if(isset($whom['uuid']))
				{
					$whom = $whom['uuid'];
				}
				else
				{
					trigger_error('Cannot determine UUID of owner', E_USER_NOTICE);
					return null;
				}
			}
			if(!UUID::isUUID($whom))
			{
				trigger_error('Cannot determine UUID of owner (' . $whom . ')', E_USER_NOTICE);
				return null;
			}
		}
		if(!isset($object['uuid']))
		{
			if(isset($object[RDF::rdf.'about'][0]['type']) && isset($object[RDF::rdf.'about'][0]['value']) && !strcmp($object[RDF::rdf.'about'][0]['type'], 'uri'))
			{
				if(($obj = $this->objectForIri($object[RDF::rdf.'about'][0]['value'])))
				{
					$object['uuid'] = $obj->uuid;
				}
				else
				{
					$object['uuid'] = UUID::generate();
					$this->debug('Generated new UUID ' . $object['kind'] . '/' . $object['uuid']);
				}
			}
		}
		if($whom !== null)
		{
			$user = array('scheme' => 'object', 'uuid' => $whom);
			$object['publisher'] = $whom;
		}
		else
		{
			$user = null;
			$object['publisher'] = null;
		}
		$what = $this->setData($what, $user);
		if(isset($what[0]))
		{
			$uuid = $what[0]['uuid'];
			$kind = $what[0]['kind'];
		}
		else
		{
			$uuid = $what['uuid'];
			$kind = $what['kind'];
		}
		$this->debug('Pushed', $kind, 'object as', $uuid);
		$nonce = $this->needsMapping($uuid, $kind);
		if(!$lazy)
		{
			$this->evaluateEntity($what);
			$this->needsMapping($uuid, $kind, false, $nonce);
		}
		return $uuid;
	}

	/**
	 * Store data in the trove.
	 *
	 * For normal entity pushes, use $this->push() instead (which ensures
	 * entities are marked as requiring evaluation, as well as performing
	 * sanity checks).
	 *
	 * @task Ingesting resources
	 * @internal
	 */
	public function setData($data, $user = null, $lazy = null, $owner = null)
	{
		if($lazy === null)
		{
			$lazy = TROVE_INDEX_IS_LAZY;
		}
		if(is_object($data))
		{
			$data = get_object_vars($data);
		}
		if(isset($data[0]))
		{
			$object =& $data[0];
		}
		else
		{
			$object =& $data;
		}
		if(isset($object['uuid']) && strlen($object['uuid']) == 36)
		{
			$uuid = $object['uuid'];
		}
		else
		{
			$object['uuid'] = $uuid = UUID::generate();
		}
//		if(php_sapi_name() == 'cli')
//		{
//			echo "[Pushing $uuid (" . @$data['kind'] . ")]\n";
//		}
		if(!isset($object['tags'])) $object['tags'] = array();
		if(!isset($object['structuralRefs'])) $object['structuralRefs'] = array();
		if(!isset($object['publisher']) && $object['kind'] != 'publisher')
		{
			if(isset($object[RDF::rdf.'about']) && is_array($object[RDF::rdf.'about']))
			{
				foreach($object[RDF::rdf.'about'] as $about)
				{
					if(is_array($about) && isset($about['type']) && isset($about['value']) && $about['type'] == 'uri')
					{
						$u = new URL($about['value']);
						if($u->scheme != 'http' && $u->scheme != 'https')
						{
							continue;
						}
						$u->path = '/';
						$u->query = null;
						$u->fragment = null;
						$publisher = strval($u);
						if(($o = $this->dataForIri($publisher, null, null, true)) !== null)
						{
							$this->debug('Set publisher of', $uuid, 'to', $o['uuid'], 'via', $publisher);
							$object['publisher'] = $o['uuid'];
							break;
						}
					}
				}
			}
		}
		if(isset($object['parent']))
		{
			if(!in_array($object['parent'], $object['structuralRefs']))
			{
				$object['structuralRefs'][] = $object['parent'];
			}
		}
		foreach($object['structuralRefs'] as $ref)
		{
			if(!in_array($ref, $object['tags']))
			{
				$object['tags'][] = $ref;
			}
		}
		$tagList = $this->db->rows('SELECT "tag" FROM {local_tags} WHERE "uuid" = ?', $uuid);
		foreach($tagList as $t)
		{
			$t = trim($t['tag']);
			if(strlen($t) && !in_array($t, $object['tags']))
			{
				$object['tags'][] = $t;
			}
		}
		return parent::setData($data, $user, $lazy, $owner);
	}

	/**
	 * Callback invoked immediately after an object has been stored in the
	 * database.
	 *
	 * @task Ingesting resources
	 * @internal
	 */	
	protected function stored($data, $json = null, $lazy = false)
	{
		if(!parent::stored($data, $json, $lazy))
		{
			return false;
		}
		if(isset($data[0]))
		{
			$object =& $data[0];
		}
		else
		{
			$object =& $data;
		}
//		$this->debug(':stored(' . @$object['kind'] . '/' . $object['uuid'] . ',', ($lazy ? 'true' : 'false') . ')');
		if(!$lazy && $this->isStub($object) && ($indexer = $this->searchIndexer()) !== null)
		{
			$this->debug('Indexing',  $object['uuid']);
			if(isset($object['_index']))
			{
				$attributes = $object['_index'];
			}
			else
			{
				$attributes = array();
			}
			$attributes['uuid'] = $object['uuid'];
			$attributes['kind'] = $object['kind'];
			$attributes['visible'] = $object['visible'];
			$attributes['tags'] = $object['tags'];
			if(isset($data['parent']))
			{
				$attributes['parent'] = $object['parent'];
			}
			$attributes['created'] = $object['created'];
			$attributes['modified'] = $object['modified'];
			if(isset($attributes['_fullText']))
			{
				$fullText = $attributes['_fullText'];
				unset($attributes['_fullText']);
			}
			else
			{
				$fullText = $object['title'];
			}
			$indexer->indexDocument($object['uuid'], $fullText, $attributes);
		}
		return true;
	}

	/**
	 * Invoked by Store::setData() via Store::stored() to update indexes when
	 * an object is stored in the database.
	 *
	 * @task Ingesting resources
	 * @internal
	 */
	public /*callback*/ function storedTransaction($db, $args)
	{
		$uuid =& $args['uuid'];
		$json =& $args['json'];
		$lazy =& $args['lazy'];
		$data =& $args['data'];
		if(isset($data[0]))
		{
			$object =& $data[0];
		}
		else
		{
			$object =& $data;
		}
		$isStub = $this->isStub($object);
		if($isStub)
		{
			$this->debug('Updating browse data for ' . $uuid);
			$db->exec('DELETE FROM {object_browse} WHERE "uuid" = ?', $uuid);
			$db->exec('DELETE FROM {object_type} WHERE "uuid" = ?', $uuid);
			$db->exec('DELETE FROM {object_datetime} WHERE "uuid" = ?', $uuid);
			$db->exec('DELETE FROM {object_structrefs} WHERE "from" = ?', $uuid);
			$db->exec('DELETE FROM {object_superior} WHERE "uuid" = ?', $uuid);
			$db->exec('DELETE FROM {object_geo} WHERE "uuid" = ?', $uuid);
			$geo = array('uuid' => $uuid, 'has_coords' => 'N');
			if(isset($object[RDF::geo.'lat'][0]) && isset($object[RDF::geo.'long'][0]))
			{
				$geo['lat'] = $object[RDF::geo.'lat'][0];
				$geo['long'] = $object[RDF::geo.'long'][0];
				$geo['has_coords'] = 'Y';
			}
			$db->insert('object_geo', $geo);
			$browse = array(
				'uuid' => $uuid,
				'parent' => null,
				'norm_title' => '',
				'sort_char' => '*',
				'visible' => 'N',
				'outbound_refs' => null,
				'inbound_refs' => null,
				'total_refs' => null,
				'struct_refs' => null,
				'adjusted_refs' => null,
				'has_superior' => null,
				);
			if(!isset($object['relatedStubs']))
			{
				$object['relatedStubs'] = array();
			}
			if(!isset($object['score']))
			{
				$object['score'] = 1.0;
			}
			if(!isset($object['structuralRefs']))
			{
				$object['structuralRefs'] = array();
			}
			if(!isset($object['superior']))
			{
				$object['superior'] = array();
			}
			if(isset($object['parent']))
			{
				if(!in_array($object['parent'], $object['superior']))
				{
					$object['superior'][] = $object['parent'];
				}
			}		   
			foreach($object['superior'] as $sup)
			{
				$this->db->insert('object_superior', array('uuid' => $uuid, 'superior_uuid' => $sup));
				if(!in_array($sup, $object['structuralRefs']))
				{
					$object['structuralRefs'][] = $sup;
				}
			}
			foreach($object['structuralRefs'] as $refUuid)
			{
				$this->db->insert('object_structrefs', array('from' => $uuid, 'to' => $refUuid));
				if(!in_array($refUuid, $object['relatedStubs']))
				{
					$object['relatedStubs'][] = $refUuid;
				}
			}
			$browse['has_superior'] = count($object['superior']) ? 'Y' : 'N';
			$browse['outbound_refs'] = count($object['relatedStubs']);
			$browse['inbound_refs'] = intval($this->db->value('SELECT COUNT("uuid") FROM {object_tags} WHERE "tag" = ?', $uuid));
			$browse['struct_refs'] = count($object['structuralRefs']) + intval($this->db->value('SELECT COUNT("from") FROM {object_structrefs} WHERE "to" = ?', $uuid));
			$browse['total_refs'] = intval($browse['outbound_refs']) + $browse['inbound_refs'];
			$browse['adjusted_refs'] = floor(($browse['total_refs'] - intval($browse['struct_refs'])) * floatval($object['score']));
			if(isset($object['title']) && is_array($object['title']))
			{
				trigger_error('Title of stub ' . $uuid . ' is an array', E_USER_NOTICE);
				if(isset($object['title']['value']))
				{
					$browse['norm_title'] = $this->normaliseTitle($object['title']['value']);
					if(ctype_alpha(substr($browse['norm_title'], 0, 1)))
					{
						$browse['sort_char'] = substr($browse['norm_title'], 0, 1);
					}
				}
				else
				{
					print_r($object['title']);
				}
			}
			else if(isset($object['title']) && strlen($object['title']))
			{
				$browse['norm_title'] = $this->normaliseTitle($object['title']);
				if(ctype_alpha(substr($browse['norm_title'], 0, 1)))
				{
					$browse['sort_char'] = substr($browse['norm_title'], 0, 1);
				}
			}
			if(isset($object['parent']))
			{
				$browse['parent'] = $object['parent'];
			}
			if(isset($object['visible']))
			{
				$browse['visible'] = $object['visible'];
			}
			$db->insert('object_browse', $browse);
			if(isset($object[RDF::rdf.'type']))
			{
				foreach($object[RDF::rdf.'type'] as $class)
				{
					if(is_array($class) && isset($class['type']) && isset($class['value']) && $class['type'] == 'uri')
					{
						$db->insert('object_type',
									array(
										'uuid' => $uuid,
										'class' => $class['value'],
										'md5' => md5($class['value']),
										)
							);
					}
				}
			}
			$dt = array();
			if(isset($object['start']))
			{
				foreach($object['start'] as $k => $v)
				{
					if(strlen($v))
					{
						$dt['start_' . $k] = $v;
					}
				}
				if(!empty($object['start']['year']) &&
				   !empty($object['start']['month']) &&
				   !empty($object['start']['day']))
				{
					$dt['start_date'] = sprintf('%04d-%02d-%02d', $object['start']['year'], $object['start']['month'], $object['start']['day']);
				}
			}
			if(isset($object['end']))
			{
				foreach($object['end'] as $k => $v)
				{
					if(strlen($v))
					{
						$dt['end_' . $k] = $v;
					}
				}
				if(!empty($object['end']['year']) &&
				   !empty($object['end']['month']) &&
				   !empty($object['end']['day']))
				{
					$dt['end_date'] = sprintf('%04d-%02d-%02d', $object['end']['year'], $object['end']['month'], $object['end']['day']);
				}
			}
			if(count($dt))
			{
				$dt['uuid'] = $uuid;
				$this->db->insert('object_datetime', $dt);
			}		
		}
		else
		{
			if(TROVE_FORCE_INDEX_CLEANUP)
			{
				$db->exec('DELETE FROM {object_browse} WHERE "uuid" = ?', $uuid);
				$db->exec('DELETE FROM {object_type} WHERE "uuid" = ?', $uuid);
				$db->exec('DELETE FROM {object_datetime} WHERE "uuid" = ?', $uuid);
				$db->exec('DELETE FROM {object_structrefs} WHERE "from" = ?', $uuid);
			}
			$object['tags'] = array();
			$object['structuralRefs'] = array();
		}
		if(!isset($object['iri']))
		{
			$object['iri'] = array();
		}
		$urn = 'urn:uuid:' . $uuid;
		if(!in_array($urn, $object['iri']))
		{			
			$object['iri'][] = $urn;
		}
		if(isset($object['kind']) && $this->isStub($object['kind']))
		{
			$local = '/' . $this->plural($object['kind']) . '/' . $uuid . '#' . $object['kind'];
			if(!in_array($local, $object['iri']))
			{
				$object['iri'][] = $local;
			}
		}
		if(isset($object[RDF::rdf.'about']) && is_array($object[RDF::rdf.'about']))
		{
			foreach($object[RDF::rdf.'about'] as $about)
			{
				if(is_array($about) && isset($about['type']) && isset($about['value']) && $about['type'] == 'uri')
				{
					if(!in_array($about['value'], $object['iri']))
					{
						$object['iri'][] = $about['value'];
					}
					if(($p = strrpos($about['value'], '#')) !== false)
					{
						$p = substr($about['value'], 0, $p);
						if(!in_array($p, $object['iri']))
						{
							$object['iri'][] = $p;
						}
					}
				}
			}
		}
		if(!parent::storedTransaction($db, $args))
		{
			return false;
		}
		return true;
	}

	/**
	 * Locate, or create, an object for a user.
	 *
	 * @task Ingesting resources
	 */
	public function objectForOwner($uri, $keyFingerprint, $distinguishedName, $certData)
	{
		$value = $this->db->value('SELECT "o"."uuid" FROM {object} "o", {object_iri} "i" WHERE "o"."uuid" = "i"."uuid" AND "i"."iri" = ? AND "o"."owner" = ?', $uri, $keyFingerprint);
		if($value !== null)
		{			
			return $value;
		}
		$data = array(
			'kind' => 'user',
			'iri' => array($uri),
			'name' => $distinguishedName,
			'certData' => $certData,
			'subjectKeyIdentifier' => $keyFingerprint,
			);
		$data = $this->setData($data, null, false, $keyFingerprint);
//		print_r($data);
		return $data['uuid'];
	}

	/**
	 * Return the first stub UUID with URIs pending for ingest.
	 *
	 * @task Ingesting resources
	 */
	public function ingestQueueUuid()
	{
		if(($row = $this->db->row('SELECT "uuid" FROM {fetch_queue} WHERE "when" <= NOW()')))
		{
			return $row['uuid'];
		}
		return null;
	}

	/**
	 * Return the set of URIs pending ingest for a given stub.
	 *
	 * @task Ingesting resources
	 */
	public function pendingIngestResourcesForUuid($uuid)
	{
		$rows = $this->db->rows('SELECT * FROM {fetch_queue} WHERE "uuid" = ? AND "when" <= NOW()', $uuid);
		$resources = array();
		foreach($rows as $row)
		{
			if(strlen($row['callback']))
			{
				$cb = json_decode($row['callback'], true);
				$row = array_merge($cb, $row);
			}
			unset($row['callback']);
			$resources[$row['uri']] = $row;
		}
		return $resources;
	}
	
	/**
	 * Create a mapping from $resource to the stub $uuid according to $source.
	 * If $resource is null, mappings to the stub $uuid according to $source
	 * will be removed.
	 *
	 * $resource may be an array, in order to map multiple resources from the
	 * same source (multiple invocations of mapResource() will not have the
	 * same effect, as previous mappings from the same source are replaced
	 * each time).
	 *
	 * @task Entity mappings
	 */
	public function mapResource($uuid, $resource, $source = null, $title = null, $type = 'exactMatch', $confidence = 100, $priority = 50, $local = false)
	{
		if(!strlen($source))
		{
			$source = $this->determineSourceOfResource($resource);
		}
		if(!is_array($resource) && strlen($resource))
		{
			$resource = array($resource);
		}
		$data = array(
			'uuid' => $uuid,
			'resource' => null,
			'source' => $source,
			'title' => $title,
			'type' => $type,
			'confidence' => $confidence,
			);
		if($local)
		{
			$table = 'local_mapping';
			$data['priority'] = $priority;
		}
		else
		{
			$table = 'object_mapping';
			$data['@when'] = $this->db->now();
		}
		$this->db->exec('DELETE FROM {' . $table . '} WHERE "uuid" = ? AND "source" = ?', $uuid, $source);
		if(is_array($resource))
		{
			foreach($resource as $res)
			{
				$data['resource'] = $res;
				$this->db->insert($table, $data);
			}
		}
	}
	
	/**
	 * Remove a mapping from a stub to a resource
	 *
	 * @internal
	 * @task Entity mappings
	 */
	protected function removeMapping($stubUuid, $resource)
	{
		$this->db->exec('DELETE FROM {object_mapping} WHERE "uuid" = ? AND "resource" = ?', $stubUuid, $resource);
	}

	/**
	 * Remove all mappings between a stub and an object
	 *
	 * @internal
	 * @task Entity mappings
	 */
	protected function removeMappingsAttaching($stubUuid, $objectUuid)
	{
		$this->db->exec('DELETE FROM {object_mapping} WHERE "uuid" = ? AND "object_uuid" = ?', $stubUuid, $objectUuid);
	}
		
	/**
	 * Return all of the mappings attached to a specified UUID, arranged by
	 * match type ('exactMatch', 'noMatch', etc.).
	 *
	 * @task Entity mappings
	 */
	public function mappingsByTypeForUuid($uuid)
	{
		$list = $this->mappingsForUuid($uuid, null, true);
		$set = array();
		$highWaterMark = array();
		foreach($list as $row)
		{
			/* Only those with a priority matching the highest priority for
			 * that source are added; that is, higher priority entries
			 * prevent lower-priority entries from appearing at all.
			 */
			if(!isset($highWaterMark[$row['source']]))
			{
				$highWaterMark[$row['source']] = $row['priority'];
				$set[$row['type']][] = $row;
			}
			else if($row['priority'] == $highWaterMark[$row['source']])
			{
				$set[$row['type']][] = $row;
			}
		}
		return $set;
	}

	/**
	 * Return all of the mappings attached to a UUID, ordered by
	 * priority (low first), then confidence (high first). $reverse can
	 * be specified as true to reverse the sort order.
	 *
	 * If $type is specified, only those mappings of match type $type will
	 * be included in the resultset.
	 *
	 * @task Entity mappings
	 */
	public function mappingsForUuid($uuid, $type = null, $reverse = false)
	{
		$set = array();
		$list = array();
		if($type === null)
		{
			$rows = $this->db->rows('SELECT * FROM {object_mapping} WHERE "uuid" = ?', $uuid);
		}
		else
		{
			$rows = $this->db->rows('SELECT * FROM {object_mapping} WHERE "uuid" = ? AND "type" = ?', $uuid, $type);
		}
		$c = 100;
		foreach($rows as $r)
		{
			/* Assign automatic mappings a notional priority of 50 */
			$r['priority'] = 50;
			$r['local'] = false;
			if(!strlen($r['resource_uuid']))
			{				
				$r['resource_uuid'] = $this->uuidForIri($r['resource']);
			}
			$k = sprintf('%04d-%03d-%04d', 50, 100 - $r['confidence'], $c);
			$c++;
			$list[$k] = $r;
		}
		if($type === null)
		{
			$rows = $this->db->rows('SELECT * FROM {local_mapping} WHERE "uuid" = ?', $uuid);
		}
		else
		{
			$rows = $this->db->rows('SELECT * FROM {local_mapping} WHERE "uuid" = ? AND "type" = ?', $uuid, $type);
		}
		$c = 0;
		foreach($rows as $r)
		{
			$r['local'] = true;
			if(!strlen($r['resource_uuid']))
			{
				$r['resource_uuid'] = $this->uuidForIri($r['resource']);
			}
			$k = sprintf('%04d-%03d-%04d', $r['priority'], 100 - $r['confidence'],  $c);
			$list[$k] = $r;
			$c++;
		}
		if($reverse)
		{
			krsort($list);
		}
		else
		{
			ksort($list);
		}
		return $list;		
	}

	/**
	 * Return the UUID of the stub mapped to the specified resource
	 *
	 * @task Entity mappings
	 */
	public function stubForResource($uri, $recurse = true)
	{
		$uuid = $this->db->value('SELECT "uuid" FROM {local_mapping} WHERE "resource" = ? AND "type" = ?', $uri, 'exactMatch');
		if(!strlen($uuid))
		{
			$uuid = $this->db->value('SELECT "uuid" FROM {object_mapping} WHERE "resource" = ? AND "type" = ?', $uri, 'exactMatch');
		}
		if(!strlen($uuid) && $recurse)
		{
			$resourceUuid = $this->db->value('SELECT "uuid" FROM {object_iri} WHERE "iri" = ?', $uri);
			if(strlen($resourceUuid))
			{
				$uuid = $this->stubForUuid($resourceUuid);
			}
		}
		return $uuid;
	}

	/**
	 * Return the UUID of the stub mapped to the specified UUID
	 *
	 * @task Entity mappings
	 */
	public function stubForUuid($uuid, $recurse = true)
	{
		$stub = $this->db->value('SELECT "uuid" FROM {local_mapping} WHERE "object_uuid" = ? AND "type" = ?', $uuid, 'exactMatch');
		if(strlen($stub))
		{
			return $stub;
		}
		$stub = $this->db->value('SELECT "uuid" FROM {object_mapping} WHERE "object_uuid" = ? AND "type" = ?', $uuid, 'exactMatch');
		if(strlen($stub))
		{
			return $stub;
		}
		$stub = $this->stubForResource('urn:uuid:' . $uuid, false);
		if(strlen($stub))
		{
			return $stub;
		}
		if($recurse)
		{
			$iriList = $this->db->rows('SELECT * FROM {object_iri} WHERE "uuid" = ?', $uuid);
			foreach($iriList as $row)
			{
				$uuid = $this->stubForResource($row['iri'], false);
				if(strlen($uuid))
				{
					return $uuid;
				}
			}
		}
		return null;
	}

	/**
	 * Apply a set of mappings (contained within a TroveMap instance).
	 *
	 * @internal
	 * @task Entity mappings
	 */
	public function applyMappings($stubUuid, $map, $previousStub, $relatedObjectUuid)
	{
		if($previousStub !== null && $relatedObjectUuid !== null)
		{
			$this->db->exec('DELETE FROM {object_mapping} WHERE "uuid" = ? AND "object_uuid" = ?', $previousStub, $relatedObjectUuid);
			$this->db->exec('DELETE FROM {local_mapping} WHERE "uuid" = ? AND "object_uuid" = ?', $previousStub, $relatedObjectUuid);
		}
		else if($relatedObjectUuid !== null)
		{
			$this->db->exec('DELETE FROM {object_mapping} WHERE "object_uuid" = ?', $relatedObjectUuid);
			$this->db->exec('DELETE FROM {local_mapping} WHERE "object_uuid" = ?', $relatedObjectUuid);
		}
		foreach($map as $k => $mapping)
		{
//			print_r($mapping);
			if(empty($mapping['local']))
			{
				unset($mapping['priority']);
			}
			$table = empty($mapping['local']) ? 'object_mapping' : 'local_mapping';
			if($mapping['object_uuid'] !== $relatedObjectUuid || ($previousStub !== null && $mapping['uuid'] !== $previousStub))
			{
				if($mapping['uuid'] !== null)
				{
					$this->db->exec('DELETE FROM {' . $table . '} WHERE "uuid" = ? AND "object_uuid" = ?', $mapping['uuid'], $mapping['object_uuid']);
				}
				else
				{
					$this->db->exec('DELETE FROM {' . $table . '} WHERE "object_uuid" = ?', $mapping['object_uuid']);
				}
			}
			if($previousStub !== null && $previousStub != $stubUuid)
			{
				$this->db->exec('DELETE FROM {' . $table . '} WHERE "uuid" = ? AND "source" = ? AND "object_uuid" IS NULL', $previousStub, $mapping['source']);
			}
			$this->db->exec('DELETE FROM {' . $table . '} WHERE "uuid" = ? AND "source" = ? AND "object_uuid" IS NULL', $stubUuid, $mapping['source']);
			$mapping['uuid'] = $stubUuid;
			$mapping['@when'] = $this->db->now();
			$map[$k] = $mapping;
		}
		foreach($map as $mapping)
		{
			$table = empty($mapping['local']) ? 'object_mapping' : 'local_mapping';			
			unset($mapping['local']);
			$this->db->insert($table, $mapping);
		}
	}

	/**
	 * Find all exactly-matching stubs for a given resource and optional
	 * object UUID.
	 *
	 * @task Entity mapping
	 * @internal
	 */
	public function allStubsForResource($uri, $uuid = null)
	{
		$args = array('exactMatch', $uri);
		$where = '"m"."type" = ? AND ("m"."resource" = ?';
		if($uuid !== null)
		{
			$args[] = $uuid;
			$where .= ' OR "m"."resource_uuid" = ?';
		}
		$where .= ')';
		$list = array();
		$rows = $this->db->rowsArray('SELECT "m"."uuid", "o"."created"  FROM {local_mapping} "m" LEFT JOIN {object} "o" ON "o"."uuid" = "m"."uuid" WHERE ' . $where, $args);
		foreach($rows as $r)
		{
			$list[] = $r;
		}
		$rows = $this->db->rowsArray('SELECT "m"."uuid", "o"."created"  FROM {object_mapping} "m" LEFT JOIN {object} "o" ON "o"."uuid" = "m"."uuid" WHERE ' . $where, $args);
		foreach($rows as $r)
		{
			$list[] = $r;
		}
		return $list;
	}

	/**
	 * Construct a database query.
	 *
	 * @task Queries
	 * @internal
	 */
	protected function buildQuery(&$qlist, &$tables, &$query)
	{
		if(!isset($tables['browse'])) $tables['browse'] = 'object_browse';
		if(!isset($tables['type'])) $tables['type'] = 'object_type';
		if(!isset($tables['dt'])) $tables['dt'] = 'object_datetime';
		if(!isset($tables['superior'])) $tables['superior'] = 'object_superior';
		if(!isset($tables['geo'])) $tables['geo'] = 'object_geo';
		foreach($query as $k => $v)
		{
			$value = $v;
			switch($k)
			{
			case 'sort_char':
				unset($query[$k]);
				$qlist['browse'][] = '"browse"."sort_char" = ' . $this->db->quote($value);
				break;
			case 'norm_title':
				unset($query[$k]);
				$qlist['browse'][] = '"browse"."norm_title" = ' . $this->db->quote($value);
				break;
			case 'norm_title%':
				unset($query[$k]);
				$qlist['browse'][] = '"browse"."norm_title" LIKE ' . $this->db->quote($value);
				break;
			case 'parent':
				unset($query[$k]);
				$qlist['browse'][] = '"browse"."parent" = ' . $this->db->quote($value);
				break;
			case 'parent?':
				unset($query[$k]);
				$qlist['browse'][] = '"browse"."parent" ' . ($value ? ' IS NOT NULL ' : ' IS NULL');
				break;
			case 'class':
				unset($query[$k]);
				$qlist['type'][] = '"type"."md5" = ' . $this->db->quote(md5($value));
				break;
			case 'superior?':
				unset($query[$k]);
				$qlist['browse'][] = '"browse"."has_superior" = ' . $this->db->quote($value ? 'Y' : 'N');
				break;
			case 'superior':
				unset($query[$k]);
				$qlist['superior'][] = '"superior"."superior_uuid" = ' . $this->db->quote($value);
				break;
			case 'coords?':
				unset($query[$k]);
				$qlist['geo'][] = '"geo"."has_coords" = ' . $this->db->quote($value ? 'Y' : 'N');
				break;
			}
		}
		return parent::buildQuery($qlist, $tables, $query);
	}

	/**
	 * Parse the various supported ordering terms.
	 *
	 * @internal
	 * @task Queries
	 */
	protected function parseOrder(&$order, $key, $desc = true)
	{
		$dir = $desc ? 'DESC' : 'ASC';
		$rdir = $desc ? 'ASC' : 'DESC';
		switch($key)
		{
		case 'sort_char':
			$order['browse'][] = '"browse"."sort_char" ' . $rdir;
			return true;		
		case 'norm_title':
			$order['browse'][] = '"browse"."norm_title" ' . $rdir;
			return true;
		case 'outbound_refs':
			$order['browse'][] = '"browse"."outbound_refs" ' . $dir;
			return true;
		case 'inbound_refs':
			$order['browse'][] = '"browse"."inbound_refs" ' . $dir;
			return true;
		case 'total_refs':
			$order['browse'][] = '"browse"."total_refs" ' . $dir;
			return true;
		case 'struct_refs':
			$order['browse'][] = '"browse"."struct_refs" ' . $dir;
			return true;
		case 'adjusted_refs':
			$order['browse'][] = '"browse"."adjusted_refs" ' . $dir;
			return true;
		case 'random':
			$order['obj'][] = 'RAND()';				
			return true;
		case 'modified':
			$order['obj'][] = '"obj"."modified" ' . $dir;
			return true;
		case 'created':
			$order['obj'][] = '"obj"."created" ' . $dir;
			return true;
		case 'start_year':
			$order['dt'][] = '"dt"."start_year" ' . $rdir;
			return true;
		case 'start_month':
			$order['dt'][] = '"dt"."start_month" ' . $rdir;
			return true;
		case 'start_day':
			$order['dt'][] = '"dt"."start_day" ' . $rdir;
			return true;
		}
		return false;
	}

}
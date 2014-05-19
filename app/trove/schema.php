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

/**
 * @ignore
 */
if(!defined('TROVE_IRI')) define('TROVE_IRI', null);

/**
 * @ignore
 */
class TroveModule extends Module
{
	public $moduleId = 'com.projectreith.trove';
	public $latestVersion = 38;

	public static function getInstance($args = null)
	{
		if(!isset($args['db'])) $args['db'] = TROVE_IRI;
		if(!isset($args['class'])) $args['class'] = 'TroveModule';
		return parent::getInstance($args);
	}

	public function dependencies()
	{
		$this->depend('com.nexgenta.eregansu.store');
	}
	
	public function updateSchema($targetVersion)
	{
		if($targetVersion == 1)
		{
			$t = $this->tableWithOptions('dirty', DBTable::CREATE_ALWAYS);
			$t->columnWithSpec('uuid', DBType::UUID, null, DBCol::NOT_NULL, null, 'Unique object identifier');
			$t->columnWithSpec('dirty', DBType::BOOLEAN, null, DBCol::NOT_NULL, null, 'Whether the object is dirty or not');
			$t->columnWithSpec('nonce', DBType::VARCHAR, 16, DBCol::NOT_NULL, null, 'A random key for this occurrence of dirtying');
			$t->indexWithSpec(null, DBIndex::PRIMARY, 'uuid');
			$t->indexWithSpec('dirty', DBIndex::INDEX, 'dirty');
			return $t->apply();
		}
		if($targetVersion == 2)
		{
			return true;
		}
		if($targetVersion == 3)
		{
			$t = $this->table('dirty');
			$t->columnWithSpec('kind', DBType::VARCHAR, 64, DBCol::NOT_NULL, null, 'The kind of object dirtied');
			$t->indexWithSpec('kind', DBIndex::INDEX, 'kind');
			return $t->apply();
		}
		if($targetVersion == 4)
		{
			$t = $this->tableWithOptions('local_tags', DBTable::CREATE_ALWAYS);
			$t->columnWithSpec('uuid', DBType::UUID, null, DBCol::NOT_NULL, null, 'Object UUID');
			$t->columnWithSpec('tag', DBType::VARCHAR, 64, DBCol::NOT_NULL, null, 'Tag to apply to this object');
			$t->indexWithSpec(null, DBIndex::PRIMARY, 'uuid', 'tag');
			$t->indexWithSpec('uuid', DBIndex::INDEX, 'uuid');
			return $t->apply();
		}
		if($targetVersion == 5)
		{
			$t = $this->tableWithOptions('object_mapping', DBTable::CREATE_ALWAYS);
			$t->columnWithSpec('uuid', DBType::UUID, null, DBCol::NOT_NULL, null, 'Object UUID');
			$t->columnWithSpec('source', DBType::VARCHAR, 64, DBCol::NOT_NULL, null, 'Mapping source (URI base)');
			$t->columnWithSpec('type', DBType::ENUM, array('sameAs', 'closeMatch', 'narrowMatch', 'broadMatch', 'exactMatch', 'relatedMatch'), DBCol::NOT_NULL, null, 'Match type');
			$t->columnWithSpec('resource', DBType::VARCHAR, 255, DBCol::NOT_NULL, null, 'Matching URI');
			$t->indexWithSpec('uuid', DBIndex::INDEX, 'uuid');
			$t->indexWithSpec('source', DBIndex::INDEX, 'source');
			$t->indexWithSpec('type', DBIndex::INDEX, 'type');
			return $t->apply();
		}
		if($targetVersion == 6)
		{
			$t = $this->table('object_mapping');
			$t->columnWithSpec('confidence', DBType::INT, null, DBCol::NOT_NULL, null, 'Confidence factor (0 >= confidence >= 100)');
			return $t->apply();
		}
		if($targetVersion == 7)
		{
			$t = $this->table('object_mapping');
			$t->columnWithSpec('resource', DBType::VARCHAR, 255, DBCol::NULLS, null, 'Matching URI');
			return $t->apply();
		}
		if($targetVersion == 8)
		{
			$t = $this->table('object_mapping');
			$t->columnWithSpec('title', DBType::VARCHAR, 255, DBCol::NULLS, null, 'Target resource title');
			return $t->apply();
		}
		if($targetVersion == 9)
		{
			$t = $this->table('object_mapping');
			$t->columnWithSpec('type', DBType::ENUM, array('sameAs', 'closeMatch', 'narrowMatch', 'broadMatch', 'exactMatch', 'relatedMatch', 'noMatch'), DBCol::NOT_NULL, null, 'Match type');
			return $t->apply();
		}
		if($targetVersion == 10)
		{
			$t = $this->tableWithOptions('local_mapping', DBTable::CREATE_ALWAYS);
			$t->columnWithSpec('uuid', DBType::UUID, null, DBCol::NOT_NULL, null, 'Object UUID');
			$t->columnWithSpec('source', DBType::VARCHAR, 64, DBCol::NOT_NULL, null, 'Mapping source (URI base)');
			$t->columnWithSpec('type', DBType::ENUM, array('sameAs', 'closeMatch', 'narrowMatch', 'broadMatch', 'exactMatch', 'relatedMatch', 'noMatch'), DBCol::NOT_NULL, null, 'Match type');
			$t->columnWithSpec('resource', DBType::VARCHAR, 255, DBCol::NOT_NULL, null, 'Matching URI');
			$t->columnWithSpec('confidence', DBType::INT, null, DBCol::NOT_NULL, null, 'Confidence factor (0 >= confidence >= 100)');
			$t->columnWithSpec('title', DBType::VARCHAR, 255, DBCol::NULLS, null, 'Target resource title');
			$t->indexWithSpec('uuid', DBIndex::INDEX, 'uuid');
			$t->indexWithSpec('source', DBIndex::INDEX, 'source');
			$t->indexWithSpec('type', DBIndex::INDEX, 'type');
			return $t->apply();
		}
		if($targetVersion == 11)
		{
			$t = $this->table('local_mapping');
			$t->columnWithSpec('priority', DBType::INT, null, DBCol::NULLS, null, 'Priority, where 0 = highest');
			$t->indexWithSpec('priority', DBIndex::INDEX, 'priority');
			return $t->apply();
		}
		if($targetVersion == 12)
		{
			$t = $this->tableWithOptions('object_browse', DBTable::CREATE_ALWAYS);
			$t->columnWithSpec('uuid', DBType::UUID, null, DBCol::NOT_NULL, null, 'Object UUID');
			$t->columnWithSpec('parent', DBType::UUID, null, DBCol::NULLS, null, 'Parent object UUID');
			$t->columnWithSpec('norm_title', DBType::VARCHAR, 255, DBCol::NULLS, null, 'Normalised title');
			$t->columnWithSpec('sort_char', DBType::CHAR, 1, DBCol::NULLS, null, 'Sort character (* for non-alphanumeric');
			$t->columnWithSpec('visible', DBType::BOOLEAN, null, DBCol::NOT_NULL, 'N', 'Whether the object is visible when browsing');
			$t->indexWithSpec(null, DBIndex::PRIMARY, 'uuid');
			$t->indexWithSpec('parent', DBIndex::INDEX, 'parent');
			$t->indexWithSpec('norm_title', DBIndex::INDEX, 'norm_title');
			$t->indexWithSpec('sort_char', DBIndex::INDEX, 'sort_char');
			$t->indexWithSpec('visible', DBIndex::INDEX, 'visible');
			return $t->apply();
		}
		if($targetVersion == 13)
		{
			$t = $this->tableWithOptions('object_mapstatus', DBTable::CREATE_ALWAYS);
			$t->columnWithSpec('uuid', DBType::UUID, null, DBCol::NOT_NULL, null, 'Object UUID');
			$t->columnWithSpec('dirty', DBType::BOOLEAN, null, DBCol::NOT_NULL, null, 'Object mapping status');
			$t->columnWithSpec('nonce', DBType::VARCHAR, 16, DBCol::NOT_NULL, null, 'Contention nonce');
			$t->columnWithSpec('kind', DBType::VARCHAR, 64, DBCol::NOT_NULL, null, 'Object kind');
			$t->indexWithSpec(null, DBIndex::PRIMARY, 'uuid');
			$t->indexWithSpec('dirty', DBIndex::INDEX, 'dirty');
			$t->indexWithSpec('kind', DBIndex::INDEX, 'kind');
			return $t->apply();
		}
		if($targetVersion == 14)
		{
			return true;
		}
		if($targetVersion == 15)
		{
			$t = $this->table('object_browse');
			$t->columnWithSpec('outbound_refs', DBType::INT, null, DBCol::NULLS, null, 'Number of outbound references from this stub to others');
			return $t->apply();
		}
		if($targetVersion == 16)
		{
			$t = $this->table('object_browse');
			$t->columnWithSpec('inbound_refs', DBType::INT, null, DBCol::NULLS, null, 'Number of inbound references from this stub to others');
			$t->columnWithSpec('total_refs', DBType::INT, null, DBCol::NULLS, null, 'Number of total references from this stub to others');			
			return $t->apply();
		}
		if($targetVersion == 17)
		{
			$t = $this->table('object_browse');
			$t->indexWithSpec('outbound_refs', DBIndex::INDEX, 'outbound_refs');
			$t->indexWithSpec('inbound_refs', DBIndex::INDEX, 'inbound_refs');
			$t->indexWithSpec('total_refs', DBIndex::INDEX, 'total_refs');
			return $t->apply();
		}
		if($targetVersion == 18)
		{
			return $this->dropTable('dirty');
		}
		if($targetVersion == 19)
		{
			$t = $this->tableWithOptions('object_datetime', DBTable::CREATE_ALWAYS);
			$t->columnWithSpec('uuid', DBType::UUID, null, DBCol::NOT_NULL, null, 'Object UUID');
			$t->columnWithSpec('start_date', DBType::DATE, null, DBCol::NULLS, null, 'Start date of this event');
			$t->columnWithSpec('start_time', DBType::DATETIME, null, DBCol::NULLS, null, 'Start time of this event');
			$t->columnWithSpec('start_year', DBType::INT, null, DBCol::NULLS, null, 'Start year of this event');
			$t->columnWithSpec('start_month', DBType::INT, null, DBCol::NULLS|DBCol::UNSIGNED, null, 'Start month of this event (1-12)');
			$t->columnWithSpec('start_day', DBType::INT, null, DBCol::NULLS|DBCol::UNSIGNED, null, 'Start day of this event (1-31)');
			$t->columnWithSpec('start_hour', DBType::INT, null, DBCol::NULLS|DBCol::UNSIGNED, null, 'Start hour of this event (0-23)');
			$t->columnWithSpec('start_min', DBType::INT, null, DBCol::NULLS|DBCol::UNSIGNED, null, 'Start minute of this event (0-59)');
			$t->columnWithSpec('start_sec', DBType::INT, null, DBCol::NULLS|DBCol::UNSIGNED, null, 'Start second of this event (0-59)');
			$t->columnWithSpec('end_date', DBType::DATE, null, DBCol::NULLS, null, 'End date of this event');
			$t->columnWithSpec('end_time', DBType::DATETIME, null, DBCol::NULLS, null, 'End time of this event');
			$t->columnWithSpec('end_year', DBType::INT, null, DBCol::NULLS, null, 'End year of this event');
			$t->columnWithSpec('end_month', DBType::INT, null, DBCol::NULLS|DBCol::UNSIGNED, null, 'End month of this event (1-12)');
			$t->columnWithSpec('end_day', DBType::INT, null, DBCol::NULLS|DBCol::UNSIGNED, null, 'End day of this event (1-31)');
			$t->columnWithSpec('end_hour', DBType::INT, null, DBCol::NULLS|DBCol::UNSIGNED, null, 'End hour of this event (0-23)');
			$t->columnWithSpec('end_min', DBType::INT, null, DBCol::NULLS|DBCol::UNSIGNED, null, 'End minute of this event (0-59)');
			$t->columnWithSpec('end_sec', DBType::INT, null, DBCol::NULLS|DBCol::UNSIGNED, null, 'End second of this event (0-59)');
			$t->indexWithSpec(null, DBIndex::PRIMARY, 'uuid');
			$indexes = array('date', 'time', 'year', 'month', 'day', 'hour', 'min', 'sec');
			foreach($indexes as $i)
			{
				$t->indexWithSpec('start_' . $i, DBIndex::INDEX, 'start_' . $i);
				$t->indexWithSpec('end_' . $i, DBIndex::INDEX, 'end_' . $i);
			}
			return $t->apply();
		}
		if($targetVersion == 20)
		{
			$t = $this->table('object_mapping');
			$t->columnWithSpec('when', DBType::DATETIME, null, DBCol::NOT_NULL, null, 'Timestamp of this mapping being added');
			return $t->apply();
		}
		if($targetVersion == 21)
		{
			$this->db->exec('UPDATE {object_mapping} SET "when" = ' . $this->db->now());
			return true;
		}
		if($targetVersion == 22)
		{
			$t = $this->tableWithOptions('object_type', DBTable::CREATE_ALWAYS);
			$t->columnWithSpec('uuid', DBType::UUID, null, DBCol::NOT_NULL, null, 'Object identifier');
			$t->columnWithSpec('class', DBType::VARCHAR, 255, DBCol::NOT_NULL, null, 'URI of the class');
			$t->columnWithSpec('md5', DBType::VARCHAR, 32, DBCol::NOT_NULL, null, 'MD5 hash of the class URI');
			$t->indexWithSpec('uuid', DBIndex::INDEX, 'uuid');
			$t->indexWithSpec('md5', DBIndex::INDEX, 'md5');
			return $t->apply();
		}
		if($targetVersion == 23)
		{
			$t = $this->tableWithOptions('object_structrefs', DBTable::CREATE_ALWAYS);
			$t->columnWithSpec('from', DBType::UUID, null, DBCol::NOT_NULL, null, 'Source of the structural reference');
			$t->columnWithSpec('to', DBType::UUID, null, DBCol::NOT_NULL, null, 'Target of the structural reference');
			$t->indexWithSpec('from', DBIndex::INDEX, 'from');
			$t->indexWithSpec('to', DBIndex::INDEX, 'to');
			return $t->apply();
		}
		if($targetVersion == 24)
		{
			$t = $this->table('object_browse');
			$t->columnWithSpec('struct_refs', DBType::INT, null, DBCol::NULLS, null, 'Number of structural references from other stubs to this one');
			$t->columnWithSpec('adjusted_refs', DBType::INT, null, DBCol::NULLS, null, 'adjusted_refs = total_refs - struct_refs');
			$t->indexWithSpec('struct_refs', DBIndex::INDEX, 'struct_refs');
			$t->indexWithSpec('adjusted_refs', DBIndex::INDEX, 'adjusted_refs');
			return $t->apply();
		}
		if($targetVersion == 25)
		{
			$t = $this->tableWithOptions('fetch_queue', DBTable::CREATE_ALWAYS);
			$t->columnWithSpec('uuid', DBType::UUID, null, DBCol::NOT_NULL, null, 'Stub UUID');
			$t->columnWithSpec('uri', DBType::VARCHAR, 255, DBCol::NOT_NULL, null, 'URI to fetch');
			$t->columnWithSpec('when', DBType::DATETIME, null, DBCol::NOT_NULL, null, 'When the entry was added');
			$t->columnWithSpec('callback', DBType::TEXT, null, DBCol::NULLS, null, 'Fetch callback data');
			$t->indexWithSpec('uuid', DBIndex::INDEX, 'uuid');
			return $t->apply();
		}
		if($targetVersion == 26)
		{
			$t = $this->table('object_mapping');
			$t->indexWithSpec('resource', DBIndex::INDEX, 'resource');
			return $t->apply();
		}
		if($targetVersion == 27)
		{
			$t = $this->table('object_mapping');
			$t->indexWithSpec('resource', DBIndex::INDEX, 'resource');
			return $t->apply();
		}		
		if($targetVersion == 28)
		{
			$t = $this->table('fetch_queue');
			$t->indexWithSpec('uri', DBIndex::INDEX, 'uri');
			return $t->apply();
		}
		if($targetVersion == 29)
		{
			$t = $this->table('local_mapping');
			$t->columnWithSpec('type', DBType::ENUM, array('sameAs', 'closeMatch', 'narrowMatch', 'broadMatch', 'exactMatch', 'relatedMatch', 'noMatch', 'tombstone'), DBCol::NOT_NULL, null, 'Match type');
			return $t->apply();
		}
		if($targetVersion == 30)
		{
			$t = $this->table('object_mapping');
			$t->columnWithSpec('type', DBType::ENUM, array('sameAs', 'closeMatch', 'narrowMatch', 'broadMatch', 'exactMatch', 'relatedMatch', 'noMatch', 'tombstone'), DBCol::NOT_NULL, null, 'Match type');
			return $t->apply();
		}
		if($targetVersion == 31)
		{
			$t = $this->tableWithOptions('local_depiction', DBTable::CREATE_ALWAYS);
			$t->columnWithSpec('uuid', DBType::UUID, null, DBCol::NOT_NULL, null, 'Stub UUID');
			$t->columnWithSpec('sequence', DBType::INT, null, DBCol::NOT_NULL, null, 'Sequence (0 = first, 999... = last)');
			$t->columnWithSpec('resource', DBType::TEXT, null, DBCol::NULLS, null, 'Resource URI');
			$t->indexWithSpec('uuid', DBIndex::INDEX, 'uuid');
			$t->indexWithSpec('sequence', DBIndex::INDEX, 'sequence');
			return $t->apply();
		}
		if($targetVersion == 32)
		{
			$t = $this->tableWithOptions('object_default_stub', DBTable::CREATE_ALWAYS);
			$t->columnWithSpec('uuid', DBType::UUID, null, DBCol::NOT_NULL, null, 'Resource UUID');
			$t->columnWithSpec('stub', DBType::UUID, null, DBCol::NOT_NULL, null, 'Stub UUID');
			$t->indexWithSpec('uuid', DBIndex::INDEX, 'uuid');
			$t->indexWithSpec('stub', DBIndex::INDEX, 'stub');
			return $t->apply();
		}
		if($targetVersion == 33)
		{
			$t = $this->table('object_mapping');
			$t->columnWithSpec('resource_uuid', DBType::UUID, null, DBCol::NULLS, null, 'UUID of the object containing the resource');
			$t->columnWithSpec('object_uuid', DBType::UUID, null, DBCol::NULLS, null, 'UUID of the object which was matched to this resource');
			$t->indexWithSpec('resource_uuid', DBIndex::INDEX, 'resource_uuid');
			$t->indexWithSpec('object_uuid', DBIndex::INDEX, 'object_uuid');
			return $t->apply();
		}
		if($targetVersion == 34)
		{
			$t = $this->table('local_mapping');
			$t->columnWithSpec('resource_uuid', DBType::UUID, null, DBCol::NULLS, null, 'UUID of the object containing the resource');
			$t->columnWithSpec('object_uuid', DBType::UUID, null, DBCol::NULLS, null, 'UUID of the object which was matched to this resource');
			$t->indexWithSpec('resource_uuid', DBIndex::INDEX, 'resource_uuid');
			$t->indexWithSpec('object_uuid', DBIndex::INDEX, 'object_uuid');
			return $t->apply();
		}
		if($targetVersion == 35)
		{
			$t = $this->table('object_browse');
			$t->columnWithSpec('has_superior', DBType::BOOLEAN, null, DBCol::NOT_NULL, 'N', 'If "Y", this object has superior references');
			$t->indexWithSpec('has_superior', DBIndex::INDEX, 'has_superior');
			return $t->apply();
		}
		if($targetVersion == 36)
		{
			$t = $this->tableWithOptions('object_superior', DBTable::CREATE_ALWAYS);
			$t->columnWithSpec('uuid', DBType::UUID, null, DBCol::NOT_NULL, null, 'The UUID of the subsidiary object');
			$t->columnWithSpec('superior_uuid', DBType::UUID, null, DBCol::NOT_NULL, null, 'The UUID of the superior object');
			$t->indexWithSpec('uuid', DBIndex::INDEX, 'uuid');
			$t->indexWithSpec('superior_uuid', DBIndex::INDEX, 'superior_uuid');
			return $t->apply();
		}
		if($targetVersion == 37)
		{
			$rs = $this->db->query('SELECT "uuid", "parent" AS "superior_uuid" FROM {object_browse} WHERE "parent" IS NOT NULL');
			foreach($rs as $row)
			{
				$this->db->insert('object_superior', $row);
			}
			return true;
		}
		if($targetVersion == 38)
		{
			$t = $this->tableWithOptions('object_geo', DBTable::CREATE_ALWAYS);
			$t->columnWithSpec('uuid', DBType::UUID, null, DBCol::NOT_NULL, null, 'The UUID of the object');
			$t->columnWithSpec('has_coords', DBType::BOOLEAN, null, DBCol::NOT_NULL, null, 'If "Y", we have lat and long');
			$t->columnWithSpec('lat', DBType::DECIMAL, array(8, 3), DBCol::NULLS, null, 'Latitude');
			$t->columnWithSpec('long', DBType::DECIMAL, array(8, 3), DBCol::NULLS, null, 'Longitude');
			$t->indexWithSpec(null, DBIndex::PRIMARY, 'uuid');
			$t->indexWithSpec('has_coords', DBIndex::INDEX, 'has_coords');
			return $t->apply();
		}
	}
}

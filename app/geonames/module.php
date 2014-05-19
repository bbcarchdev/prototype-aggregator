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

/* Database schema module for geonames */

if(!defined('GEONAMES_IRI')) define('GEONAMES_IRI', null);

class GeonamesModule extends Module
{
	public $moduleId = 'org.geonames';
	public $latestVersion = 2;

	public static function getInstance($args = null)
	{
		if(!isset($args['db'])) $args['db'] = GEONAMES_IRI;
		if(!isset($args['class'])) $args['class'] = 'GeonamesModule';
		return parent::getInstance($args);
	}
	
	public function dependencies()
	{
		global $SETUP_MODULES;

		/* This is *the* most horrendous hack, sorry */
		if(in_array('provider', $SETUP_MODULES))
		{
			/* Only depend on the provider module if it's present, perversely */
			$this->depend('com.projectreith.provider');
		}
	}

	public function updateSchema($targetVersion)
	{
		if($targetVersion == 1)
		{
			$t = $this->tableWithOptions('geonames', DBTable::CREATE_ALWAYS);
			$t->columnWithSpec('geonameid', DBType::INT, null, DBCol::UNSIGNED|DBCol::NOT_NULL|DBCol::BIG, null, 'integer id of record in geonames database');
			$t->columnWithSpec('name', DBType::VARCHAR, 200, DBCol::NOT_NULL, null, 'name of geographical point');
			$t->columnWithSpec('asciiname', DBType::VARCHAR, 200, DBCol::NULLS, null, 'name of geographical point in plain ascii characters');
			$t->columnWithSpec('alternatenames', DBType::TEXT, null, DBCol::NULLS, null, 'alternatenames, comma separated');
			$t->columnWithSpec('latitude', DBType::DECIMAL, array(8,5), DBCol::NULLS, null, 'latitude in decimal degrees (wgs84)');
			$t->columnWithSpec('longitude', DBType::DECIMAL, array(9,5), DBCol::NULLS, null, 'longitude in decimal degrees (wgs84)');
			$t->columnWithSpec('feature_class', DBType::CHAR, 1, DBCol::NULLS, null, 'see http://www.geonames.org/export/codes.html');
			$t->columnWithSpec('feature_code', DBType::VARCHAR, 10, DBCol::NULLS, null, 'see http://www.geonames.org/export/codes.html');
			$t->columnWithSpec('country_code', DBType::CHAR, 2, DBCol::NULLS, null, 'ISO-3166 2-letter country code');
			$t->columnWithSpec('cc2', DBType::VARCHAR, 60, DBCol::NULLS, null, 'alternate country codes, comma separated, ISO-3166 2-letter country code');
			$t->columnWithSpec('admin1_code', DBType::VARCHAR, 20, DBCol::NULLS, null, 'fipscode (subject to change to iso code)');
			$t->columnWithSpec('admin2_code', DBType::VARCHAR, 80, DBCol::NULLS, null, 'code for the second administrative division, a county in the US');
			$t->columnWithSpec('admin3_code', DBType::VARCHAR, 20, DBCol::NULLS, null, 'code for third level administrative division');
			$t->columnWithSpec('admin4_code', DBType::VARCHAR, 20, DBCol::NULLS, null, 'code for fourth level administrative division');
			$t->columnWithSpec('population', DBType::INT, null, DBCol::NULLS|DBCol::UNSIGNED|DBCol::BIG, null);
			$t->columnWithSpec('elevation', DBType::INT, null, DBCol::NULLS, null, 'in metres');
			$t->columnWithSpec('gtopo30', DBType::INT, null, DBCol::NULLS, null, "average elevation of 30'x30' (ca 900mx900m) area in meters");
			$t->columnWithSpec('timezone', DBType::VARCHAR, 32, DBCol::NULLS, null, 'the timezone id');
			$t->columnWithSpec('modification_date', DBType::DATE, null, DBCol::NOT_NULL, null, 'date of last modification');			
			$t->indexWithSpec(null, DBIndex::PRIMARY, 'geonameid');
			$t->indexWithSpec('name', DBIndex::INDEX, 'name');
			$t->indexWithSpec('feature_class', DBIndex::INDEX, 'feature_class');
			$t->indexWithSpec('feature_code', DBIndex::INDEX, 'feature_code');
			$t->indexWithSpec('country_code', DBIndex::INDEX, 'country_code');
			$t->indexWithSpec('admin1_code', DBIndex::INDEX, 'admin1_code');
			$t->indexWithSpec('admin2_code', DBIndex::INDEX, 'admin2_code');
			$t->indexWithSpec('admin3_code', DBIndex::INDEX, 'admin3_code');
			$t->indexWithSpec('admin4_code', DBIndex::INDEX, 'admin4_code');
			$t->indexWithSpec('timezone', DBIndex::INDEX, 'timezone');
			$t->indexWithSpec('modification_date', DBIndex::INDEX, 'modification_date');
			return $t->apply();
		}
		if($targetVersion == 2)
		{
			$t = $this->db->schema->tableWithOptions('geonames_entries', DBTable::CREATE_ALWAYS);
			$t->columnWithSpec('_uuid', DBType::UUID, null, DBCol::NOT_NULL, null, 'Unique object identifier');
			$t->columnWithSpec('_created', DBType::DATETIME, null, DBCol::NOT_NULL, null, 'Creation timestamp');
			$t->columnWithSpec('_modified', DBType::DATETIME, null, DBCol::NOT_NULL, null, 'Modification timestamp');
			$t->columnWithSpec('uri', DBType::VARCHAR, 200, DBCol::NOT_NULL, null, 'Absolute Geonames URI of this entry');
			$t->columnWithSpec('record', DBType::TEXT, null, DBCol::NULLS, null, 'JSON-encoded record');
			$t->indexWithSpec(null, DBIndex::PRIMARY, '_uuid');
			$t->indexWithSpec('uri', DBIndex::INDEX, 'uri');
			if(!$t->apply())
			{
				return false;
			}
			try
			{
				$this->db->exec('ALTER TABLE {geonames_entries} PARTITION BY KEY ("_uuid") PARTITIONS 256');
			}
			catch(DBException $e)
			{
				echo $e;
			}
			return true;
		}			
	}
}

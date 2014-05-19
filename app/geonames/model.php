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

if(!defined('GEONAMES_IRI')) define('GEONAMES_IRI', null);

uses('model');

class Geonames extends Model
{
	public $db;

	public static function getInstance($args = null)
	{
		if(!isset($args['db'])) $args['db'] = GEONAMES_IRI;
		if(!isset($args['class'])) $args['class'] = 'Geonames';
		return parent::getInstance($args);
	}

	public function truncateGeonames()
	{
		$this->db->exec('TRUNCATE {geonames}');
	}
	
	public function importRawGeoname($row)
	{
		$params = array();
		foreach($row as $value)
		{
			$params[] = $this->db->quote($value);
		}
		$this->db->exec('INSERT INTO {geonames} VALUES (' . implode(',', $params) . ')');
	}

	public function geoname($query)
	{
		$where = array();
		foreach($query as $k => $v)
		{
			$where[] = '"' . $k . '" = ' . $this->db->quote($v);
		}
		$sql = 'SELECT * FROM {geonames} WHERE ' . implode(' AND ', $where);
		return $this->db->row($sql);
	}
}

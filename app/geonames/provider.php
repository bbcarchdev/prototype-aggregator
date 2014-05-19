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

/* Data model interface for the Geonames provider */

if(!defined('GEONAMES_DISK_STORE')) define('GEONAMES_DISK_STORE', true);

uses('model');

require_once(MODULES_ROOT . 'provider/base.php');

class GeonamesProvider extends ProviderBase
{
	protected $uriPrefixConstant = 'GEONAMES_BASE';
	protected $rootPath;

	public static function getInstance($args = null)
	{
		if(!isset($args['db'])) $args['db'] = GEONAMES_IRI;
		if(!isset($args['class'])) $args['class'] = 'GeonamesProvider';
		return parent::getInstance($args);
	}

	public function __construct($args)
	{
		$this->rootPath = INSTANCE_ROOT . 'data/geonames/';
		$this->publisher = array(
			'uuid' => '267b3c57-896b-445a-9ffb-f659dac06e2a',
			RDF::rdf . 'about' => array(array('type' => 'uri', 'value' => 'http://sws.geonames.org/#org')),
			RDF::rdf . 'type' => array(array('type' => 'uri', 'value' => RDF::foaf . 'Organization')),
			RDF::foaf . 'name' => array(array('type' => 'literal', 'value' => 'Geonames')),
			RDF::foaf . 'page' => array(
				array('type' => 'uri', 'value' => 'http://www.geonames.org/'),
				),
			);
		parent::__construct($args);
	}

	/* Return an instance of a translator class for the specified kind */
	protected function translator($kind)
	{
		switch($kind)
		{
		case 'geonames_entries':
			require_once(dirname(__FILE__) . '/translate-entry.php');
			$class = 'GeonamesEntry';
			break;
		default:
			return parent::translator($kind);
		}
		if(!isset($this->translators[$class]))
		{
			$this->translators[$class] = new $class($this);
		}
		return $this->translators[$class];
	}

	public function createDirectories()
	{
		if(!GEONAMES_DISK_STORE)
		{
			return;
		}
		for($x = 0; $x < 256; $x++)
		{
			$path = sprintf('%s%02x', $this->rootPath, $x);
			if(!file_exists($path))
			{
				mkdir($path, 0777);
			}
			for($y = 0; $y < 256; $y++)
			{
				$p = sprintf('%s/%02x', $path, $y);
				if(!file_exists($p))
				{
					mkdir($p, 0777);
				}
			}
		}
	}

	protected function pathForUuid($uuid)
	{
		$path = $this->rootPath . substr($uuid, 0, 2) . '/' . substr($uuid, 2, 2) . '/';
		return $path . $uuid . '.json';
	}

	public function updateEntry($uri, $data)
	{
		if(is_object($data))
		{
			$data = $data->asArray();
			$data = $data['value'];
		}
		else
		{		   
			if(isset($data['type']) && $data['type'] == 'node')
			{
				$data = $data['value'];
			}
		}
		$data = json_encode($data);
		if(($row = $this->db->row('SELECT "_uuid" FROM {geonames_entries} WHERE "uri" = ?', $uri)))
		{
			if(GEONAMES_DISK_STORE)
			{
				$path = $this->pathForUuid($row['_uuid']);
				file_put_contents($path, $data);
				$data = null;
			}
			$this->db->exec('UPDATE {geonames_entries} SET "record" = ?, "_modified" = ' . $this->db->now() . ' WHERE "_uuid" = ?', $data, $row['_uuid']);
			$uuid = $row['_uuid'];
		}
		else
		{
			$uuid = UUID::generate();
			if(GEONAMES_DISK_STORE)
			{
				$path = $this->pathForUuid($uuid);
				file_put_contents($path, $data);
				$data = null;
			}
			$this->db->insert('geonames_entries',
							  array(
								  '_uuid' => $uuid,
								  '@_created' => $this->db->now(),
								  '@_modified' => $this->db->now(),
								  'uri' => $uri,
								  'record' => $data,
								  ));
		}
		return $uuid;
	}

	public function geonameForId($id)
	{
		return $this->db->row('SELECT * FROM {geonames} WHERE "geonameid" = ?', $id);
	}

	public function entryForIdentifier($identifier)
	{
		if(UUID::isUUID($identifier))
		{
			$row = $this->db->row('SELECT "e".* FROM {geonames_entries} "e" WHERE "e"."_uuid" = ?', $identifier);
		}
		else if(is_numeric($identifier))
		{
			$uri = 'http://sws.geonames.org/' . $identifier . '/';
			$row = $this->db->row('SELECT "e".* FROM {geonames_entries} "e" WHERE "e"."uri" = ?', $uri);
		}
		else
		{
			$row = $this->db->row('SELECT "e".* FROM {geonames_entries} "e" WHERE "e"."uri" = ?', $identifier);
		}
		if($row)
		{
			if(!strlen($row['record']))
			{
				$row['record'] = file_get_contents($this->pathForUuid($row['_uuid']));
			}
			return $row;
		}
		return null;
	}  
}

class GeonamesImporter extends ProviderImporter
{
	protected $modelClass = 'GeonamesProvider';
}

abstract class GeonamesTranslator extends ProviderTranslator
{
	public function related($uuid, $kind)
	{
		return null;
	}
}



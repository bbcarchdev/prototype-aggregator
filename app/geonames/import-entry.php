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

/* Import engine for the Geonames RDF data */

require_once(dirname(__FILE__) . '/provider.php');

uses('rdf');

class ProviderImport_Geonames_Entries extends GeonamesImporter
{
	protected $start;

	public function import()
	{
		$this->emit('Geonames:', 'Initialising...');
		$this->model->db->exec('/*!40101 SET autocommit=0*/');
		$this->model->db->exec('/*!40101 SET unique_checks=0*/');
		$this->model->useFastDirtying = true;
		$this->model->createDirectories();
		$this->count = 0;
		$path = INSTANCE_ROOT . 'incoming/geonames/all-geonames-rdf.txt';
		$this->importFrom($path);
		$this->emit('Geonames:', 'Imported', $this->count, 'entries.');
		return true;
	}

	public function importFrom($path)
	{
		$f = fopen($path, 'r');
		if(!$f)
		{
			return false;
		}
		$base = 'http://sws.geonames.org/';
		$this->emit('Geonames:', 'Importing from',  $path);
		while(!feof($f) && ($buf = fgets($f)) !== false)
		{
			$uri = trim($buf);
			$key = null;
			if(!strncmp($uri, $base, strlen($base)))
			{
				$key = substr($uri, strlen($base));
				if(substr($key, -1) == '/')
				{
					$key = substr($key, 0, -1);
				}
			}
			if(($buf = fgets($f)) === false)
			{
				break;
			}
			if(($doc = RDF::documentFromXMLString($buf, $uri)))
			{
				if(($topic = $doc->primaryTopic()))
				{
					$uuid = $this->model->updateEntry($uri, $topic);
					$this->model->push($uuid, 'geonames_entries', $key);
					$this->count++;
				}
				else
				{
					trigger_error('Failed to locate primary topic for ' . $uri, E_USER_WARNING);
					continue;
				}
			}
			else
			{
				trigger_error('Failed to parse document for ' . $uri, E_USER_WARNING);
				continue;
			}
			if(!($this->count % 500))
			{
				$this->model->db->exec('COMMIT');
				$this->emit('Geonames:', 'Imported', $this->count, 'entries...');
			}
		}
		fclose($f);
		return true;
	}
}

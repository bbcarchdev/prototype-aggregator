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

require_once(dirname(__FILE__) . '/model.php');

uses('csv-import');

class GeonamesImport extends CommandLine
{
	protected $modelClass = 'Geonames';
	protected $dataDir = null;
	protected $usage = 'geonames-import SOURCE-DIR';

	protected function checkArgs(&$args)
	{
		if(!parent::checkArgs($args))
		{
			return;
		}
		if(count($args) != 1)
		{
			$this->usage();
			return;
		}
		$this->dataDir = $args[0];
		return true;
	}

	public function main($args)
	{
		if(!file_exists($this->dataDir))
		{
			trigger_error($this->dataDir . ': no such file or directory', E_USER_ERROR);
			return 1;
		}
		if(is_dir($this->dataDir))
		{
			$path = $this->dataDir . '/allCountries.txt';
		}
		else
		{
			$path = $this->dataDir;
		}
		if(!($f = fopen($path, 'r')))
		{
			return 1;
		}
		$c = 0;
		$this->model->truncateGeonames();
		while(($buf = fgets($f)) !== false)
		{
			$row = explode("\t", trim($buf));
			if(count($row) == 1 && !strlen($row[0]))
			{
				continue;
			}
			foreach($row as $k => $v)
			{
				if(!strlen($v))
				{
					$row[$k] = null;
				}
			}
			$this->model->importRawGeoname($row);
			$c++;
			if(!($c % 1000))
			{
				echo "Imported $c entries...\n";
			}
		}
		fclose($f);
		echo "Imported $c entries.\n";
	}
}

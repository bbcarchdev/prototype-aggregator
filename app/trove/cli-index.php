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

if(!defined('TROVE_INDEXER_BATCH_SIZE')) define('TROVE_INDEXER_BATCH_SIZE', 250);

class TroveIndexCLI extends CommandLine
{
	protected $command = 'indexer';
	protected $modelClass = 'Trove';
	protected $usage = 'trove index[er] [OPTIONS] [UUID... | all]';
	protected $options = array(
		'reset' => array('value' => 'r', 'description' => 'Reset the dirty status of all entities'),
		'continuous' => array('value' => 'c', 'description' => 'Run indexer continuously'),
		);
	protected $oneShot;

	protected function log()
	{
		static $pid, $hostname;
		
		$args = func_get_args();
		if(empty($pid))
		{
			$pid = getmypid();
		}
		if(!strlen($hostname))
		{
			$hostname = explode('.', php_uname('n'));
			$hostname = $hostname[0];			
		}
		echo strftime('%y%m%d.%H%M%S') . ' ' . $hostname . ' ' . $this->command . '[' . $pid . ']: ' . implode(' ', $args) . "\n";
		flush();
	}

	protected function checkargs(&$args)
	{
		if(!parent::checkargs($args))
		{
			return false;
		}
		if(!empty($args))
		{
			if(!empty($this->options['reset']['flag']) || !empty($this->options['continuous']['flag']))
			{
				$this->usage();
				return false;
			}
		}
		else
		{
			if(empty($this->options['reset']['flag']) && empty($this->options['continuous']['flag']))
			{
				$this->usage();
				return false;
			}
		}
		if(in_array('all', $args) && count($args) > 1)
		{
			$this->usage();
		}
		if(count($args) && $args[0] == 'all')
		{
			$args = array();
			$this->options['continuous']['flag'] = true;
			$this->oneShot = true;
		}
		else
		{
			$this->oneShot = false;
		}
		return true;
	}
	
	public function main($args)
	{
		if(!empty($this->options['reset']['flag']))
		{
			$this->log('Marking all objects as needing indexing.');
			$this->model->markAllAsDirty();
			$this->log('All objects marked as needing indexing.');
			if(empty($this->options['continuous']['flag']))
			{
				return 0;
			}
		}
		if(!empty($this->options['continuous']['flag']))
		{
			return $this->indexer($this->oneShot);
		}
		$c = 0;
		foreach($this->args as $arg)
		{
			if(!($data = $this->model->updateObjectWithUUID($arg)))
			{
				$this->log("$arg: object not found");
				continue;
			}
			$c++;
		}
		return ($c ? 1 : 0);
	}

	protected function indexingPass()
	{
		$rs = $this->model->pendingObjectsSet(TROVE_INDEXER_BATCH_SIZE);
		$count = 0;
		while(($row = $rs->next()))
		{
			$count++;
			try
			{
				if(!($this->model->updateObjectWithUUID($row['uuid'])))
				{
					$this->log($row['uuid'] . ': object marked as dirty but could not be indexed');
				}
			}
			catch(Exception $e)
			{
				trigger_error($e, E_USER_NOTICE);
			}
		}
		return $count;
	}		

	protected function indexer($oneShot = true)
	{
		$shown = false;
		$tcount = 0;
		while(true)
		{
			$count = $this->indexingPass();
			if($count)
			{
				$tcount += $count;
				$this->model->commit();
				$this->log('Indexed', $tcount, 'objects...');
				$shown = false;
//				sleep(2);
			}
			else
			{
				if(!$shown)
				{
					$this->log('All pending objects indexed.');
					if(!$oneShot)
					{
						$this->log('Going to sleep.');
					}
				}
				if($oneShot)
				{
					return;
				}
				$shown = true;
				$tcount = 0;
				sleep(5);
			}
		}
	}
}

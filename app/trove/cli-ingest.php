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

class TroveIngestCLI extends CommandLine
{
	protected $minArgs = 0;
	protected $maxArgs = 2;
	protected $modelClass = 'Trove';
	protected $usage = 'trove ingest';
	protected $command = 'ingest';
	protected $run = false;

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

	public function checkargs(&$args)
	{
		if(!parent::checkargs($args))
		{
			return false;
		}	   
		if(count($args) == 1 && (!strcmp($args[0], 'run') || !strcmp($args[0], 'all')))
		{
			$this->run = true;
			return true;
		}
		if(count($args) == 1 || count($args) == 2)
		{			
			return true;
		}
		return $this->usage();
	}
	
	public function main($args)
	{
		if($this->run)
		{
			return $this->run();
		}
		$uri = $args[0];
		if(isset($args[1]))
		{
			$canonicalUri = $args[1];
		}
		else
		{
			$canonicalUri = null;
		}
		if(!$this->model->pushUri($uri, $canonicalUri, true, null, true))
		{
			$this->request->err('Failed to ingest ' . $uri);
			return 1;
		}			
		return 0;
	}
	
	protected function run()
	{
		$p = false;
		while(true)
		{
			$uuid = $this->model->ingestQueueUuid();
			if(!strlen($uuid))
			{
				if(!$p)
				{
					$this->log('All ingest queue entries processed; sleeping.');
					$p = true;
				}
				sleep(5);
				continue;
			}
			$p = false;
			$this->log('Ingesting entries for', $uuid);
			$fetched = array();
			$resources = $this->model->pendingIngestResourcesForUuid($uuid);
			foreach($resources as $res)
			{
				if(in_array($res['uri'], $fetched))
				{
					continue;
				}
				$this->log('Fetching ' . $res['uri'] . ' for ' . $uuid);
				if(!isset($res['realUri']))
				{
					$res['realUri'] = null;
				}
				$result = $this->model->ingestRDF($res['uri'], false, $res['realUri']);
				$fetched[] = $res['uri'];
				if($result)
				{
					$uuid = $this->model->uuidOfObject($result);
					$this->log('New object UUID is', $uuid);
					$rs = $this->model->ingestQueueEntriesForUri($res['uri']);
					foreach($rs as $res)
					{
						if(isset($res['callback']) && strlen($res['callback']))
						{
							$res = array_merge($res, json_decode($res['callback'], true));
							unset($res['callback']);
						}
						if(isset($res['method']))
						{
							switch($res['method'])
							{
							case 'evaluateEntity':
								$this->log('Marking', $uuid, 'as needing mapping');
								$this->model->needsMapping($uuid, 'graph', true);
								break;
							default:
								trigger_error('Unknown callback method ' . $res['method'] . ' in ingest queue entry for ' . $res['uuid'] . ' - ' . $res['uri'], E_USER_NOTICE);
							}
						}
						if(isset($res['reevaluate']))
						{
							if(!is_array($res['reevaluate'])) $res['reevaluate'] = array($res['reevaluate']);
							foreach($res['reevaluate'] as $genUuid)
							{
								$this->log('Marking', $genUuid, 'as needing mapping');
								$this->model->needsMapping($genUuid, 'graph', true);
							}
						}
						if(isset($res['regenerate']))
						{
							foreach($res['regenerate'] as $genUuid)
							{
								$this->log('Marking', $genUuid, 'as requiring regeneration');
								$this->model->needsMapping($genUuid, 'stub', true);
							}
						}
						$this->haveIngested($res);
					}
				}
				else
				{
					$this->log('Failed to ingest', $res['uri']);
					$rs = $this->model->ingestQueueEntriesForUri($res['uri']);
					foreach($rs as $res)
					{
						$this->ingestFailed($res);
					}
				}
			}
		}
	}

	protected function ingestFailed($queueEntry)
	{
		$this->model->delayIngestQueueEntry($queueEntry['uuid'], $queueEntry['uri']);
	}

	protected function haveIngested($queueEntry)
	{
		$this->model->removeIngestQueueEntry($queueEntry['uuid'], $queueEntry['uri']);
	}
}
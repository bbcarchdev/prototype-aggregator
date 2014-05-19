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

if(!defined('TROVE_EVALUATOR_BATCH_SIZE')) define('TROVE_EVALUATOR_BATCH_SIZE', 100);

class TroveEvalCLI extends CommandLine
{
	protected $modelClass = 'Trove';
	protected $usage = 'trove eval[uate] [OPTIONS | UUID... | all]';
	protected $options = array(
		'reset' => array('value' => 'r', 'description' => 'Reset the evaluation status of all entities'),
		'continuous' => array('value' => 'c', 'description' => 'Run evaluator continuously'),
		);
	protected $command = 'eval';
	protected $kind = array();

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
		if(!CommandLine::checkargs($args))
		{
			return false;
		}
		$all = false;
		foreach($args as $k => $arg)
		{
			if($arg == 'all')
			{
				$all = true;
				$args = array();
				$this->kind = null;
				break;
			}
			if(strlen($arg) > 16)
			{
				continue;
			}
			$this->kind[] = $arg;
			unset($args[$k]);
		}
		$args = array_values($args);
		if(count($args))
		{
			if(!empty($this->options['reset']['flag']) || !empty($this->options['continuous']['flag']))
			{
				$this->usage();
				return false;
			}
		}
		if(!$all && !count($this->kind) && !count($args))
		{
			$this->usage();
		}
		return true;
	}
	
	public function main($args)
	{
		if(!empty($this->options['reset']['flag']))
		{
			$this->log('Resetting evaluation status...');
			$this->model->resetEvaluationStatus($this->kind);
		}
		if(!count($args))
		{
			$oneShot = empty($this->options['continuous']['flag']);
			if(!$oneShot)
			{
				$this->log("Evaluating pending entities...");
			}
			return $this->evaluator($oneShot, $this->kind);
		}
		$c = 0;
		foreach($this->args as $arg)
		{
			if($this->evaluateItem($arg))
			{
				$c++;
			}
		}
		return ($c ? 1 : 0);
	}

	protected function evaluateItem($uuid, $kind = null)
	{
		if(strlen($kind))
		{
			$this->log('Evaluating', $uuid, '[' . $kind . ']');
		}
		else
		{
			$this->log('Evaluating', $uuid);
		}
		if($kind === 'stub')
		{
			if($this->model->generateStub($uuid))
			{
				return array($uuid);
			}
			return null;
		}
		if(!($data = $this->model->dataForUuid($uuid)))
		{
			$children = $this->model->mappingsByTypeForUuid($uuid);
			if(!$children || !count($children) || !count($children['exactMatch']))
			{
				$this->log("$uuid: object not found");
				return false;
			}
			if($this->model->generateStub($uuid))
			{
				return array($uuid);
			}
			return null;
		}
		if($this->model->isStub($data))
		{
			return $this->model->evaluateEntitiesOfStub($data);
		}		
		if($this->model->evaluateEntity($data))
		{
			return array($uuid);
		}
		return null;
	}

	protected function evaluator($oneShot = true, $kind = false)
	{
		$first = true;
		$pass = 0;
		while(true)
		{
			$block = 0;
			$rs = $this->model->pendingEvaluationSet(TROVE_EVALUATOR_BATCH_SIZE, $kind);
			$evaluated = array();
			while(($obj = $rs->next()))
			{
				if(!in_array($obj['uuid'], $evaluated))
				{
					$a = $this->evaluateItem($obj['uuid'], $obj['kind']);
					if(is_array($a))
					{
						$evaluated = array_merge($evaluated, $a);
					}
					else
					{
						$this->log('Non-array returned from evaluateItem(', $obj['uuid'], ')');
					}
				}
				$this->model->needsMapping($obj['uuid'], $obj['kind'], false, $obj['nonce']);
				$block++;
				$pass++;
			}
			if($block)
			{
				$this->model->commit();
				echo "Evaluated $pass entities...\n";
				sleep(1);
			}
			else
			{
				if($pass)
				{
					echo "Evaluated $pass entities. Going to sleep.\n";
				}
				else if($first)
				{
					echo "No entities to evaluate; going to sleep.\n";
				}
				$first = false;
				$block = $pass = 0;
				if($oneShot)
				{
					return;
				}
				sleep(5);
			}
		}
	}
}

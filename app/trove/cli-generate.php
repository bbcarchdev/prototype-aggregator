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

class TroveGenerateCLI extends CommandLine
{
	protected $modelClass = 'Trove';
	protected $usage = 'trove gen[erate] [OPTIONS | UUID... | all]';
	protected $options = array(
		'continuous' => array('value' => 'c', 'description' => 'Run generator continuously (do not use while evaluator is running)'),
		);
	protected $command = 'gen';
	protected $kind = 'stub';

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
		if(!count($args))
		{
			$oneShot = empty($this->options['continuous']['flag']);
			if(!$oneShot)
			{
				$this->log("Generating pending stubs...");
			}
			return $this->generator($oneShot, $this->kind);
		}
		$c = 0;
		foreach($this->args as $arg)
		{
			if($this->generateItem($arg))
			{
				$c++;
			}
		}
		return ($c ? 1 : 0);
	}

	protected function generateItem($uuid)
	{
		if($this->model->generateStub($uuid))
		{
			return array($uuid);
		}
		return null;
	}

	protected function generator($oneShot = true)
	{
		$first = true;
		$pass = 0;
		while(true)
		{
			$block = 0;
			$rs = $this->model->pendingEvaluationSet(TROVE_EVALUATOR_BATCH_SIZE, 'stub');
			$evaluated = array();
			while(($obj = $rs->next()))
			{
				if(!in_array($obj['uuid'], $evaluated))
				{
					$a = $this->generateItem($obj['uuid']);
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

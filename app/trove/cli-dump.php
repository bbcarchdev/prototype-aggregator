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

class TroveDumpCLI extends CommandLine
{
	protected $minArgs = 1;
	protected $maxArgs = 1;
	protected $modelClass = 'Trove';
	protected $usage = 'trove dump UUID';
	
	public function main($args)
	{
		if(($obj = $this->model->objectForUuid($args[0])))
		{
			print_r($obj);
			if(is_array($obj))
			{
				if(method_exists($obj[0], 'asTurtle'))
				{
					foreach($obj as $k => $o)
					{
						echo "#### Object $k\n\n";
						echo $o->asTurtle();
					}
				}
			}
			else
			{
				if(method_exists($obj, 'asTurtle'))
				{
					echo $obj->asTurtle();
				}
			}
			return 0;
		}
		echo $args[0] . ": not found\n";
		return 1;
	}
}
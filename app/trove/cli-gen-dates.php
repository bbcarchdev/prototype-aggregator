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

class TroveGenerateDatesCLI extends CommandLine
{
	protected $minArgs = 0;
	protected $maxArgs = 0;
	protected $modelClass = 'Trove';
	protected $usage = 'trove gen-dates';
	
	public function main($args)
	{
		$helpers = $this->model->matchingHelpers();
		if(!isset($helpers['TroveTimeMatcher']))
		{
			echo "TroveTimeMatcher isn't registered, aborting\n";
			return 1;
		}
		$time = $helpers['TroveTimeMatcher'];
		echo "Generating years...\n";
		$dummy = array('tags' => array());
		$thisYear = intval(strftime('%Y'));
		for($year = $thisYear; $year < $thisYear + 5; $year++)
		{
			$time->obtainInstanceForYear($year, $dummy);
		}
		for($year = $thisYear - 100; $year < $thisYear; $year++)
		{
			$time->obtainInstanceForYear($year, $dummy);
		}
		for($year = 1; $year < 2100; $year++)
		{
			if($year >= $thisYear - 100 || $year <= $thisYear + 5)
			{
				continue;
			}
			$time->obtainInstanceForYear($year, $dummy);
		}
		echo "Done.\n";
		return 0;
	}
}

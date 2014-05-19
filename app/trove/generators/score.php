<?php

/**
 * Trove Scoring Generator
 *
 * Sets a 'score' internal property on stubs which is used as a multiplier
 * against the adjusted_refcount. Stubs which have certain 'less interesting'
 * relationships with other entities have their score reduced.
 */

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

/**
 * TroveScoreGenerator is responsible for generating the a stub's internal
 * 'score' property, which is a floating point value between 0 and 1.0
 * (although in principle values greater than 1.0 are permissible) and is
 * used as a multiplier when determining a stub's adjusted_refcount. This
 * provides a mechanism for certain kinds of entity to be deprioritised,
 * even if they have a relatively high reference count.
 */
class TroveScoreGenerator extends TroveGenerator
{
	public static $predicateScores = array(
		'http://purl.org/theatre#performance_of' => 0.5,
		'http://purl.org/theatre#production_of' => 0.5,
		);

	/**
	 * Generate score information for the stub.
	 */
	public function generate($stubUuid, TroveMap $objects, &$stubSet)
	{
		$data =& $stubSet[0];
		$score = 1;
		foreach(self::$predicateScores as $predicate => $predicateScore)
		{
			if(isset($data[$predicate]))
			{
				foreach($data[$predicate] as $value)
				{
					$score = $score * $predicateScore;
				}
			}
		}
		$data['score'] = $score;
	}
}

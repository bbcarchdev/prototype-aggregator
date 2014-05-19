<?php

/* Copyright 2009, 2010 Mo McRoberts.
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
 * @package EregansuLib Eregansu Core Library
 * @year 2009, 2010
 * @include uses('base32');
 * @since Available in Eregansu 1.0 and later. 
 */
 
/**
 * Abstract class implementing base-32 encoding and decoding.
 *
 * @note Instances of the Base32 class are never created; all methods are static.
 */
abstract class Base32
{
	/**
	 * Maps numerical values to base-32 digits
	 * @internal
	 * @hideinitializer
	 */
	protected static $alphabet = array(
		'0', '1', '2', '3', '4', '5', '6', '7', '8', '9',
		'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'j', 'k',
		'l', 'm', 'n', 'p', 'q', 'r', 's', 't', 'u', 'v',
		'w', 'x',
	);
	/**
	 * Maps base-32 digits to numerical values
	 * @internal
	 */
	protected static $ralphabet;
	
	/**
	 * Encode an integer as base-32
	 * @task Encoding and decoding base-32 values
	 *
	 * Encodes an integer as a base-32 value, that is, a value where each digit
	 * has 32 possible values (0-9, a-x). The letters 'i', 'l', 'o', 'y' and
	 * 'z' are not included in the alphabet.
	 *
	 * @type string
	 * @param[in] int $input The number to encode
	 * @return A string containing the value of \p{$input} encoded as base-32
	 */
	public static function encode($input)
	{
		$output = '';
		do
		{
			$v = $input % 32;
			$input = floor($input / 32);
			$output = self::$alphabet[$v] . $output;
		}
		while($input);
		return $output;
	}
	
	/**
	 * Decode a base-32 string and return the value as an integer
	 * @task Encoding and decoding base-32 values
	 *
	 * Accepts a base-32-encoded string as encoded by \m{Base32::encode} and
	 * returns its integer value.
	 *
	 * @type int
	 * @param[in] string $input A base-32 encoded value
	 * @return The integer value represented by \p{$input}
	 */
	public static function decode($input)
	{
		if(!self::$ralphabet)
		{
			self::$ralphabet = array_flip(self::$alphabet);
		}
		$output = 0;
		$l = strlen($input);
		for($n = 0; $n < $l; $n++)
		{
			$c = $input[$n];
			$output *= 32;
			if(isset(self::$ralphabet[$c]))
			{
				$output += self::$ralphabet[$c];
			}
			else
			{
				return false;
			}
		}
		return $output;
	}
}

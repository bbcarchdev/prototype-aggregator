<?php

/**
 * Trove Geographic Generator
 *
 * Looks for geographical information on matching entities and includes
 * it in the stub.
 *
 * @year 2011
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
 * TroveGeoGenerator copies geographical data from matching entities into
 * the stub.
 */
class TroveGeoGenerator extends TroveGenerator
{
	/**
	 * Generate geo data for a stub
	 */
	public function generate($stubUuid, TroveMap $objects, &$stubSet)
	{
		$data =& $stubSet[0];
		foreach($objects['exactMatch'] as $obj)
		{
			$obj = $this->model->firstObject($obj);
			$this->debug('Checking ' . $obj);
			if(isset($obj->{RDF::geo.'lat'}[0]) && isset($obj->{RDF::geo.'long'}[0]))
			{
				$lat = strval($obj->{RDF::geo.'lat'}[0]);
				$long = strval($obj->{RDF::geo.'long'}[0]);
				$data[RDF::geo.'lat'] = array($lat);					
				$data[RDF::geo.'long'] = array($long);
				return true;
			}
		}
	}
}

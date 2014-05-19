<?php

/**
 * Trove Summary Generator
 *
 * Constructs title and description information on stub objects.
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
 * TroveSummaryGenerator contains the implementation of the stub-generation
 * logic which handles titles (expressed as rdfs:label) and descriptions
 * (expressed as dct:description). Both are added to the full-text indexing
 * data attached to the stub.
 */
class TroveSummaryGenerator extends TroveGenerator
{
	/**
	 * Generate summary data for a stub
	 */
	public function generate($stubUuid, TroveMap $objects, &$stubSet)
	{
		$data =& $stubSet[0];
		/* Set rdfs:label and the browse title */
		$exact = $objects['exactMatch'];
		foreach(Trove::$labelPredicates as $pred)
		{
			$set = RDFSet::setFromInstances($pred, $exact);
			if(count($set))
			{
				$data['title'] = strval($set->first());
				$data[RDF::rdfs.'label'] = $set->valuePerLanguage(true)->asArray();
				$data['_index']['_fullText'] = array_merge($data['_index']['_fullText'], $set->valuePerLanguage(true)->strings());
				break;
			}
		}
		/* Set dct:description */
		$set = RDFSet::setFromInstances(Trove::$descriptionPredicates, $objects['exactMatch']);
		if(count($set))
		{
			$data[RDF::dcterms.'description'] = $set->valuePerLanguage(true)->asArray();
			$data['_index']['_fullText'] = array_merge($data['_index']['_fullText'], $set->valuePerLanguage(true)->strings());
		}
		/* If there are 'start' or 'end' entries on the stub, generate
		 * time:hasBeginning and time:hasEnd bnodes.
		 */
		if(isset($data['start']))
		{
			$data[RDF::time.'hasBeginning'][] = array('type' => 'node', 'value' => $this->generateInstant($data['start']));
		}
		if(isset($data['end']))
		{
			$data[RDF::time.'hasEnd'][] = array('type' => 'node', 'value' => $this->generateInstant($data['end']));
		}
	}

	protected function generateInstant($time)
	{
		$instant = array(
			RDF::rdf.'type' => array(
				array('type' => 'uri', 'value' => RDF::time.'Instant'),
				),
			);
		if(isset($time['year']))
		{
			$instant[RDF::time.'year'][] = array('type' => 'literal', 'datatype' => XMLNS::xsd.'gYear', 'value' => $time['year']);
		}
		if(isset($time['month']))
		{
			$instant[RDF::time.'month'][] = array('type' => 'literal', 'datatype' => XMLNS::xsd.'gMonth', 'value' => $time['month']);
		}
		if(isset($time['day']))
		{
			$instant[RDF::time.'day'][] = array('type' => 'literal', 'datatype' => XMLNS::xsd.'gDay', 'value' => $time['day']);
		}
		return $instant;
	}
}
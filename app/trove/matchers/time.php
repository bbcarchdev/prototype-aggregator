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

class TroveTimeMatcher extends TroveMatcher
{
    /* Update this value when the structure of time objects change */
	protected $generation = 129;

	protected $months = array(null, 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December');

	public function __construct($model)
	{
		parent::__construct($model);
	}

	public function performMatching($stubUuid, &$objects, &$extraData, $kind = null, $parent = null, $returnMatch = false)
	{
		$start = null;
		$end = null;
		$spot = null;
		foreach($objects['exactMatch'] as $exact)
		{
			if($exact->kind == 'time')
			{
				if(isset($exact->start))
				{
					$extraData['start'] = $exact->start;
				}
				if(isset($exact->end))
				{
					$extraData['end'] = $exact->end;
				}
				foreach($exact->tags as $t)
				{
					if(!in_array($t, $extraData['tags']))
					{
						$extraData['tags'][] = $t;
					}
				}
				if(empty($exact->generation) || $exact->generation < $this->generation)
				{
					$this->debug('Warning: time object ' . $exact->uuid . ' is an old version and will need to be re-generated');
				}
				return;
			}
			$time = $exact['sem:hasTime']->values();
			foreach($time as $value)
			{
				if($value instanceof RDFStoredObject && $value->isA('sem:Time'))
				{
					if(($x = $this->timeFromSemTime($value)))
					{
						$spot = $x;
					}
				}
			}
			$time = $exact['time:hasBeginning']->values();
			foreach($time as $value)
			{
				if($value instanceof RDFStoredObject && $value->isA('time:Instant'))
				{
					if(($x = $this->timeFromOwlTime($value)))
					{
						$start = $x;
					}
				}
			}
			$time = $exact['time:hasEnd']->values();
			foreach($time as $value)
			{
				if($value instanceof RDFStoredObject && $value->isA('time:Instant'))
				{
					if(($x = $this->timeFromOwlTime($value)))
					{
						$end = $x;
					}
				}
			}
		}
		if(!$start)
		{
			$start = $spot;
		}
		if(!$end)
		{
			$end = $spot;
		}
		$this->mapTimes($start, $extraData);
		$this->mapTimes($end, $extraData);
		if($start)
		{
			$extraData['start'] = $start;
		}
		if($end)
		{
			$extraData['end'] = $end;
		}
	}

	protected function mapTimes(&$when, &$extraData)
	{
		if(!is_array($when))
		{
			return;
		}
		$yearStub = $monthStub = $dayStub = $yearMonthStub = $yearMonthDayStub = null;
		$partOf = array();
		if(isset($when['year']) && isset($when['month']) && isset($when['day']))
		{
			$yearMonthDayStub = $this->obtainInstanceForYearMonthDay($when['year'], $when['month'], $when['day'], $extraData);
			$monthStub = $this->obtainInstanceForMonth($when['month'], $extraData);
			$partOf[] = $yearMonthDayStub;
		}
		else if(isset($when['year']) && isset($when['month']))
		{
			$yearMonthStub = $this->obtainInstanceForYearMonth($when['year'], $when['month'], $extraData);
			$monthStub = $this->obtainInstanceForMonth($when['month'], $extraData);
			$partOf[] = $yearMonthStub;
		}
		else if(isset($when['year']))
		{
			$yearStub = $this->obtainInstanceForYear($when['year'], $extraData);
			$partOf[] = $yearStub;
		}
		else if(isset($when['month']) && isset($when['day']))
		{
			$monthStub = $this->obtainInstanceForMonth($when['month'], $extraData);
			$monthDayStub = $this->obtainInstanceForMonthDay($when['month'], $when['day'], $extraData);
			$partOf[] = $monthDayStub;
		}
		else if(isset($when['month']))
		{
			$monthStub = $this->obtainInstanceForMonth($when['month'], $extraData);
			$partOf[] = $monthStub;
		}
		foreach($partOf as $obj)
		{
			if(is_object($obj))
			{
				$uuid = $obj->uuid;
			}
			else
			{
				$uuid = $obj;
			}
			$urn = 'urn:uuid:' . $uuid;
			$stub = $this->model->stubForResource($urn);
			$this->addUriToData($extraData, RDF::dcterms.'isPartOf', '/events/' . $stub . '#event');
			$extraData['relatedStubs'][] = $uuid;
		}
	}

	protected function addUriToData(&$data, $predicate, $uri)
	{
		$uri = strval($uri);
		if(isset($data[$predicate]))
		{
			foreach($data[$predicate] as $value)
			{
				if(is_array($value) && isset($value['type']) && isset($value['value']) &&
				   $value['type'] == 'uri' && !strcmp($value['value'], $uri))
				{
					return;
				}
			}
		}
		$data[$predicate][] = array('type' => 'uri', 'value' => $uri);
	}

	protected function numericSuffix($num)
	{
		$num = intval($num);
		switch($num)
		{
		case 11:
		case 12:
		case 13:
			return 'th';
		}		
		$r = $num % 10;
		switch($r)
		{
		case 1:
			return 'st';
		case 2:
			return 'nd';
		case 3:
			return 'rd';
		}
		return 'th';
	}

	protected function obtainInstance($resource, $parent, $parentResource, $data, &$extraData)
	{
//		$this->debug('obtainInstance(parent=', gettype($parent), ')');
		if(($obj = $this->model->objectForIri($resource)) && isset($obj->generation) && $obj->generation == $this->generation)
		{			
			$urn = 'urn:uuid:' . $obj->uuid;
			$stub = $this->model->stubForResource($urn);
			if(strlen($stub))
			{
				if(!in_array($stub, $extraData['tags']))
				{
					$extraData['tags'][] = $stub;
				}
//				$this->debug("Located stub $stub for $resource");
				return $obj;
			}
			else
			{
				$this->debug("Failed to locate stub for $urn, will regnerate");
			}
//			$this->debug("Located $urn for $resource");
		}
		$this->debug('Generating ' . $resource);
		if(isset($obj->uuid))
		{
			$data['uuid'] = $obj->uuid;
		}
		else
		{
			$data['uuid'] = UUID::generate();
		}
		$data['kind'] = 'time';
		$data['generation'] = $this->generation;
		$data['iri'] = array($resource);
		$data['tags'] = array();
		$data['structuralRefs'] = array();
		$data[RDF::rdf.'about'] = array(array('type' => 'uri', 'value' => $resource));
		if(!is_array($parentResource) && strlen($parentResource))
		{
			$parentResource = array($parentResource);
		}
		else if(!is_array($parentResource))
		{
			$parentResource = null;
		}
		if(!is_array($parent) && is_object($parent))
		{
			$parent = array($parent);
		}
		else if(!is_array($parent))
		{
			$parent = null;
		}
		if(is_array($parentResource) && is_array($parent))
		{
			foreach($parentResource as $p)
			{
				$this->addUriToData($data, RDF::dcterms.'isPartOf', $p);
			}
			$data['parent'] = $parent[0]->uuid;
			foreach($parent as $p)
			{
				if(!in_array($p->uuid, $data['structuralRefs']))
				{
					$data['structuralRefs'][] = $p->uuid;
				}
				foreach($p->structuralRefs as $ref)
				{
					if(!in_array($ref, $data['structuralRefs']))
					{
						$data['structuralRefs'][] = $ref;
					}
				}
			}
		}
		$this->addUriToData($data, RDF::rdf.'type', RDF::time.'DurationDescription');
		$urn = 'urn:uuid:' . $data['uuid'];
		$result = $this->model->setData($data, null, false, null);
		$stub = $this->model->generateStubForResource($urn, 'time', null, null, false, true);
		if(strlen($stub) && (!is_array($extraData['tags']) || !in_array($stub, $extraData['tags'])))
		{
			$extraData['tags'][] = $stub;
		}		
		$obj = $this->model->objectForUUID($data['uuid']);
		return $obj;
	}

	protected function obtainInstanceForYearMonthDay($year, $month, $day, &$extraData)
	{
		$formatted = sprintf('%04d-%02d-%02d', $year, $month, $day);
		$resource = 'urn:x-time:date:' . $formatted;
		$suf = $this->numericSuffix($day);
		$label = intval($day) . $suf . ' ' . $this->months[intval($month)] . ', ' . $year;
		$data = array(
			RDF::skos.'prefLabel' => array(
				array('type' => 'literal', 'value' => $formatted),
				array('type' => 'literal', 'value' => $label, 'lang' => 'en'),
				),
			RDF::rdfs.'comment' => array(array('type' => 'literal', 'value' => 'The day of the ' . $label, 'lang' => 'en')),
			RDF::rdf.'type' => array(array('type' => 'uri', 'value' => Trove::trove.'Date')),
			'start' => array('year' => $year, 'month' => $month, 'day' => $day),
			'end' => array('year' => $year, 'month' => $month, 'day' => $day),
			);
		$parent = array();
		$parentResource = array();
		$parent[0] = $this->obtainInstanceForYearMonth($year, $month, $extraData);
		$parentResource[0] = 'urn:x-time:date:' . sprintf('%04d-%02d', $year, $month);
		$parent[1] = $this->obtainInstanceForMonthDay($month, $day, $extraData);
		$parentResource[1] = 'urn:x-time:monthday:' . sprintf('%02d-%02d', $month, $day);
		return $this->obtainInstance($resource, $parent, $parentResource, $data, $extraData);
	}

	protected function obtainInstanceForMonthDay($month, $day, &$extraData)
	{
		$formatted = sprintf('%02d-%02d', $month, $day);
		$resource = 'urn:x-time:monthday:' . $formatted;
		$suf = $this->numericSuffix($day);
		$label = intval($day) . $suf . ' ' . $this->months[intval($month)];
		$data = array(
			RDF::skos.'prefLabel' => array(
				array('type' => 'literal', 'value' => $formatted),
				array('type' => 'literal', 'value' => $label, 'lang' => 'en'),
				),
			RDF::rdfs.'comment' => array(array('type' => 'literal', 'value' => 'The ' . $label, 'lang' => 'en')),
			RDF::rdf.'type' => array(array('type' => 'uri', 'value' => Trove::trove.'Day')),
			RDF::owl.'sameAs' => array(array('type' => 'uri', 'value' => 'http://dbpedia.org/resource/' . $this->months[intval($month)] . '_' . intval($day))),
			'start' => array('month' => $month, 'day' => $day),
			'end' => array('month' => $month, 'day' => $day),
			);
		$parent = $this->obtainInstanceForMonth($month, $extraData);
		$parentResource = 'urn:x-time:month:' . sprintf('%02d', $month);
		return $this->obtainInstance($resource, $parent, $parentResource, $data, $extraData);
	}

	protected function obtainInstanceForYearMonth($year, $month, &$extraData)
	{
		$formatted = sprintf('%04d-%02d', $year, $month);
		$resource = 'urn:x-time:date:' . $formatted;
		$label = $this->months[intval($month)] . ' ' . $year;
		$data = array(
			RDF::skos.'prefLabel' => array(
				array('type' => 'literal', 'value' => $formatted),
				array('type' => 'literal', 'value' => $label, 'lang' => 'en'),
				),
			RDF::rdfs.'comment' => array(array('type' => 'literal', 'value' => 'The month of ' . $this->months[intval($month)] . ' in ' . $year, 'lang' => 'en')),
			RDF::rdf.'type' => array(array('type' => 'uri', 'value' => Trove::trove.'YearMonth')),
			'start' => array('year' => $year, 'month' => $month),
			'end' => array('year' => $year, 'month' => $month),
			);
		$parent = $this->obtainInstanceForYear($year, $extraData);
		$parentResource = 'urn:x-time:date:' . sprintf('%04d', $year);
		return $this->obtainInstance($resource, $parent, $parentResource, $data, $extraData);
	}


	public function obtainInstanceForYear($year, &$extraData)
	{
		$formatted = sprintf('%04d', $year);
		$resource = 'urn:x-time:date:' . $formatted;
		$data = array(
			RDF::skos.'prefLabel' => array(array('type' => 'literal', 'value' => $year)),
			RDF::rdfs.'comment' => array(array('type' => 'literal', 'value' => 'The year ' . $year, 'lang' => 'en')),
			RDF::rdf.'type' => array(array('type' => 'uri', 'value' => Trove::trove.'Year')),
			RDF::owl.'sameAs' => array(array('type' => 'uri', 'value' => 'http://dbpedia.org/resource/' . intval($year))),
			'start' => array('year' => $year, 'month' => 1, 'day' => 1),
			'end' => array('year' => $year, 'month' => 12, 'day' => 31),
			);
		$parent = $this->obtainInstanceForDecade($year, $extraData);
		$parentResource = 'urn:x-time:decade:' . (floor($year / 10) * 10);
		return $this->obtainInstance($resource, $parent, $parentResource, $data, $extraData);
	}

	protected function obtainInstanceForMonth($month, &$extraData)
	{
		static $ends = array(null, 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31);

		$formatted = sprintf('%02d', $month);
		$resource = 'urn:x-time:month:' . $formatted;
		$label = $this->months[intval($month)];
		$data = array(
			RDF::skos.'prefLabel' => array(
				array('type' => 'literal', 'value' => $month),
				array('type' => 'literal', 'value' => $label, 'lang' => 'en'),
				),
			RDF::rdfs.'comment' => array(array('type' => 'literal', 'value' => 'The month of ' . $label, 'lang' => 'en')),
			RDF::rdf.'type' => array(array('type' => 'uri', 'value' => Trove::trove.'Month')),
			RDF::owl.'sameAs' => array(array('type' => 'uri', 'value' => 'http://dbpedia.org/resource/' . $this->months[intval($month)])),
			'start' => array('month' => $month, 'day' => 1),
			'end' => array('month' => $month, 'day' => $ends[$month]),
			);
		return $this->obtainInstance($resource, null, null, $data, $extraData);
	}

	protected function obtainInstanceForDecade($year, &$extraData)
	{
		$year = floor($year / 10) * 10;
		$resource = 'urn:x-time:decade:' . $year;
		$data = array(
			RDF::skos.'prefLabel' => array(array('type' => 'literal', 'value' => $year . 's')),
			RDF::rdfs.'comment' => array(array('type' => 'literal', 'value' => 'The decade ' . $year . '-' . ($year + 9), 'lang' => 'en')),
			RDF::rdf.'type' => array(array('type' => 'uri', 'value' => Trove::trove.'Decade')),
			'start' => array('year' => $year, 'month' => 1, 'day' => 1),
			'end' => array('year' => $year + 9, 'month' => 12, 'day' => 31),
			);
		$parent = $this->obtainInstanceForCentury($year, $extraData);
		$parentResource = 'urn:x-time:century:' . (floor($year / 100) * 100);
		return $this->obtainInstance($resource, $parent, $parentResource, $data, $extraData);
	}

	protected function obtainInstanceForCentury($year, &$extraData)
	{
		$year = floor($year / 100) * 100;
		$resource = 'urn:x-time:century:' . $year;
		$cent = floor($year / 100) + 1;
		$label = $cent . $this->numericSuffix($cent) . ' Century';
		$data = array(
			RDF::skos.'prefLabel' => array(
				array('type' => 'literal', 'value' => $label, 'lang' => 'en'),
				array('type' => 'literal', 'value' => $year . '-' . ($year + 99)),
				),
			RDF::rdfs.'comment' => array(array('type' => 'literal', 'value' => 'The century ' . $year . '-' . ($year + 99), 'lang' => 'en')),
			RDF::rdf.'type' => array(array('type' => 'uri', 'value' => Trove::trove.'Century')),
			'start' => array('year' => $year, 'month' => 1, 'day' => 1),
			'end' => array('year' => $year + 99, 'month' => 12, 'day' => 31),
			RDF::owl.'sameAs' => array(array('type' => 'uri', 'value' => 'http://dbpedia.org/resource/' . $cent . $this->numericSuffix($cent) . '_Century')),
			);
		$parent = $this->obtainInstanceForMillennium($year, $extraData);
		$parentResource = 'urn:x-time:millennium:' . (floor($year / 1000) * 1000);
		return $this->obtainInstance($resource, $parent, $parentResource, $data, $extraData);
	}

	protected function obtainInstanceForMillennium($year, &$extraData)
	{
		$year = floor($year / 1000) * 1000;
		$resource = 'urn:x-time:millennium:' . $year;
		$mil = floor($year / 1000) + 1;
		$end = $year + 999;
		if($year == 0)
		{
			$year = 1;
		}
		$label = $mil . $this->numericSuffix($mil) . ' Millennium';
		$data = array(
			RDF::skos.'prefLabel' => array(
				array('type' => 'literal', 'value' => $label, 'lang' => 'en'),
				array('type' => 'literal', 'value' => $year . '-' . ($year + 999)),
				),
			RDF::rdfs.'comment' => array(array('type' => 'literal', 'value' => 'The millennium ' . $year . '-' . $end, 'lang' => 'en')),
			RDF::rdf.'type' => array(array('type' => 'uri', 'value' => Trove::trove.'Millennium')),
			'start' => array('year' => $year, 'month' => 1, 'day' => 1),
			'end' => array('year' => $end, 'month' => 12, 'day' => 31),
			RDF::owl.'sameAs' => array(array('type' => 'uri', 'value' => 'http://dbpedia.org/resource/' . $mil . $this->numericSuffix($mil) . '_millennium')),
			);
		return $this->obtainInstance($resource, null, null, $data, $extraData);
	}

	/* Obtain a time interval array from a sem:Time instance */
	protected function timeFromSemTime($value)
	{
		$ts = $value['sem:hasTimestamp']->values();
		foreach($ts as $stamp)
		{
			if($stamp instanceof RDFComplexLiteral)
			{
				if($stamp->{RDF::rdf.'datatype'}[0] == 'http://www.w3.org/2001/XMLSchema#date')
				{
					$matches = array();
					if(preg_match('!^(\d{4})-?(\d{2})-?(\d{2})$!', $stamp, $matches) && isset($matches[3]))
					{
						return array('year' => intval($matches[1]), 'month' => intval($matches[2]), 'day' => intval($matches[3]));
					}
				}
			}
		}
	}

	protected function timeFromOwlTime($value)
	{
		static $list = array('year', 'month', 'day');

		$time = array();
		foreach($list as $key)
		{
			$predicate = RDF::time.$key;
			if(isset($value->{$predicate}) && is_array($value->{$predicate}))
			{
				foreach($value->{$predicate} as $v)
				{
					if(is_object($v))
					{
						if(!($v instanceof RDFComplexLiteral))
						{
							continue;
						}
					}
					$v = intval(strval($v));
					if($v)
					{
						$time[$key] = $v;
						break;
					}
				}
			}
		}
		return $time;
	}
}


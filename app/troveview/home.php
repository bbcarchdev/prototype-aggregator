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

require_once(dirname(__FILE__)  . '/page.php');

class TroveHomePage extends TrovePage
{
	protected $doc = null;

	protected $things;
	protected $places;
	protected $people;
	protected $collections;
	protected $title = 'Spindle';

	protected $supportedMethods = array('GET', 'HEAD', 'POST');

	public function __construct()
	{
		parent::__construct();
		$this->supportedTypes[] = 'application/vnd.sun.wadl+xml';
	}

	protected function perform_GET_WADL($type)
	{
		$this->request->header('Content-type', $type);
		echo '<?xml version="1.0" encoding="UTF-8" ?>' . "\n";
		echo '<application xmlns="http://wadl.dev.java.net/2009/02" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://wadl.dev.java.net/2009/02 wadl.xsd">' . "\n";

		echo '</application>' . "\n";
	}

	protected function getObject()
	{		
		if(!parent::getObject())
		{
			return;
		}
		if(!isset($this->model->db))
		{
			return $this->error(Error::SERVICE_UNAVAILABLE, null, null, 'No database connection has been configured [TROVE_IRI].');
		}
		if(($route = $this->request->consume()))
		{
			if(($obj = $this->model->objectForUuid($route)))
			{
				$this->redirectToObject($obj);
			}
			else
			{
				return $this->error(Error::OBJECT_NOT_FOUND);
			}
		}
		$this->doc = new RDFDocument();
		$this->collections = $this->model->query(array('kind' => 'collection', 'tags' => 'featured'));
		$this->things = $this->model->query(array('kind' => 'thing', 'tags' => 'featured'));
		$this->people = $this->model->query(array('kind' => 'person', 'tags' => 'featured'));
		$this->places = $this->model->query(array('kind' => 'place', 'tags' => 'featured'));
		$this->events = $this->model->query(array('kind' => 'event', 'tags' => 'featured'));
		return true;
	}

	protected function finaliseDoc($mime, $suffix)
	{
		$inst = new RDFInstance($this->uri . $suffix);
		$inst['rdf:type'] = array(
			new RDFURI(RDF::foaf.'Document'),
			new RDFURI(RDF::dcmit . 'Text'),
			);
		$inst{RDF::dcterms.'format'} = array(new RDFURI('http://purl.org/NET/mediatypes/' . $mime));
		$inst['rdfs:label'] = new RDFString($this->title . ' (' . MIME::description($mime) . ')', 'en-GB');
		$inst['rdfs:seeAlso'] = array(
			new RDFURI($this->request->root . 'collections' . $this->request->explicitSuffix),
			new RDFURI($this->request->root . 'people' . $this->request->explicitSuffix),
			new RDFURI($this->request->root . 'places' . $this->request->explicitSuffix),
			new RDFURI($this->request->root . 'events' . $this->request->explicitSuffix),
			new RDFURI($this->request->root . 'things' . $this->request->explicitSuffix),
			new RDFURI($this->request->root . 'millennia' . $this->request->explicitSuffix),
			new RDFURI($this->request->root . 'centuries' . $this->request->explicitSuffix),
			new RDFURI($this->request->root . 'decades' . $this->request->explicitSuffix),
			new RDFURI($this->request->root . 'years' . $this->request->explicitSuffix),
			new RDFURI($this->request->root . 'months' . $this->request->explicitSuffix),
			new RDFURI($this->request->root . 'all' . $this->request->explicitSuffix),
			new RDFURI($this->request->root . 'ns' . $this->request->explicitSuffix),
			);
		$this->doc->add($inst);
	}

	protected function postObject($doc)
	{
		if(!($doc instanceof RDFDocument))
		{
			return;
		}		
		if(!($inst = $doc->primaryTopic()))
		{
			return;
		}		
		$data = $inst->asArray();
		if(isset($this->request->certificateInfo['extensions']['subjectAltName']))
		{
			$alts = explode(',', $this->request->certificateInfo['extensions']['subjectAltName']);
			foreach($alts as $alt)
			{
				$alt = trim($alt);
				if(strncmp($alt, 'URI:', 4))
				{
					continue;
				}
				$uri = substr($alt, 4);
				$whom = $this->model->objectForOwner($uri, $this->request->publicKeyHash, $this->request->certificateInfo['name'], $this->request->certificate);
				die('user = ' . $whom);
				$this->model->push($data, $whom);
			}
		}
	}
}

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

require_once(dirname(__FILE__) . '/page.php');

class TroveNamespacePage extends TrovePage
{
	protected function getObject()
	{
		if(!parent::getObject())
		{
			return;
		}
		RDF::ns('http://www.w3.org/2003/06/sw-vocab-status/ns#', 'vs');
		$this->doc = new RDFDocument();

		$inst = new RDFInstance($this->uri, RDF::owl.'Ontology');
		$inst['rdfs:label'] = 'The Spindle ontology';
		$inst['vs:term_status'] = 'testing';
		$this->doc->add($inst);
		
		$inst = new RDFInstance($this->uri . '#Object', RDF::owl.'Class');
		$inst['rdfs:label'] = 'Aggregate objects (real, fictional, tangible, conceptual, singular, plural, and otherwise)';
		$inst['rdfs:comment'] = 'Abstract class constituting the ancestor of the more specific Spindle aggregate classes (Person, Place, Event, Collection, Thing).';
		$inst['vs:term_status'] = 'stable';
		$inst['rdfs:subClassOf'] = array(
			new RDFURI(RDF::owl . 'Thing'),
			);
		$inst['rdfs:isDefinedBy'] = new RDFURI($this->uri);
		$this->doc->add($inst);

		$inst = new RDFInstance($this->uri . '#Person', RDF::owl.'Class');
		$inst['rdfs:label'] = 'People and organisations';
		$inst['vs:term_status'] = 'testing';
		$inst['rdfs:subClassOf'] = array(
			new RDFURI($this->uri . '#Object'),
			new RDFURI(RDF::foaf . 'Agent'),
			);
		$inst['owl:disjointWith'] = array(
			new RDFURI($this->uri . '#Place'),
			new RDFURI($this->uri . '#Event'),
			new RDFURI($this->uri . '#Thing'),
			new RDFURI($this->uri . '#Collection'),
			);
		$inst['rdfs:isDefinedBy'] = new RDFURI($this->uri);
		$this->doc->add($inst);

		$inst = new RDFInstance($this->uri . '#Place', RDF::owl.'Class');
		$inst['rdfs:label'] = 'Geographical locations';
		$inst['vs:term_status'] = 'testing';
		$inst['rdfs:subClassOf'] = array(
			new RDFURI($this->uri . '#Object'),
			new RDFURI(RDF::gn . 'Feature'),
			);
		$inst['owl:disjointWith'] = array(
			new RDFURI($this->uri . '#Person'),
			new RDFURI($this->uri . '#Event'),
			new RDFURI($this->uri . '#Thing'),
			new RDFURI($this->uri . '#Collection'),
			);
		$inst['rdfs:isDefinedBy'] = new RDFURI($this->uri);
		$this->doc->add($inst);

		$inst = new RDFInstance($this->uri . '#Event', RDF::owl.'Class');
		$inst['rdfs:label'] = 'Temporal events';
		$inst['vs:term_status'] = 'testing';
		$inst['rdfs:subClassOf'] = array(
			new RDFURI($this->uri . '#Object'),
			new RDFURI('http://semanticweb.cs.vu.nl/2009/11/sem/Event'),
			);
		$inst['owl:disjointWith'] = array(
			new RDFURI($this->uri . '#Person'),
			new RDFURI($this->uri . '#Place'),
			new RDFURI($this->uri . '#Thing'),
			new RDFURI($this->uri . '#Collection'),
			);
		$inst['rdfs:isDefinedBy'] = new RDFURI($this->uri);
		$this->doc->add($inst);

		$inst = new RDFInstance($this->uri . '#Thing', RDF::owl.'Class');
		$inst['rdfs:label'] = 'Things';
		$inst['rdfs:comment'] = '“Things” are objects which are not people, places, events or collections; they may be real, imaginary, tangible or conceptual.';
		$inst['vs:term_status'] = 'testing';
		$inst['rdfs:subClassOf'] = array(
			new RDFURI($this->uri . '#Object'),
			);
		$inst['owl:disjointWith'] = array(
			new RDFURI($this->uri . '#Person'),
			new RDFURI($this->uri . '#Place'),
			new RDFURI($this->uri . '#Event'),
			new RDFURI($this->uri . '#Collection'),
			);
		$inst['rdfs:isDefinedBy'] = new RDFURI($this->uri);
		$this->doc->add($inst);

		return true;
	}

	protected function finaliseDoc($mime, $suffix)
	{
		$ext = strlen($this->request->explicitSuffix) ? $this->request->explicitSuffix : $suffix;
		$path = $this->uri;
		$params = implode(';', $this->uriParams);
		$inst = new RDFInstance($this->uri . $suffix . (strlen($params) ? '?' . $params : ''));
		$inst['rdf:type'] = array(new RDFURI(RDF::foaf.'Document'), new RDFURI(RDF::dcmit . 'Text'));
		$inst->{RDF::dcterms.'format'} = array(new RDFURI('http://purl.org/NET/mediatypes/' . $mime));
		if(isset($this->request->data['title']))
		{
			$inst['rdfs:label'] = new RDFString($this->request->data['title'] . ' (' . MIME::description($mime) . ')', 'en-GB');
		}
		$inst['foaf:primaryTopic'] = new RDFURI($this->uri);
		$this->doc->add($inst, 0);
	}
}

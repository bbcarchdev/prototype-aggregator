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

uses('mime');

require_once(dirname(__FILE__) . '/page.php');

class TroveProxyPage extends TrovePage
{
	protected $related = array();
	protected $objects = array();
	protected $stashed = false;
	protected $rs;
	
	public $uuid = null;
	public $fragment = null;
	public $kind = null;

	public function __construct()
	{
		parent::__construct();
		$this->defaults['order'] = 'adjusted_refs';
	}
	
	protected function getObject()
	{		
		$this->populateQuery($args);
		if(!parent::getObject())
		{
			return;
		}
		$args['permitRedirect'] = true;
		if(!isset($this->object) && isset($this->uuid))
		{
			$this->object = $this->model->objectForUuid($this->uuid, null, null, false, $args);
		}
		if(!isset($this->object))
		{
			return $this->error(Error::OBJECT_NOT_FOUND);
		}
		if(!$this->model->isStub($this->object))
		{
			$this->redirectToObject($this->object);
			return;
		}
		$obj = $this->model->firstObject($this->object);
		if(strcmp($obj->uuid, $this->uuid))
		{
			$this->redirectToObject($obj);
			return;
		}
		if(isset($this->request->data['kind']))
		{
			$this->kind = $this->request->data['kind'];
		}
		if(strcmp($obj->kind, $this->kind))
		{
			$this->redirectToObject($obj);
			return;
		}
		unset($args['permitRedirect']);
		$this->kind = $obj->kind;
		if(!strlen($this->fragment))
		{
			$this->fragment = '#' . $this->kind;
		}
		if(!isset($obj->{RDF::rdf.'about'}) || !count($obj->{RDF::rdf.'about'}))
		{
			$obj->{RDF::rdf.'about'} = array(new RDFURI($this->uri . $this->fragment));
		}

		$mode = 'all';
		$sub = $this->request->consume();
		if(strlen($sub))
		{
			switch($sub)
			{
			case 'stub':
			case 'children':
			case 'related':
			case 'tagged':
			case 'children':
			case 'matches':
				$mode = $sub;
				break;
			case 'stash':
				$mode = 'stash';
				$this->stashed = true;
				break;
			default:
				if(UUID::isUUID($sub))
				{
					$mode = 'single';
					break;
				}
				return $this->error(Error::OBJECT_NOT_FOUND);
			}
		}
		if($mode == 'all' || $mode == 'stub' || $mode == 'stash' || $mode == 'matches')
		{
			if(is_array($this->object))
			{
				foreach($this->object as $o)
				{
					$this->objects[] = $o;
				}
			}
			else
			{
				$this->objects[] = $this->object;
			}
		}
		else
		{
			$this->objects = array();
		}
		if($mode == 'matches' || $mode == 'single' || $mode == 'all')
		{
			/* Fetch all of the mapped instances */
			$this->related = $this->model->mappedObjectsByTypeForUuid($obj->uuid, $this->objects, $args);
			if($mode == 'single')
			{
				$found = false;
				foreach($this->related as $kind => $mapped)
				{
					foreach($mapped as $o)
					{
						if(isset($o->uuid) && !strcmp($o->uuid, $sub))
						{
							$this->related = array();
							$this->object = $o;
							$this->objects = array($o);
							$found = true;
							break 2;
						}
					}
				}
				if(!$found)
				{
					return $this->error(Error::OBJECT_NOT_FOUND);
				}
			}
							
		}
		if($mode == 'children' || $mode == 'all')
		{
			/* Fetch all of the children of this one */
			$this->related['child'] = $this->model->childrenOfObjectWithUuid($obj->uuid, $args);
		}
		if($mode == 'tagged' || $mode == 'all')
		{
			/* Fetch all of the objects tagged with this one */
			$this->rs = $this->model->objectsTaggedWithUuid($obj->uuid, $args);
		}
		if($mode == 'related' || $mode == 'all')
		{
			/* Fetch all of the directly-related objects */
			$this->related['related'] = $this->model->objectsRelatedTo($obj, $args);			
			foreach($this->related['related'] as $o)
			{
				if(!in_array($o, $this->objects)) $this->objects[] = $o;
			}
		}
		if($mode != 'stub' && $mode != 'single' && $mode != 'stash' && $mode != 'all' && $mode != 'matches')
		{
			unset($this->object);
			unset($obj);
		}
		unset($this->related['noMatch']);
		if(isset($obj))
		{
			$this->title = $obj->title();
		}
		return true;
	}
	
	protected function finaliseDoc($docType, $suffix)
	{
		$this->doc = new RDFDocument();
		if($this->stashed)
		{
			$this->doc->add($this->object->stash);
			return $this->doc;
		}
		$kind = 'object';
		$obj = null;
		if(isset($this->object))
		{
			$obj = $this->model->firstObject($this->object);
		}
		if(isset($this->kind))
		{
			$kind = $this->kind;
		}
		else if(isset($obj->kind))
		{
			$kind = $obj->kind;
		}
		else if(isset($this->args['kind']))
		{
			$kind = $this->args['kind'];
		}
		$this->updateUri();
		$this->contentLocation = $this->uri . $suffix;
		$inst = new RDFInstance($this->uri . $suffix);
		$inst['rdf:type'] = array(new RDFURI(RDF::foaf.'Document'), new RDFURI(RDF::dcmit . 'Text'));
		if(isset($obj))
		{
			$inst['foaf:primaryTopic'] = array($obj->subject());
			$inst['rdfs:label'] = new RDFString('Description of the ' . $kind . ' “' . $this->title . '” (' . MIME::description($docType) . ')', 'en');
			$inst['dct:created'] = new RDFDateTime($obj->created);
			$inst['dct:modified'] = new RDFDateTime($obj->modified);
			if(is_array($this->object))
			{
				foreach($this->object as $o)
				{
					$this->doc->add($o);
				}
			}
			else
			{
				$this->doc->add($this->object);
			}
		}
		$inst['dct:format'] = new RDFURI('http://purl.org/NET/mediatypes/' . $docType);
		$list = array();
		foreach($this->related as $kind => $objects)
		{
			foreach($objects as $obj)
			{
				if(is_array($obj))
				{
					foreach($obj as $o)
					{
						$this->doc->add($o);
					}
				}
				else
				{
					$this->doc->add($obj);
				}
/*				if($kind == 'child')
				{
					$list[] = new RDFURI($obj->path($this->request) . $this->request->explicitSuffix);					
				} */
			}
		}
/*		$inst['rdfs:seeAlso'] = $list; */
		if(is_object($this->rs))
		{
			$this->addResultSetToDocument($inst, $this->rs, $this->offset, $this->limit, $suffix);
		}
		$this->doc->add($inst, 0);
	}
}

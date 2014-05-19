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
	
class TroveListPage extends TrovePage
{
	protected $entityFile = 'proxy.php';
	protected $entityClass = 'TroveProxyPage';
	protected $queryArgs = null;
	protected $uriParams = array();

	public function process(Request $req)
	{
		if(isset($req->data['kind'])) $this->defaults['kind'] = $req->data['kind'];
		if(isset($req->data['superior?'])) $this->defaults['superior?'] = $req->data['superior?'];
		if(isset($req->data['coords?'])) $this->defaults['coords?'] = $req->data['coords?'];
		if(isset($req->data['entityFile'])) $this->entityFile = $req->data['entityFile'];
		if(isset($req->data['entityClass'])) $this->entityFile = $req->data['entityClass'];
		if(isset($req->data['title'])) $this->title = $req->data['title'];
		return parent::process($req);
	}
	
	protected function getObject()
	{
		if(!parent::getObject()) return;
		$args = array('offset' => $this->offset, 'limit' => $this->limit + 1, 'order' => $this->order);
		if(!$this->populateQuery($args))
		{
			return;
		}		
		$this->objects = $this->model->query($args);
		$this->queryArgs = $args;
		$this->doc = new RDFDocument();
		return true;
	}
	
	protected function populateQuery(&$args)
	{
		parent::populateQuery($args);
		$route = $this->request->consume();
		if(!strcmp($route, 'a-z'))
		{
			/* a-z browsing... */
			$route = $this->request->consume();
			if(strcmp($route, 'by'))
			{
				return $this->error(Error::OBJECT_NOT_FOUND);
			}
			$route = $this->request->consume();
			$q = $this->model->normaliseTitle($route);
			if(!strlen($q))
			{
				return $this->error(Error::OBJECT_NOT_FOUND, null, null, 'No filter specified');
			}
			$ch = substr($q, 0, 1);
			$args['sort_char'] = ctype_alpha($ch) ? $ch : '*';
			if(strlen($q) > 1)
			{
				$args['norm_title%'] = $q . '%'; 
			}
			$args['order'] = 'norm_title';
			return true;
		}
		else if(!strcmp($route, 'tagged'))
		{
			$tagged = array();
			for($tag = $this->request->consume(); $tag !== null; $tag = $this->request->consume())
			{
				$tag = trim(strtolower($tag));
				if(strlen($tag) && !in_array($tag, $tagged))
				{
					$tagged[] = $tag;
				}
			}
			if(count($tagged))
			{
				$this->title .= ' tagged with “' . implode('” and ”', $tagged) . '”';
			}
			$args['tags'] = $tagged;			
			return true;
		}
		else if(strlen($route))
		{
			if(!($this->forwardToProxy($route, $args)))
			{
				return false;
			}
		}
		return true;
	}

	protected function forwardToProxy($uuid, &$args)
	{
		$route = $this->request->data;
		$route['file'] = $this->entityFile;
		Loader::load($route);
		$class = $this->entityClass;
		$inst = new $class();
		$inst->kind = $this->kind;
		$inst->uuid = $uuid;
		if(isset($this->request->data['entityTemplate']))
		{
			$this->request->data['templateName'] = $this->request->data['entityTemplate'];					
		}
		else
		{
			unset($this->request->data['templateName']);
		}
		$inst->process($this->request);
		return false;
	}
	
	protected function finaliseDoc($mime, $suffix)
	{
		$ext = strlen($this->request->explicitSuffix) ? $this->request->explicitSuffix : $suffix;
		/* this->uri will be wrong if any parameters were processed */
		$this->updateUri();
		$path = $this->uri;
		$params = implode(';', $this->uriParams);
		$resourceUri = $this->uri . $suffix . (strlen($params) ? '?' . $params : '');
		$this->contentLocation = $resourceUri;
		$inst = new RDFInstance($resourceUri);
		$inst['rdf:type'] = array(new RDFURI(RDF::foaf.'Document'), new RDFURI(RDF::dcmit . 'Text'));
		$inst['dct:format'] = array(new RDFURI('http://purl.org/NET/mediatypes/' . $mime));
		$inst['dct:isPartOf'] = new RDFURI($this->uri);
		$topic = new RDFInstance($this->uri);
		$topic['rdf:type'] = new RDFURI('http://rdfs.org/ns/void#Dataset');
		if(isset($this->request->data['title']))
		{
			$inst['rdfs:label'] = new RDFString($this->title . ' (' . MIME::description($mime) . ')', 'en-GB');
			$topic['rdfs:label'] = new RDFString($this->title, 'en-GB');
		}
		if(strcmp($this->proxyUri, $this->request->pageUri))
		{
			$topic['http://rdfs.org/ns/void#subset'] = new RDFURI($this->proxyUri);
		}
		else
		{
			$topic['http://rdfs.org/ns/void#subset'] = new RDFURI($this->request->root);
		}
		switch(@$this->defaults['kind'])
		{
		case 'person':
		case 'place':
		case 'thing':
		case 'event':
		case 'collection':
			$topic['http://rdfs.org/ns/void#uriPattern'] = '^' . $this->proxyUri;
			break;
		default:
			$topic['http://rdfs.org/ns/void#uriPattern'] = '^' . $this->request->root . '(collections|people|places|events|things)/';
		}
			
		$inst['foaf:primaryTopic'] = new RDFURI($this->uri);
		$topic['foaf:homepage'] = new RDFURI($this->request->root);
		$this->doc->add($topic);
		$this->addResultSetToDocument($inst, $this->objects, $this->offset, $this->limit, $suffix);
		$this->doc->add($inst, 0);
	}
}

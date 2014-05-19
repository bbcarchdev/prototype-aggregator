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

require_once(MODULES_ROOT . 'trove/model.php');

class TrovePage extends Page
{
	protected $modelClass = 'Trove';
	protected $uri;
	protected $doc;
	protected $contentLocation = null;
	protected $uriParams = array();
	protected $supportedTypes = array(
		'application/rdf+xml',
		'application/xml',
		'text/xml',
		'text/html',
		'application/json',
		'text/plain',
		'text/turtle',
		'text/n3',
		'application/ld+json',
		'application/rdf+json',
		'application/x-rdf+json',
		'application/atom+xml'
		);
	protected $defaults = array();
	protected $offset;
	protected $limit;

	public function __construct()
	{
		parent::__construct();
		$this->defaults['order'] = 'modified';
		$this->defaults['offset'] = 0;
		$this->defaults['limit'] = 50;
		$this->defaults['kind'] = null;
		$this->defaults['parent'] = null;
		$this->defaults['parent?'] = false;
		$this->defaults['superior'] = null;
		$this->defaults['superior?'] = null;
		$this->defaults['coords?'] = null;
		$this->defaults['tags'] = null;
		$this->defaults['sort_char'] = null;
		$this->defaults['norm_title'] = null;
		$this->defaults['norm_title%'] = null;
		$this->defaults['text'] = null;
		$this->defaults['iri'] = null;
		$this->defaults['uri'] = null;
		$this->defaults['q'] = null;
	}
	
	protected function getObject()
	{
		if(!parent::getObject())
		{
			return;
		}
		$this->updateUri();
		return true;
	}

	protected function updateUri()
	{
		$this->uri = $this->request->pageUri;
		if(strlen($this->uri) > 1 && substr($this->uri, -1) == '/') $this->uri = substr($this->uri, 0, -1);
		if(!strcmp($this->uri, $this->request->root))
		{
			$this->uri .= 'index';
		}
	}

	protected function perform_GET($type)
	{
		if($type == 'text/html' || $type == 'text/xml' || $type == 'text/plain')
		{
			$this->finaliseDoc('application/rdf+xml', '.rdf');
		}
		else
		{
			$this->finaliseDoc($type, MIME::extForType($type));
		}
		if(isset($this->contentLocation))
		{
			$this->request->header('Content-Location', $this->contentLocation);
		}
		return parent::perform_GET($type);
	}

	protected function perform_GET_HTML($type = 'text/html')
	{
		return $this->perform_GET_RDF('text/xml');
	}
		
	protected function populateQuery(&$args)
	{
		if(!empty($this->request->query['offset']))
		{
			$this->request->query['offset'] = intval($this->request->query['offset']);
		}
		if(!empty($this->request->query['limit']))
		{
			$this->request->query['limit'] = intval($this->request->query['limit']);
		}
		foreach($this->defaults as $k => $v)
		{
			if(isset($this->request->query[$k]))
			{
				$v = $this->request->query[$k];
				$this->uriParams[$k] = rawurlencode($k) . '=' . rawurlencode($v);
				if(!strcmp($v, '*'))
				{
					unset($args[$k]);
				}
				else if(strlen($v))
				{
					$args[$k] = $v;
				}
			}
			else if($v !== null)
			{
				$args[$k] = $v;
			}
		}
		if(isset($args['q']))
		{
			$args['text'] = $args['q'];
			unset($args['q']);
		}
		if(isset($args['uri']))
		{
			$args['iri'] = $args['uri'];
			unset($args['uri']);
		}
		if(isset($args['kind']) && !is_array($args['kind']))
		{
			$args['kind'] = explode(',', $args['kind']);
		}
		if(isset($args['parent']))
		{
			$args['parent?'] = true;
		}
		$this->limit = $args['limit'];
		$this->offset = $args['offset'];
		$args['limit']++;
	}

	protected function finaliseDoc($mime, $ext)
	{
	}

	protected function addResultSetToDocument($inst, $rs, $offset, $limit, $suffix = '', $includeAnciliary = true)
	{
		assert(is_object($rs));
		$ext = strlen($this->request->explicitSuffix) ? $this->request->explicitSuffix : $suffix;
		$path = $this->uri;
		$params = implode(';', $this->uriParams);
		$total = $rs->total;
		if($offset)
		{
			$params = $this->uriParams;
			unset($params['offset']);
			$params = implode(';', $params);
			$uri = $path . $this->request->explicitSuffix . (strlen($params) ? '?' . $params : '');
			$inst['xhv:first'] = new RDFURI($uri);
			$this->links[] = array('rel' => 'first', 'href' => $uri);
			$prev = $offset - $limit;
			if($prev < 0) $prev = 0;
			$params = $this->uriParams;
			if($prev)
			{
				$params['offset'] = 'offset=' . $prev;
			}
			else
			{
				unset($params['offset']);
			}
			$params = implode(';', $params);
			$uri = $path . $this->request->explicitSuffix . (strlen($params) ? '?' . $params : '');			
			$inst['xhv:prev'] = new RDFURI($uri);
			$this->links[] = array('rel' => 'prev', 'href' => $uri);
		}
		if(isset($inst->{RDF::rdfs.'seeAlso'}))
		{
			$list = $inst->{RDF::rdfs.'seeAlso'};
		}
		else
		{
			$list = array();
		}
		$c = 0;
		foreach($rs as $obj)
		{
			if(is_array($obj) && isset($obj[0]))
			{
				$others = $obj;
				$obj = array_shift($others);
			}
			else
			{
				$others = array();
			}
			if(!isset($obj->uuid))
			{
				continue;
			}
			if(!$limit || $c < $limit)
			{
				$list[] = new RDFURI($obj->path($this->request) . $this->request->explicitSuffix);
				$this->doc->add($obj);
				if($includeAnciliary)
				{
					foreach($others as $obj)
					{
						$this->doc->add($obj);
					}
				}
			}
			$c++;
		}
		if($c > $total)
		{
			$total = $this->offset + $c;	
		}
		if($offset + count($list) < $total)
		{
			$params = $this->uriParams;
			$params['offset'] = 'offset=' . ($offset + count($rs) - 1);
			$params = implode(';', $params);
			$uri = $path . $this->request->explicitSuffix . (strlen($params) ? '?' . $params : '');
			$inst['xhv:next'] = new RDFURI($uri);
			$this->links[] = array('rel' => 'next', 'href' => $uri);
		}
		$inst['rdfs:seeAlso'] = $list;
	}

	protected function perform_GET_JSON($type = 'application/json')
	{
		return $this->serialise($type);
	}

	protected function perform_GET_RDFJSON($type = 'application/rdf+json')
	{
		return $this->serialise($type);
	}

	protected function serialise($type, $returnBuffer = false, $sendHeaders = true, $reportError = true)
	{
		$this->object = $this->doc;
		if($type == 'application/rdf+xml' || $type == 'text/xml' || $type == 'application/xml' || $type == 'text/html')
		{
			if($sendHeaders)
			{
				$this->request->header('Content-type', $type);
				$this->request->flush();
			}
			$xml = $this->doc->asXML(
				'<?xml version="1.0" encoding="UTF-8" ?>' . "\n" .
				'<?xml-stylesheet href="/templates/troveview/rdfxml.xsl" type="text/xsl" ?>' . "\n"
				);
			if($returnBuffer)
			{
				return $xml;
			}
			writeLn($xml);		
			return true;
		}
		else if($type == 'text/plain')
		{
			if($sendHeaders)
			{
				$this->request->header('Content-type', 'text/plain; charset=UTF-8');
				$this->request->header('Content-location', $this->uri . '.txt');
			}
			print_r($this->doc);
			return true;
		}
		return parent::serialise($type, $returnBuffer, $sendHeaders, $reportError);
	}
	
	protected function perform_PUT($type)
	{
		if($this->request->contentType == 'application/rdf+xml')
		{
			if(($data = file_get_contents('php://input')))
			{
				error_log('read ' . $data);
				if(($doc = RDF::documentFromXMLString($data)))
				{
					return $this->putObject($doc);
				}
			}
		}
		return $this->error(Error::UNSUPPORTED_MEDIA_TYPE, null, null, $this->request->contentType . ' is not supported by ' . get_class($this) . '::perform_PUT()');
	}
	
	protected function perform_POST($type)
	{
		header('Content-type: text/plain; charset=UTF-8');
		$ct = explode(';', $this->request->contentType);
/*		if(trim($ct[0]) == 'multipart/signed')
		{
			if($this->request->processMultipart())
			{
				return $this->perform_POST($type);
			}
			die('Unable to verify message');
			} */
		if(trim($ct[0]) == 'application/rdf+xml')
		{
			if(isset($this->request->certificate) && isset($this->request->publicKeyHash))
			{	
				$data = $this->request->postData;
				if($data === null)
				{
					$data = file_get_contents('php://input');
				}
				if(($doc = RDF::documentFromXMLString($data)))
				{
					return $this->postObject($doc);
				}
			}
			die('No certificate in request');
		}
		return parent::perform_POST($type);
	}
   
	protected function stubForObject($object)
	{
		$object = $this->model->firstObject($object);
		$stub = $this->model->stubForResource('urn:uuid:' . $object->uuid);
		if(strlen($stub))
		{
			if(($stubObj = $this->model->objectForUUID($stub)))
			{
				return $stubObj;
			}
		}
		return null;
	}

	protected function redirectToStub($object)
	{
		$object = $this->model->firstObject($object);
		if(($stubObj = $this->stubForObject($object)) !== null)
		{
			return $this->redirectToObject($stubObj);
		}
		return $this->error(Error::OBJECT_NOT_FOUND);
	}
	
	protected function redirectToObject($stubObj)		
	{
		$stubObj = $this->model->firstObject($stubObj);
		if(!$this->model->isStub($stubObj))
		{
			return $this->redirectToStub($stubObj);
		}
		$this->request->redirect($stubObj->path($this->request) . $this->explicitSuffix, 303);
	}

	protected function perform_GET_Atom($type = 'application/atom+xml')
	{
		$id = $this->request->absolutePage;
		if(substr($id, -1) == '/')
		{
			$id = substr($id, 0, -1);
		}
		$title = null;
		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		echo '<feed xmlns="http://www.w3.org/2005/Atom">' . "\n";
		if(isset($this->object))
		{
			$obj = $this->model->firstObject($this->object);
			$title = $this->object->title();
			echo "\t" . '<id>urn:uuid:' . $obj->uuid . '</id>' . "\n";
		}
		else
		{
			echo "\t" . '<id>' . _e($id) . '</id>' . "\n";
			if(isset($this->title))
			{
				$title = $this->title;
			}
			else if(isset($this->request->data['title']))
			{
				$title = $this->request->data['title'];
			}
		}
		echo "\t" . '<title>' . _e($title) . '</title>' . "\n";
		if(isset($this->rs))
		{
			$rs = $this->rs;
		}
		else if(isset($this->objects))
		{
			$rs = $this->objects;
		}
		else
		{
			$rs = null;
		}
		if($rs !== null)
		{
			foreach($rs as $obj)
			{
				$obj = $this->model->firstObject($obj);
				echo "\t" . '<entry>' . "\n";
				echo "\t\t" . '<id>urn:uuid:' . _e($obj->uuid) . '</id>' . "\n";
				echo "\t\t" . '<title>' . _e($obj->title()) . '</title>' . "\n";
				echo "\t\t" . '<link href="' . _e($obj->subject()) . '" />' . "\n";
				$d = $obj->description();
				if(strlen($d))
				{
					echo "\t\t" . '<summary>' . _e($d) . '</summary>' . "\n";
				}
				$i = $obj['foaf:depiction']->first();
				if(strlen($i) || strlen($d))
				{
					echo "\t\t". '<content type="xhtml">' . "\n";
					echo "\t\t\t" . '<div xmlns="http://www.w3.org/1999/xhtml"><p>' . "\n";
					if(strlen($i))
					{
						echo "\t\t\t\t" . '<img align="left" src="' . _e($i) . '" alt=""/>' . "\n";
					}
					if(strlen($d))
					{
						echo "\t\t\t\t" . _e($d) . "\n";
					}
					echo "\t\t\t" . '</p></div>' . "\n";
					echo "\t\t" . '</content>' . "\n";
				}			
				echo "\t" . '</entry>' . "\n";
			}
		}
		echo '</feed>' . "\n";
	}

}

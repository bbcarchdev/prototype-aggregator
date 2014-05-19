<?php

/* Copyright 2010-2011 Mo McRoberts.
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
 * An RDF document
 */

class RDFDocument implements ArrayAccess, ISerialisable
{
	public static $parseableTypes = array(
		'application/rdf+xml',
		);

	public $rdfInstanceClass = 'RDFInstance';	
	protected $subjects = array();
	protected $keySubjects = array();
	protected $namespaces = array();
	protected $qnames = array();
	public $xmlStylesheet = null;
	public $fileURI;
	public $primaryTopic;

	public function __construct($fileURI = null, $primaryTopic = null)
	{
		$this->fileURI = $fileURI;
		$this->primaryTopic = $primaryTopic;
	}

	public function parse($type, $content)
	{
		if($type == 'application/rdf+xml')
		{
			if($content instanceof DOMNode)
			{
				return $this->fromDOM($content);
			}
			$xml = simplexml_load_string($content);
			if(!is_object($xml))
			{
				return null;
			}
			$dom = dom_import_simplexml($xml);
			if(!is_object($dom))
			{
				return null;
			}
			$dom->substituteEntities = true;
			return $this->fromDOM($dom);
		}
		return false;
	}

	/* ISerialisable::serialise */
	public function serialise(&$type, $returnBuffer = false, $request = null, $sendHeaders = null)
	{
		if(!isset($request) || $returnBuffer)
		{
			$sendHeaders = false;
		}
		else if($sendHeaders === null)
		{
			$sendHeaders = true;
		}
		if($returnBuffer)
		{
			ob_start();
		}
		if($type == 'text/turtle')
		{
			if($sendHeaders)
			{
				$request->header('Content-type', $type);
			}			
			$output = $this->asTurtle();
			echo is_array($output) ? implode("\n", $output) : $output;
		}
		else if($type == 'application/rdf+xml')
		{
			if($sendHeaders)
			{
				$request->header('Content-type', $type);
			}			
			$output = $this->asXML();
			echo is_array($output) ? implode("\n", $output) : $output;
		}
		else if($type == 'application/json')
		{
			if($sendHeaders)
			{
				$request->header('Content-type', $type);
			}
			$output = $this->asJSONLD();
			echo is_array($output) ? implode("\n", $output) : $output;
		}
		else if($type == 'application/x-rdf+json')
		{
			$type = 'application/json';
			if($sendHeaders)
			{
				$request->header('Content-type', $type);
			}
			$output = $this->asJSON();
			echo is_array($output) ? implode("\n", $output) : $output;
		}
		else
		{
			if($returnBuffer)
			{
				ob_end_clean();
			}
			return false;
		}
		if($returnBuffer)
		{
			return ob_get_clean();
		}
		return true;
	}

	public function subjectsReferencing($primary)
	{
		$set = array();
		$subjects = $doc->subjects();
		foreach($subjects as $subj)
		{
			foreach($subj as $prop => $value)
			{
				if(strpos($prop, ':') !== false && is_array($value))
				{
					foreach($value as $v)
					{
						if($v instanceof RDFURI && $primary->hasSubject($v))
						{
							$set[] = $this->objectAsArray($subj);
							break 2;
						}
					}
				}
			}
		}
		return $set;
	}

	/* ArrayAccess::offsetGet() */
	public function offsetGet($key)
	{
		if(!strcasecmp($key, 'primaryTopic'))
		{
			return $this->primaryTopic();
		}
		return $this->subject($key, null, false);
	}

	/* ArrayAccess::offsetSet() */
	public function offsetSet($key, $what)
	{
		if($key !== null)
		{
			throw new Exception('Explicit keys cannot be specified in RDFDocument::offsetSet');
		}
		if(($what instanceof RDFInstance))
		{				
			$this->add($what);
			return true;
		}
		throw new Exception('Only RDFInstance instances may be assigned via RDFDocument::offsetSet');
	}

	/* ArrayAccess::offsetExists() */
	public function offsetExists($key)
	{
		if($this->offsetGet($key) !== null)
		{
			return true;
		}
		return false;
	}

	/* ArrayAccess::offsetUnset() */
	public function offsetUnset($key)
	{
		throw new Exception('Subjects may not be unset via RDFDocument::offsetUnset');
	}

	/* Promote a subject to the root of the document; in RDF/XML this
	 * means that it will appear as a child of the rdf:RDF element.
	 * Behaviour with other serialisations may vary.
	 */
	public function promote($subject)
	{
		if($subject instanceof RDFInstance)
		{
			$subject = $subject->subject();
		}
		$subject = strval($subject);
		if(!in_array($subject, $this->keySubjects))
		{
			$this->keySubjects[] = $subject;
		}
	}

	/* Locate an RDFInstance for a given subject. If $create is
	 * true, a new instance will be created (optionally a $type).
	 * Callers should explicitly invoke promote() if required.
	 */
	public function subject($uri, $type = null, $create = true)
	{
		if($uri instanceof RDFInstance)
		{
			$uri = $uri->subject();
		}
		$uri = strval($uri);
		if(isset($this->subjects[$uri]))
		{
			return $this->subjects[$uri];
		}
		foreach($this->subjects as $g)
		{
			if(isset($g->{RDF::rdf . 'about'}[0]) && !strcmp($g->{RDF::rdf . 'about'}[0], $uri))
			{
				return $g;
			}
			if(isset($g->{RDF::rdf . 'ID'}[0]) && !strcmp($g->{RDF::rdf . 'ID'}[0], $uri))
			{
				return $g;
			}
			if(isset($g->{RDF::rdf . 'nodeID'}[0]) && !strcmp($g->{RDF::rdf . 'nodeID'}[0], $uri))
			{
				return $g;
			}
		}
		if(!$create)
		{
			return null;
		}
		if($type === null && !strcmp($uri, $this->fileURI))
		{
			$type = RDF::rdf . 'Description';
		}
		$this->subjects[$uri] = new RDFInstance($uri, $type);
		return $this->subjects[$uri];
	}

	/* Merge all of the assertions in $subject with those already in the
	 * document.
	 */
	public function merge($subject, $pos = null)
	{
		if($subject instanceof RDFInstance)
		{
			$uri = strval($subject->subject());
		}
		else
		{
			$uri = strval($subject);
		}
		if(($s = $this->subject($uri, null, false)))
		{
			$s->merge($subject, $this);
			return $s;
		}
		if($subject instanceof RDFInstance)
		{
			if(strlen($uri))
			{
				if($pos !== null)
				{
					$rep = array($uri => $subject);
					array_splice($this->subjects, $pos, 0, $rep);
				}
				else
				{
					$this->subjects[$uri] = $subject;
				}
			}
			else
			{
				if($pos !== null)
				{
					array_splice($this->subjects, $pos, 0, array($subject));
				}
				else
				{
					$this->subjects[] = $subject;
				}
			}
			$valset = get_object_vars($subject);
			foreach($valset as $values)
			{
				if(is_array($values))
				{
					foreach($values as $val)
					{
						if($val instanceof RDFInstance)
						{
							$this->merge($val);
						}
					}
				}
			}
			return $subject;
		}
		return null;
	}

	/* Add an RDFInstance to a document at the top level. As merge(), but
	 * always invokes promote() on the result.
	 */
	public function add(RDFInstance $subject, $pos = null)
	{
		if(($inst = $this->merge($subject, $pos)))
		{
			$this->promote($inst);
		}
		return $inst;
	}

	/* Completely replace all assertions about a subject in the
	 * document with a new instance.
	 */
	public function replace(RDFInstance $graph, $addIfNotFound = true)
	{		
		$uri = strval($graph->subject());
		if(!strlen($uri))
		{
			if($addIfNotFound)
			{
				$this->subjects[] = $graph;
				$graph->refcount++;
				return $graph;
			}
			return null;
		}
		foreach($this->subjects as $k => $g)
		{
			if(isset($g->{RDF::rdf . 'about'}[0]) && !strcmp($g->{RDF::rdf . 'about'}[0], $uri))
			{
				$graph->refcount = $this->subjects[$k]->refcount;
				$this->subjects[$k] = $graph;
				return $graph;
			}
			if(isset($g->{RDF::rdf . 'ID'}[0]) && !strcmp($g->{RDF::rdf . 'ID'}[0], $uri))
			{
				$graph->refcount = $this->subjects[$k]->refcount;
				$this->subjects[$k] = $graph;
				return $graph;
			}
			if(isset($g->{RDF::rdf . 'nodeID'}[0]) && !strcmp($g->{RDF::rdf . 'nodeID'}[0], $uri))
			{
				$graph->refcount = $this->subjects[$k]->refcount;
				$this->subjects[$k] = $graph;
				return $graph;
			}
		}
		if($addIfNotFound)
		{
			$this->subjects[$uri] = $graph;
			$graph->refcount++;
			return $graph;
		}
		return null;
	}

	/* Return the RDFInstance which is either explicitly or implicitly the
	 * resource topic of this document.
	 */
	public function resourceTopic()
	{
		$top = $file = null;
		if(isset($this->fileURI))
		{
			$top = $file = $this->subject($this->fileURI, null, false);
			if($file)
			{
				return $file;
			}
		}
		foreach($this->subjects as $g)
		{
			if(isset($g->{RDF::rdf . 'type'}[0]) && !strcmp($g->{RDF::rdf . 'type'}[0], RDF::rdf . 'Description'))
			{
				return $g;
			}
		}
		foreach($this->subjects as $g)
		{
			return $g;
		}
	}		
	
	/* Return the RDFInstance which is either explicitly or implicitly the
	 * primary topic of this document.
	 */
	public function primaryTopic()
	{
		if(isset($this->primaryTopic))
		{
			return $this->subject($this->primaryTopic, null, false);
		}
		$top = $file = null;
		if(isset($this->fileURI))
		{
			$top = $file = $this->subject($this->fileURI, null, false);
			if(!isset($top->{RDF::foaf . 'primaryTopic'}))
			{
				$top = null;
			}
		}
		if(!$top)
		{
			foreach($this->subjects as $g)
			{
				if(isset($g->{RDF::rdf . 'type'}[0]) && !strcmp($g->{RDF::rdf . 'type'}[0], RDF::rdf . 'Description'))
				{
					$top = $g;
					break;
				}
			}			
		}
		if(!$top)
		{
			foreach($this->subjects as $g)
			{
				$top = $g;
				break;
			}
		}
		if(!$top)
		{
			return null;
		}
		if(isset($top->{RDF::foaf . 'primaryTopic'}[0]))
		{
			if($top->{RDF::foaf . 'primaryTopic'}[0] instanceof RDFInstance)
			{
				return $top->{RDF::foaf . 'primaryTopic'}[0];
			}
			$uri = strval($top->{RDF::foaf . 'primaryTopic'}[0]);
			$g = $this->subject($uri, null, false);
			if($g)
			{
				return $g;
			}
		}
		if($file)
		{
			return $file;
		}
		return $top;
	}

	/* Explicitly register a namespace with a given prefix */
	public function ns($uri, $suggestedPrefix)
	{
		if(!isset($this->namespaces[$uri]))
		{
			$this->namespaces[$uri] = $suggestedPrefix;
		}
		return $this->namespaces[$uri];
	}

	/* Given a URI, generate a prefix:short form name */
	public function namespacedName($qname, $generate = true)
	{
		RDF::ns();
		$qname = strval($qname);
		if(!isset($this->qnames[$qname]))
		{
			if(false !== ($p = strrpos($qname, '#')))
			{
				$ns = substr($qname, 0, $p + 1);
				$lname = substr($qname, $p + 1);
			}
			else if(false !== ($p = strrpos($qname, ' ')))
			{
				$ns = substr($qname, 0, $p);
				$lname = substr($qname, $p + 1);
			}
			else if(false !== ($p = strrpos($qname, '/')))
			{
				$ns = substr($qname, 0, $p + 1);
				$lname = substr($qname, $p + 1);
			}
			else
			{
				return $qname;
			}
			if(!strcmp($ns, XMLNS::xml))
			{
				return 'xml:' . $lname;
			}
			if(!strcmp($ns, XMLNS::xmlns))
			{
				return 'xmlns:' . $lname;
			}
			if(!isset($this->namespaces[$ns]))
			{
				if(isset(RDF::$namespaces[$ns]))
				{
					$this->namespaces[$ns] = RDF::$namespaces[$ns];
				}
				else if($generate)
				{
					$this->namespaces[$ns] = 'ns' . count($this->namespaces);
				}
				else
				{
					return $qname;
				}
			}
			if(!strlen($lname))
			{
				return $qname;
			}
			$pname = $this->namespaces[$ns] . ':' . $lname;
			$this->qnames[$qname] = $pname;
		}
		return $this->qnames[$qname];
	}
	
	/* Serialise a document as Turtle */
	public function asTurtle()
	{
		$turtle = array();
		foreach($this->subjects as $g)
		{
			$x = $g->asTurtle($this);
			if(is_array($x))
			{
				$x = implode("\n", $x);
			}
			$turtle[] = $x . "\n";
		}
		if(count($this->namespaces))
		{
			array_unshift($turtle, '');
			foreach($this->namespaces as $ns => $prefix)
			{
				array_unshift($turtle, '@prefix ' . $prefix . ': <' . $ns . '>');
			}
		}
		return $turtle;
	}

	public function subjects($all = false)
	{
		$list = array();
		foreach($this->subjects as $subj)
		{
			if($all || $this->isKeySubject($subj))
			{
				$list[] = $subj;
			}
		}
		return $list;
	}

	protected function isKeySubject($i)
	{
		if($i instanceof RDFInstance)
		{
			if($i->refcount == 0 || $i->refcount > 1)
			{
				return true;
			}
			$i = $i->subjects();
		}
		if(!is_array($i))
		{
			$i = array($i);
		}
		foreach($i as $u)
		{
			if(in_array(strval($u), $this->keySubjects))
			{
				return true;
			}
		}
		return false;
	}

	/* Return a nice of nicely-formatted HTML representing the document */
	public function dump()
	{
		$result = array('<dl>');
		foreach($this->subjects as $g)
		{
			$result[] = $g->dump($this);
		}
		$result[] = '</dl>';
		return implode("\n", $result);
	}

	/* Serialise a document as RDF/XML */
	public function asXML($leader = null)
	{
		if($leader === null)
		{
			$leader = '<?xml version="1.0" encoding="UTF-8" ?>';
			if(isset($this->xmlStylesheet))
			{				
				$type = null;
				if(isset($this->xmlStylesheet['type']))
				{
					$type = ' type="' . _e($this->xmlStylesheet['type']) . '"';
				}
				$leader .= "\n" . '<?xml-stylesheet href="' . _e($this->xmlStylesheet['href']) . '"' . $type . '?>';
			}
		}
		$xml = array();
		foreach($this->subjects as $g)
		{
			if(!$this->isKeySubject($g))
			{
				continue;
			}
			$x = $g->asXML($this);
			if(is_array($x))
			{
				$x = implode("\n", $x);
			}
			$xml[] = $x . "\n";
		}
		$root = $this->namespacedName(RDF::rdf . 'RDF');
		$nslist = array();
		foreach($this->namespaces as $ns => $prefix)
		{
			$nslist[] = 'xmlns:' . $prefix . '="' . _e($ns) . '"';
		}
		array_unshift($xml, '<' . $root . ' ' . implode(' ', $nslist) . '>' . "\n");
		$xml[] = '</' . $root . '>';
		if(strlen($leader))
		{
			array_unshift($xml, $leader);
		}
		return implode("\n", $xml);
	}
	
    /* Serialise a document as JSON */
	public function asJSON()
	{
		$array = array();
		foreach($this->subjects as $subj)
		{
			if(!$this->isKeySubject($subj)) continue;
			$x = $subj->asArray();
			$array[] = $x['value'];
		}
		return str_replace('\/', '/', json_encode($array));
	}

    /* Serialise a document as JSON-LD */
	public function asJSONLD()
	{
		$array = array();
		foreach($this->subjects as $subj)
		{
			if(!$this->isKeySubject($subj)) continue;
			$x = $subj->asJSONLD($this);
			$array[] = $x;
		}
		return str_replace('\/', '/', json_encode($array));
	}

	/* Import sets of triples from an (RDF/XML) DOMElement instance */
	public function fromDOM($root)
	{
		$node = $root->firstChild;
		while(is_object($node))
		{
			while(!($node instanceof DOMElement))
			{
				$node = $node->nextSibling;
				if(!is_object($node)) return;
			}
			$g = null;
			if(isset(RDF::$ontologies[$node->namespaceURI]))
			{
				$g = call_user_func(array(RDF::$ontologies[$node->namespaceURI], 'rdfInstance'), $node->namespaceURI, $node->localName);
			}
			if(!$g)
			{
				$g = new RDFInstance();
			}
			$k = count($this->subjects);
			$g->fromDOM($node, $this);
			$mg = $this->merge($g, $k);
			$mg->refcount++;
			$this->promote($g);
			$g->transform();
			$node = $node->nextSibling;
		}
		return true;
	}

	public function __isset($name)
	{
		if($name == 'subjects' || $name == 'namespaces')
		{
			return true;
		}
		$obj = $this->subject($name, null, false);
		return is_object($obj);
	}
	
	public function __get($name)
	{
		if($name == 'subjects')
		{
			return $this->subjects;
		}
		if($name == 'namespaces')
		{
			return $this->namespaces;
		}
		return $this->subject($name, null, false);
	}
}

/* A set of triples */
class RDFTripleSet
{
	public $triples = array();
	public $fileURI;
	public $primaryTopic;

	/* Add the contents of the RDF/XML DOM tree to the set */
	public function fromDOM($root)
	{
		$node = $root->firstChild;
		while(is_object($node))
		{
			while(!($node instanceof DOMElement))
			{
				$node = $node->nextSibling;
				if(!is_object($node)) return;
			}
			$this->triplesFromNode($node);
			$node = $node->nextSibling;
		}
	}

	/* Return the array of triples with the given subject */
	public function subject($uri)
	{
		if(is_string($uri))
		{
			$uri = new RDFURI($uri, $this->fileURI);
		}
		$uri = strval($uri);
		echo "[Looking for $uri]\n";
		if(isset($this->triples[$uri]))
		{
			echo "[Found triples with $uri as subject]\n";
			return $this->triples[$uri];
		}
		print_r($this);
		echo "[Failed to find $uri]\n";
	}
		
	/* Return the array of triples which have the primary topic of the document as their subject */
	public function primaryTopic()
	{
		if(isset($this->primaryTopic))
		{
			if(($triples = $this->subject($this->primaryTopic)))
			{
				return $triples;
			}
		}
		$top = $file = null;
		if(isset($this->fileURI))
		{
			$top = $file = $this->subject($this->fileURI);
			$foundPT = false;
			if(isset($top))
			{
				foreach($top as $triple)
				{
					if(!strcmp($triple->predicate, RDF::foaf . 'primaryTopic'))
					{
						$foundPT = true;
						break;
					}
				}
			}
			if(!$foundPT)
			{
				$top = null;
			}
		}
		if(!$top)
		{
			foreach($this->triples as $g)
			{
				$haveDesc = false;
				foreach($g as $triple)
				{
					if(!strcmp($triple->predicate, RDF::rdf.'type') && strcmp($triple->object, RDF::rdf.'Description'))
					{
						$haveDesc = true;
						break;
					}
				}
				if(!$haveDesc)
				{
					$top = $g;
					break;
				}
			}			
		}
		if(!$top)
		{
			foreach($this->triples as $g)
			{
				$top = $g;
				break;
			}
		}
		if(!$top)
		{
			return null;
		}
		$uri = null;
		foreach($top as $triple)
		{
			if(!strcmp($triple->predicate, RDF::foaf.'primaryTopic'))
			{
				$uri = strval($triple->object);
				break;
			}
		}
		if(strlen($uri))
		{		   
			$g = $this->subject($uri);
			if($g)
			{
				return $g;
			}
		}
		if($file)
		{
			return $file;
		}
		return $top;
	}

	/* Add the contents of the given instance node to the set */
	protected function triplesFromNode($root)
	{
		$subject = null;
		$set = array();

		foreach($root->attributes as $attr)
		{
			$predicate = XMLNS::fqname($attr);
			$v = strval($attr->value);
			if($attr->namespaceURI == RDF::rdf)
			{
				if($attr->localName == 'about' || $attr->localName == 'resource')
				{
					$v = new RDFURI($v, $this->fileURI);
					$subject = strval($v);
				}
				else if($attr->localName == 'ID')
				{
					$v = new RDFURI('#' . $v, $this->fileURI);
					$subject = strval($v);
				}
				else if($attr->localName == 'nodeID')
				{
					$v = new RDFURI('_:' . $v, $this->fileURI);
					$subject = strval($v);
				}
			}
			$set[] = new RDFTriple(null, $predicate, $v);
		}
		/* Parse the children of this node, if any */
		$node = $root->firstChild;		
		while(is_object($node))
		{
			while(!($node instanceof DOMElement))
			{
				$node = $node->nextSibling;
				if(!is_object($node)) break;;
			}
			if(!is_object($node)) break;
			$parseType = null;
			$type = null;
			$nattr = 0;			
			if($node->hasAttributes())
			{
				foreach($node->attributes as $attr)
				{
					if($attr->namespaceURI == 'http://www.w3.org/1999/02/22-rdf-syntax-ns#' &&
					   $attr->localName == 'datatype')
					{
						$type = $attr->value;
					}
					else if($attr->namespaceURI == 'http://www.w3.org/1999/02/22-rdf-syntax-ns#' &&
							$attr->localName == 'parseType')
					{
						$parseType = $attr->value;
						$nattr++;
					}
					else
					{
						$nattr++;
					}
				}
			}
			$parseType = strtolower($parseType);
			if($node->hasChildNodes() || $parseType == 'literal')
			{
				/* Might be a literal, a complex literal, or a graph */
				$child = $node->firstChild;
				if($parseType == 'literal' || ($child instanceof DOMCharacterData && !$child->nextSibling))
				{
					$value = $child->textContent;
					if($parseType == 'literal')
					{
						$v = new RDFXMLLiteral();
					}
					else if(strlen($type) || $nattr)
					{
						if($type == 'http://www.w3.org/2001/XMLSchema#dateTime')
						{
							$v = new RDFDateTime();
						}
						else
						{
							$v = new RDFComplexLiteral();
						}
					}
					else
					{
						$v = $value;
					}
					if(is_object($v))
					{
						$v->fromDOM($node);
					}
					$set[] = new RDFTriple(null, XMLNS::fqname($node), $v);
				}
				else
				{
					$v = null;
					$gnode = $node->firstChild;
					while(is_object($gnode))
					{
						while(!($gnode instanceof DOMElement))
						{
							$gnode = $gnode->nextSibling;
							if(!is_object($gnode)) break;
						}
						if(!is_object($gnode)) break;
						$childSubj = $this->triplesFromNode($gnode);
						if(strlen($childSubj))
						{
							$set[] = new RDFTriple(null, XMLNS::fqname($node), $childSubj);
						}
						$gnode = $gnode->nextSibling;
					}
				}
			}
			else
			{
				/* If there's only one attribute and it's rdf:resource, we
				 * can compress the whole thing to an RDFURI instance.
				 */
				$uri = null;
				foreach($node->attributes as $attr)
				{
					if($uri !== null)
					{
						$uri = null;
						break;
					}
					if($attr->namespaceURI != 'http://www.w3.org/1999/02/22-rdf-syntax-ns#' ||
					   ($attr->localName != 'resource' && $attr->localName != 'nodeID'))
					{
						break;
					}
					if($attr->localName == 'resource')
					{
						$uri = $attr->value;
					}
					else
					{
						$uri = '_:' . $attr->value;
					}
				}
				if($uri !== null)
				{
					/* Do we have an rdf:resource attribute? */
					$set[] = new RDFTriple(null, XMLNS::fqname($node), new RDFURI($uri, $this->fileURI));
				}
				else
				{
					/* No... it's a whole other set of triples */
					$childSubj = $this->triplesFromNode($node);
					if($childSubj !== null)
					{
						$set[] = new RDFTriple(null, XMLNS::fqname($node), $childSubj);
					}
				}
			}
			$node = $node->nextSibling;
		}
		/* Generate a bnode subject if we need one */
		if($subject === null)
		{
			$subject = '_:' . uniqid();
		}
		$subject = new RDFURI($subject, $this->fileURI);
		$subjectKey = strval($subject);
		/* Add all of the triples to our overall set */
		foreach($set as $k => $triple)
		{
			if($triple->subject === null)
			{
				$triple->subject = $subject;
			}
			$this->triples[$subjectKey][] = $triple;
		}
		return $subject;
	}	
}

/* A triple: a subject, a predicate, and an object. The object may be an
 * instance, but the subject and predicate are always URIs.
 */

class RDFTriple
{
	public $subject;
	public $predicate;
	public $object;

	public function __construct($subject, $predicate, $object)
	{
		$this->subject = $subject;
		$this->predicate = $predicate;
		$this->object = $object;
	}

	public function subject()
	{
		if($this->subject instanceof RDFURI || $this->subject === null)
		{
			return $this->subject;
		}
		return $this->coerce($this->subject);
	}

	public function predicate()
	{
		if($this->predicate instanceof RDFURI || $this->predicate === null)
		{
			return $this->predicate;
		}
		return $this->coerce($this->predicate);
	}		

	/* Ensure that $thing is a RDFURI */
	protected function coerce(&$thing)
	{
		if($thing instanceof RDFURI)
		{
			return $thing;
		}
		if($thing instanceof RDFInstance)
		{
			$thing = $thing->subject();
		}
		else
		{
			$thing = new RDFURI(strval($thing));
		}
		return $thing;
	}
}

/* An RDF instance: an object representing a subject, where predicates are
 * properties, and objects are property values. Every property is a stringified
 * URI, and its native value is an indexed array.
 */
abstract class RDFInstanceBase implements ArrayAccess
{
	public $refcount = 0;
	protected $localId;

	public function __construct($uri = null, $type = null)
	{
		if(strlen($uri))
		{
			$this->{RDF::rdf . 'about'}[] = new RDFURI($uri);
		}
		if(strlen($type))
		{
			$this->{RDF::rdf . 'type'}[] = new RDFURI($type);
		}
		$this->localId = new RDFURI('#' . uniqid());
	}

	/* An instance's "value" is the URI  of its subject */
	public function __toString()
	{
		return strval($this->subject());
	}

	public function __set($predicate, $value)
	{
		if(strpos($predicate, ':') === false)
		{
			$this->{$predicate} = $value;
			return;
		}
		if(is_array($value))
		{
			$this->{$predicate} = $value;
			return;
		}
		$this->{$predicate}[] = $value;
	}

	public function predicateObjectList()
	{
		$list = array();
		$v = get_object_vars($this);
		foreach($v as $k => $v)
		{
			if(strpos($predicate, ':') === false || !is_array($values))
			{
				continue;
			}
			$list[$k] = $v;
		}
		return $list;
	}

	/**** ArrayAccess methods ****/

	public function offsetExists($offset)
	{
		$offset = $this->translateQName($offset);
		if(isset($this->{$offset}) && is_array($this->{$offset}) && count($this->{$offset}))
		{
			return true;
		}
	}
	
	public function offsetGet($offset)
	{
		return $this->all($offset);
	}
	
	public function offsetSet($offset, $value)
	{
		if($offset === null)
		{
			/* $inst[] = $value; -- meaningless unless $value is a triple */
			if($value instanceof RDFTriple)
			{
				$offset = strval($value->predicate);
				$value = $value->object;
			}
			else
			{
				return;
			}
		}
		else
		{
			$offset = $this->translateQName($offset);
		}
		if(is_array($value))
		{
			$this->{$offset} = $value;
		}
		else if($value instanceof RDFSet)
		{
			$this->{$offset} = $value->values();
		}
		else
		{
			$this->{$offset} = array($value);
		}
	}

	public function offsetUnset($offset)
	{
		$offset = $this->translateQName($offset);
		unset($this->{$offset});
	}

	/* If this instance is a $type, return true. If $type is an instance,
	 * we compare our rdf:type against its subject, allowing, for example:
	 *
	 * $class = $doc['http://purl.org/example#Class'];
	 * if($thing->isA($class)) ...
	 */
	public function isA($type)
	{		
		if(is_string($type))
		{
			$type = $this->translateQName($type);
		}
		else if($type instanceof RDFInstance)
		{
			$type = strval($type->subject());
		}
		if(isset($this->{RDF::rdf . 'type'}))
		{
			foreach($this->{RDF::rdf . 'type'} as $t)
			{
				if(!strcmp($t, $type))
				{
					return true;
				}
			}
		}
		return false;
	}

	/* Merge the assertions in $source into this instance. */
	public function merge(RDFInstance $source, RDFDocument $doc = null)
	{
		$this->refcount += $source->refcount;
		foreach($source as $prop => $values)
		{
			if(!is_array($values)) continue;
			foreach($values as $value)
			{
				$match = false;
				if($value instanceof RDFInstance && $doc)
				{
					$doc->merge($value);
				}
				if(isset($this->{$prop}))
				{
					foreach($this->{$prop} as $val)
					{
						if($val == $value)
						{
							$match = true;
							break;
						}
					}
				}
				if(!$match)
				{
					$this->{$prop}[] = $value;
				}
			}
		}
		return $this;
	}

	/* Return the first value for the given predicate */
	public function first($key)
	{
		$key = $this->translateQName($key);
		if(isset($this->{$key}) && count($this->{$key}))
		{
			return $this->{$key}[0];
		}
		return null;
	}
	
	/* Return the values of a given predicate */
	public function all($key, $nullOnEmpty = false)
	{
		if(!is_array($key)) $key = array($key);
		$values = array();
		foreach($key as $k)
		{
			$k = $this->translateQName($k);
			if(isset($this->{$k}))
			{
				if(is_array($this->{$k}))
				{
					foreach($this->{$k} as $value)
					{
						$values[] = $value;
					}
				}
				else
				{
					$values[] = $this->{$k};
				}
			}
		}
		if(count($values))
		{
			return new RDFSet($values);
		}
		if($nullOnEmpty)
		{
			return null;
		}
		return new RDFSet();
	}

	/* Return the first URI this instance claims to have
	 * as a subject.
	 */
	public function subject()
	{
		if(null !== ($s = $this->first(RDF::rdf . 'about')))
		{
			return $s;
		}
		if(null !== ($s = $this->first(RDF::rdf . 'ID')))
		{
			return $s;
		}
		if(null !== ($s = $this->first(RDF::rdf . 'nodeID')))
		{
			return $s;
		}
		return $this->localId;
	}

	/* Return the set of URIs this instance has as subjects */
	public function subjects()
	{
		$subjects = array();
		if(isset($this->{RDF::rdf . 'about'}))
		{
			foreach($this->{RDF::rdf . 'about'} as $u)
			{
				$subjects[] = $u;
			}
		}
		if(isset($this->{RDF::rdf . 'ID'}))
		{
			foreach($this->{RDF::rdf . 'ID'} as $u)
			{
				$subjects[] = $u;
			}
		}
		if(isset($this->{RDF::rdf . 'nodeID'}))
		{
			foreach($this->{RDF::rdf . 'nodeID'} as $u)
			{
				$subjects[] = $u;
			}
		}
		if(!count($subjects))
		{
			$subjects[] = $this->localId;
		}
		return $subjects;
	}

	public function hasSubject($uri)
	{
		$uri = strval($uri);
		$list = $this->subjects();
		foreach($list as $v)
		{
			if(!strcmp($v, $uri))
			{
				return true;
			}
		}
		return false;
	}

	/* Format an RDFURI for output as Turtle */
	protected function turtleURI($doc, $v)
	{
		$v = strval($v instanceof RDFInstance ? $v->subject() : $v);
		if($v[0] == '#')
		{
			return '_:' . substr($v, 1);
		}
		$vn = $doc->namespacedName($v, false);
		if(!strcmp($vn, $v))
		{
			return '<' . $v . '>';
		}
		return $vn;
	}
	
	/* Serialise this instance as Turtle */
	public function asTurtle($doc)
	{
		$turtle = array();
		$about = $this->subjects();
		if(count($about))
		{
			$first = array_shift($about);
			$turtle[] = $this->turtleURI($doc, $first);
		}
		if(isset($this->{RDF::rdf . 'type'}))
		{
			$types = $this->{RDF::rdf . 'type'};
			$tlist = array();
			foreach($types as $t)
			{
				$tlist[] = $doc->namespacedName(strval($t));
			}
			$turtle[] = "\t" . 'a ' . implode(' , ', $tlist) . ' ;';
		}
		if(count($about))
		{
			$tlist = array();
			foreach($about as $u)
			{
				$tlist[] = $this->turtleURI($doc, $u);
			}
			$turtle[] = "\t" . 'rdf:about ' . implode(' , ', $tlist) . ' ;';
		}
		$props = get_object_vars($this);
		$c = 0;
		foreach($props as $name => $values)
		{
			if(strpos($name, ':') === false) continue;
			if(!is_array($values)) continue;
			if(!strcmp($name, RDF::rdf . 'about') || !strcmp($name, RDF::rdf . 'ID') || !strcmp($name, RDF::rdf . 'type') || !strcmp($name, RDF::rdf . 'nodeID'))
			{
				continue;
			}
			if(!count($values))
			{
				continue;
			}
			$nname = $doc->namespacedName($name, false);
			$vlist = array();
			foreach($values as $v)
			{
				if(is_string($v) || $v instanceof RDFComplexLiteral)
				{
					$suffix = null;
					if(is_object($v))
					{
						if(isset($v->{RDF::rdf . 'datatype'}) && count($v->{RDF::rdf . 'datatype'}))
						{
							$suffix = '^^' . $doc->namespacedName($v->{RDF::rdf . 'datatype'}[0]);
						}
						else if(isset($v->{XMLNS::xml . ' lang'}) && count($v->{XMLNS::xml . ' lang'}))
						{
							$suffix = '@' . $v->{XMLNS::xml . ' lang'}[0];
						}
						$v = strval($v);
					}
					if(strpos($v, "\n") !== false || strpos($v, '"') !== false)
					{
						$vlist[] = '"""' . $v . '"""' . $suffix;
					}
					else
					{
						$vlist[] = '"' . $v . '"' . $suffix;
					}
				}
				else if($v instanceof RDFURI || $v instanceof RDFInstance)
				{
					$vlist[] = $this->turtleURI($doc, $v);
				}
			}
			if(!strcmp($nname, $name))
			{
				$nname = '<' . $name . '>';
			}
			$turtle[] = "\t" . $nname . ' ' . implode(" ,\n\t\t", $vlist) . ' ;';
		}
		$last = array_pop($turtle);
		$turtle[] = substr($last, 0, -1) . '.';
		return $turtle;
	}

	/* Transform this instance into a native array which can itself be
	 * serialised as JSON to result in RDF/JSON.
	 */
	public function asArray()
	{
		$array = array();
		$props = get_object_vars($this);
		foreach($props as $name => $values)
		{
			if(strpos($name, ':') === false) continue;
			if(!is_array($values)) continue;
			$array[$name] = array();
			foreach($values as $v)
			{
				if(is_object($v))
				{
					$array[$name][] = $v->asArray();
				}
				else
				{
					$array[$name][] = array('type' => 'literal', 'value' => strval($v));
				}
			}
		}
		return array('type' => 'node', 'value' => $array);
	}

	/* Transform this instance into a native array which can itself be
	 * serialised as JSON to result in JSON-LD.
	 */
	public function asJSONLD($doc)
	{
		$array = array('@context' => array(), '@' => null, 'a' => null);
		$isArray = array();
		$props = get_object_vars($this);
		$up = array();
		$bareProps = RDF::barePredicates();
		$uriProps = RDF::uriPredicates();
		foreach($props as $name => $values)
		{
			if(strpos($name, ':') === false) continue;
			if(!is_array($values)) continue;
			if(!strcmp($name, RDF::rdf.'about'))
			{
				$name = $kn = '@';
			}
			else if(!strcmp($name, RDF::rdf.'type'))
			{
				$name = $kn = 'a';
			}
			else if(($kn = array_search($name, $bareProps)) !== false)
			{
				if(!isset($array['@context'][$kn]))
				{
					$array['@context'][$kn] = $name;
				}
			}
			else
			{
				$kn = $doc->namespacedName($name, true);
				$x = explode(':', $kn, 2);
				if(count($x) == 2 && !isset($array['@context'][$x[0]]))
				{
					$ns = array_search($x[0], $doc->namespaces);
					$array['@context'][$x[0]] = $ns;
				}
			}
			foreach($values as $v)
			{				
				if($v instanceof RDFURI)
				{
					$vn = $doc->namespacedName($v, false);				
				}
				if(is_object($v))
				{
					$value = $v->asJSONLD($doc);
				}
				else
				{
					$value = strval($v);
				}
				if(($kn == '@' || $kn == 'a' || in_array($name, $uriProps)) && is_array($value))
				{
					if($kn != '@' && $kn != 'a')
					{
						$up[$name] = $kn;
					}
					if(isset($value['@uri']))
					{
						$value = $value['@uri'];
					}
				}
				if(isset($array[$kn]))
				{
					if(empty($isArray[$kn]))
					{
						$array[$kn] = array($array[$kn]);
						$isArray[$kn] = true;
					}
					$array[$kn][] = $value;
				}
				else
				{
					$array[$kn] = $value;
				}
			}
		}
		if(count($up))
		{
			$array['@context']['@coerce']['xsd:anyURI'] = array();
			foreach($uriProps as $uri)
			{
				if(isset($up[$uri]))
				{
					$array['@context']['@coerce']['xsd:anyURI'][] = $up[$uri];
				}
			}
		}
		if(!isset($array['@']))
		{
			unset($array['@']);
		}
		if(!isset($array['a']))
		{
			unset($array['a']);
		}
		return $array;
	}

	/* Transform this instance as a string or array of strings which represent
	 * the instance as RDF/XML.
	 */
	public function asXML($doc)
	{
		if(isset($this->{RDF::rdf . 'type'}))
		{
			$types = $this->{RDF::rdf.'type'};
		}
		else
		{
			$types = array();
		}
		$primaryType = null;
		foreach($types as $k => $t)
		{
			$nsn = $doc->namespacedName($t);
			$x = explode(':', $nsn);
			if(preg_match('!^[a-z_]([a-z0-9_.-])*$!i', $x[1]))
			{
				$primaryType = $nsn;
				unset($types[$k]);
				break;
			}
		}
		if($primaryType === null)
		{
			$primaryType = 'rdf:Description';
		}
		if(isset($this->{RDF::rdf . 'about'}))
		{
			$about = $this->{RDF::rdf . 'about'};
		}
		else
		{
			$about = array();
		}
		$rdf = array();
		if(count($about))
		{
			$top = $primaryType . ' rdf:about="' . _e(array_shift($about)) . '"';
		}
		else
		{
			$top = $primaryType;
		}
		$rdf[] = '<' . $top . '>';
		$props = get_object_vars($this);
		$c = 0;
		foreach($props as $name => $values)
		{
			if(strpos($name, ':') === false) continue;
			if($name == RDF::rdf . 'about')
			{
				$values = $about;
			}
			else if($name == RDF::rdf . 'type')
			{
				$values = array_values($types);
			}
			if(!is_array($values) || !count($values))
			{
				continue;
			}
			$pname = $doc->namespacedName($name);
			foreach($values as $v)
			{
				$c++;
				if($v instanceof RDFURI)
				{
					$rdf[] = '<' . $pname . ' rdf:resource="' . _e($v) . '" />';
				}
				else if($v instanceof RDFInstance)
				{
					if($v->refcount > 1)
					{
						$rdf[] = '<' . $pname . ' rdf:resource="' . _e($v->subject()) . '" />';
					}
					else
					{
						$rdf[] = '<' . $pname . '>';
						$val = $v->asXML($doc);
						if(is_array($val))
						{
							$val = implode("\n", $val);
						}
						$rdf[] = $val;
						$rdf[] = '</' . $pname . '>';
					}
				}
				else if(is_object($v))
				{
					$props = get_object_vars($v);
					$attrs = array();
					foreach($props as $k => $values)
					{
						if($k == 'value')
						{
							continue;
						}
						$attrs[] = $doc->namespacedName($k) . '="' . _e($values[0]) . '"';
					}
					if(!($v instanceof RDFXMLLiteral))
					{
						$v = _e($v);
					}
					$rdf[] = '<' . $pname . (count($attrs) ? ' ' . implode(' ', $attrs) : '') . '>' . $v . '</' . $pname . '>';
				}
				else
				{
					$rdf[] = '<' . $pname . '>' . _e($v) . '</' . $pname . '>';
				}
			}
		}
		if(!$c)
		{
			return '<' . $top . ' />';
		}
		$rdf[] = '</' . $primaryType . '>';
		return $rdf;
	}

	protected function dumpuri($doc, $uri, $spo)
	{
		$uri = strval($uri);
		if($spo == 1 && !strcmp($uri, RDF::rdf . 'type'))
		{
			return 'a';
		}
		if($uri[0] == '#')
		{
			return '_:' . substr($uri, 1);
		}
		if($doc)
		{
			return $doc->namespacedName($uri, false);
		}
		return $uri;
	}

	/* Output style shamelessly stolen from Graphite */
	public function dump($doc = null)
	{
		$result = array();
		if(!$doc) $result[] = '<dl>';
		$subj = $this->subject();
		$result[] = '<dt><a class="subject" href="' . _e($subj) . '" style="color: #aa00aa;">' . _e($this->dumpuri($doc, $subj, 0)) . '</a></dt>';
		$props = get_object_vars($this);
		foreach($props as $name => $values)
		{
			if(strpos($name, ':') === false) continue;
			if(!strcmp($name, RDF::rdf . 'about') || !strcmp($name, RDF::rdf . 'ID') || !strcmp($name, RDF::rdf . 'nodeID'))
			{
				if(!strcmp($values[0], $subj))
				{
					array_shift($values);
				}
			}
			if(!strcmp($name, RDF::rdf . 'type'))
			{
				if(!strcmp($values[0], RDF::rdf . 'Description'))
				{
					array_shift($values);
				}
			}
			if(!is_array($values) || !count($values)) continue;
			$result[] = '<dd about="' . _e($subj) . '">→ <a class="prop" style="color: #0000aa;" href="' . _e($name). '">' . _e($this->dumpuri($doc, $name, 1)) . '</a> → ';
			$vl = array();
			foreach($values as $val)
			{
				if($val instanceof RDFURI)
				{
					$vl[] = '<a rel="'. _e($name) . '" class="uri" style="color: #aa0000;" href="' . _e($val) . '">' . _e($this->dumpuri($doc, $val, 2)) . '</a>';
				}
				else if($val instanceof RDFInstance)
				{
					$v = $val->subject();
					$vl[] = '<a rel="' . _e($name) . '" class="uri" style="color: #aa0000;" href="' . _e($v) . '">' . _e($this->dumpuri($doc, $v, 2)) . '</a>';
				}
				else
				{
					$dt = null;
					if($val instanceof RDFComplexLiteral && isset($val->{RDF::rdf.'datatype'}[0]))
					{
						$uri = $val->{RDF::rdf.'datatype'}[0];
						$dt = ' ^ <a class="uri datatype" style="color: #aa0000;" href="' . _e($uri) . '">' . _e($this->dumpuri($doc, $uri, 1)) . '</a>';
					}
					$vl[] = '"<span property="' . _e($name) . '" class="literal" style="color: #00aa00;">' . _e($val) . '</span>"' . $dt;
				}
			}
			$result[] = implode(', ', $vl) . '</dd>';
		}
		if(!$doc) $result[] = '</dl>';
		return implode("\n", $result);
	}		

	/* Populate this instance from an array of triples. It is assumed that all of the triples
	 * have the same subject.
	 */
	public function fromTriples($set)
	{
		if($set instanceof RDFTripleSet)
		{
			$set = $set->subjects;
		}
		foreach($set as $triple)
		{
			$predicate = strval($triple->predicate);
			$match = false;
			if(isset($this->{$predicate}))
			{
				foreach($this->{$predicate} as $val)
				{
					if($val == $triple->object)
					{
						$match = true;
						break;
					}
				}
			}
			if(!$match)
			{
				$this->{$predicate}[] = $triple->object;
			}
		}
	}

	/* Deserialise this instance from an RDF/XML DOMElement  */
	public function fromDOM($root, $doc)
	{
		$fqname = XMLNS::fqname($root);
		if(strcmp($fqname, RDF::rdf.'Description'))
		{
			$this->{'http://www.w3.org/1999/02/22-rdf-syntax-ns#type'}[] = new RDFURI($fqname);
		}
		foreach($root->attributes as $attr)
		{
			$v = strval($attr->value);
			if($attr->namespaceURI == RDF::rdf)
			{
				if($attr->localName == 'about' || $attr->localName == 'resource')
				{
					$v = new RDFURI($v, $doc->fileURI);
				}
				else if($attr->localName == 'ID')
				{
					$v = new RDFURI('#' . $v, $doc->fileURI);
				}
				else if($attr->localName == 'nodeID')
				{
					$v = new RDFURI('_:' . $v, $doc->fileURI);
				}
			}
			$this->{XMLNS::fqname($attr)}[] = $v;
		}
		$node = $root->firstChild;		
		while(is_object($node))
		{
			while(!($node instanceof DOMElement))
			{
				$node = $node->nextSibling;
				if(!is_object($node)) return;
			}
			$parseType = null;
			$type = null;
			$nattr = 0;
			
			if($node->hasAttributes())
			{
				foreach($node->attributes as $attr)
				{
					if($attr->namespaceURI == 'http://www.w3.org/1999/02/22-rdf-syntax-ns#' &&
					   $attr->localName == 'datatype')
					{
						$type = $attr->value;
					}
					else if($attr->namespaceURI == 'http://www.w3.org/1999/02/22-rdf-syntax-ns#' &&
							$attr->localName == 'parseType')
					{
						$parseType = $attr->value;
						$nattr++;
					}
					else
					{
						$nattr++;
					}
				}
			}
			$parseType = strtolower($parseType);
			if($node->hasChildNodes() || $parseType == 'literal')
			{
				/* Might be a literal, a complex literal, or a graph */
				$child = $node->firstChild;
				if($parseType == 'literal' || ($child instanceof DOMCharacterData && !$child->nextSibling))
				{
					$value = $child->textContent;
					if($parseType == 'literal')
					{
						$v = new RDFXMLLiteral();
					}
					else if(strlen($type) || $nattr)
					{
						if($type == 'http://www.w3.org/2001/XMLSchema#dateTime')
						{
							$v = new RDFDateTime();
						}
						else
						{
							$v = new RDFComplexLiteral();
						}
					}
					else
					{
						$v = $value;
					}
					if(is_object($v))
					{
						$v->fromDOM($node, $doc);
					}
					$this->{XMLNS::fqname($node)}[] = $v;
				}
				else
				{
					$v = null;
					$gnode = $node->firstChild;
					while(is_object($gnode))
					{
						while(!($gnode instanceof DOMElement))
						{
							$gnode = $gnode->nextSibling;
							if(!is_object($gnode)) break;
						}
						if(!is_object($gnode)) break;
						if(isset(RDF::$ontologies[$gnode->namespaceURI]))
						{
							$v = call_user_func(array(RDF::$ontologies[$gnode->namespaceURI], 'rdfInstance'), $gnode->namespaceURI, $gnode->localName);
						}
						if(!$v)
						{
							$v = new RDFInstance();
						}
						$v->fromDOM($gnode, $doc);
						$v = $doc->merge($v);
						$v->refcount++;
						$v->transform();
						$this->{XMLNS::fqname($node)}[] = $v;
						$gnode = $gnode->nextSibling;
					}
				}
			}
			else
			{
				/* If there's only one attribute and it's rdf:resource, we
				 * can compress the whole thing to an RDFURI instance.
				 */
				$uri = null;
				foreach($node->attributes as $attr)
				{
					if($uri !== null)
					{
						$uri = null;
						break;
					}
					if($attr->namespaceURI != 'http://www.w3.org/1999/02/22-rdf-syntax-ns#' ||
					   ($attr->localName != 'resource' && $attr->localName != 'nodeID'))
					{
						break;
					}
					if($attr->localName == 'resource')
					{
						$uri = $attr->value;
					}
					else
					{
						$uri = '_:' . $attr->value;
					}
				}
				if($uri !== null)
				{
					$v = new RDFURI($uri, $doc->fileURI);
				}
				else
				{
					$v = new RDFInstance();
					$v->fromDOM($node, $doc);
					$v = $doc->merge($v);
					$v->refcount++;
					$v->transform();
				}
				$this->{XMLNS::fqname($node)}[] = $v;
			}
			$node = $node->nextSibling;
		}
	}

	/* Fetch all of the instances which are the same as this; returns an array of instances.
	 * If $doc is specified, adds each to the document. If $doc is specified and $useDoc is
	 * true, subjects will be looked up against $doc first, and won't be fetched if they're
	 * already present.
	 */
	public function fetchSameAs($doc = null, $useDoc = true)
	{
		$sameAs = array();
		if(isset($this->{RDF::owl.'sameAs'}))
		{
			foreach($this->{RDF::owl.'sameAs'} as $k => $inst)
			{
				if(!($inst instanceof RDFURI))
				{
					continue;
				}
				if($doc && $useDoc)
				{
					if(($subj = $doc->subject($inst, null, false)))
					{
						$subj->refcount++;
						$this->{RDF::owl.'sameAs'}[$k] = $subj;
						$sameAs[] = $subj;
						continue;
					}
				}
				if(($ndoc = RDF::tripleSetFromURL($inst)))
				{
					$ndoc->primaryTopic = $inst;
					if(($triples = $ndoc->primaryTopic()))
					{
						$inst = new RDFInstance();
						$inst->fromTriples($triples);
						if($doc)
						{
							$doc->add($inst);
						}
						$sameAs[] = $inst;
						continue;
					}
				}
			}
		}
		return $sameAs;
	}
}

class RDFComplexLiteral
{
	public $value;

	public static function literal($type = null, $value = null, $lang = null)
	{
		if(!strcmp($type, 'http://www.w3.org/2001/XMLSchema#dateTime'))
		{
			return new RDFDatetime($value);
		}
		return new RDFComplexLiteral($type, $value, $lang);
	}

	public function lang()
	{
		if(isset($this->{XMLNS::xml.' lang'}[0]))
		{
			return $this->{XMLNS::xml.' lang'}[0];
		}
		return '';
	}

	protected function setValue($value)
	{
		$this->value = $value;
	}

	public function __construct($type = null, $value = null, $lang = null)
	{
		if($type !== null)
		{
			$this->{'http://www.w3.org/1999/02/22-rdf-syntax-ns#datatype'}[] = $type;
		}
		if($lang !== null)
		{
			$this->{XMLNS::xml.' lang'}[] = $lang;
		}
		if($value !== null)
		{
			$this->setValue($value);
		}
	}

	public function asArray()
	{
		$val = array('type' => 'literal', 'value' => $this->value);
		if(isset($this->{RDF::rdf . 'type'}[0]))
		{
			$val['datatype'] = $this->{RDF::rdf . 'type'}[0];
		}
		if(isset($this->{XMLNS::xml . ' lang'}[0]))
		{
			$val['lang'] = $this->{XMLNS::xml . ' lang'}[0];
		}
		return $val;
	}

	public function asJSONLD()
	{
		$val = array('@literal' => $this->value);
		if(isset($this->{RDF::rdf . 'type'}[0]))
		{
			$val['@datatype'] = $this->{RDF::rdf . 'type'}[0];
		}
		if(isset($this->{XMLNS::xml . ' lang'}[0]))
		{
			$val['@language'] = $this->{XMLNS::xml . ' lang'}[0];
		}
		if(count($val) == 1)
		{
			return $val['@literal'];
		}
		return $val;
	}		

	public function fromDOM($node, $doc = null)
	{
		foreach($node->attributes as $attr)
		{
			$this->{XMLNS::fqname($attr)}[] = $attr->value;
		}
		$this->setValue($node->textContent);
	}
	
	public function __toString()
	{
		return strval($this->value);
	}
}

class RDFURI extends URL
{
	public function __construct($uri, $base = null)
	{
		parent::__construct($uri, $base);		
		$this->value = parent::__toString();
	}
	
	public function __toString()
	{
		return $this->value;
	}

	public function asArray()
	{
		return array('type' => 'uri', 'value' => $this->value);
	}

	public function asJSONLD()
	{
		return array('@uri' => $this->value);
	}
}

class RDFXMLLiteral extends RDFComplexLiteral
{
	public function fromDOM($node, $pdoc = null)
	{
		parent::fromDOM($node, $pdoc);
		$doc = array();
		for($c = $node->firstChild; $c; $c = $c->nextSibling)
		{
			$doc[] = $node->ownerDocument->saveXML($c);
		}
		$this->value = implode('', $doc);
	}
}

class RDFSet implements Countable
{
	protected $values = array();
	
	public static function setFromInstances($keys, $instances /* ... */)
	{
		$set = new RDFSet();
		$instances = func_get_args();
		array_shift($instances);
		foreach($instances as $list)
		{
			if($list instanceof RDFInstance)
			{
				$list = array($list);
			}
			foreach($list as $instance)
			{
				if(is_array($instance) && isset($instance[0]))
				{
					$instance = $instance[0];
				}
				if(!is_object($instance))
				{
					throw new Exception('RDFSet::setFromInstances() invoked with a non-object instance');
				}
				if(!($instance instanceof RDFInstance))
				{
					throw new Exception('RDFSet::setFromInstances() invoked with a non-RDF instance');
				}		
				$set->add($instance->all($keys));
			}
		}
		return $set;
	}
	
	public function __construct($values = null)
	{
		if($values === null) return;
		if(is_array($values))
		{
			$this->values = $values;
		}
		else
		{
			$this->values[] = $values;
		}
	}

	/* Return a simple human-readable representation of the property values */
	public function __toString()
	{
		return $this->join(', ');
	}

	/* Add one or more arrays-of-properties to the set. Call as, e.g.:
	 *
	 * $set->add($inst->{RDF::dc.'title'}, $inst->{RDF::rdfs.'label'});
	 *
	 * Any of the property arrays passed may already be an RDFSet instance, so that
	 * you can do:
	 *
	 * $foo = $k->all(array(RDF::dc.'title', RDF::rdfs.'label'));
	 * $set->add($foo); 
	 */
	public function add($property)
	{
		$props = func_get_args();
		foreach($props as $list)
		{
			if($list instanceof RDFSet)
			{
				$list = $list->values;
			}
			foreach($list as $value)
			{
				$this->values[] = $value;
			}
		}
	}

	/* Remove objects matching the specified string from the set */
	public function removeValueString($string)
	{		
		foreach($this->values as $k => $v)
		{
			if(is_resource($v))
			{
				print_r($this);
				trigger_error('Not a string', E_USER_ERROR);
			}
			if(!strcmp($string, $v))
			{
				unset($this->values[$k]);
				$this->values = array_values($this->values);
				return;
			}
		}
	}

	/* Return all of the values as an array */
	public function values()
	{
		return $this->values;
	}

	/* Return an array containing one value per language */
	public function valuePerLanguage($asSet = false)
	{
		$langs = array();
		$list = array();
		foreach($this->values as $val)
		{
			if(!($val instanceof RDFComplexLiteral))
			{
				$l = '';
			}
			else
			{
				$l = $val->lang();
			}
			$val = strval($val);
			if(!in_array($l, $langs))
			{
				$langs[] = $l;
				$list[$l] = ($asSet ? new RDFString($val, $l) : $val);
			}
		}
		if($asSet)
		{
			return new RDFSet($list);
		}
		return $list;
	}
	
	/* Return a slice of the set */
	public function slice($start, $count)
	{
		return new RDFSet(array_slice($this->values, $start, $count));
	}

	/* Return all of the values as an array of strings */
	public function strings()
	{
		$list = array();
		foreach($this->values as $v)
		{
			$list[] = strval($v);
		}
		return $list;
	}

	/* Return all of the values which are URIs (or instances) as an array
	 * of RDFURI instances
	 */
	public function uris()
	{
		$list = array();
		foreach($this->values as $v)
		{
			if($v instanceof RDFURI)
			{
				$list[] = $v;
			}
			else if($v instanceof RDFInstance)
			{
				$list[] = $v->subject();
			}
		}
		return $list;
	}
	
	/* Add the named properties from one or more instances to the set. As with
	 * RDFInstance::all(), $keys may be an array. Multiple instances may be
	 * supplied, either as additional arguments, or as array arguments, or
	 * both.
	 */
	public function addInstance($keys, $instance)
	{
		$instances = func_get_args();
		array_shift($instances);
		foreach($instances as $list)
		{
			if(!is_array($list))
			{
				$list = array($list);
			}
			foreach($list as $instance)
			{
				$this->add($instance->all($keys));
			}
		}
	}

	/* Return the first value in the set */
	public function first()
	{
		if(count($this->values))
		{
			return $this->values[0];
		}
		return null;
	}
	
	/* Return a string joining the values with the given string */
	public function join($by)
	{
		if(count($this->values))
		{
			return implode($by, $this->values);
		}
		return '';
	}

	/* Return the number of values held in this set; can be
	 * called as count($set) instead of $set->count().
	 */
	public function count()
	{
		return count($this->values);
	}
	
	/* Return the value matching the specified language. If $lang
	 * is an array, it specifies a list of languages in order of
	 * preference. if $fallbackFirst is true, return the first
	 * value instead of null if no language match could be found.
	 * $langs may be an array of languages, or a comma- or space-
	 * separated list in a string.
	 */
	public function lang($langs = null, $fallbackFirst = false)
	{
		if($langs === null)
		{
			$langs = RDF::$langs;
		}
		if(!is_array($langs))
		{
			$langs = explode(',', str_replace(' ', ',', $langs));
		}
		foreach($langs as $lang)
		{
			$lang = trim($lang);
			if(!strlen($lang)) continue;
			foreach($this->values as $value)
			{
				if($value instanceof RDFComplexLiteral &&
				   $value->lang() == $lang)
				{
					return strval($value);
				}
			}
		}
		foreach($this->values as $value)
		{
			if(is_string($value) || ($value instanceof RDFComplexLiteral && !strlen($value->lang())))
			{
				return strval($value);
			}
		}
		if($fallbackFirst)
		{
			foreach($this->values as $value)
			{
				return strval($value);
			}
		}
		return null;
	}
	
	/* Return the values as an array of RDF/JSON-structured values */
	public function asArray()
	{
		$list = array();
		foreach($this->values as $value)
		{
			if(is_object($value))
			{
				$list[] = $value->asArray();
			}
			else
			{
				$list[] = array('type' => 'literal', 'value' => $value);
			}
		}
		return $list;
	}
}


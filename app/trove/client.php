<?php

/**
 * The Trove model -- HTTP client implementation
 *
 * This is the programmatic interface to Trove -- Spindle's aggregation
 * engine.
 *
 * @package TroveAggregator The Spindle data aggregator
 * @author BBC
 * @year 2011
 * @license http://www.apache.org/licenses/LICENSE-2.0
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

require_once(dirname(__FILE__) . '/model.php');

/* Model class which talks to a running trove instance over HTTP instead of accessing the
 * store database directly.
 */
class TroveClient extends Trove
{
	/* Map 'kind' strings to paths on the web service */
	protected static $kindMap = array(
		'collection' => 'collections',
		'person' => 'people',
		'place' => 'places',
		'event' => 'events',
		'thing' => 'things',
		);

	/* The URI of the trove aggregator */
	public $baseUri;

	/* RDF document cache */
	protected $documents = array();
	protected $associatedDocuments = array();
	protected $documentCacheLimit = 16;

	/* The current document */
	protected $document = null;
	
	public function __construct($args)
	{
		$this->baseUri = $args['db'];
		$info = new URL($this->baseUri);
		if(isset($info->user) || isset($info->pass))
		{
			$auth = $info->user . ':' . $info->pass;
			unset($info->user);
			unset($info->pass);
			$this->baseUri = strval($info);
			uses('curl');
			Curl::$authData[$this->baseUri] = $auth;
		}
		$args['db'] = null;
		parent::__construct($args);
		$this->db = new TroveMockDB();
		RDF::$ontologies[Trove::trove] = 'TroveClient';
	}
	
	/* Retrieve a document given the specified (possibly partial) URI.
	 *
	 * The document will be cached in $this->documents[]. If $associateWith is not null,
	 * its value (or values, if an array) will be used as keys and the document will be
	 * stored against those keys in $this->associatedDocuments[] (typical use-case: storing
	 * the document against the UUID of the object it contains.
	 */
	protected function documentFromUri($uri, $params = null, $associateWith = null)
	{
		$permitRedirect = false;
		if(substr($uri, 0, 1) == '/' && strlen($this->baseUri))
		{
			$base = $this->baseUri;
			if(substr($base, -1) == '/')
			{
				$uri = $base . substr($uri, 1);
			}
			else
			{
				$uri = $base . $uri;
			}
		}
		if(strncmp($uri, $this->baseUri, strlen($this->baseUri)))
		{
			$this->debug('Will not attempt to fetch', $uri);
			return null;
		}
		if(!empty($params['permitRedirect']))
		{
			$permitRedirect = true;
		}
		unset($params['permitRedirect']);		
		if(is_array($params))
		{
			$params = http_build_query($params, null, ';');
		}
		if(strlen($params))
		{
			$uri .= '?' . $params;
		}
//		die('uri = ' . $uri);
		if(strpos($uri, '00000000') !== false)
		{
			throw new Exception('wtf: ' . $uri);
		}
		if(isset($this->documents[$uri]))
		{
			return $this->documents[$uri];
		}
		$this->debug('documentFromUri:', 'Fetching:', $uri);
		$doc = RDF::documentFromUrl($uri);
		if(!is_object($doc))
		{
			$this->debug('documentFromUri:', 'Result --', $doc, '-- is not an object');
			return null;
		}
		$doc->rdfInstanceClass = 'TroveObject';
		while(count($this->documents) > $this->documentCacheLimit)
		{
			$r = array_shift($this->documents);
			foreach($this->associatedDocuments as $k => $v)
			{
				if($v === $r)
				{
					unset($this->associatedDocuments[$k]);
				}
			}
		}
		$this->documents[$uri] = $doc;
		if($associateWith !== null)
		{
			if(!is_array($associateWith))
			{
				$associateWith = array($associateWith);
			}
			foreach($associateWith as $key)
			{
				$this->associatedDocuments[$key] = $doc;
			}
		}
		$this->document = $doc;
		return $doc;
	}
	
	/* Given a URI, return a resultset object, assuming successful retrieval of
	 * a document from that URI.
	 */
	protected function queryFromUri($uri, $params = null)
	{		
		if(!($doc = $this->documentFromUri($uri, $params)))
		{
			$this->debug('Failed to retrieve document for', $uri);
			return null;
		}
		if(!($res = $doc->resourceTopic()))
		{
			$this->debug('Failed to obtain resource topic from', $uri);
			return null;
		}
		$params['document'] = $doc;
		return new TroveClientSet($this, $res, $uri, $params);
	}

	/* Return the document for the given UUID */
	protected function documentForUuid($uuid, $params = null)
	{
		if(!isset($this->associatedDocuments[$uuid]))
		{
			if(!($inst = $this->objectForUuid($uuid, null, null, false, $params)))
			{
				return null;
			}
		}
		return $this->associatedDocuments[$uuid];
	}

	/* Given a URI, return an instance representing that URI
	 *
	 * (Note: at present, this actually returns the primary topic from the document,
	 *  this may well change, although it's unlikely to impact negatively in the event).
	 */
	protected function objectFromUri($uri, $params = null, $associateWith = null)
	{
		$created = $modified = null;
		if(isset($this->document))
		{
			$doc = $this->document;
		}
		else
		{
			if(!($doc = $this->documentFromUri($uri, $params, $associateWith)))
			{
				return null;
			}
		}
		if(($p = strrpos($uri, '#')) === false)
		{
			if(($object = $doc->primaryTopic()) === null)
			{			
				return null;
			}
		}
		else
		{
			if(($object = $doc->subject($uri, null, false)))
			{
				return null;
			}
		}			
		$objects = array($object);
		if(($res = $doc->resourceTopic()))
		{
			if(isset($res['dct:created']))
			{
				$created = $res['dct:created'];
			}
			if(isset($res['dct:modified']))
			{
				$modified = $res['dct:modified'];
			}
		}
		if(isset($object->{Trove::trove.'uuid'}[0]))
		{
			$object->uuid = strval($object->{Trove::trove.'uuid'}[0]);
		}
		if(isset($object->uuid) && strlen($object->uuid))
		{
			$this->associatedDocuments[$object->uuid] = $doc;
		}
		else
		{
			return null;
		}
		$object->created = $created;
		$object->modified = $modified;
//		$object->stash = $this->stashForInstance($object, $doc);
		if($object->isA(Trove::trove.'Event'))
		{
			$object->kind = 'event';
		}
		else if($object->isA(Trove::trove.'Collection'))
		{
			$object->kind = 'collection';
		}
		else if($object->isA(Trove::trove.'Person'))
		{
			$object->kind = 'person';
		}
		else if($object->isA(Trove::trove.'Place'))
		{
			$object->kind = 'place';
		}
		else if($object->isA(Trove::trove.'Thing'))
		{
			$object->kind = 'thing';
		}
		return $this->fixupObjects($objects, $doc);
	}

	/* Given an instance ($object) and the document it's contained within,
	 * initialise and populate $object->stash appropriately for the UI.
	 */
	protected function deprecated_stashForInstance($object, $doc)
	{
		$stash = new RDFInstance();
		$exact = array();
//		print_r($object); die();
		$set = $object['skos:exactMatch']->uris();
		foreach($set as $match)
		{
			$match = strval($match);
			if(isset($doc[$match]))
			{
				$exact[] = $doc[$match];
			}
		}
		$stashLangKeys = array(
			RDF::skos.'prefLabel', 
			RDF::foaf.'name',
			RDF::rdfs.'label',
			RDF::dcterms.'title',
			RDF::dc.'title',
			'http://purl.org/ontology/po/medium',
			RDF::rdfs . 'comment',
			'http://purl.org/ontology/po/short_synopsis',
			'http://purl.org/ontology/po/long_synopsis',
			RDF::dcterms . 'description',
			'http://dbpedia.org/ontology/abstract',
			RDF::dc . 'description',
			);
		$stashAllKeys = array(
			RDF::foaf . 'depiction',
			RDF::foaf . 'page',
			RDF::foaf . 'isPrimaryTopicOf',
		);
		$stashFirstKeys = array(
			'http://data.ordnancesurvey.co.uk/ontology/geometry/asGML',
			'http://data.ordnancesurvey.co.uk/ontology/geometry/extent',
		);
		/* Special
			RDF::time . 'hasBeginning',
			RDF::time . 'hasEnd',
		);
		*/
		foreach($exact as $obj)
		{
			if(isset($obj['geo:lat']) && isset($obj['geo:long']))
			{
				$stash['geo:lat'] = $obj['geo:lat'];
				$stash['geo:long'] = $obj['geo:long'];
			}
		}
		foreach($stashLangKeys as $key)
		{
			$set = RDFSet::setFromInstances($key, $exact);
			if($set->count())
			{
				$stash[$key] = $set->valuePerLanguage(true);
			}
		}
		foreach($stashFirstKeys as $key)
		{
			$set = RDFSet::setFromInstances($key, $exact);
			if($set->count())
			{
				$stash[$key] = $set->slice(0, 1);
			}
		}
		foreach($stashAllKeys as $key)
		{
			$set = RDFSet::setFromInstances($key, $exact);
			if($set->count())
			{
				$stash[$key] = $set->values();
			}
		}
		return $stash;
	}
	
	/* Translate a store query into an aggregator URI and return the resultset created
	 * as a result of GETting that URI.
	 */
	public function query($args)
	{
		if($this->debug !== false)
		{
			ob_start();
			print_r($args);
			$this->debug(ob_get_clean());
		}
		$params = array();
		$uri = '/';
		if(!isset($args['kind']) && !isset($args['uuid']))
		{
			$uri .= 'all/';
		}
		else if(isset($args['kind']) && !is_array($args['kind']) && strlen($args['kind']))
		{
			if(isset(self::$kindMap[$args['kind']]))
			{
				$uri .= self::$kindMap[$args['kind']] . '/';
			}
			else
			{
				$uri .= $args['kind'] . '/';
			}
		}
		else if(isset($args['kind']) && is_array($args['kind']))
		{
			$uri .= 'all/';
			$params['kind'] = implode(',', $args['kind']);
		}
		if(isset($args['uuid']))
		{
			$uri .= $args['uuid'];
		}
		else
		{
			if(isset($args['tags']))
			{
				$args['tags'] = is_array($args['tags']) ? $args['tags'] : array($args['tags']);
				if(count($args['tags']))
				{
					$uri .= 'tagged';
					foreach($args['tags'] as $t)
					{
						$uri .= '/' . urlencode($t);
					}
				}
			}
			if(isset($args['sort_char']) || isset($args['norm_title%']))
			{
				$uri .= 'a-z/by/';
				if(isset($args['norm_title%']))
				{
					$uri .= urlencode($args['norm_title%']);
				}
				else
				{
					$uri .= urlencode($args['sort_char']);
				}
			}
		}
		if(isset($args['offset']))
		{
			$params['offset'] = $args['offset'];
		}
		if(isset($args['limit']))
		{
			$params['limit'] = $args['limit'];
		}
		if(isset($args['order']))
		{
			$params['order'] = $args['order'];
		}
		if(isset($args['parent']))
		{
			$params['parent'] = $args['parent'];
		}
		if(isset($args['superior']))
		{
			$params['superior'] = $args['superior'];
		}
		if(isset($args['coords?']))
		{
			$params['coords?'] = $args['coords?'];
		}
		if(isset($args['text']))
		{
			$params['q'] = $args['text'];
		}
		return $this->queryFromUri($uri, $params);
	}	
	
	/* Retrieve the object with the specified UUID. Passing $kind avoids
	 * the redirect exchange, and so is preferred.
	 */
	public function objectForUuid($uuid, $owner = null, $kind = null, $firstOnly = false, $queryArgs = null)
	{
		$uuid = UUID::formatted($uuid);
		if(!strlen($uuid))
		{
			return null;
		}
		if(isset(self::$kindMap[$kind])) $kind = self::$kindMap[$kind];
		return $this->objectFromUri('/' . (strlen($kind) ? $kind . '/' : '') . $uuid, $queryArgs, $uuid);
	}

	/* Retrieve the object for the specified URI, which may be a urn:uuid: URI */	
	public function objectForIri($uri, $owner = null, $kind = null, $firstOnly = false, $useCached = true)
	{
		if(null !== ($uuid = UUID::isUUID($uri)))
		{
			return $this->objectForUuid($uuid);
		}
		if($useCached)
		{
			foreach($this->documents as $doc)
			{
				if(isset($doc[$uri]))
				{
					$set = array($doc[$uri]);
					return $this->fixupObjects($set, $doc);
				}
				$subjects = $doc->subjectUris();
				foreach($subjects as $subj)
				{
					if(($p = strrpos($subj, '#')) !== false)
					{
						$tsubj = substr($subj, 0, $p);
						if(!strcmp($tsubj, $uri))
						{
							$set = array($doc[$subj]);
							return $this->fixupObjects($set, $doc);
						}
					}
				}
			}
		}
		return $this->objectFromUri($uri);
	}

	/* Return all of the mappings attached to the object of the specified UUID. If it wasn't
	 * fetched recently enough for its document to appear in the cache, it will be re-fetched.
	 */
	public function mappingsByTypeForUuid($uuid)
	{
		if(!isset($this->associatedDocuments[$uuid]))
		{
			if(!($inst = $this->objectForUUID($uuid)))
			{
				return null;
			}
		}
		$doc = $this->associatedDocuments[$uuid];
		if(!isset($inst))
		{
			$inst = $doc->primaryTopic();
		}
		$mappings = array();
		foreach($this->matchTypes as $type => $predicate)
		{
			if(isset($inst->{$predicate}) && count($inst->{$predicate}))
			{
				foreach($inst->{$predicate} as $object)
				{
					if(!($object instanceof RDFURI))
					{
						continue;
					}
					$mappings[$type][] = array(
						'uuid' => $uuid,
						'source' => null,
						'type' => $type,
						'resource' => strval($object),						
						);
				}
			}
		}
		return $mappings;
	}

	/* Helper method:
	 *
	 * Find all of the objects tagged with the object with the specified
	 * UUID and query parameters.
	 */
	public function objectsTaggedWithUuid($uuid, $query = null)
	{
		if(!is_array($query))
		{
			$query = array();
		}
		if(!isset($query['limit']))
		{
			$query['limit'] = 50;
		}
		if(is_object($uuid))
		{
			$url = '/' . $uuid->kind . '/' . $uuid->uuid;
		}
		else
		{
			$url = '/' . $uuid;
		}
		return $this->queryFromUri($url, $query);
	}

	/* Helper method:
	 *
	 * Find all of the objects which are explicitly related to the
	 * specified one.
	 */
	public function objectsRelatedTo($object)
	{
		if(!is_object($object))
		{
			if(!($object = $this->objectForIri($object)))
			{
				return array();
			}
		}
		$doc = $this->documentForUuid($object->uuid);
		$predicates = $object->predicates();
		foreach($predicates as $predicate)
		{
			if(in_array($predicate, $this->matchTypes))
			{
				continue;
			}
			$values = $object[$predicate]->uris();
			foreach($values as $uri)
			{
				if(!($uri instanceof RDFURI))
				{
					continue;
				}
				$uri = strval($uri);
				if(!strncmp($uri, RDF::rdf, strlen(RDF::rdf)))
				{
					continue;
				}
				if(isset($doc[$uri]))
				{
					$list[] = $doc[$uri];
				}
			}
		}
		return $list;
	}

	/* Helper method:
	 *
	 * Find all of the children associated with the object with the specified
	 * UUID.
	 */
	public function childrenOfObjectWithUuid($uuid, $query = null)
	{
		$doc = $this->documentForUuid($uuid, $query);
		if($doc === null)
		{
			return array();
		}
		$children = array();
		$primary = $doc->primaryTopic();
		$subjects = $doc->subjects();	   
		foreach($subjects as $subj)
		{
			$p = $subj[Trove::trove.'parent']->uris();
			foreach($p as $uri)
			{
				if(!strcmp($uri, $primary))
				{
					$children[] = $subj;
					break;
				}
			}
		}
		return $children;
	}		

	/* Short-circuit the query for collections */
	public function collections($parent = null)
	{
		return $this->queryFromUri('/collections');
	}

	/**
	 * For any predicates the object has, if they're *not* a SKOS
	 * matching predicate but *are* a URI whose object exists in $doc,
	 * add them to $objects.
	 */
	protected function fixupObjects(&$objects, $doc, $index = 0)
	{
		$obj = $objects[$index];
		$props = $obj->predicates();
		foreach($props as $k)
		{
/*			if(strpos($k, ':') === false || !is_array($values))
			{
				continue;
				} */
			if(!strcmp($k, RDF::rdf.'about') || !strcmp($k, RDF::rdf.'type'))
			{
				continue;
			}
			if(in_array($k, $this->matchTypes))
			{
				continue;
			}
//			echo '<p>Predicate is ' . $k . '</p>';
			$values = $obj[$k]->uris();
//			print_r($values);
			foreach($values as $index => $value)
			{
				if($value instanceof RDFURI)
				{
					$value = strval($value);
					foreach($objects as $o)
					{
						if(!strcmp($o->subject(), $value))
						{
							continue 2;
						}
					}
					if(($target = $doc[$value]) !== null)
					{
						$i = count($objects);
						$objects[] = $target;
						$this->fixupObjects($objects, $doc, $i);
					}
				}
			}
		}
		if(count($objects) == 1)
		{
			return $objects[0];
		}
		return $objects;
	}

	/* Add an entity to the trove
	 *
	 * $whom must be an array containing two elements: 'cert' and 'key', both of which are
	 * PEM-encoded (consisting of an X.509v3 certificate and RSA or DSA private key, respectively).
	 */
	public function push($what, $whom = null, $lazy = false)
	{	   
		uses('curl');		
		error_log('------------------------');		
		$doc = new RDFDocument();
		if(is_array($what))
		{
			$what = RDFStoredObject::objectForData($what, $this);
		}
		$doc->add($what);
		$xml = $doc->asXML();
		$xml = is_array($xml) ? implode("\n", $xml) : $xml;
		$docTmp = tempnam(sys_get_temp_dir(), 'trovepush');
		$xml = "Content-type: application/rdf+xml\n" .
			"\n" .
			$xml;
		file_put_contents($docTmp, $xml);		
		error_log($docTmp);
		$outTmp = tempnam(sys_get_temp_dir(), 'trovepush');
		$cert = openssl_x509_read($whom['cert']);
		$key = openssl_pkey_get_private($whom['key']);
		if(openssl_pkcs7_sign($docTmp, $outTmp, $cert, $key, array()))
		{
			unlink($docTmp);
			chmod($outTmp, 0644);
			$signed = file_get_contents($outTmp);
			$signed = explode("\n\n", $signed, 2);
			unlink($outTmp);
			$c = new Curl($this->baseUri);
			$c->headers = explode("\n", $signed[0]);
			$c->verbose = true;
			$c->httpPOST = true;
			$c->returnTransfer = true;
			$c->postFields = $signed[1];
			$c->followLocation = true;
			$c->autoReferrer = true;
			$c->unrestrictedAuth = true;
			$c->httpAuth = Curl::AUTH_ANYSAFE;
			$result = $c->exec();
			ob_start();
			print_r($c->info);
			print_r($result);
			echo '<pre>' . _e(ob_get_clean()) . '</pre>';			
			die();
		}
		else
		{
			error_log('openssl_pkcs7_sign() failed');
			return false;
		}
	}
	
	public function status()
	{
		$c = new Curl($this->baseUri . '/status');
		$c->returnTransfer = true;
		return json_decode($c->exec(), true);
	}
}

/**
 * Placeholder for TroveClient's $db property, to catch any unintended calls
 * to database access methods.
 *
 * @internal
 */
class TroveMockDB
{
	public function __call($method, $params)
	{
		trigger_error('Attempt to invoke MockDB::' . $method . '()', E_USER_ERROR);
	}
}

/* A resultset created from an RDFInstance */
class TroveClientSet extends StaticStorableSet
{
	public $uri;
	public $params;
	public $document;

	public function __construct($model, $inst, $uri, $params)
	{
		$args = array('list' => array());
		$this->uri = $uri;
		if(isset($params['document']))
		{
			$this->document = $params['document'];
			unset($params['document']);
		}
		$this->params = $params;
		foreach($inst['rdfs:seeAlso']->uris() as $ref)
		{
			$ref = strval($ref);
			$args['list'][] = $ref;
		}
		parent::__construct($model, $args);
	}

	protected function storableForEntry($entry, $rowData = null)
	{
		if(($obj = $this->model->objectForIri($entry)))
		{
			return $obj;
		}
	}
}


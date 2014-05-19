<?php

/**
 * The Trove model
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

uses('rdfstore');

/**
 * Define TROVE_IRI to IRI of the database containing the Trove object store,
 * or of the website hosting an instance of the Trove.
 *
 * Examples:
 * <code>
 * define('TROVE_IRI', 'mysql://trove:password@localhost/trove');
 * define('TROVE_IRI', 'http://trove.localnet/');
 * </code>
 */
if(!defined('TROVE_IRI')) define('TROVE_IRI', null);

/**
 * Define TROVE_SEARCH to be the connection details for the local search
 * engine, if any. At present only Xapian is supported.
 *
 * If left undefined or explicitly defined to null, full-text search will
 * not be available.
 *
 * For example:
 * <code>
 * define('TROVE_SEARCH', 'xapian+file:///opt/local/data/trove-index');
 * </code>
 */
if(!defined('TROVE_SEARCH')) define('TROVE_SEARCH', null);

/**
 * Define TROVE_PUSH_IS_LAZY to true to cause pushes to not perform evaluation
 * or update indices after an object has been written to the store.
 *
 * If defined, you must run a separate evaluator process, as calls to
 * Trove::push() will simply write the object and defer the follow-on
 * work until later.
 */
if(!defined('TROVE_PUSH_IS_LAZY')) define('TROVE_PUSH_IS_LAZY', false);

/**
 * Define TROVE_INDEX_IS_LAZY to true to cause objects not to be indexed
 * after they are written to the store, including when stubs are written by
 * the evaluator. Setting this option is not recommended, as correct
 * evaluation relies upon indices being up to date.
 *
 * If defined, you must run a separate indexer process to ensure that
 * indexing happens and objects can be found.
 */
if(!defined('TROVE_INDEX_IS_LAZY')) define('TROVE_INDEX_IS_LAZY', true);

/**
 * Define TROVE_FORCE_INDEX_CLEAN to true to cause the indexing operations
 * to explicitly delete any left-over browse index data relating to objects
 * which aren't stubs. This should not normally need to be defined, as
 * current versions of the Trove ensure that browse index data isn't written
 * for non-stub objects in the first place.
 */
if(!defined('TROVE_FORCE_INDEX_CLEANUP')) define('TROVE_FORCE_INDEX_CLEANUP', false);

/**
 * Define TROVE_DEBUG to true to enable debug-logging.
 *
 * When enabled, debug messages will be written to stdout when invoking
 * command-line tools, and to the server's error log when running on a Web
 * server.
 */
if(!defined('TROVE_DEBUG')) define('TROVE_DEBUG', true);


/**
 * The Trove aggregator interface
 *
 * As the Trove class derives from Model {@link https://github.com/nexgenta/eregansu/wiki/Model},
 * it is a per-connection singleton. If you use Trove::getInstance() to
 * obtain an instance of it, a connection will be established to the server
 * details in the TROVE_IRI constant (if defined).
 */
abstract class Trove extends RDFStore
{
	/**
	 * The URI of the Trove namespace.
	 *
	 * Internal classes and properties occur within this namespace.
	 */
	const trove = 'http://projectreith.com/ns/';
	
	/**
	 * The name of the class whose ::objectForData() static method is
	 * invoked in order to translate an array-representation of an object
	 * into an instance of some class.
	 *
	 * @internal
	 */
	protected $storableClass = 'TroveObject';

    /**
	 * Setting $queriesCalcRows to false prevents database queries from
	 * attempting to determine the total number of matching rows, to aid
	 * performance.
	 *
	 * @internal
	 */
	protected $queriesCalcRows = false;	

	/**
	 * Setting $queriesGroupByUuid prevents database queries from including a
	 * GROUP BY "obj"."uuid" clause, which can cause performance problems if
	 * not ordering by that field (which is most of the time). Grouping by
	 * UUID should not normally be necessary.
	 *
	 * @internal
	 */
	protected $queriesGroupByUuid = false;

	/**
	 * An instance of the TroveEvaluator class. Created on-demand by
	 * Trove::evaluator()
	 *
	 * @internal
	 * @see Trove::evaluator()
	 */
	protected $evaluator = null;

	/**
	 * The list of matching helper class instances. Created on-demand by
	 * Trove::matchingHelpers()
	 *
	 * @internal
	 * @see Trove::matchingHelpers()
	 */
	protected $matchingHelpers = null;

	/**
	 * The list of generator helper class instances. Created on-demand by
	 * Trove::generators()
	 *
	 * @internal
	 * @see Trove::generators()
	 */
	protected $generators = null;


	/**
	 * An instance of the full-text search engine, if any. Created on-demand
	 * by Trove::searchEngine()
	 *
	 * @internal
	 * @see Trove::searchEngine()
	 */
	protected $search;

	/**
	 * An instance of the full-text search engine's indexing interface, if
	 * any. Created on-demand by Trove::searchIndexer()
	 *
	 * @internal
	 * @see Trove::searchIndexer()
	 */
	protected $indexer;
	
	/**
	 * Enable debug logging.
	 *
	 * If set to 'stdout', debugging output will be written to stdout; if
	 * set to 'error', debugging output will be written via error_log().
	 *
	 * Currently all other values are ignored, but false should be used
	 * explicitly disable debugging output.
	 *
	 * @internal
	 * @see Trove::debug()
	 * @see Trove::vdebug()
	 */ 
	public $debug = false;

	/**
	 * Mapping between the internal matching types and the SKOS predicate URIs
	 * for those matching types.
	 */
	public $matchTypes = array(
		'noMatch' => 'http://www.w3.org/2008/05/skos#noMatch',
		'exactMatch' => 'http://www.w3.org/2008/05/skos#exactMatch',
		'closeMatch' => 'http://www.w3.org/2008/05/skos#closeMatch',
		'narrowMatch' => 'http://www.w3.org/2008/05/skos#narrowMatch',
		'broadMatch' => 'http://www.w3.org/2008/05/skos#broadMatch',
		);

	/**
	 * Mapping between class URIs and internal object "kinds"
	 */
	public static $stubTypes = array(
		'http://purl.org/vocab/frbr/core#Work' => 'thing',
		'http://purl.org/theatre#Production' => 'thing',
		'http://purl.org/ontology/bibo/Book' => 'thing',
		'http://purl.org/ontology/po/Brand' => 'thing',
		'http://purl.org/ontology/po/Series' => 'thing',
		'http://purl.org/ontology/po/Episode' => 'thing',
		'http://purl.org/ontology/po/Version' => 'thing',
		'http://purl.org/ontology/po/Clip' => 'thing',

		'http://purl.org/vocab/frbr/core#Event' => 'event',
		'http://semanticweb.cs.vu.nl/2009/11/sem/Event' => 'event',
		'http://purl.org/NET/c4dm/event.owl#Event' => 'event',
		'http://www.w3.org/2006/time#DurationDescription' => 'event',
		'http://purl.org/ontology/po/Broadcast' => 'event',
		'http://purl.org/ontology/mo/Recording' => 'event',

		'http://purl.org/dc/dcmitype/Collection' => 'collection',

		'http://xmlns.com/foaf/0.1/Person' => 'person',
		'http://xmlns.com/foaf/0.1/Agent' => 'person',
		'http://xmlns.com/foaf/0.1/Organization' => 'person',
		'http://purl.org/ontology/po/Person' => 'person',

		'http://data.ordnancesurvey.co.uk/ontology/admingeo/EuropeanRegion' => 'place',
		'http://purl.org/theatre#Venue' => 'place',
		'http://purl.org/vocab/frbr/core#Place' => 'place',
		'http://www.geonames.org/ontology#Feature' => 'place',
		'http://dbpedia.org/ontology/Place' => 'place',
		'http://dbpedia.org/ontology/City' => 'place',
		'http://dbpedia.org/ontology/PopulatedPlace' => 'place',
		'http://www.opengis.net/gml/_Feature' => 'place',
		);

	/**
	 * Last-resort mappings between class URIs and object kinds
	 */   
	public static $fallbackStubTypes = array(
		'http://www.w3.org/2004/02/skos/core#Concept' => 'collection',
		'http://www.w3.org/2008/05/skos#Concept' => 'collection',
		'http://www.w3.org/2002/07/owl#Thing' => 'thing',
		);
	
	/**
	 * List of predicates used as titles/labels when generating stubs, in
	 * order of preference.
	 */
	public static $labelPredicates = array(
		'http://www.w3.org/2008/05/skos#prefLabel',
		'http://www.w3.org/2004/02/skos/core#prefLabel',
		'http://www.geonames.org/ontology#name',
		'http://www.geonames.org/ontology#officialName',
		'http://www.geonames.org/ontology#alternateName',
		'http://xmlns.com/foaf/0.1/name',
		'http://www.w3.org/2000/01/rdf-schema#label',
		'http://purl.org/dc/terms/title',
		'http://purl.org/dc/elements/1.1/title',
		);

	/**
	 * List of predicates used as descriptions when generating stubs
	 */
	public static $descriptionPredicates = array(
		'http://purl.org/ontology/po/long_synopsis',
		'http://purl.org/ontology/po/medium_synopis',
		'http://www.w3.org/2000/01/rdf-schema#comment',
		'http://purl.org/ontology/po/short_synopsis',
		'http://purl.org/dc/terms/description',
		'http://dbpedia.org/ontology/abstract',
		'http://purl.org/dc/elements/1.1/description',
		);

	abstract public function status();
	
	abstract public function mappingsByTypeForUuid($uuid);

	abstract public function push($what, $whom = null, $lazy = false);

	/**
	 * Obtain an instance to the Trove.
	 *
	 * If $args is null or does not contain a 'db' member, it will default
	 * to the value of TROVE_IRI, if defined.
	 */
	public static function getInstance($args = null)
	{
		if(!isset($args['db'])) $args['db'] = TROVE_IRI;
		if(!isset($args['class']))
		{
			/* If the 'database' URI is http: or https:, create an instance of
			 * TroveClient instead.
			 */
			if(!strncmp($args['db'], 'http:', 5) || !strncmp($args['db'], 'https:', 6))
			{
				require_once(dirname(__FILE__) . '/client.php');
				$args['class'] = 'TroveClient';
			}
			else
			{
				require_once(dirname(__FILE__) . '/db.php');
				$args['class'] = 'TroveDB';
			}
		}
		return parent::getInstance($args);
	}
	
	/**
	 * Class constructor
	 * @ignore
	 */
	public function __construct($args)
	{
		parent::__construct($args);
		if(php_sapi_name() == 'cli' && TROVE_DEBUG)
		{
			$this->debug = 'stdout';
		}
		else if(TROVE_DEBUG)
		{
			$this->debug = 'error';
		}
		/* Register well-known namespace prefixes */
		RDF::ns('http://purl.org/ontology/po/', 'po');
		RDF::ns('http://projectreith.com/ns/', 'trove');
		RDF::ns('http://purl.org/theatre#', 'theatre');
		RDF::ns('http://purl.org/ontology/mo/', 'mo');
		RDF::ns('http://data.ordnancesurvey.co.uk/ontology/geometry/', 'geom');
		RDF::ns('http://semanticweb.cs.vu.nl/2009/11/sem/', 'sem');
		RDF::ns('http://www.w3.org/ns/auth/cert#', 'cert');
		RDF::ns('http://www.w3.org/ns/auth/rsa#', 'rsa');
		RDF::ns('http://rdf.freebase.com/ns/', 'fb');
		RDF::ns('http://rdf.freebase.com/ns/m/', 'freebase');
		RDF::ns('http://dbpedia.org/ontology/', 'dbo');
		RDF::ns('http://dbpedia.org/property/', 'dbp');
		RDF::ns('http://dbpedia.org/resource/', 'dbpedia');
		RDF::ns('http://data.nytimes.com/', 'nytimes');
		RDF::ns('http://creativecommons.org/ns#', 'cc');
		RDF::ns('http://rdfs.org/ns/void#', 'void');
		RDF::ns('http://www.w3.org/2004/02/skos/core#', 'skoscore');
		RDF::ns('http://purl.org/NET/c4dm/event.owl#', 'event');
        /* Register ourselves as the handler for the Trove ontology, so that
		 * TroveObject subclasses are instantiated when RDF instances are deserialised.
		 */
		RDF::$ontologies[Trove::trove] = 'Trove';
	}

	/**
	 * Invoked by RDF::instanceForClass()
	 *
	 * Returns an instance of one of the TroveObject descendants if
	 * the class URI belongs to the Trove namespace and is known (e.g.,
	 * trove:Event causes a TroveEvent instance to be created).
	 *
	 * @ignore
	 */
	public static function rdfInstance($ns, $lname)
	{
		$class = null;
		if(!strcmp($ns, Trove::trove))
		{
			switch($lname)
			{
			case 'Thing':
			case 'Event':
			case 'Place':
			case 'Person':
			case 'Collection':
				$class = 'Trove' . $lname;
				break;
			}
		}
		if(strlen($class))
		{
			return new $class();
		}
	}

	/**
	 * Emit a debugging message.
	 *
	 * If debugging is enabled (TROVE_DEBUG is nonempty and the current SAPI
	 * is 'cli'), then echo any parameters, prefixed by the class name
	 * and suffixed with a newline.
	 *
	 * @internal	 
	 */
	public function debug()
	{
		$a = func_get_args();
		$this->vdebug(get_class($this), $a);
	}

	/**
	 * Emit a debugging message for a class.
	 *
	 * @internal.
	 */
	public function vdebug($className, $args)
	{
		if($this->debug == 'stdout')
		{
			echo $className . ': ' . implode(' ', $args) . "\n";
		}
		else if($this->debug == 'error')
		{
			error_log($className . ': ' . implode(' ', $args));
		}			
	}

	/**
	 * Return the stub object for a specified resource
	 */
	public function stubObjectForIri($iri)
	{
		if(!strncmp($iri, 'urn:uuid:', 9))
		{
			$uuid = substr($iri, 9);
			$obj = null;
		}
		else if(($obj = $this->objectForIri($iri, null, null, true)))
		{
			if($this->isStub($obj))
			{
				return $obj;
			}
			$uuid = $obj->uuid;
		}
		else
		{
			return null;
		}
		if(($stubUuid = $this->stubForUuid($uuid)) !== null)
		{
			if(($stubObject = $this->objectForUuid($stubUuid, null, null, true)) !== null)
			{
				return $stubObject;
			}
		}
		if($obj === null)
		{
			if(($obj = $this->objectForUuid($uuid, null, null, true)) === null)
			{
				return null;
			}
		}
		foreach($obj->iri as $resource)
		{
			$stub = $this->stubForResource($resource);
			if(strlen($stub))
			{
				if(($stubObject = $this->objectForUuid($stub, null, null, true)) !== null)
				{
					return $stubObject;
				}
			}
		}
		$this->debug('Stub', $stubUuid, 'for object', $uuid, '(' . $obj->kind . ') does not exist yet');
		return null;
	}
		
	/**
	 * Return a TroveMap object, optionally populated by a set of mappings
	 * for a given UUID.
	 *
	 * The mappings should be as returned by Trove::mappingsForUuid()
	 *
	 * @task Entity mappings
	 */
	public function resourceMap($mappings = null, $stubUuid = null)
	{
		$map = new TroveMap($this, $mappings, $stubUuid);
		return $map;
	}

	/**
	 * Return a TroveMap object containing the mappings for a given UUID.
	 *
	 * @task Entity mappings
	 */
	public function resourceMapForUuid($stubUuid)
	{
		$mappings = $this->mappingsForUuid($stubUuid);
		return $this->resourceMap($mappings, $stubUuid);
	}
	
	/**
	 * Return all of the mappings attached to a specified UUID, arranged by
	 * source URI.
	 *
	 * @task Entity mappings
	 */
	public function mappingsBySourceForUuid($uuid)
	{
		$list = $this->mappingsForUuid($uuid, null, true);
		$set = array();
		foreach($list as $row)
		{
			$set[$row['source']] = $row;
		}
		return $set;
	}

	/** 
	 * Find all of the mappings attached to a specified UUID, arranged by
	 * match type, and then fetch all of the objects referred to by those
	 * mappings.
	 *
	 * Matches which refer to the stub itself will be filtered out.
	 *
	 * Non-exact matches will return the relevant stub object, or be filtered
	 * if none exists.
	 *
	 * @deprecated
	 * @task Entity mappings
	 */
	public function mappedObjectsByTypeForUuid($uuid, &$flatList)
	{
		$map = $this->mappingsByTypeForUuid($uuid);
		$set = array();
		foreach($map as $type => $relatedSet)
		{
			foreach($relatedSet as $k => $related)
			{
				$inst = $this->objectForIri($related['resource']);
				if($inst)
				{
					if($type != 'exactMatch')
					{
						if(!$this->isStub($inst))
						{
							$stubUuid = $this->stubForUuid($this->uuidOfObject($inst));
							if(!strlen($stubUuid))
							{
								continue;
							}
							$stub = $this->objectForUuid($stubUuid);
							if(!$stub)
							{
								continue;
							}
							$inst = $stub;
						}
					}
/*					if(is_array($inst))
					{
						foreach($inst as $o)
						{
							$set[$type][] = $o;
							if($type == 'exactMatch')
							{
								$flatList[] = $o;
							}
						}
					}
					else */
					{
						$set[$type][] = $inst;
						if($type == 'exactMatch')
						{
							$flatList[] = $inst;
						}
					}
				}
			}
		}
		return $set;
	}

	/**
	 * Find all of the children associated with the object with the specified
	 * UUID.
	 *
	 * @deprecated
	 * @task Entity mappings
	 */
	public function childrenOfObjectWithUuid($uuid)
	{
		$list = array();
		$rs = $this->query(array('superior' => $uuid));
		while(($obj = $rs->next()))
		{
			if($this->isStub($obj))
			{
				if(is_array($obj))
				{
					foreach($obj as $o)
					{
						$list[] = $o;
					}
				}
				else
				{
					$list[] = $obj;
				}
			}
		}
		return $list;
	}
	
	/**
	 * Locate an object given its IRI.
	 *
	 * @task Queries
	 */
	public function objectForIri($iri, $owner = null, $kind = null, $firstOnly = false)
	{
		if(!strncmp($iri, 'urn:uuid:', 9))
		{
			if(($obj = $this->objectForUuid(substr($iri, 9), $owner, $kind, $firstOnly)) !== null)
			{
				return $obj;
			}
		}
		return parent::objectForIri($iri, $owner, $kind, $firstOnly);
	}

	/**
	 * Find all of the objects tagged with the object with the specified
	 * UUID and query parameters.
	 *
	 * @task Queries
	 */
	public function objectsTaggedWithUuid($uuid, $query = null)
	{
		if(!is_array($query))
		{
			$query = array();
		}
		$query['tags'][] = $uuid;
		if(!isset($query['limit']))
		{
			$query['limit'] = 50;
		}
		return $this->query($query);
	}

	/**
	 * Find all of the objects which are explicitly related to the
	 * specified one.
	 *
	 * @task Queries
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
		$list = array();
		if(isset($object->relatedStubs) && count($object->relatedStubs))
		{
			foreach($object->relatedStubs as $uuid)
			{
				if(is_array($uuid) || is_object($uuid))
				{
					continue;
				}
				if(($obj = $this->objectForUuid($uuid)) && $this->isStub($obj))
				{
					if(is_array($obj))
					{
						foreach($obj as $o)
						{
							$list[] = $o;
						}
					}
					else
					{
						$list[] = $obj;
					}
				}
			}
		}
		return $list;
	}
	
	/**
	 * Given a set returned by mappingsBy...ForUuid(), fetch all of the objects
	 *
	 * @task Entity mappings
	 * @deprecated
	 */
	public function objectsForMappings($children, $preserveAncilliary = true, $fetchIfNeeded = false)
	{
		$firstOnly = !$preserveAncilliary;
		$objects = array();
		foreach($children as $type => $entities)
		{
			foreach($entities as $entity)
			{
				/* XXX We could be smarter about this, all told */
				if(!strlen($entity['resource_uuid']) ||
				   !($obj = $this->objectForUUID($entity['resource_uuid'], null, null, $firstOnly)))
				{
					if(!strncmp($entity['resource'], 'urn:uuid:', 9))
					{
						/* If the resource_uuid is unset or otherwise didn't
						 * return an object, but the resource takes the form
						 * urn:uuid:, then we know it's an object we actually
						 * have; reset resource_uuid to be the UUID from the URN
						 * and try again.
						 */
						$entity['resource_uuid'] = substr($entity['resource'], 9);
						$obj = $this->objectForUUID($entity['resource_uuid'], null, null, $firstOnly);
						$this->debug('Warning: child', $entity['resource_uuid'], ' has not been indexed yet');
					}
					else
					{
						/* We don't have the child's UUID, but we may be able
						 * to locate it by its resource.
						 */
						$obj = $this->objectForIri($entity['resource'], null, null, $firstOnly);
						if($obj)
						{
							$entity['resource_uuid'] = $this->uuidOfObject($obj);
						}
						else if($fetchIfNeeded)
						{
							$obj = $this->ingestRDF($entity['resource'], false, null, $firstOnly);
							if($obj)
							{
								$entity['resource_uuid'] = $this->uuidOfObject($obj);
							}
						}
					}
					if(!$obj)
					{
						$this->debug("Warning: no child found for " . $entity['resource']);
						continue;
					}
				}
				if(is_array($obj) && isset($obj[0]))
				{					
					$obj[0]->inboundMapping = $entity;
				}
				else
				{
					$obj->inboundMapping = $entity;
				}
				$objects[$type][] = $obj;
			}
		}
		return $objects;
	}
	

	/**
	 * Is the object or kind string $thing a stub object or not?
	 *
	 * @type boolean
	 * @param[in] mixed $thing An object or a string.
	 * @task Utilities
	 */
	public function isStub($thing)
	{
		$thing = $this->kindOfObject($thing);
	    if(strlen($thing) == 36)
		{
			if(($d = $this->dataForUuid($thing, null, null, true)) !== null)
			{
				$thing = $d['kind'];
			}
			else
			{
				return false;
			}
		}
		switch($thing)
		{
			case 'person':
			case 'place':
			case 'thing':
			case 'event':
			case 'collection':
				return true;
		}
	}
	
	/**
	 * Determine the kind of stub we should generate (thing, person, collection,
	 * event, place) based on the RDF class URI supplied.
	 *
	 * @task Utilities
	 */
	public function kindOfStubForRDFClass($classUri)
	{
		$classUri = strval($classUri);
		if(isset(Trove::$stubTypes[$classUri]))
		{
			return Trove::$stubTypes[$classUri];
		}
		return null;
	}

	/**
	 * Determine the kind of stub which would be generated for a given entity.
	 *
	 * @task Utilities
	 */
	public function kindOfStubForEntity($entity)
	{
		$first = $this->firstObject($entity);
		$classes = $first['rdf:type']->uris();
		foreach($classes as $u)
		{
			if(($s = $this->kindOfStubForRDFClass($u)) !== null)
			{
				return $s;
			}
		}
		return null;
	}

	/**
	 * Determine the kind of stub for a given set of class URIs
	 * @task Utilities
	 */
	public function kindOfStubForClassList($classes, $fallback = true)
	{
		foreach($classes as $k => $uri)
		{
			$classes[$k] = strval($uri);
		}
		foreach(Trove::$stubTypes as $uri => $kind)
		{
			if(in_array($uri, $classes))
			{
				return $kind;
			}
		}
		if($fallback)
		{
			foreach(Trove::$fallbackStubTypes as $uri => $kind)
			{
				if(in_array($uri, $classes))
				{
					return $kind;
				}
			}
		}
	}		

	/**
	 * Generate the normalised sortable form of a title string
	 *
	 * @task Utilities
	 */
	public function normaliseTitle($title)
	{
		return strtolower(str_replace('--', '-', preg_replace('/[^A-Za-z0-9-]/', '-', str_replace("'", '', $title))));
	}

	/**
	 * Return the plural form of a kind.
	 *
	 * @task Utilities
	 */
	public function plural($kind)
	{
		switch($kind)
		{
		case 'thing':
			return 'things';
		case 'event':
			return 'events';
		case 'place':
			return 'places';
		case 'person':
			return 'people';
		case 'collection':
			return 'collections';
		}
		return null;
	}			
	
	/**
	 * Invoked periodically by the batch command-line interfaces to ensure
	 * that all indexing changes are written to disk.
	 *
	 * @task Utilities
	 */
	public function commit()
	{
		if($this->indexer !== null)
		{
			$this->indexer->commit();			
		}
	}

	/**
	 * Return a set of collection objects.
	 *
	 * @task Queries
	 */
	public function collections($parent = null)
	{
		$args = array('kind' => 'collection');
		if($parent === null)
		{
			$args['parent?'] = false;
		}
		else
		{
			$args['parent'] = $parent;
		}
		return $this->query($args);
	}
}

/**
 * Representation of mappings between stubs and resources
 */
class TroveMap implements ArrayAccess, IteratorAggregate, Countable
{
	/**
	 * A reference to the Trove model instance
	 */
	protected $model;
	/**
	 * A copy of the map for this UUID, as would be returned by
	 * Trove::mappingsForUuid()
	 */
	protected $map = array();
	/**
	 * The UUID of the stub these mappings are associated with.
	 */
	protected $uuid;
	/**
	 * The UUID of the stub these mappings were associated with previously.
	 */
	protected $previousStub;
	/**
	 * The objects which are targetted by this map
	 */
	protected $objects = array();
	/**
	 * The default object UUID to be associated with new entries
	 */
	protected $objectUuid;

	/**
	 * Initialise a new TroveMap instance, optionally provided a map and stub
	 * UUID.
	 *
	 * This method is invoked automatically when a new TroveMap instance is
	 * constructed by Trove::resourceMap().
	 *
	 * If $stubUuid is not specified and $map is a non-empty array,
	 * TroveMap::$uuid will be set to the UUID contained in the first entry
	 * of $map.
	 *
	 * @internal
	 */
	public function __construct($model, $map = null, $stubUuid = null, $objects = null)
	{
		$this->model = $model;
		if($map !== null)
		{
			$this->map = $map;
		}
		if($stubUuid !== null)
		{
			$this->previousStub = $stubUuid;
			$this->uuid = $stubUuid;
		}
		else if($map !== null)
		{
			foreach($map as $entry)
			{
				$this->uuid = $entry['uuid'];
				break;
			}
		}
		if(is_array($objects))
		{
			foreach($this->map as $k => $entry)
			{
				if(isset($objects[$entry['resource']]))
				{
					$this->objects[$entry['resource']] = $objects[$entry['resource']];
				}
			}
		}
	}

	/**
	 * Obtain the object referred to by an entry
	 *
	 * @internal
	 */
	protected function objectForEntry($entry)
	{
		if(isset($this->objects[$entry['resource']]))
		{
			return $this->objects[$entry['resource']];
		}
		$obj = null;
		if(isset($entry['resource_uuid']))
		{
			$obj = $this->model->objectForUuid($entry['resource_uuid']);
		}
		else
		{
			print_r($entry);
		}
		if($obj === null)
		{
			$obj = $this->model->objectForIri($entry['resource']);
		}
		if($obj === null)
		{
			return null;
		}
		$this->objects[$entry['resource']] = $obj;
		if(is_array($obj) && isset($obj[0]))
		{
			$obj[0]->inboundMapping = $entry;
		}
		else
		{
			$obj->inboundMapping = $entry;
		}
		return $obj;
	}

	/**
	 * Return the full, flat, list of mappings.
	 */
	public function map()
	{
		return $this->map;
	}
	
	/**
	 * Return the stub UUID this mapping is associated with, if any.
	 */
	public function uuid()
	{
		return $this->uuid;
	}
	
	/**
	 * Change the stub UUID this mapping is associated with.
	 */
	public function setUuid($newUuid)
	{
		$this->uuid = $newUuid;
	}
	
	/**
	 * Return the default related object UUID
	 */
	public function relatedObjectUuid()
	{
		return $this->objectUuid;
	}

	/**
	 * Change the default related object UUID that new mappings will be
	 * associated with if a different object is not specified.
	 */
	public function setRelatedObjectUuid($newUuid)
	{
		$this->objectUuid = $newUuid;
	}

	/**
	 * Return a list of all of the mappings where the resource source
	 * matches $source.
	 */
	public function mappingsFromSource($source)
	{
		$list = array();
		foreach($this->map as $k => $entry)
		{
			if(!strcmp($entry['source'], $source))
			{
				$list[$k] = $entry;
			}
		}
		return new TroveMap($this->model, $list, $this->uuid, $this->objects);
	}

	/**
	 * Return a list of all of the entries where the resource source does
	 * NOT match $source
	 */
	public function mappingsExcludingSource($source)
	{
		$list = array();
		foreach($this->map as $k => $entry)
		{
			if(strcmp($entry['source'], $source))
			{
				$list[$k] = $entry;
			}
		}
		return new TroveMap($this->model, $list, $this->uuid, $this->objects);
	}

	/**
	 * Remove all of the mappings where the resource source matches
	 * $source. An array will be returned containing all of the entries
	 * which were removed.
	 */
	public function removeMappingsFromSource($source, $maxPriority = 50)
	{
		$list = array();
		foreach($this->map as $k => $entry)
		{
			if(!strcmp($entry['source'], $source) && $entry['priority'] <= $maxPriority)
			{
				$list[$k] = $entry;
				unset($this->objects[$entry['resource']]);
				unset($this->map[$k]);
			}
		}
		return $list;
	}

	/**
	 * Return a list of all of the mappings of a given type, optionally
	 * excluding a specific source.
	 *
	 * Only those mappings with a priority equal to the highest priority
	 * of those of that type from that source are returned;
	 */
	public function mappingsOfType($type, $excludeSource = null)
	{
		$list = array();
		$priorities = array();
		foreach($this->map as $k => $entry)
		{
			if(strcmp($entry['type'], $type))
			{
				continue;
			}
			if($excludeSource !== null && !strcmp($entry['source'], $excludeSource))
			{
				continue;
			}
			if(isset($priorities[$entry['source']]))
			{
				/* Mapping lists are sorted by priority (highest first), so
				 * we only need to check if the priority of $entry is lower
				 * than the priority we've previously seen for that source.
				 */
				if($entry['priority'] < $priorities[$entry['source']])
				{
					continue;
				}
			}
			else
			{
				$priorities[$entry['source']] = $entry['priority'];
			}
			$list[$entry['resource']] = $entry;
		}
		foreach($list as $k => $entry)
		{
			if($entry['priority'] < $priorities[$entry['source']])
			{
				unset($list[$k]);
			}
		}
		$map = new TroveMap($this->model, $list, $this->uuid);
		$map->objects =& $this->objects;
		return $map;
	}

	/**
	 * ArrayAccess::offsetGet(): Allow the use of the indexed access
	 * operator as an alias to TroveMap::mappingsOfType().
	 */
	public function offsetGet($type)
	{
		return $this->mappingsOfType($type);
	}

	/**
	 * ArrayAccess::offsetExists(): Return true if an an entry with the
	 * specified match type exists in the map.
	 */
	public function offsetExists($type)
	{
		foreach($this->map as $entry)
		{
			if(!strcmp($entry['type'], $type))
			{
				return true;
			}
		}
	}

	/**
	 * ArrayAccess::offsetSet(): Do nothing
	 */
	public function offsetSet($dummyKey, $dummyValue)
	{
		trigger_error('TroveMap instances cannot be modified via array access', E_USER_ERROR);		
	}	

	/**
	 * ArrayAccess::offsetUnset() -- do nothing
	 */
	public function offsetUnset($dummyKey)
	{
		trigger_error('TroveMap instances cannot be modified via array access', E_USER_ERROR);
	}

	/**
	 * Countable::count() -- return the count of the number of objects
	 * in the map.
	 */
	public function count()
	{
		return count($this->map);
	}

	/**
	 * IteratorAggregate::getIterator() -- return an Iterator instance
	 * which can be used with foreach()
	 */
	public function getIterator()
	{
		$list = array();
		foreach($this->map as $k => $entry)
		{
			if(($obj = $this->objectForEntry($entry)) !== null)
			{
				$list[$entry['resource']] = $obj;
			}
		}
		return new ArrayIterator($list);
	}

}

/**
 * Abstract base class for all helpers
 */
abstract class TroveHelper
{
	protected $model;

	public function __construct($model)
	{
		$this->model = $model;
	}

	/* If Trove debugging is enabled, log all of the parameters passed,
	 * prefixed by the name of the class and suffixed by a newline.
	 */
	public function debug()
	{
		$a = func_get_args();
		$this->model->vdebug(get_class($this), $a);
	}

	/**
	 * Add a URI value for a particular predicate to stub data
	 */
	protected function addUriToData(&$data, $predicate, $uri)
	{
		if(is_array($uri))
		{
			$uri = $uri['value'];
		}
		if(!strlen($predicate))
		{
			$predicate = RDF::skos.'relatedMatch';
		}
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

	/**
	 * Add a set of URI values for a particular predicate to stub data
	 */
	protected function addUrisToData(&$data, $predicate, $uris)
	{
		foreach($uris as $uri)
		{
			$this->addUriToData($data, $predicate, $uri);
		}
	}	
}

/* Abstract base class for all matching helpers
 *
 * Matchers all derive from TroveMatcher, and must at a bare minimum
 * set $this->source and implement $this->performMatching().
 */
abstract class TroveMatcher extends TroveHelper
{
	protected static $similarity;
	protected $model;

	/* Set $this->source to be the URI of the matching source, for example
	 * 'http://dbpedialite.org/' or 'http://example.com/#org'.
	 */
	public $source;

	/* Return an instance of the similarity engine */
	protected function similarity()
	{
		if(!isset(self::$similarity))
		{
			require_once(dirname(__FILE__) . '/similarity.php');
			self::$similarity = new TroveSimilarity();
		}
		return self::$similarity;
	}

	/* Invoked by the evaluator to request a matcher to attempt matching
	 * of $objects, which are mapped from $stubUuid, against its sources.
	 *
	 * Any information populated in $extraData will be stored directly in
	 * the generated stub object.
	 *
	 * Return true to indicate that the mappings from $stubUuid changed
	 * as a result of the matching process, false otherwise.
	 */
//	abstract public function performMatching($stubUuid, &$objects, &$extraData);

	/* Evaluate the similarity of an object $object, against a mapped set,
	 * $objects (for the time being, only $objects['exactMatch'] is employed
	 * for evaluation.
	 *
	 * Upon completion, $match is set to the matching kind (exactMatch,
	 * closeMatch, etc.), while $confidence is set to a value between 0 and
	 * 100 inclusive scoring the similarity confidence.
	 *
	 * Note that $match is derived from $confidence: the latter is not a
	 * measure of confidence that $match is correct, but a measure of
	 * confidence that $object and $objects correlate to the same entity.
	 */
	protected function evaluateSimilarity($stubUuid, $objects, $object, &$match, &$confidence)
	{
		$ev = $this->similarity();
		$docA = $this->literalsFromInstances($this->model->firstObject($object));
		$matches = array();
		if(!isset($objects['exactMatch']) || !count($objects['exactMatch']))
		{
			$match = 'noMatch';
			return 0;
		}
		foreach($objects['exactMatch'] as $match)
		{
			$matches[] = $this->model->firstObject($match);
		}
		$docB = $this->literalsFromInstances($matches);
		$confidence = $ev->evaluate($docA, $docB);
		if($confidence >= 70)
		{
			$match = 'exactMatch';
		}
		else if($confidence > 60)
		{
			$match = 'closeMatch';
		}
		else if($confidence > 50)
		{
			$match = 'narrowMatch';
		}
		else if($confidence > 25)
		{
			$match = 'broadMatch';
		}
		else
		{
			$match = 'noMatch';
		}
		return $confidence;
	}
	
	/* Return all of the literal values from the instance list, $instances.
	 * The return value is an array, where each member is an array of
	 * (literal string, weight), with 0 >= weight > 1.0
	 */
	protected function literalsFromInstances($instances)
	{
		static $predicateWeighting = array(
			'http://www.w3.org/2008/05/skos#prefLabel' => 1.0,		
			'http://www.geonames.org/ontology#officialName' => 1.0,	
			'http://www.geonames.org/ontology#name' => 1.0,
			'http://www.geonames.org/ontology#alternateName' => 1.0,
			'http://xmlns.com/foaf/0.1/name' => 1.0,
			'http://www.w3.org/2000/01/rdf-schema#label' => 1.0,
			'http://purl.org/dc/terms/title' => 1.0,
			'http://purl.org/dc/elements/1.1/title' => 1.0,
			'http://purl.org/ontology/po/medium_synopsis' => 0.75,
			'http://www.w3.org/2000/01/rdf-schema#comment' => 0.75,
			'http://purl.org/ontology/po/short_synopsis' => 0.75,
			'http://purl.org/ontology/po/long_synopsis' => 0.75,
			'http://purl.org/dc/terms/description' => 0.75,
			'http://dbpedia.org/ontology/abstract' => 0.75,
			'http://purl.org/dc/elements/1.1/description' => 0.75,
			);
		static $defaultPredicateWeighting = 0.6;

		$literals = array();
		if(!is_array($instances))
		{
			$instances = array($instances);
		}
		foreach($instances as $inst)
		{
			$v = $inst->predicateObjectList();
			foreach($v as $predicate => $values)
			{
				if(isset($predicateWeighting[$predicate]))
				{
					$weight = $predicateWeighting[$predicate];
				}
				else
				{
					$weight = $defaultPredicateWeighting;
				}
				foreach($values as $val)
				{
					if(is_object($val))
					{
						if($val instanceof RDFComplexLiteral)
						{
							$literals[] = array(strval($val), $weight);
						}
					}
					else
					{
						$literals[] = array(strval($val), $weight);
					}
				}
			}
		}
		return $literals;
	}
	
	/* Return a flat array of all of the objects with the match type of
	 * $kindOfMatch, optionally excluding those with a source of
	 * $notWithSource.
	 */
	protected function objectsMatching($objects, $kindOfMatch, $notWithSource = null, $firstOnly = false)
	{
		$list = array();
		if(!isset($objects[$kindOfMatch]) || !count($objects[$kindOfMatch]))
		{
			return $list;
		}
		foreach($objects[$kindOfMatch] as $obj)
		{
			$first = $this->model->firstObject($obj);
			if(strlen($notWithSource))
			{
				if(!strcmp($first->inboundMapping['source'], $notWithSource))
				{
					continue;
				}
			}
			$list[] = ($firstOnly ? $first : $obj);
		}
		return $list;
	}

}

abstract class TroveGenerator extends TroveHelper
{
	abstract public function generate($stubUuid, TroveMap $objects, &$dataSet);
}

/* The base class for all Trove objects */
class TroveObject extends RDFStoredObject
{
	public $stash = null;
	protected $basePath = null;
	protected $hash = null;

	public static function objectForData($data, $model = null, $className = null)
	{
		if(isset($data[0]))
		{
			$list = array();
			foreach($data as $k => $array)
			{
				$list[$k] = self::objectForData($array, $model, $className);
			}
			return $list;
		}
		if(!isset($data['kind']))
		{
			$data['kind'] = 'graph';
		}
		if(!isset($className) || !strcmp($className, 'TroveObject'))
		{
			switch($data['kind'])
			{
				case 'thing':
					$className = 'TroveThing';
					break;
				case 'event':
					$className = 'TroveEvent';
					break;
				case 'place':
					$className = 'TrovePlace';
					break;
				case 'person':
					$className = 'TrovePerson';
					break;
				case 'collection':
					$className = 'TroveCollection';
					break;
				default:
					$className = 'TroveObject';
					break;
			}
		}
		$inst = parent::objectForData($data, $model, $className);
		if($inst)
		{
			if(isset($data['_stash']))
			{
				$inst->stash = parent::objectForData($data['_stash'], $model, $className);
			}
			unset($inst->_stash);
			if(!isset($inst->{Trove::trove.'uuid'}) && isset($inst->uuid))
			{
				$inst->{Trove::trove.'uuid'} = array($inst->uuid);
			}
		}
		return $inst;
	}

	/* Invoked by RDFDocument::fromDOM() */
	public function transform()
	{
		parent::transform();
		$className = get_class($this);
		if(!isset($this->kind) && !strncmp($className, 'Trove', 5))
		{
			$this->kind = strtolower(substr($className, 5));
		}
		if(!isset($this->hash) && strlen($this->kind))
		{
			$this->hash = '#' . $this->kind;
		}
		if(!isset($this->uuid) && isset($this->{Trove::trove.'uuid'}[0]))
		{
			$this->uuid = strval($this->{Trove::trove.'uuid'}[0]);
		}
	}

	protected function doesHaveSubject()
	{
		$slist = parent::subjects();
		foreach($slist as $s)
		{
			$s = strval($s);
			if(strlen($s) && strncmp($s, '#', 1) && strncmp($s, '_:', 2))
			{
				return $s;
			}
		}
		return false;
	}
	

	/* Invoked by RDFStoredObject::objectForData() */
	protected function loaded($reloaded = false)
	{
		if(!isset($this->hash) && strlen($this->kind))
		{
			$this->hash = '#' . $this->kind;
		}
		if(isset($this->uuid))
		{
			$model = self::$models[get_class($this)];
			if($this->doesHaveSubject() === false)
			{
				$this->__set(RDF::rdf.'about', array(new RDFURI('/' . $this->path() . $this->hash)));
			}
			if($model->isStub($this))
			{
				$browse = $model->db->row('SELECT * FROM {object_browse} WHERE "uuid" = ?', $this->uuid);
				$inbound = intval($browse['inbound_refs']);
				$struct = intval($browse['struct_refs']);
				$this->{Trove::trove.'inboundReferenceCount'} = array(new RDFComplexLiteral(XMLNS::xsd.'integer', $inbound));
				$this->{Trove::trove.'structuralReferenceCount'} = array(new RDFComplexLiteral(XMLNS::xsd.'integer', $struct));
				$outbound = 0;
				if(isset($this->{Trove::trove.'outboundReferenceCount'}[0]))
				{
					$outbound = intval(strval($this->{Trove::trove.'outboundReferenceCount'}[0]));
				}
				if(empty($browse['total_refs']))
				{
					$total = $inbound + $outbound;
				}
				else
				{
					$total = $browse['total_refs'];
				}
				$this->{Trove::trove.'totalReferenceCount'} = array(new RDFComplexLiteral(XMLNS::xsd.'integer', $total));
				if(empty($browse['adjusted_refs']))
				{
					$score = empty($this->score) ? 1 : $this->score;
					$adjusted = floor(($total - $struct) * $score);
				}
				else
				{
					$adjusted = $browse['adjusted_refs'];
				}
				$this->{Trove::trove.'adjustedReferenceCount'} = array(new RDFComplexLiteral(XMLNS::xsd.'integer', $adjusted));
			}
		}
	}
	
	public function troveUri($request = null)
	{
		return ($request === null ? '/' : '') . $this->path($request) . $this->hash;
	}
	
	public function title($langs = null, $fallbackFirst = true)
	{
		$t = parent::title($langs, $fallbackFirst);
		if($fallbackFirst && !strlen($t) && isset($this->title))
		{
			$t = $this->title;
		}
		return $t;
	}

	protected function fetchImage($uri)
	{
		$path = PUBLIC_ROOT . 'images/' . $this->uuid . '/';
		$hash = md5($uri);
		if(!file_exists($path))
		{
			mkdir($path, 0777, true);
		}
		$cc = new CurlCache($uri);
		$cc->followLocation = true;
		$cc->returnTransfer = true;
		$buf = $cc->exec();
		$info = $cc->info;
		switch(@$info['content_type'])
		{
		case 'image/png':
		case 'image/jpeg':
		case 'image/gif':
			$source = imagecreatefromstring($buf);
			break;
		default:
			return false;
		}
		$name = $hash . MIME::extForType($info['content_type']);			
		$f = fopen($path . $name, 'wb');
		fwrite($f, $buf);
		fclose($f);
		imagepng($source, $path . $hash . '.png');
		$this->tryResize($source, $hash, 32, 32);
		$this->tryResize($source, $hash, 64, 64);
		$this->tryResize($source, $hash, 128, 128);
		$this->tryResize($source, $hash, 512, 512);
		$this->tryResize($source, $hash, 640, 360);
		$this->tryResize($source, $hash, 832, 468);
		$this->tryResize($source, $hash, 118, 87);
		$this->tryResize($source, $hash, 976, 360);
		return 'images/' . $path . $hash . '.png';
	}

	protected function tryResize($source, $hash, $tWidth, $tHeight)
	{
		$width = imagesx($source);
		$height = imagesy($source);
		if($width < $tWidth || $height < $tHeight)
		{
			return;
		}
		/* Resize based on the longest target size */
		if($tWidth > $tHeight)
		{
			$rWidth = $tWidth;
			$rHeight = floatval($height) * (floatval($tWidth) / floatval($width));
		}
		else
		{
			$rHeight = $tHeight;
			$rWidth = floatval($width) * (floatval($tHeight) / floatval($height));
		}
		/* If the result is too small in some dimension, try the other way around */
		if($rHeight < $tHeight)
		{
			$rHeight = $tHeight;
			$rWidth = floatval($width) * (floatval($tHeight) / floatval($height));
		}
		else if($rWidth < $tWidth)
		{
			$rWidth = $tWidth;
			$rHeight = floatval($height) * (floatval($tWidth) / floatval($width));
		}			
		$xDiff = floor(($rWidth - $tWidth) / 2);
		$yDiff = floor(($rHeight - $tHeight) / 2);
		$dest = imagecreatetruecolor($tWidth, $tHeight);
		imagecopyresampled($dest, $source, -$xDiff, -$yDiff, 0, 0, $rWidth, $rHeight, $width, $height);
		imagepng($dest, PUBLIC_ROOT . 'images/' . $this->uuid . '/' . $hash . '-' . $tWidth . 'x' . $tHeight . '.png');
	}

	public function depiction($minWidth = 0, $minHeight = 0, $maxWidth = 0, $maxHeight = 0, $biggest = false)
	{
		$list = $this->all('foaf:depiction')->uris();
		foreach($list as $uri)
		{
			$hash = md5($uri);
			if(!file_exists(PUBLIC_ROOT . 'images/' . $this->uuid . '/' . $hash . '.png'))
			{
				$path = $this->fetchImage($uri);
			}
		}
		if(file_exists(PUBLIC_ROOT . 'images/' . $this->uuid))
		{
			$d = opendir(PUBLIC_ROOT . 'images/' . $this->uuid);
			$sizes = array();
			while(($de = readdir($d)))
			{
				$sx = array();
				if(preg_match('/^([0-9]+)x([0-9]+)\.(jpeg|gif|png)$/i', $de, $sx) && count($sx) == 4)
				{
					if($maxWidth && $maxHeight && ($sx[1] >= $maxWidth || $sx[2] >= $maxHeight))
					{
						continue;
					}
					if($sx[1] >= $minWidth && $sx[2] >= $minHeight)
					{						
						$k = sprintf('%05d-%05d', $sx[2], $sx[1]);
						$sizes[$k] = '/images/' . $this->uuid . '/' . $de;
					}	
				}
				else if(preg_match('/^([0-9a-z]{32})-([0-9]+)x([0-9]+)\.(jpeg|gif|png)$/i', $de, $sx) && count($sx) == 5)
				{
					if($maxWidth && $maxHeight && ($sx[2] >= $maxWidth || $sx[3] >= $maxHeight))
					{
						continue;
					}
					if($sx[2] >= $minWidth && $sx[3] >= $minHeight)
					{						
						$k = sprintf('%05d-%05d', $sx[2], $sx[3]);
						$sizes[$k] = '/images/' . $this->uuid . '/' . $de;
					}
				}
			}
			closedir($d);
			if(count($sizes))
			{
				if($biggest)
				{
					krsort($sizes);
				}
				else
				{
					ksort($sizes);
				}
				return array_shift($sizes);
			}
		}
	}
	
	/* Return the values of a given predicate */
	public function all($key, $nullOnEmpty = false)
	{
		$all = parent::all($key, true);
		if($all === null)
		{
			if($this->stash)
			{
				return $this->stash->all($key, $nullOnEmpty);
			}
			if(!$nullOnEmpty)
			{
				return new RDFSet();
			}
		}
		return $all;
	}
	
	public function offsetExists($offset)
	{
		if(!strcmp($offset, 'path'))
		{
			return true;
		}
		$offset = $this->translateQName($offset);
		if(isset($this->{$offset}) && is_array($this->{$offset}) && count($this->{$offset}))
		{
			return true;
		}
		if(isset($this->stash->{$offset}) && is_array($this->stash->{$offset}) && count($this->stash->{$offset}))
		{
			return true;
		}
	}
	
	public function offsetGet($offset)
	{
		if(!strcmp($offset, 'path'))
		{
			return $this->path();		
		}
		return parent::offsetGet($offset);
	}

	/* Attempt to construct a path for this object; if $request is supplied,
	 * the path will be prefixed with the application base path,
	 * $request->base; otherwise, a relative path will be returned.
	 */
	public function path($request = null)
	{
		if(!isset($this->uuid))
		{
			trigger_error(get_class($this) . '::$uuid is not set while attempting to generate a path', E_USER_ERROR);
			return null;
		}
		return ($request ? $request->base : '') . (strlen($this->basePath) ? $this->basePath . '/' : '') . $this->uuid;
	}
}

class TroveThing extends TroveObject
{
	protected $basePath = 'things';
	protected $hash = '#thing';
}

class TroveEvent extends TroveObject
{
	protected $basePath = 'events';
	protected $hash = '#event';
}

class TrovePlace extends TroveObject
{
	protected $basePath = 'places';
	protected $hash = '#place';
}

class TrovePerson extends TroveObject
{
	protected $basePath = 'people';
	protected $hash = '#person';
}

class TroveCollection extends TroveObject
{
	protected $basePath = 'collections';
	protected $hash = '#collection';
}


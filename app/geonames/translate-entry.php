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

/* Translator class for Geonames entries */

class GeonamesEntry extends GeonamesTranslator
{
	public function translate($ident, $kind)
	{
		if(($row = $this->model->entryForIdentifier($ident)))
		{
			$map = json_decode($row['record'], true);
			$map['kind'] = $kind;
			$map['uuid'] = $row['_uuid'];
			return $map;
		}
		if(!($row = $this->model->geonameForId($ident)))
		{
			return;
		}
		/* In lieu of having the real RDF, fake it */
		$map = $this->flatToRDF($row, 'http://sws.geonames.org/' . $row['geonameid'] . '/');
		$map[RDF::rdf.'type'] = array(array('type' => 'uri', 'value' => RDF::gn.'Feature'));
		$this->mapLiterals($map, array(
							   'name' => RDF::gn.'name',
							   'latitude' => RDF::geo.'lat',
							   'longitude' => RDF::geo.'long',
							   'country_code' => RDF::gn.'countryCode',
							   ));
		if(strlen($map['feature_code'])) $map['feature_code'] = 'http://www.geonames.org/ontology#' . $map['feature_class'] . '.' . $map['feature_code'];
		if(strlen($map['feature_class'])) $map['feature_class'] = 'http://www.geonames.org/ontology#' . $map['feature_class'];
		$map['is_defined_by'] = 'http://sws.geonames.org/' . $map['geonameid'] . '/about.rdf';
		$map['nearby_features'] = 'http://sws.geonames.org/' . $map['geonameid'] . '/nearby.rdf';
		$this->mapURIs($map, array(
						   'feature_class' => RDF::gn.'featureClass',
						   'feature_code' => RDF::gn.'featureCode',
						   'is_defined_by' => RDF::rdfs.'isDefinedBy',
						   'nearby_features' => RDF::gn.'nearbyFeatures',
						   ));
		return $map;
	}
}


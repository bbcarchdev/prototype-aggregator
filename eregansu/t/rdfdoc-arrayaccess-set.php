<?php

/* Copyright 2011 Mo McRoberts.
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

uses('rdf');

class TestRDFDocArrayAccessSet extends TestHarness
{
	public function main()
	{
		$doc = RDF::documentFromFile('data/rdfdoc-arrayaccess.xml', 'http://example.com/sample');
		if(!is_object($doc))
		{
			echo "Failed to parse document\n";
			return false;
		}		
		$subj = new RDFInstance('http://example.com/sample#bar');
		$subj['rdfs:label'] = 'Sample thing bar';
		$doc[] = $subj;		
		$subj = $doc['http://example.com/sample#bar'];
		if(!is_object($subj))
		{
			echo "Failed to locate topic after adding to document\n";
			return false;
		}
		$subj = strval($subj->subject());
		if(strcmp($subj, 'http://example.com/sample#bar'))
		{
			echo "Subject expected to be <http://example.com/sample#bar> is <$subj>\n";
			return false;
		}
		return true;
	}
}

return 'TestRDFDocArrayAccessSet';


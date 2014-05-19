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

/* Installer class for the Wikipedia interface */

class GeonamesModuleInstall extends ModuleInstaller
{
	public $moduleOrder = 900;
	
	public function writeAppConfig($file)
	{
		fwrite($file, "/* Trove Geonames interface */\n");
		fwrite($file, "\$SETUP_MODULES[] = 'geonames';\n");
		fwrite($file, "\$TROVE_MATCHING_MODULES[] = 'geonames';\n");
		fwrite($file, "\$CLI_ROUTES['geonames-import'] = array('name' => 'geonames', 'file' => 'import.php', 'class' => 'GeonamesImport', 'description' => 'Import geonames data');\n");
		fwrite($file, "\$TROVE_PROVIDER_MODULES['GEONAMES_IRI'] = array(\n" .
			   "\t'name' => 'geonames', 'file' => 'provider.php', 'class' => 'GeonamesProvider', 'importers' => array(\n" .
			   "\t\t'geonames_entries' => array('file' => 'import-entry.php'),\n" .
			   "\t),\n" .
			   ");\n");
		fwrite($file, "\n");
	}

	public function writeInstanceConfig($file)
	{
		$this->writePlaceholderDBIri($file, 'GEONAMES_IRI');
		fwrite($file, "/* For the provider only:\n" .
			   " * To store geonames entries on disk instead of within the database,\n" .
			   " * uncomment the below:\n" .
			   " */\n" .
			   "/* define('GEONAMES_DISK_STORE', true); */\n");
		fwrite($file, "/* For the matcher only:\n" .
			   " * Specify an alternate base URL for the Geonames RDF resources.\n" .
			   " */\n" .
			   "/* define('GEONAMES_BASE_URL', 'http://sws.geonames.org/'); */\n");		
	}
}

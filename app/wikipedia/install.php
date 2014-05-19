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

class WikipediaModuleInstall extends ModuleInstaller
{
	public function writeAppConfig($file)
	{
		fwrite($file, "/* Trove Wikipedia interface */\n");
		fwrite($file, "\$TROVE_MATCHING_MODULES[] = 'wikipedia';\n");
		fwrite($file, "\n");
	}

	public function writeInstanceConfig($file)
	{
		fwrite($file, "/* Uncomment the below to specify how many dbpedialite results should\n" .
			   " * be evaluated for similarity (default is 5).\n" .
			   " */\n" .
			   "/* define('WIKIPEDIA_MATCH_RESULTS', 5); */\n");
	}
}

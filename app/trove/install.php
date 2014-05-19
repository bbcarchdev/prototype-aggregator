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

/* Installer class for the trove aggregator */

class TroveModuleInstall extends ModuleInstaller
{
	public $moduleOrder = 800;
	
	public function writeAppConfig($file)
	{
		fwrite($file, "/* Trove aggregator */\n");
		fwrite($file, "\$SETUP_MODULES[] = 'trove';\n");
		fwrite($file, "\$CLI_ROUTES['trove'] = array('name' => 'trove', 'file' => 'cli.php', 'class' => 'TroveCLI', 'description' => 'Trove command-line interface', 'adjustBase' => true);\n");
//		fwrite($file, "\$TROVE_MATCHING_MODULES[] = array('name' => 'trove', 'file' => 'timematcher.php', 'class' => 'TroveTimeMatcher');\n");
		fwrite($file, "\n");
		fwrite($file, "\$TROVE_GENERATORS[] = array('name' => 'trove', 'file' => 'generators/type.php', 'class' => 'TroveTypeGenerator');\n");
		fwrite($file, "\$TROVE_GENERATORS[] = array('name' => 'trove', 'file' => 'generators/summary.php', 'class' => 'TroveSummaryGenerator');\n");
		fwrite($file, "\$TROVE_GENERATORS[] = array('name' => 'trove', 'file' => 'generators/depiction.php', 'class' => 'TroveDepictionGenerator');\n");
		fwrite($file, "\$TROVE_GENERATORS[] = array('name' => 'trove', 'file' => 'generators/refs.php', 'class' => 'TroveRefsGenerator');\n");
		fwrite($file, "\$TROVE_GENERATORS[] = array('name' => 'trove', 'file' => 'generators/score.php', 'class' => 'TroveScoreGenerator');\n");
		fwrite($file, "\$TROVE_GENERATORS[] = array('name' => 'trove', 'file' => 'generators/geo.php', 'class' => 'TroveScoreGenerator');\n");
		fwrite($file, "\n");
	}
	
	public function writeInstanceConfig($file)
	{
		$this->writePlaceholderDBIri($file, 'TROVE_IRI');
		fwrite($file, "/* Uncomment the below to make pushes lazy: that is, objects will be stored,\n" .
			   " * but will not be evaluated immediately -- you will need to evaluate them\n" .
			   " * either by a periodic run of the evaluator, or through an ongoing process.\n" .
			   " * This has no effect when communicating with a remote instance (i.e.,\n" .
			   " * TROVE_IRI begins http: or https:\n" .
			   " */\n");
		fwrite($file, "/* define('TROVE_PUSH_IS_LAZY', true); */\n");
		fwrite($file, "/* Uncomment the below to enable debugging output */\n");
		fwrite($file, "/* define('TROVE_DEBUG', true); */\n");
		fwrite($file, "/* Uncomment the below to customise public-facing location of depictions */\n");
		fwrite($file, "/* define('TROVE_DEPICTIONS_IRI', '/images/'); */\n");
		fwrite($file, "\n");
	}
}

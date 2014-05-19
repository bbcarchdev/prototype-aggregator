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

class TroveViewModuleInstall extends ModuleInstaller
{
	public $moduleOrder = 825;
	
	public function writeAppConfig($file)
	{
		fwrite($file, "/* Trove viewer */\n");
		fwrite($file, "\$HTTP_ROUTES['__DEFAULT__'] = array('name' => 'troveview', 'file' => 'home.php', 'class' => 'TroveHomePage');\n");
		fwrite($file, "\$HTTP_ROUTES['index'] = array('name' => 'troveview', 'file' => 'home.php', 'class' => 'TroveHomePage');\n");
		fwrite($file, "\$HTTP_ROUTES['status'] = array('name' => 'troveview', 'file' => 'status.php', 'class' => 'TroveStatusPage');\n");
		fwrite($file, "\$HTTP_ROUTES['collections'] = array('name' => 'troveview', 'file' => 'list.php', 'class' => 'TroveListPage', 'kind' => 'collection', 'title' => 'Collections');\n");
		fwrite($file, "\$HTTP_ROUTES['things'] = array('name' => 'troveview', 'file' => 'list.php', 'class' => 'TroveListPage', 'kind' => 'thing', 'title' => 'Things');\n");
		fwrite($file, "\$HTTP_ROUTES['people'] = array('name' => 'troveview', 'file' => 'list.php', 'class' => 'TroveListPage', 'kind' => 'person', 'title' => 'People');\n");
		fwrite($file, "\$HTTP_ROUTES['places'] = array('name' => 'troveview', 'file' => 'list.php', 'class' => 'TroveListPage', 'kind' => 'place', 'title' => 'Places');\n");
		fwrite($file, "\$HTTP_ROUTES['events'] = array('name' => 'troveview', 'file' => 'list.php', 'class' => 'TroveListPage', 'kind' => 'event', 'title' => 'Events');\n");
		fwrite($file, "\$HTTP_ROUTES['all'] = array('name' => 'troveview', 'file' => 'list.php', 'class' => 'TroveListPage', 'kind' => array('collection', 'thing', 'person', 'place', 'event'), 'title' => 'All objects');\n");
		fwrite($file, "\$HTTP_ROUTES['millennia'] = array('name' => 'troveview', 'file' => 'millennia.php', 'class' => 'TroveMillenniaListPage', 'title' => 'Millennia');\n");
		fwrite($file, "\$HTTP_ROUTES['centuries'] = array('name' => 'troveview', 'file' => 'centuries.php', 'class' => 'TroveCenturiesListPage', 'title' => 'Centuries');\n");
		fwrite($file, "\$HTTP_ROUTES['decades'] = array('name' => 'troveview', 'file' => 'decades.php', 'class' => 'TroveDecadesListPage', 'title' => 'Centuries');\n");
		fwrite($file, "\$HTTP_ROUTES['years'] = array('name' => 'troveview', 'file' => 'years.php', 'class' => 'TroveYearsListPage', 'title' => 'Years');\n");
		fwrite($file, "\$HTTP_ROUTES['months'] = array('name' => 'troveview', 'file' => 'months.php', 'class' => 'TroveMonthsListPage', 'title' => 'Months');\n");
		fwrite($file, "\$HTTP_ROUTES['ns'] = array('name' => 'troveview', 'file' => 'ns.php', 'class' => 'TroveNamespacePage', 'title' => 'Namespaces');\n");
		fwrite($file, "\n");
	}
}

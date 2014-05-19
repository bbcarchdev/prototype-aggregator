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

require_once(dirname(__FILE__) . '/datebrowser.php');
	
class TroveMonthsListPage extends TroveDateBrowser
{
	protected $class = 'http://projectreith.com/ns/Month';

	protected function populateQuery(&$args)
	{
		if(isset($this->within))
		{
			$this->class = 'http://projectreith.com/ns/YearMonth';
		}
		return parent::populateQuery($args);
	}
	
	protected function forwardToProxy($route, &$args)
	{
		if(!($obj = $this->model->objectForIri('urn:x-time:millennium:' . $route)))
		{
			return $this->error(Error::OBJECT_NOT_FOUND);
		}
		if(!($stubObj = $this->stubForObject($obj)))
		{
			return $this->error(Error::OBJECT_NOT_FOUND);
		}
		$kind = $this->request->consume();
		if(strlen($kind))
		{
			return $this->childListBrowser($stubObj, $kind);
		}
		$this->redirectToObject($stubObj);
	}
}

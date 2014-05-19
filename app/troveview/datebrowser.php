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

require_once(dirname(__FILE__) . '/list.php');

abstract class TroveDateBrowser extends TroveListPage
{
	protected $kind = 'event';
	protected $order = 'start_year,start_month,start_day';
	protected $class = null;
	protected $within = null;

	protected function populateQuery(&$args)
	{
		if(strlen($this->class))
		{
			$args['class'] = $this->class;
		}
		if(isset($this->within))
		{
			$args['tags'][] = $this->model->uuidOfObject($this->within);
		}
		if(!parent::populateQuery($args))
		{
			return false;
		}
		unset($args['parent?']);
		return true;
	}

	protected function childListBrowser($parent, $kind)
	{
		$className = null;
		$title = null;
		$ptitle = '(None)';
		if(is_array($parent) && isset($parent[0]))
		{
			$ptitle = $parent[0]->title();
		}
		else if(is_object($parent))
		{
			$ptitle = $parent->title();
		}
		switch($kind)		   
		{
		case 'millennia':
			require_once(dirname(__FILE__) . '/millennia.php');
			$className = 'TroveMilleniaListPage';
			$title = 'Millennia';
			break;
		case 'centuries':
			require_once(dirname(__FILE__) . '/centuries.php');
			$className = 'TroveCenturiesListPage';
			$title = 'Centuries';
			break;
		case 'decades':
			require_once(dirname(__FILE__) . '/decades.php');
			$className = 'TroveDecadesListPage';
			$title = 'Decades';
			break;
		case 'years':
			require_once(dirname(__FILE__) . '/years.php');
			$className = 'TroveYearsListPage';
			$title = 'Years';
			break;			
		case 'months':
			require_once(dirname(__FILE__) . '/months.php');
			$className = 'TroveMonthsListPage';
			$title = 'Months';
			break;			
		default:
			return $this->error(Error::OBJECT_NOT_FOUND);
		}
		if(strlen($ptitle))
		{
			$title .= ' in ' . $ptitle;
		}
		$inst  = new $className();
		$inst->within = $parent;
		$this->request->data['title'] = $title;
		$inst->process($this->request);
		return false;
	}
}

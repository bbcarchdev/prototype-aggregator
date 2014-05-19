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

class TroveCLI extends App
{
	public function __construct()
	{
		parent::__construct();
		$this->sapi['cli']['eval'] = array('file' => 'cli-eval.php', 'class' => 'TroveEvalCLI');
		$this->sapi['cli']['evaluate'] = array('file' => 'cli-eval.php', 'class' => 'TroveEvalCLI', 'description' => 'Evaluate entities');
		$this->sapi['cli']['gen'] = array('file' => 'cli-generate.php', 'class' => 'TroveGenerateCLI', 'description' => 'Generate stubs');
		$this->sapi['cli']['index'] = array('file' => 'cli-index.php', 'class' => 'TroveIndexCLI');
		$this->sapi['cli']['indexer'] = array('file' => 'cli-index.php', 'class' => 'TroveIndexCLI', 'description' => 'Re-index objects');
		$this->sapi['cli']['dump'] = array('file' => 'cli-dump.php', 'class' => 'TroveDumpCLI', 'description' => 'Dump an object');
		$this->sapi['cli']['gen-dates'] = array('file' => 'cli-gen-dates.php', 'class' => 'TroveGenerateDatesCLI', 'description' => 'Generate date objects');
		$this->sapi['cli']['ingest'] = array('file' => 'cli-ingest.php', 'class' => 'TroveIngestCLI', 'description' => 'Ingest from remote sources');
	}
}


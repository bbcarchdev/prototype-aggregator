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

/* This is the trove similarity engine: a rather naïve algorithm for
 * evaluting the similarity between two documents.
 *
 * The value returned is floating point value between 0 (utterly distinct)
 * and 1 (exactly the same) — at least, those are the meanings in theory.
 *
 * The algorithm is based upon a combination of term frequency and
 * limited-state Markov chains.
 *
 * Note that this is not a bidirectional algorithm: quite often the matches
 * attempted will be highly unbalanced. For example, $docA might simply be a
 * title, while $docB is a complete article. evaluate() should be interpreted
 * as being "score the terms in $docA according to their prominence in $docB",
 * *however*, it will automatically swap the documents over $docA isn't the
 * shorter of the two.
 */ 

class TroveSimilarity
{
	public static $stopwords = array(
		'a', 'about', 'above', 'above', 'across', 'after', 'afterwards', 'again', 'against', 'all', 'almost', 'alone', 'along', 'already', 'also','although','always','am','among', 'amongst', 'amoungst', 'amount',  'an', 'and', 'another', 'any','anyhow','anyone','anything','anyway', 'anywhere', 'are', 'around', 'as',
		'at', 'back','be','became', 'because','become','becomes', 'becoming', 'been', 'before', 'beforehand', 'behind', 'being', 'below', 'beside', 'besides', 'between', 'beyond', 'bill', 'both', 'bottom','but', 'by',
		'call', 'can', 'cannot', 'cant', 'co', 'con', 'could', 'couldnt', 'cry',
		'de', 'describe', 'detail', 'do', 'done', 'down', 'due', 'during',
		'each', 'eg', 'eight', 'either', 'eleven','else', 'elsewhere', 'empty', 'enough', 'etc', 'even', 'ever', 'every', 'everyone', 'everything', 'everywhere', 'except',
		'few', 'fifteen', 'fify', 'fill', 'find', 'fire', 'first', 'five', 'for', 'former', 'formerly', 'forty', 'found', 'four', 'from', 'front', 'full', 'further',
		'get', 'give', 'go', 'had',
		'has', 'hasnt', 'have', 'he', 'hence', 'her', 'here', 'hereafter', 'hereby', 'herein', 'hereupon', 'hers', 'herself', 'him', 'himself', 'his', 'how', 'however', 'hundred',
		'ie', 'if', 'in', 'inc', 'indeed', 'interest', 'into', 'is', 'it', 'its', 'itself',
		'keep', 'last', 'latter', 'latterly', 'least', 'less', 'ltd',
		'made', 'many', 'may', 'me', 'meanwhile', 'might', 'mill', 'mine', 'more', 'moreover', 'most', 'mostly', 'move', 'much', 'must', 'my', 'myself',
		'name', 'namely', 'neither', 'never', 'nevertheless', 'next', 'nine', 'no', 'nobody', 'none', 'noone', 'nor', 'not', 'nothing', 'now', 'nowhere',
		'of', 'off', 'often', 'on', 'once', 'one', 'only', 'onto', 'or', 'other', 'others', 'otherwise', 'our', 'ours', 'ourselves', 'out', 'over', 'own',
		'part', 'per', 'perhaps', 'please', 'put',
		'rather', 're',
		'same', 'see', 'seem', 'seemed', 'seeming', 'seems', 'serious', 'several', 'she', 'should', 'show', 'side', 'since', 'sincere', 'six', 'sixty', 'so', 'some', 'somehow', 'someone', 'something', 'sometime', 'sometimes', 'somewhere', 'still', 'such', 'system',
		'take', 'ten', 'than', 'that', 'the', 'their', 'them', 'themselves', 'then', 'thence', 'there', 'thereafter', 'thereby', 'therefore', 'therein', 'thereupon', 'these', 'they', 'thick', 'thin', 'third', 'this', 'those', 'though', 'three', 'through', 'throughout', 'thru', 'thus', 'to', 'together', 'too', 'top', 'toward', 'towards', 'twelve', 'twenty', 'two',
		'un', 'under', 'until', 'up', 'upon', 'us',
		'very', 'via',
		'was', 'we', 'well', 'were', 'what', 'whatever', 'when', 'whence', 'whenever', 'where', 'whereafter', 'whereas', 'whereby', 'wherein', 'whereupon', 'wherever', 'whether', 'which', 'while', 'whither', 'who', 'whoever', 'whole', 'whom', 'whose', 'why', 'will', 'with', 'within', 'without', 'would', 
		'yet', 'you', 'your', 'yours', 'yourself', 'yourselves');

	public function evaluate($docA, $docB, $useMetaphones = true)
	{
		$docA = $this->normalise($docA, true, $useMetaphones);
		$docB = $this->normalise($docB, true, $useMetaphones);
		$aterms = $this->evaluateTerms($docA);
		$terms = $this->evaluateTerms($docB);
		if(!count($aterms) || !count($terms))
		{
			return 0;
		}
		if(count($aterms) > count($terms))
		{
			/* If docA consists of more terms than docB, swap them over */
			$t = $docB;
			$docB = $docA;
			$docA = $t;
			$t = $aterms;
			$aterms = $terms;
			$terms = $t;
		}
		/* $ratio is the ratio of the size (in terms) of docA vs docB */
		if(!count($terms))
		{
			print_r($docB);
			trigger_error('No terms to evaluate against', E_USER_NOTICE);
			die();
		}
		$ratio = count($aterms) / count($terms);
		$this->adjustFrequencies($terms);
		$this->adjustFrequencies($aterms);
		$score = $this->scoreTermLists($aterms, $terms, $ratio);
		return $score;
	}

	protected function scoreTermLists($shorter, $longer, $ratio = 0)
	{
		$tscore = 0;
		$ttotal = 0;
		foreach($shorter as $k => $termInfo)
		{
			$weight = asin(pow($termInfo['@weight'], (1 + (3 * $ratio))) * sin(1));
//			echo "Adjusted weight is $weight, was " . $termInfo['@weight'] . "\n";
			$score = $total = 0;
			$term = $termInfo['@term'];
			$total += 1 * $weight;
			if(isset($longer[$term]))
			{
//				echo "Found " . $term . " (source=" . $termInfo['@value'] . ", corpus=" . $longer[$term]['@value'] . ")\n";
				$score += $longer[$term]['@value'] * $weight;
				$scale = asin(pow($longer[$term]['@weight'], (1 + (3 * $ratio))) * sin(1));
			}
			else
			{
//				echo "Did not find " . $term . ")\n";
				$scale = $weight;
			}
			foreach($termInfo as $next => $freq)
			{
				if(substr($next, 0, 1) == '@' && (strcmp($next, '@end')))
				{
					continue;
				}
				$total += $freq * $weight * $scale;
				if(isset($longer[$term][$next]))
				{
//					echo "Found " . $term . " => " . $next . " (source=" . $freq . ", corpus=" . $longer[$term][$next] . ")\n";
					$score += $freq * $longer[$term][$next] * $weight * $scale;
				}
				else
				{
//					echo "Did not find " . $term . " => " . $next . " (source=" . $freq . ")\n";
				}
			}
			if($total)
			{
				$tscore += $score;
				$ttotal += $total;
				$shorter[$k]['@match'] = $score;
				$shorter[$k]['@total'] = $total;
			}
			else
			{
				$shorter[$k]['@match'] = 0;
				$shorter[$k]['@total'] = 0;
			}
		}
//		print_r($shorter);
//		echo "Total Score: " . $tscore . " / " . $ttotal . "\n";
		$tscore = round(($tscore * 100) / $ttotal);
//		echo "Final score: " . $tscore . "%\n";
//		die();
		return $tscore;
	}

	/** Evaluate phrases and build a term list
	 *
	 * Build a term list in the form
	 *    'term' => array(...)
	 * Where the content of the array is:
	 *    array(
	 *        '@term' => 'term',  -- term text, as in list key above
	 *        '@freq' => 0..n,   -- frequency of that term appearing
	 *        '@end' => 0,        -- how often term ends a phrase
	 *        '@weight' => 0..1   -- maximum weighting factor
	 *        'term2' => 0..n,    -- how often 'term2' follows 'term'
	 */

	protected function evaluateTerms($doc)
	{
		$terms = array();
		foreach($doc as $lineInfo)
		{
			$weight = $lineInfo['weight'];
			$line = $lineInfo['terms'];			
			foreach($line as $index => $term)
			{				
				if(!isset($terms[$term]))
				{
					$terms[$term] = array('@term' => $term, '@freq' => 0, '@end' => 0, '@weight' => 0);
				}
				$terms[$term]['@freq']++;
				if($terms[$term]['@weight'] < $weight)
				{
					$terms[$term]['@weight'] = $weight;
				}
				if(isset($line[$index + 1]))
				{
					$next = $line[$index + 1];
					if(!isset($terms[$term][$next]))
					{
						$terms[$term][$next] = 0;
					}
					$terms[$term][$next]++;
				}
				else
				{
					$terms[$term]['@end']++;
				}	   
			}
		}
		return $terms;
	}

	/** Adjust term frequencies according to weighting factors
	 *
	 * $termList must be an array of arrays generated by evaluateTerms()
	 *
	 * For each term calculate a final frequency score ($term['@value'])
	 * calculated as:
	 *
	 * The frequency of term divided by the maximum frequency across
	 * all terms (e.g., if the highest frequency value is 47, then
	 * the valie will $term['@freq'] / 47.
	 *
	 * Perform the same for each of the 'following' terms (i.e., the
	 * pairs within each term array), including '@end', using the maximum
	 * frequency value amongst all of pairs for the single parent as
	 * the divisor.
	 */

	protected function adjustFrequencies(&$terms)
	{
		$c = 0;
		/* Calculate the overall divisor*/
		$freq = array();
		foreach($terms as $term)
		{
			if(!in_array($term['@freq'], $freq))
			{
				$freq[] = $term['@freq'];
			}
		}
		rsort($freq);
		$divisor = $freq[0];
		/* Calculate $term['@value'] for each term */
		foreach($terms as $k => $term)
		{
			$term['@value'] = ($term['@freq'] / $divisor);			
			/* Calculate the following-term divisor, $tdivisor */
			$tfreq = array();
			foreach($term as $tk => $nfreq)
			{
				if($tk[0] == '@' && $tk != '@end')
				{
					continue;
				}
				if(!in_array($nfreq, $tfreq))
				{
					$tfreq[] = $nfreq;
				}
			}
			rsort($tfreq);
			$tdivisor = $tfreq[0];
			/* Calculate the value using $tdivisor as we did for the 
			 * term itself above
			 */
			foreach($term as $tk => $nfreq)
			{
				if($tk[0] == '@' && $tk != '@end')
				{
					continue;
				}
				$term[$tk] = ($nfreq / $tdivisor);
			}
			$terms[$k] = $term;
			$c++;
		}
		return $terms;
	}

	/**
	 * Obtain a word list from a corpus
	 */
	public function wordList($corpus, $splitWords = true, $metaphone = false)
	{
		return $this->normalise($corpus, $splitWords, $metaphone);
	}

	/**
	 * Normalise a body of text into a set of phrases
	 *
	 * $set may be a newline-separated set of strings of equal weights or
	 * may be an array of phrases. In the latter case, each phrase may
	 * itself be an array of the form ($phrase, $weight) where $weight
	 * is a value between 0..1.
	 * Where a phrase is not an array (i.e., no $weight is specified)
	 * a default value of 1 is used.
	 */
	protected function normalise($set, $splitWords = false, $metaphone = false)
	{
		static $replace = array(
			"'" => '',
			"\t" => ' ',
			"\r" => "\n",
			'(' => "\n",
			')' => "\n",
			',' => "\n",
			'.' => "\n",
			'"' => "\n",
			);
		if(!is_array($set))
		{
			$set = explode("\n", $set);
		}
		$doc = array();
		foreach($set as $str)
		{
			if(is_array($str))
			{
				$weight = $str[1];
				$str = $str[0];
			}
			else
			{
				$weight = 1;
			}
			$str = mb_convert_encoding($str, 'ASCII');
//		$str = iconv('UTF-8', 'ASCII//IGNORE//TRANSLIT', $str);
			$str = strtolower(str_replace(array_keys($replace), array_values($replace), $str));
			$str = preg_replace('![^0-9a-z-\n]!i', ' ', $str);
			$strings = explode("\n", $str);
			foreach($strings as $v)
			{
				$v = trim(str_replace("  ", " ", $v));
				if(!strlen($v))
				{
					continue;
				}
				if($splitWords)
				{
					$list = explode(' ', $v);
					
					foreach($list as $wk => $wv)
					{
						if(preg_match('/^(urn:uuid:)?\{?[a-f0-9]{8}-?[a-f0-9]{4}-?[a-f0-9]{4}-?[a-f0-9]{4}-?[a-f0-9]{12}\}?$/i', $wv))
						{
							unset($list[$wk]);
							continue;
						}
						if(in_array($wv, self::$stopwords) || !strlen($wv) || !strcmp($wv, '-'))
						{
							unset($list[$wk]);
							continue;
						}
						if($metaphone)
						{
							$wv = metaphone($wv);
							if(!strlen($wv))
							{
								unset($list[$wk]);
								continue;
							}
						}
						$list[$wk] = $wv;
					}
				}
				$doc[] = array('weight' => $weight, 'terms' => array_values($list));
			}
		}
		return array_values($doc);
	}	
}

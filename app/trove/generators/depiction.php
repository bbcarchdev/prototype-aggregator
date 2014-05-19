<?php

ini_set('memory_limit', '1024M');

/**
 * Trove Depiction Generator
 *
 * Constructs depiction information on stub objects.
 *
 * @year 2011
 */

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

/**
 * TroveDepictionGenerator contains the implementation of the stub-generation
 * logic which handles depictions. For each exactly-matching entity, any
 * foaf:depiction URIs are fetched, and if they contain an image, cached
 * locally. A foaf:depiction predicate is then added to the stub referring to
 * that locally-cached version.
 *
 * An anciliary instance is added to stub, with a subject of the local
 * version's URI, containing exif:imageWidth and exif:imageHeight properties,
 * an owl:sameAs pointing at the source image's URL, and a dct:source
 * whose object is the entity whose depiction is being described.
 *
 * For each of the sets of image dimensions listed in
 * TroveDepictionGenerator::$sizes, a thumbnail is generated (proportions
 * maintained, always cropping rather than filling), and a further
 * anciliary instance added describing it. A dct:hasVersion reference is
 * added from the instance describing local copy of the original image
 * to the URI of the thumbnail.
 */

if(!defined('TROVE_DEPICTIONS_IRI')) define('TROVE_DEPICTIONS_IRI', '/images/');

class TroveDepictionGenerator extends TroveGenerator
{
	/**
	 * The list of sizes array(width, height) for which thumbnails
	 * should be generated. If the source image is smaller in either
	 * dimension than a given pair, that thumbnail won't be generated.
	 */
	protected $sizes = array(
		array(32, 32),
		array(64, 64),
		array(128, 128),
		array(512, 512),
		array(640, 360),
		array(832, 468),
		array(118, 87),
		array(976, 360),
		);

	/**
	 * Generate depiction data for a stub
	 */
	public function generate($stubUuid, TroveMap $objects, &$stubSet)
	{
		$local = $this->model->localDepictions($stubUuid);
		foreach($local as $uri)
		{
			$this->ingestDepiction($stubUuid, null, $uri, $stubSet);
		}
		foreach($objects['exactMatch'] as $match)
		{
			$obj = $this->model->firstObject($match);
			$local = $this->model->localDepictions($obj->uuid);
			foreach($local as $uri)
			{
				$this->ingestDepiction($obj->uuid, $obj->subject(), $uri, $stubSet);
			}
			$set = RDFSet::setFromInstances(RDF::foaf.'depiction', $obj)->uris();
			foreach($set as $uri)
			{
				$this->ingestDepiction($obj->uuid, $obj->subject(), $uri, $stubSet);
			}
		}		
	}

	/**
	 * Ingest an image and express it as a depiction of the stub.
	 *
	 * The image URL ($image) is retrieved and stored locally in a directory
	 * named according to the source's UUID. If the local version doesn't
	 * already exist, it (currently) won't be re-fetched. Once fetched,
	 * thumbnails are created, anciliary instances are added to $stubSet for
	 * each of the image versions, and a dct:depiction property is added
	 * to the stub.
	 *
	 * @internal
	 */
	protected function ingestDepiction($sourceUuid, $sourceSubject, $image, &$stubSet)
	{
		if(preg_match('!^http://www.bbc.co.uk/iplayer/images/episode/([^_]+)_512_288.jpg$!', $image, $matches))
		{
			if($this->ingestDepiction($sourceUuid, $sourceSubject, 'http://www.bbc.co.uk/iplayer/images/episode/' . $matches[1] . '_832_468.jpg', $stubSet))
			{
				return true;
			}
			if($this->ingestDepiction($sourceUuid, $sourceSubject, 'http://www.bbc.co.uk/iplayer/images/episode/' . $matches[1] . '_640_360.jpg', $stubSet))
			{
				return true;
			}
		}
		if(preg_match('!^http://www.bbc.co.uk/iplayer/images/series/([^_]+)_512_288.jpg$!', $image, $matches))
		{
			if($this->ingestDepiction($sourceUuid, $sourceSubject, 'http://www.bbc.co.uk/iplayer/images/series/' . $matches[1] . '_832_468.jpg', $stubSet))
			{
				return true;
			}
			if($this->ingestDepiction($sourceUuid, $sourceSubject, 'http://www.bbc.co.uk/iplayer/images/series/' . $matches[1] . '_640_360.jpg', $stubSet))
			{
				return true;
			}
		}
		$this->debug('Ingesting', $image, 'for', $sourceSubject, '{' . $sourceUuid . '}');
		$stem = $sourceUuid . '/';
		$path = PUBLIC_ROOT . 'images/';
		if(!file_exists($path))
		{
			mkdir($path, 0777, true);
			chmod($path, (0777 & ~umask() | 0555));
		}
		$path .= $sourceUuid . '/';
		if(!file_exists($path))
		{
			mkdir($path, 0777, true);
			chmod($path, (0777 & ~umask() | 0555));
		}
		$hash = md5($image);
		if(!file_exists($path . $hash . '.png'))
		{
			if(!$this->fetchImage($image, $path))
			{
				return;
			}
		}
		$depiction = $this->constructDepiction($path . $hash . '.png', TROVE_DEPICTIONS_IRI . $stem . $hash . '.png', 'image/png', $sourceSubject);
		$depiction[RDF::owl.'sameAs'] = array(
			array('type' => 'uri', 'value' => strval($image)),
			);
		foreach($this->sizes as $size)
		{
			$name = $hash . '-' . $size[0] . 'x' . $size[1] . '.png';
			if(file_exists($path . $name))
			{
				$ver = $this->constructDepiction($path . $name, TROVE_DEPICTIONS_IRI . $stem . $name, 'image/png', $sourceSubject);
				$stubSet[] = $ver;
				$depiction[RDF::dcterms.'hasVersion'][] = array('type' => 'uri', 'value' => TROVE_DEPICTIONS_IRI . $stem . $name);
			}
		}
		$stubSet[] = $depiction;
		$stubSet[0][RDF::foaf.'depiction'][] = array('type' => 'uri', 'value' => TROVE_DEPICTIONS_IRI . $stem . $hash . '.png');
		return true;
	}

	/**
	 * Construct the data for a depiction, given its local path, public URI,
	 * MIME type and source URI.
	 *
	 * @internal
	 */
	protected function constructDepiction($localPath, $uri, $mimeType, $source)
	{
		$size = getimagesize($localPath);
		$depiction = array(
			RDF::rdf.'type' => array(
				array('type' => 'uri', 'value' => RDF::foaf.'Image'),
				),
			RDF::rdf.'about' => array(
				array('type' => 'uri', 'value' => $uri),
				),
			RDF::dcterms.'format' => array(
				array('type' => 'uri', 'value' => 'http://purl.org/NET/mediatypes/' . $mimeType),
				),
			RDF::exif.'imageWidth' => array($size[0]),
			RDF::exif.'imageHeight' => array($size[1]),
			);
		if(strlen($source))
		{
			$depiction[RDF::dcterms.'source'] = array(
				array('type' => 'uri', 'value' => strval($source)),
				);
		}
		return $depiction;
	}

	/**
	 * Fetch an image, store it (in PNG format) within a specified path,
	 * and generate thumbnails for each of TroveDepictionGenerator::$sizes.
	 *
	 * @internal
	 */
	protected function fetchImage($uri, $path)
	{
		$hash = md5($uri);
		$cc = new CurlCache($uri);
		$cc->followLocation = true;
		$cc->returnTransfer = true;
		$buf = $cc->exec();
		$info = $cc->info;
		switch(@$info['content_type'])
		{
		case 'image/png':
		case 'image/jpeg':
		case 'image/gif':			
			$original = imagecreatefromstring($buf);
			break;
		default:
			return false;
		}
		$name = $hash . MIME::extForType($info['content_type']);			
		$f = fopen($path . $name, 'wb');
		fwrite($f, $buf);
		fclose($f);
		$cc = null;
		$buf = null;
		$width = imagesx($original);
		$height = imagesy($original);
		$source = imagecreatetruecolor($width, $height);
		imagealphablending($source, false);
		imagesavealpha($source, true); 
		imagecopyresampled($source, $original, 0, 0, 0, 0, $width, $height, $width, $height);
		if($info['content_type'] != 'image/png')
		{
			imagepng($source, $path . $hash . '.png');
		}
		chmod($path . $hash . '.png', (0666 & ~umask()) | 0444);
		foreach($this->sizes as $size)
		{
			$this->tryResize($source, $path . $hash, $size[0], $size[1]);
		}
		return $path . $hash . '.png';
	}

	/**
	 * Attempt to resize a source image (supplied as GD image data) to
	 * a specified width and height, storing in PNG format at the
	 * given absolute file path.
	 *
	 * If either of $tWidth or $tHeight are larger than the source image's
	 * dimensions, no thumbnail will be generated.
	 *
	 * This function always resizes preserving the aspect ratio of the
	 * source image, and always crops rather than padding.	 
	 *
	 * @internal
	 */
	protected function tryResize($source, $destPath, $tWidth, $tHeight)
	{
		$width = imagesx($source);
		$height = imagesy($source);
		if($width < $tWidth || $height < $tHeight)
		{
			return;
		}
		/* Resize based on the longest target size */
		if($tWidth > $tHeight)
		{
			$rWidth = $tWidth;
			$rHeight = floatval($height) * (floatval($tWidth) / floatval($width));
		}
		else
		{
			$rHeight = $tHeight;
			$rWidth = floatval($width) * (floatval($tHeight) / floatval($height));
		}
		/* If the result is too small in some dimension, try the other way around */
		if($rHeight < $tHeight)
		{
			$rHeight = $tHeight;
			$rWidth = floatval($width) * (floatval($tHeight) / floatval($height));
		}
		else if($rWidth < $tWidth)
		{
			$rWidth = $tWidth;
			$rHeight = floatval($height) * (floatval($tWidth) / floatval($width));
		}			
		$xDiff = floor(($rWidth - $tWidth) / 2);
		$yDiff = floor(($rHeight - $tHeight) / 2);
		$dest = imagecreatetruecolor($tWidth, $tHeight);
		imagealphablending($dest, false);
		imagesavealpha($dest, true);
		imagecopyresampled($dest, $source, -$xDiff, -$yDiff, 0, 0, $rWidth, $rHeight, $width, $height);
		imagepng($dest, $destPath . '-' . $tWidth . 'x' . $tHeight . '.png');
		chmod($destPath . '-' . $tWidth . 'x' . $tHeight . '.png', (0666 & ~umask()) | 0444);
	}

}

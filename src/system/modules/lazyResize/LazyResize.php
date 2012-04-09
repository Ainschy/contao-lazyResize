<?php if (!defined('TL_ROOT')) die('You can not access this file directly!');

/**
 * Avisota newsletter and mailing system
 * Copyright (C) 2010,2011 Tristan Lins
 *
 * Extension for:
 * Contao Open Source CMS
 * Copyright (C) 2005-2010 Leo Feyer
 *
 * Formerly known as TYPOlight Open Source CMS.
 *
 * This program is free software: you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation, either
 * version 3 of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this program. If not, please visit the Free
 * Software Foundation website at <http://www.gnu.org/licenses/>.
 *
 * PHP version 5
 * @copyright  InfinitySoft 2010,2011
 * @author     Tristan Lins <tristan.lins@infinitysoft.de>
 * @package    Avisota
 * @license    LGPL
 * @filesource
 */


class LazyResize extends PageError404
{
	protected static $objInstance = null;

	public static function getInstance()
	{
		if (self::$objInstance === null) {
			self::$objInstance = new LazyResize();
		}
		return self::$objInstance;
	}

	public function hookGetImage($image, $width, $height, $mode, $strCacheName, $objFile, $target)
	{
		if (!isset($GLOBALS['lazyResize']) || !$GLOBALS['lazyResize'] || !empty($target)) {
			return false;
		}

		$strContaoCacheName = $strCacheName;
		$strCacheName = 'system/images/' . basename($strContaoCacheName);
		$strCacheMeta = $strCacheName . '.meta';

		// Return the path of the new image if it exists already
		if (!$GLOBALS['TL_CONFIG']['debugMode'] &&
			file_exists(TL_ROOT . '/' . $strCacheName) &&
			file_exists(TL_ROOT . '/' . $strCacheMeta))
		{
			return $strCacheName;
		}

		$intWidth = $width;
		$intHeight = $height;

		// Mode-specific changes
		if ($intWidth && $intHeight)
		{
			switch ($mode)
			{
				case 'proportional':
					if ($objFile->width >= $objFile->height)
					{
						unset($height, $intHeight);
					}
					else
					{
						unset($width, $intWidth);
					}
					break;

				case 'box':
					if (ceil($objFile->height * $width / $objFile->width) <= $intHeight)
					{
						unset($height, $intHeight);
					}
					else
					{
						unset($width, $intWidth);
					}
					break;
			}
		}

		// Calculate the image size
		if ($intWidth && $intHeight)
		{
			if (($intWidth * $objFile->height) != ($intHeight * $objFile->width))
			{
				$intWidth = ceil($objFile->width * $height / $objFile->height);

				if ($intWidth < $width)
				{
					$intWidth = $width;
					$intHeight = ceil($objFile->height * $width / $objFile->width);
				}
			}
		}

		// Calculate the height if only the width is given
		elseif ($intWidth)
		{
			$intHeight = ceil($objFile->height * $width / $objFile->width);
		}

		// Calculate the width if only the height is given
		elseif ($intHeight)
		{
			$intWidth = ceil($objFile->width * $height / $objFile->height);
		}

		$resImage = imagecreate($intWidth, $intHeight);
		$resWhite = imagecolorallocate($resImage, 255, 255, 255);
		imagefill($resImage, 0, 0, $resWhite);

		$arrGdinfo = gd_info();
		$strGdVersion = preg_replace('/[^0-9\.]+/', '', $arrGdinfo['GD Version']);

		// Fallback to PNG if GIF ist not supported
		if ($objFile->extension == 'gif' && !$arrGdinfo['GIF Create Support'])
		{
			$objFile->extension = 'png';
		}

		// Create the new image
		switch ($objFile->extension)
		{
			case 'gif':
				imagegif($resImage, TL_ROOT . '/' . $strCacheName);
				break;

			case 'jpg':
			case 'jpeg':
				imagejpeg($resImage, TL_ROOT . '/' . $strCacheName, 0);
				break;

			case 'png':
				// Optimize non-truecolor images (see #2426)
				imagepng($resImage, TL_ROOT . '/' . $strCacheName);
				break;
		}

		// Destroy the temporary images
		imagedestroy($resImage);

		// Store the meta data
		$objMeta = new File($strCacheMeta);
		$objMeta->write(json_encode(array(
			'src'    => $image,
			'width'  => $width,
			'height' => $height,
			'mode'   => $mode
		), JSON_FORCE_OBJECT));
		$objMeta->close();

		// Set the file permissions when the Safe Mode Hack is used
		if ($GLOBALS['TL_CONFIG']['useFTP'])
		{
			$this->import('Files');
			$this->Files->chmod($strCacheName, 0644);
			$this->Files->chmod($strCacheMeta, 0644);
		}

		// Return the path to new image
		return $strCacheName;
	}

	public function processResize()
	{
		/**
		 * Disable lazy resize
		 */
		$GLOBALS['lazyResize'] = false;

		$strRequestUri = urldecode(urldecode($this->Environment->requestUri));
		$strDummy = 'system/images/' . basename($strRequestUri);
		$strMeta  = $strDummy . '.meta';
		$strImage = 'system/images/_' . basename($strRequestUri);

		if (file_exists(TL_ROOT . '/' . $strDummy) && file_exists(TL_ROOT . '/' . $strMeta)) {
			$objMeta = new File($strMeta);
			flock($objMeta->handle, LOCK_EX);

			// file was generated while waiting for file lock
			if (file_exists(TL_ROOT . '/' . $strImage)) {
				flock($objMeta->handle, LOCK_UN);
				$objMeta->close();
				$this->reload();
			}

			// load meta informations
			$objMetaInformation = json_decode($objMeta->getContent());
			$this->getImage($objMetaInformation->src, $objMetaInformation->width, $objMetaInformation->height, $objMetaInformation->mode, $strImage);

			flock($objMeta->handle, LOCK_UN);
			$objMeta->close();
			$this->reload();
		}

		/*
		echo '<pre>';
		var_dump();
		*/

		$this->import('FrontendUser', 'User');
		$this->User->authenticate();
		$this->generate($strRequestUri);
	}
}

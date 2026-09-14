<?php
/**
*
* This file is part of the phpBB Forum Software package.
*
* @copyright (c) phpBB Limited <https://www.phpbb.com>
* @license GNU General Public License, version 2 (GPL-2.0)
*
* For full copyright and license information, please see
* the docs/CREDITS.txt file.
*
*/

namespace phpbb\template;

/**
* Owns template handle registration and resolution.
*
* The underlying array is held by reference so the historical protected
* base::$filenames property remains authoritative for backwards compatibility.
*/
class handle_manager
{
	/** @var array */
	private $filenames;

	/**
	* @param array $filenames Canonical handle => filename map
	*/
	public function __construct(array &$filenames)
	{
		$this->filenames = &$filenames;
	}

	/**
	* Register or replace template handles without copying the whole map.
	*
	* @param array $filename_array
	*/
	public function set_filenames(array $filename_array)
	{
		foreach ($filename_array as $handle => $filename)
		{
			$this->filenames[$handle] = $filename;
		}
	}

	/**
	* Resolve a handle to its registered filename.
	*
	* @param string $handle
	* @return string
	*/
	public function get_filename($handle)
	{
		return isset($this->filenames[$handle]) ? $this->filenames[$handle] : $handle;
	}
}

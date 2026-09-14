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
* Owns root template data and prepares it for rendering.
*
* Root/scalar storage stays isolated here while loop metadata finalisation is
* delegated to row_metadata_manager.
*/
class data_manager
{
	/** @var array */
	private $tpldata = array('.' => array(0 => array()));

	/** @var array */
	private $rootref;

	/** @var bool */
	private $num_rows_is_set = false;

	/** @var \phpbb\template\row_metadata_manager */
	private $metadata_manager;

	public function __construct(row_metadata_manager|null $metadata_manager = null)
	{
		$this->metadata_manager = $metadata_manager ?: new row_metadata_manager();
		$this->clear();
	}

	public function clear()
	{
		$this->tpldata = array('.' => array(0 => array()));
		$this->rootref = &$this->tpldata['.'][0];
		$this->num_rows_is_set = false;
	}

	/**
	* Assign multiple root variables without repeated facade calls.
	*/
	public function assign_vars(array $vararray)
	{
		foreach ($vararray as $varname => $varval)
		{
			$this->rootref[$varname] = $varval;
		}

		return true;
	}

	/**
	* Retrieve multiple root variables without repeated facade calls.
	*/
	public function retrieve_vars(array $vararray)
	{
		$result = [];
		foreach ($vararray as $varname)
		{
			$result[$varname] = array_key_exists($varname, $this->rootref) ? $this->rootref[$varname] : null;
		}

		return $result;
	}

	public function assign_var(string $varname, mixed $varval)
	{
		$this->rootref[$varname] = $varval;
		return true;
	}

	public function append_var($varname, $varval)
	{
		$this->rootref[$varname] = (isset($this->rootref[$varname]) ? $this->rootref[$varname] : '') . $varval;
		return true;
	}

	public function retrieve_var($varname)
	{
		return isset($this->rootref[$varname]) ? $this->rootref[$varname] : null;
	}

	public function &get_data_ref()
	{
		if (!$this->num_rows_is_set)
		{
			$this->metadata_manager->finalize_num_rows($this->tpldata);
			$this->num_rows_is_set = true;
		}

		$ref = &$this->tpldata;
		return $ref;
	}

	public function &get_mutable_data_ref()
	{
		$ref = &$this->tpldata;
		return $ref;
	}

	public function &get_root_ref()
	{
		return $this->rootref;
	}

	public function mark_rows_dirty()
	{
		$this->num_rows_is_set = false;
	}
}

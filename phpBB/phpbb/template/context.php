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
* Stores variables assigned to template.
*
* The class remains the public compatibility facade. Root/scalar storage and
* render-time row metadata are handled by data_manager, while block traversal
* and mutation are handled by block_manager.
*/
class context
{
	/** @var \phpbb\template\data_manager */
	private $data_manager;

	/** @var \phpbb\template\block_selector */
	private $block_selector;

	/** @var \phpbb\template\block_manager */
	private $block_manager;

	public function __construct()
	{
		$metadata_manager = new row_metadata_manager();
		$this->data_manager = new data_manager($metadata_manager);
		$this->block_selector = new block_selector();
		$this->block_manager = new block_manager($this->block_selector, $metadata_manager);
	}

	/**
	* Clears template data set.
	*/
	public function clear()
	{
		$this->data_manager->clear();
	}

	/**
	* Assign multiple root variables in one operation.
	*
	* @param array $vararray Variable name => value pairs
	* @return true
	*/
	public function assign_vars(array $vararray)
	{
		return $this->data_manager->assign_vars($vararray);
	}

	/**
	* Retrieve multiple root variables in one operation.
	*
	* @param array $vararray Variable names
	* @return array
	*/
	public function retrieve_vars(array $vararray)
	{
		return $this->data_manager->retrieve_vars($vararray);
	}

	/**
	* Assign a single scalar value to a single key.
	*
	* @param string $varname Variable name
	* @param mixed $varval Value to assign to variable
	* @return true
	*/
	public function assign_var(string $varname, mixed $varval)
	{
		return $this->data_manager->assign_var($varname, $varval);
	}

	/**
	* Append text to the string value stored in a key.
	*
	* @param string $varname Variable name
	* @param string $varval Value to append to variable
	* @return true
	*/
	public function append_var($varname, $varval)
	{
		return $this->data_manager->append_var($varname, $varval);
	}

	/**
	* Retrieve a single scalar value from a single key.
	*
	* @param string $varname Variable name
	* @return mixed Variable value, or null if not set
	*/
	public function retrieve_var($varname)
	{
		return $this->data_manager->retrieve_var($varname);
	}

	/**
	* Returns a reference to template data array.
	*
	* @return array template data
	*/
	public function &get_data_ref()
	{
		$ref = &$this->data_manager->get_data_ref();
		return $ref;
	}

	/**
	* Returns a reference to template root scope.
	*
	* @return array template data
	*/
	public function &get_root_ref()
	{
		$ref = &$this->data_manager->get_root_ref();
		return $ref;
	}

	/**
	* Assign key variable pairs from an array to a specified block.
	*
	* @param string $blockname Name of block to assign $vararray to
	* @param array $vararray A hash of variable name => value pairs
	* @return true
	*/
	public function assign_block_vars($blockname, array $vararray)
	{
		$this->data_manager->mark_rows_dirty();
		$tpldata = &$this->data_manager->get_mutable_data_ref();
		return $this->block_manager->assign_block_vars($tpldata, $blockname, $vararray);
	}

	/**
	* Assign key variable pairs from an array to a whole specified block loop.
	*
	* @param string $blockname Name of block
	* @param array $block_vars_array Rows to assign
	* @return true
	*/
	public function assign_block_vars_array($blockname, array $block_vars_array)
	{
		$this->data_manager->mark_rows_dirty();
		$tpldata = &$this->data_manager->get_mutable_data_ref();
		return $this->block_manager->assign_block_vars_array($tpldata, $blockname, $block_vars_array);
	}

	/**
	* Retrieve key variable pairs from the specified block.
	*
	* @param string $blockname Name of block
	* @param array $vararray Variable names, empty array retrieves all vars
	* @return array
	*/
	public function retrieve_block_vars($blockname, array $vararray)
	{
		$tpldata = &$this->data_manager->get_mutable_data_ref();
		return $this->block_manager->retrieve_block_vars($tpldata, $blockname, $vararray);
	}

	/**
	* Find the index for a specified key in the innermost specified block.
	*
	* @param string $blockname
	* @param mixed $key
	* @return false|int
	*/
	public function find_key_index($blockname, $key)
	{
		$tpldata = &$this->data_manager->get_mutable_data_ref();
		return $this->block_manager->find_key_index($tpldata, $blockname, $key);
	}

	/**
	* Change, insert or delete one assigned block row.
	*
	* @param string $blockname
	* @param array $vararray
	* @param mixed $key
	* @param string $mode
	* @return bool
	*/
	public function alter_block_array($blockname, array $vararray, $key = false, $mode = 'insert')
	{
		$this->data_manager->mark_rows_dirty();
		$tpldata = &$this->data_manager->get_mutable_data_ref();
		return $this->block_manager->alter_block_array($tpldata, $blockname, $vararray, $key, $mode);
	}

	/**
	* Reset/empty complete block.
	*
	* @param string $blockname Name of block to destroy
	* @return true
	*/
	public function destroy_block_vars($blockname)
	{
		$this->data_manager->mark_rows_dirty();
		$tpldata = &$this->data_manager->get_mutable_data_ref();
		return $this->block_manager->destroy_block_vars($tpldata, $blockname);
	}
}

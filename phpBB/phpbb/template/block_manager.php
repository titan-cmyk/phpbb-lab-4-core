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
* Handles template block traversal and mutation.
*
* PHPBB Lab Core refactor: block selection is delegated to block_selector and
* all mutations share one traversal path. assign_block_vars_array() performs a
* real bulk insertion instead of repeatedly traversing the same block tree.
*/
class block_manager
{
	/** @var \phpbb\template\block_selector */
	private $selector;

	/** @var \phpbb\template\row_metadata_manager */
	private $metadata_manager;

	public function __construct(block_selector|null $selector = null, row_metadata_manager|null $metadata_manager = null)
	{
		$this->selector = $selector ?: new block_selector();
		$this->metadata_manager = $metadata_manager ?: new row_metadata_manager();
	}

	/**
	* Append one row to a block.
	*
	* @param array $tpldata
	* @param mixed $block_selector
	* @param array $vararray
	* @return bool
	*/
	public function assign_block_vars(array &$tpldata, $block_selector, array $vararray)
	{
		return $this->assign_block_vars_array($tpldata, $block_selector, [$vararray]);
	}

	/**
	* Append multiple rows to a block in one traversal.
	*
	* @param array $tpldata
	* @param mixed $block_selector
	* @param array $block_vars_array
	* @return bool
	*/
	public function assign_block_vars_array(array &$tpldata, $block_selector, array $block_vars_array)
	{
		if (!$block_vars_array)
		{
			return true;
		}

		return $this->operate($tpldata, $block_selector, $block_vars_array, null, 'multiinsert');
	}

	/**
	* Retrieve key variable pairs from a selected row.
	*
	* @param array $tpldata
	* @param mixed $block_selector
	* @param array $vararray
	* @return array|false
	*/
	public function retrieve_block_vars(array $tpldata, $block_selector, array $vararray)
	{
		$result = $this->operate($tpldata, $block_selector, $vararray, null, 'retrieve');
		return ($result === false) ? [] : $result;
	}

	/**
	* Find the selected row index.
	*
	* @param array $tpldata
	* @param mixed $block_selector
	* @param mixed $key
	* @return int|false
	*/
	public function find_key_index(array $tpldata, $block_selector, $key)
	{
		return $this->operate($tpldata, $block_selector, [], $key, 'find');
	}

	/**
	* Change, insert or delete one selected block row.
	*
	* @param array $tpldata
	* @param mixed $block_selector
	* @param array $vararray
	* @param mixed $key
	* @param string $mode
	* @return mixed
	*/
	public function alter_block_array(array &$tpldata, $block_selector, array $vararray, $key = false, $mode = 'insert')
	{
		if (!in_array($mode, ['insert', 'change', 'delete'], true))
		{
			return false;
		}

		return $this->operate($tpldata, $block_selector, $vararray, $key, $mode);
	}

	/**
	* Remove a whole selected block.
	*
	* @param array $tpldata
	* @param mixed $block_selector
	* @return bool
	*/
	public function destroy_block_vars(array &$tpldata, $block_selector)
	{
		// Historical context::destroy_block_vars() is idempotent and returns
		// true even when the block is already absent.
		$this->operate($tpldata, $block_selector, [], null, 'delete_block');
		return true;
	}

	/**
	* Traverse the selector and perform an operation on its final block.
	*
	* @param array $tpldata
	* @param mixed $block_selector
	* @param array $vararray
	* @param mixed $legacy_key
	* @param string $mode
	* @return mixed
	*/
	private function operate(array &$tpldata, $block_selector, array $vararray, $legacy_key, $mode)
	{
		$selector = $this->selector->normalize($block_selector);
		if ($selector === false)
		{
			return false;
		}

		$last_position = count($selector) - 1;
		$last_name = $selector[$last_position]['name'];
		$last_key = $selector[$last_position]['key'];

		// Preserve the historical $key argument: it only overrides an
		// unspecified selector on the final block level.
		if ($last_key === null && $legacy_key !== null)
		{
			$last_key = $legacy_key;
		}

		$parent = &$tpldata;
		for ($position = 0; $position < $last_position; $position++)
		{
			$name = $selector[$position]['name'];
			$key = $selector[$position]['key'];

			if (!isset($parent[$name]) || !is_array($parent[$name]))
			{
				return false;
			}

			$index = $this->selector->resolve_existing_index($parent[$name], $key);
			if ($index === false || !isset($parent[$name][$index]) || !is_array($parent[$name][$index]))
			{
				return false;
			}

			$parent = &$parent[$name][$index];
		}

		switch ($mode)
		{
			case 'multiinsert':
				return $this->multiinsert($parent, $last_name, $last_key, $vararray);

			case 'insert':
				return $this->multiinsert($parent, $last_name, $last_key, [$vararray]);

			case 'change':
				return $this->change($parent, $last_name, $last_key, $vararray);

			case 'delete':
				return $this->delete_row($parent, $last_name, $last_key);

			case 'delete_block':
				if (!array_key_exists($last_name, $parent))
				{
					return false;
				}
				unset($parent[$last_name]);
				return true;

			case 'retrieve':
				return $this->retrieve($parent, $last_name, $last_key, $vararray);

			case 'find':
				if (!isset($parent[$last_name]) || !is_array($parent[$last_name]))
				{
					return false;
				}
				return $this->selector->resolve_existing_index($parent[$last_name], $last_key);
		}

		return false;
	}

	/**
	* Insert multiple rows while maintaining phpBB loop metadata.
	*/
	private function multiinsert(array &$parent, $name, $key, array $rows)
	{
		if (!$rows)
		{
			return true;
		}

		if (!isset($parent[$name]))
		{
			$parent[$name] = [];
		}

		if (!is_array($parent[$name]))
		{
			return false;
		}

		$position = $this->selector->resolve_insert_index($parent[$name], $key);
		if ($position === false)
		{
			return false;
		}

		return $this->metadata_manager->insert_rows($parent[$name], $name, $position, $rows);
	}

	/**
	* Merge values into an existing row.
	*/
	private function change(array &$parent, $name, $key, array $vararray)
	{
		if (!isset($parent[$name]) || !is_array($parent[$name]))
		{
			return false;
		}

		$index = $this->selector->resolve_existing_index($parent[$name], $key);
		if ($index === false)
		{
			return false;
		}

		$parent[$name][$index] = array_merge($parent[$name][$index], $vararray);
		return true;
	}

	/**
	* Delete one row from a block.
	*/
	private function delete_row(array &$parent, $name, $key)
	{
		if (!isset($parent[$name]) || !is_array($parent[$name]))
		{
			return false;
		}

		$index = $this->selector->resolve_existing_index($parent[$name], $key);
		if ($index === false)
		{
			return false;
		}

		array_splice($parent[$name], $index, 1);
		if (!$parent[$name])
		{
			unset($parent[$name]);
			return true;
		}

		$this->metadata_manager->after_delete($parent[$name], $name, $index);
		return true;
	}

	/**
	* Retrieve selected scalar variables from a block row.
	*/
	private function retrieve(array $parent, $name, $key, array $vararray)
	{
		if (!isset($parent[$name]) || !is_array($parent[$name]))
		{
			return false;
		}

		$index = $this->selector->resolve_existing_index($parent[$name], $key);
		if ($index === false || !isset($parent[$name][$index]))
		{
			return false;
		}

		$row = $parent[$name][$index];
		$result = [];

		if ($vararray === [])
		{
			$excluded = ['S_FIRST_ROW', 'S_LAST_ROW', 'S_BLOCK_NAME', 'S_NUM_ROWS', 'S_ROW_COUNT', 'S_ROW_NUM'];
			foreach ($row as $varname => $varvalue)
			{
				if ($varname === strtoupper($varname) && !is_array($varvalue) && !in_array($varname, $excluded, true))
				{
					$result[$varname] = $varvalue;
				}
			}
			return $result;
		}

		foreach ($vararray as $varname)
		{
			$result[$varname] = array_key_exists($varname, $row) ? $row[$varname] : null;
		}

		return $result;
	}

}

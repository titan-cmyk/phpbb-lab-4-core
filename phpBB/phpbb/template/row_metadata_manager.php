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
* Maintains phpBB template loop row metadata.
*
* PHPBB Lab Core refactor: positional metadata is updated incrementally for
* append operations and only the affected suffix is re-indexed after inserts
* or deletes. S_NUM_ROWS remains a render-time concern and is finalised lazily.
*/
class row_metadata_manager
{
	/**
	* Append rows without re-indexing the existing block.
	*
	* @param array  $rows
	* @param string $name
	* @param array  $new_rows
	* @return bool
	*/
	public function append_rows(array &$rows, $name, array $new_rows)
	{
		if (!$new_rows)
		{
			return true;
		}

		$start = count($rows);
		if ($start > 0)
		{
			unset($rows[$start - 1]['S_LAST_ROW']);
		}

		$total_new = count($new_rows);
		foreach ($new_rows as $offset => $row)
		{
			if (!is_array($row))
			{
				return false;
			}

			$index = $start + $offset;
			$this->prepare_row($row, $name, $index, $index === 0, $offset === $total_new - 1);
			$rows[] = $row;
		}

		return true;
	}

	/**
	* Insert rows at a position and re-index only the affected suffix.
	*
	* @param array  $rows
	* @param string $name
	* @param int    $position
	* @param array  $new_rows
	* @return bool
	*/
	public function insert_rows(array &$rows, $name, $position, array $new_rows)
	{
		if (!$new_rows)
		{
			return true;
		}

		$count = count($rows);
		$position = min(max((int) $position, 0), $count);

		if ($position === $count)
		{
			return $this->append_rows($rows, $name, $new_rows);
		}

		foreach ($new_rows as $row)
		{
			if (!is_array($row))
			{
				return false;
			}
		}

		array_splice($rows, $position, 0, $new_rows);
		$this->refresh_from($rows, $name, $position);

		return true;
	}

	/**
	* Refresh metadata after one row was deleted.
	*
	* @param array  $rows
	* @param string $name
	* @param int    $deleted_position
	*/
	public function after_delete(array &$rows, $name, $deleted_position)
	{
		$count = count($rows);
		if ($count === 0)
		{
			return;
		}

		$deleted_position = (int) $deleted_position;
		if ($deleted_position >= $count)
		{
			// Deleting the old last row does not shift any positions.
			$last = $count - 1;
			unset($rows[$last]['S_LAST_ROW']);
			$rows[$last]['S_LAST_ROW'] = true;
			if ($last === 0)
			{
				$rows[0]['S_FIRST_ROW'] = true;
			}
			return;
		}

		$this->refresh_from($rows, $name, max(0, $deleted_position));
	}

	/**
	* Finalise S_NUM_ROWS recursively immediately before rendering.
	*
	* @param array $tpldata
	*/
	public function finalize_num_rows(array &$tpldata)
	{
		foreach ($tpldata as $loop_name => &$loop_data)
		{
			if ($loop_name === '.' || !is_array($loop_data))
			{
				continue;
			}

			$this->set_num_rows($loop_data);
		}
		unset($loop_data);
	}

	/**
	* Re-index an affected suffix of a block.
	*/
	private function refresh_from(array &$rows, $name, $start)
	{
		$count = count($rows);
		$start = min(max((int) $start, 0), max(0, $count - 1));

		for ($index = $start; $index < $count; $index++)
		{
			$row = &$rows[$index];
			$this->prepare_row($row, $name, $index, $index === 0, $index === $count - 1);
			unset($row);
		}
	}

	/**
	* Apply canonical positional metadata to one row.
	*/
	private function prepare_row(array &$row, $name, $index, $is_first, $is_last)
	{
		unset($row['S_FIRST_ROW'], $row['S_LAST_ROW'], $row['S_NUM_ROWS']);
		$row['S_BLOCK_NAME'] = $name;
		$row['S_ROW_COUNT'] = $row['S_ROW_NUM'] = $index;

		if ($is_first)
		{
			$row['S_FIRST_ROW'] = true;
		}
		if ($is_last)
		{
			$row['S_LAST_ROW'] = true;
		}
	}

	/**
	* Set S_NUM_ROWS recursively for one template loop.
	*/
	private function set_num_rows(array &$loop_data)
	{
		$s_num_rows = count($loop_data);

		foreach ($loop_data as &$mod_block)
		{
			if (!is_array($mod_block))
			{
				continue;
			}

			foreach ($mod_block as $sub_block_name => &$sub_block)
			{
				if ($sub_block_name === strtolower($sub_block_name) && is_array($sub_block))
				{
					$this->set_num_rows($sub_block);
				}
			}
			unset($sub_block);

			if (isset($mod_block['S_BLOCK_NAME']))
			{
				$mod_block['S_NUM_ROWS'] = $s_num_rows;
			}
		}
		unset($mod_block);
	}
}

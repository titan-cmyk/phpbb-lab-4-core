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
* Normalises template block selectors and resolves row indexes.
*
* PHPBB Lab Core refactor: selector parsing is kept separate from block
* mutation so legacy string selectors and the richer array selector format can
* share one implementation.
*/
class block_selector
{
	/**
	* Convert a selector to an ordered array of block name => row selector.
	*
	* String examples:
	* loop                  => ['loop' => null]
	* loop.inner            => ['loop' => null, 'inner' => null]
	* loop[1].inner[]       => ['loop' => 1, 'inner' => true]
	*
	* Array selectors are returned after validation. A row selector can be:
	* null/true (last), false (first), an integer position or a one-pair array
	* used for strict key/value lookup.
	*
	* @param mixed $selector
	* @return array|false
	*/
	public function normalize($selector)
	{
		if (is_array($selector))
		{
			if (!$selector)
			{
				return false;
			}

			$result = [];
			foreach ($selector as $name => $key)
			{
				if (!is_string($name) || $name === '' || !$this->is_valid_key($key))
				{
					return false;
				}
				$result[] = ['name' => $name, 'key' => $key];
			}

			return $result;
		}

		if (!is_string($selector) || $selector === '')
		{
			return false;
		}

		$result = [];
		foreach (explode('.', $selector) as $part)
		{
			if (!preg_match('/^([^\[\]]+)(?:\[(.*?)\])?$/', $part, $match))
			{
				return false;
			}

			$name = $match[1];
			if ($name === '')
			{
				return false;
			}

			if (!array_key_exists(2, $match))
			{
				$key = null;
			}
			else if ($match[2] === '')
			{
				$key = true;
			}
			else if (preg_match('/^\d+$/', $match[2]))
			{
				$key = (int) $match[2];
			}
			else
			{
				return false;
			}

			$result[] = ['name' => $name, 'key' => $key];
		}

		return $result;
	}

	/**
	* Resolve an existing row index.
	*
	* @param mixed $rows
	* @param mixed $key
	* @return int|false
	*/
	public function resolve_existing_index($rows, $key)
	{
		if (!is_array($rows) || count($rows) === 0)
		{
			return false;
		}

		$count = count($rows);

		if ($key === false)
		{
			return 0;
		}

		if ($key === true || $key === null)
		{
			return $count - 1;
		}

		if (is_int($key))
		{
			if ($key < 0 || $key > $count)
			{
				return false;
			}

			// Historical alter_block_array behaviour treats count(rows) as the
			// current last row for non-insert operations.
			return ($key === $count) ? $count - 1 : $key;
		}

		if (is_array($key) && count($key) === 1)
		{
			$search_key = array_key_first($key);
			$search_value = $key[$search_key];

			foreach ($rows as $index => $row)
			{
				if (is_array($row) && array_key_exists($search_key, $row) && $row[$search_key] === $search_value)
				{
					return $index;
				}
			}
		}

		return false;
	}

	/**
	* Resolve an insertion position in a block.
	*
	* null/true append, false prepends, integer positions are clamped to the
	* existing block bounds, and a key/value selector inserts before its match.
	*
	* @param mixed $rows
	* @param mixed $key
	* @return int|false
	*/
	public function resolve_insert_index($rows, $key)
	{
		$rows = is_array($rows) ? $rows : [];
		$count = count($rows);

		if ($key === true || $key === null)
		{
			return $count;
		}

		if ($key === false)
		{
			return 0;
		}

		if (is_int($key))
		{
			return min(max($key, 0), $count);
		}

		if (is_array($key) && count($key) === 1)
		{
			return $this->resolve_existing_index($rows, $key);
		}

		return false;
	}

	/**
	* @param mixed $key
	* @return bool
	*/
	private function is_valid_key($key)
	{
		if ($key === null || is_bool($key) || is_int($key))
		{
			return true;
		}

		return is_array($key) && count($key) === 1;
	}
}

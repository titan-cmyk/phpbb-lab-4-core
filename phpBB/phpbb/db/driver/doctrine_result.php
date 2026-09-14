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

namespace phpbb\db\driver;

use Doctrine\DBAL\Result;

/**
 * Compatibility result handle for Doctrine DBAL SELECT queries.
 *
 * phpBB's historical DBAL exposes buffered, seekable result handles. Doctrine
 * DBAL intentionally exposes a forward-only Result abstraction. This adapter
 * keeps already-read rows in a small lazy buffer so the historical
 * sql_fetchrow()/sql_rowseek()/sql_freeresult() contract can remain unchanged
 * while SELECT execution is moved to Doctrine.
 */
class doctrine_result
{
	/** @var Result|null */
	protected $result;

	/** @var array<int, array<string, mixed>> */
	protected $rows = [];

	/** @var int */
	protected $position = 0;

	/** @var bool */
	protected $exhausted = false;

	/**
	 * @param Result $result Doctrine DBAL result
	 */
	public function __construct(Result $result)
	{
		$this->result = $result;
	}

	/**
	 * Fetch the next row as an associative array.
	 *
	 * @return array<string, mixed>|false
	 */
	public function fetch_associative()
	{
		if ($this->position < count($this->rows))
		{
			return $this->rows[$this->position++];
		}

		if ($this->exhausted || $this->result === null)
		{
			return false;
		}

		$row = $this->result->fetchAssociative();
		if ($row === false)
		{
			$this->exhausted = true;
			return false;
		}

		$this->rows[] = $row;
		$this->position++;

		return $row;
	}

	/**
	 * Seek to a zero-based row number.
	 *
	 * Rows are buffered only as far as required by the requested seek position.
	 *
	 * @param int $rownum Zero-based row number
	 * @return bool True when the row exists
	 */
	public function seek($rownum)
	{
		$rownum = (int) $rownum;
		if ($rownum < 0 || $this->result === null)
		{
			return false;
		}

		while (count($this->rows) <= $rownum && !$this->exhausted)
		{
			$row = $this->result->fetchAssociative();
			if ($row === false)
			{
				$this->exhausted = true;
				break;
			}

			$this->rows[] = $row;
		}

		if (!array_key_exists($rownum, $this->rows))
		{
			return false;
		}

		$this->position = $rownum;
		return true;
	}

	/**
	 * Release the underlying Doctrine result and buffered rows.
	 *
	 * @return void
	 */
	public function free()
	{
		if ($this->result !== null)
		{
			$this->result->free();
			$this->result = null;
		}

		$this->rows = [];
		$this->position = 0;
		$this->exhausted = true;
	}
}

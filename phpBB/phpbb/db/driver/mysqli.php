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

/**
* MySQLi Database Abstraction Layer
* mysqli-extension has to be compiled with:
* MySQL 4.1+ or MySQL 5.0+
*/
class mysqli extends \phpbb\db\driver\mysql_base
{
	var $multi_insert = true;
	var $connect_error = '';

	/** @var doctrine_bridge|null Lazy Doctrine DBAL bridge using the same database credentials. */
	protected $doctrine_bridge = null;

	/** @var bool Whether Doctrine owns the same native mysqli object exposed to phpBB. */
	protected $doctrine_shared_native_connection = false;

	/** @var int|null Affected-row count from the most recent Doctrine-backed historical write. */
	protected $last_doctrine_affected_rows = null;

	/** @var int|string|null Insert id from the most recent Doctrine-backed historical INSERT. */
	protected $last_doctrine_insert_id = null;

	/** @var bool Whether the most recent historical sql_query() write executed on Doctrine. */
	protected $last_query_write_via_doctrine = false;

	/** @var array<string, string|int>|null Last Doctrine write error for phpBB's normal error handler. */
	protected $last_doctrine_error = null;


	/** @var string|null Physical backend currently owning phpBB's historical transaction. */
	protected $transaction_backend = null;

	/**
	* {@inheritDoc}
	*/
	function sql_connect($sqlserver, $sqluser, $sqlpassword, $database, $port = false, $persistency = false, $new_link = false)
	{
		if (!function_exists('mysqli_connect'))
		{
			$this->connect_error = 'mysqli_connect function does not exist, is mysqli extension installed?';
			return $this->sql_error('');
		}

		$this->persistency = $persistency;
		$this->user = $sqluser;
		$this->server = ($this->persistency) ? 'p:' . (($sqlserver) ? $sqlserver : 'localhost') : $sqlserver;
		$this->dbname = $database;
		$port = (!$port) ? null : $port;

		$socket = null;
		if ($port)
		{
			if (is_numeric($port))
			{
				$port = (int) $port;
			}
			else
			{
				$socket = $port;
				$port = null;
			}
		}

		/*
		 * Stage 8 makes Doctrine the primary owner of the physical mysqli
		 * connection. phpBB's historical driver receives Doctrine's native mysqli
		 * object, so legacy mysqli_* calls and Doctrine DBAL operate on one server
		 * session instead of maintaining two independent connections.
		 */
		mysqli_report(MYSQLI_REPORT_OFF);
		try
		{
			$this->doctrine_bridge = new doctrine_bridge(
				$sqlserver,
				$sqluser,
				$sqlpassword,
				$database,
				$port,
				$socket,
				null,
				$persistency
			);
			$this->db_connect_id = $this->doctrine_bridge->get_native_connection();
			$this->doctrine_shared_native_connection = ($this->db_connect_id instanceof \mysqli)
				&& $this->doctrine_bridge->shares_native_connection($this->db_connect_id);
		}
		catch (\Throwable $e)
		{
			$this->db_connect_id = false;
			$this->doctrine_shared_native_connection = false;
			$this->connect_error = 'Failed to establish the primary Doctrine/MySQLi connection: ' . $e->getMessage();
		}

		if (!$this->connect_error && $this->db_connect_id && $this->dbname != '')
		{
			/*
			 * Stage 10 moves phpBB's connection/session initialisation onto Doctrine as
			 * well. The DBAL connection already owns the native mysqli handle, so there
			 * is no reason to issue separate mysqli_query() calls for charset or sql_mode.
			 */
			$connection = $this->get_doctrine_connection();
			$session_sql_mode = null;

			if ($connection)
			{
				// Keep the historical phpBB connection character set explicit even though
				// Doctrine's connection params already request utf8.
				$connection->executeStatement("SET NAMES 'utf8'");

				// Enforce phpBB's historical strict SQL mode on the shared session.
				if (version_compare($this->sql_server_info(true), '5.0.2', '>='))
				{
					$current_mode = (string) $connection->fetchOne('SELECT @@session.sql_mode');
					$modes = $current_mode !== '' ? array_map('trim', explode(',', $current_mode)) : [];

					if (!in_array('TRADITIONAL', $modes))
					{
						if (!in_array('STRICT_ALL_TABLES', $modes))
						{
							$modes[] = 'STRICT_ALL_TABLES';
						}
						if (!in_array('STRICT_TRANS_TABLES', $modes))
						{
							$modes[] = 'STRICT_TRANS_TABLES';
						}
					}

					$session_sql_mode = implode(',', array_filter($modes, static function ($mode) { return $mode !== ''; }));
				}
			}

			if ($this->doctrine_bridge)
			{
				$this->doctrine_bridge->set_session_sql_mode($session_sql_mode);
			}

			return $this->db_connect_id;
		}

		return $this->sql_error('');
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_doctrine_connection()
	{
		return $this->doctrine_bridge ? $this->doctrine_bridge->get_connection() : null;
	}


	/**
	 * Whether Doctrine and the historical phpBB driver share one native mysqli session.
	 *
	 * @return bool
	 */
	protected function doctrine_uses_shared_native_connection()
	{
		return $this->doctrine_shared_native_connection
			&& $this->doctrine_bridge
			&& $this->doctrine_bridge->shares_native_connection($this->db_connect_id);
	}

	/**
	* {@inheritDoc}
	*/
	function sql_server_info($raw = false, $use_cache = true)
	{
		global $cache;

		if (!$use_cache || empty($cache) || ($this->sql_server_version = $cache->get('mysqli_version')) === false)
		{
			try
			{
				$connection = $this->get_doctrine_connection();
				$this->sql_server_version = $connection ? (string) $connection->fetchOne('SELECT VERSION()') : '';
			}
			catch (\Throwable $e)
			{
				$this->sql_server_version = '';
			}

			if ($this->sql_server_version !== '' && !empty($cache) && $use_cache)
			{
				$cache->put('mysqli_version', $this->sql_server_version);
			}
		}

		return ($raw) ? (string) $this->sql_server_version : 'MySQL(i) ' . $this->sql_server_version;
	}

	/**
	* {@inheritDoc}
	*/
	protected function _sql_transaction(string $status = 'begin'): bool
	{
		switch ($status)
		{
			case 'begin':
				// Stage 17 finalises the shared-connection model: whenever Doctrine owns
				// the primary native mysqli session, phpBB's historical transaction API
				// uses Doctrine as well. Temporary tables and session state no longer
				// require a separate MySQLi transaction because both APIs share one
				// physical connection.
				if ($this->can_start_doctrine_transaction())
				{
					try
					{
						$this->get_doctrine_connection()->beginTransaction();
						$this->transaction_backend = 'doctrine';
						return true;
					}
					catch (\Throwable $e)
					{
						$this->last_doctrine_error = [
							'message' => $e->getMessage(),
							'code' => $e->getCode(),
						];
						$this->transaction_backend = null;
						return false;
					}
				}

				@mysqli_autocommit($this->db_connect_id, false);
				$result = @mysqli_begin_transaction($this->db_connect_id);
				if ($result)
				{
					$this->transaction_backend = 'mysqli';
				}
				return $result;

			case 'commit':
				if ($this->transaction_backend === 'doctrine')
				{
					try
					{
						$this->get_doctrine_connection()->commit();
						$this->transaction_backend = null;
						return true;
					}
					catch (\Throwable $e)
					{
						$this->last_doctrine_error = [
							'message' => $e->getMessage(),
							'code' => $e->getCode(),
						];
						$this->transaction_backend = null;
						return false;
					}
				}

				$result = @mysqli_commit($this->db_connect_id);
				@mysqli_autocommit($this->db_connect_id, true);
				$this->transaction_backend = null;
				return $result;

			case 'rollback':
				if ($this->transaction_backend === 'doctrine')
				{
					try
					{
						$connection = $this->get_doctrine_connection();
						if ($connection->isTransactionActive())
						{
							$connection->rollBack();
						}
						$this->transaction_backend = null;
						return true;
					}
					catch (\Throwable $e)
					{
						$this->last_doctrine_error = [
							'message' => $e->getMessage(),
							'code' => $e->getCode(),
						];
						$this->transaction_backend = null;
						return false;
					}
				}

				$result = @mysqli_rollback($this->db_connect_id);
				@mysqli_autocommit($this->db_connect_id, true);
				$this->transaction_backend = null;
				return $result;
		}

		return true;
	}

	/**
	 * Whether a new historical phpBB transaction can be owned by Doctrine.
	 *
	 * Since Stage 8 Doctrine and the compatibility MySQLi API share one physical
	 * native connection. Stage 17 therefore removes the transitional pinning
	 * rules that were only needed while the two APIs could use separate sessions.
	 *
	 * @return bool
	 */
	protected function can_start_doctrine_transaction()
	{
		return $this->doctrine_bridge && $this->doctrine_uses_shared_native_connection();
	}

	/**
	 * Whether the current historical transaction is physically running on Doctrine.
	 *
	 * @return bool
	 */
	protected function doctrine_transaction_active()
	{
		return $this->transaction && $this->transaction_backend === 'doctrine';
	}

	/**
	 * Reset per-query Doctrine write state before executing another historical query.
	 *
	 * @return void
	 */
	protected function reset_doctrine_write_state()
	{
		$this->last_doctrine_affected_rows = null;
		$this->last_doctrine_insert_id = null;
		$this->last_query_write_via_doctrine = false;
		$this->last_doctrine_error = null;
	}

	/**
	 * Classify standard historical phpBB SQL statements for Doctrine execution.
	 *
	 * Stage 9 makes Doctrine DBAL the default execution engine for the ordinary
	 * MySQL/MariaDB SQL forms used by phpBB while the historical public API stays
	 * unchanged. Stage 10 also keeps Doctrine active while phpBB's SQL debug/
	 * explain mode is enabled; only unusual vendor-specific statements fall back
	 * to the native MySQLi compatibility path.
	 *
	 * @param string $query SQL query
	 * @return string|null "result", "statement" or null for legacy fallback
	 */
	protected function doctrine_query_kind($query)
	{
		if (!$this->doctrine_bridge)
		{
			return null;
		}

		$query = ltrim((string) $query);
		if ($query === '')
		{
			return null;
		}

		// Statements that return a row set through MySQL/MariaDB.
		if (preg_match('/^(?:SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\\b/i', $query))
		{
			return 'result';
		}

		// Common phpBB mutations, DDL and connection/session statements. Because
		// Stage 8 unified Doctrine and the legacy driver onto the same native mysqli
		// session, temporary tables, session variables and locks remain visible to
		// both APIs and no longer need a second-connection affinity workaround.
		if (preg_match('/^(?:INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE|RENAME|SET|LOCK|UNLOCK)\\b/i', $query))
		{
			return 'statement';
		}

		return null;
	}

	/**
	 * Execute a historical phpBB query through Doctrine DBAL.
	 *
	 * Result-producing queries are wrapped in doctrine_result so phpBB's legacy
	 * sql_fetchrow()/sql_rowseek()/sql_freeresult() contract remains intact.
	 * Statement queries preserve affected-row and insert-id reporting.
	 *
	 * @param string $query SQL query
	 * @param string $kind  Query kind returned by doctrine_query_kind()
	 * @return doctrine_result|bool
	 */
	protected function execute_historical_query_via_doctrine($query, $kind)
	{
		try
		{
			$connection = $this->get_doctrine_connection();
			if (!$connection)
			{
				return false;
			}

			if ($kind === 'result')
			{
				return new doctrine_result($connection->executeQuery($query));
			}

			$this->last_doctrine_affected_rows = (int) $connection->executeStatement($query);
			$this->last_query_write_via_doctrine = true;

			if (preg_match('/^\\s*(?:INSERT|REPLACE)\\b/i', $query))
			{
				$insert_id = $connection->lastInsertId();
				$this->last_doctrine_insert_id = is_numeric($insert_id) ? (int) $insert_id : $insert_id;
			}

			return true;
		}
		catch (\Throwable $e)
		{
			$this->last_doctrine_error = [
				'message' => $e->getMessage(),
				'code' => $e->getCode(),
			];
			return false;
		}
	}

	/**
	* {@inheritDoc}
	*/
	function sql_query($query = '', $cache_ttl = 0)
	{
		if ($query == '')
		{
			return false;
		}

		global $cache;

		$this->last_query_text = $query;
		$this->reset_doctrine_write_state();

		if ($this->debug_sql_explain)
		{
			$this->sql_report('start', $query);
		}
		else if ($this->debug_load_time)
		{
			$this->curtime = microtime(true);
		}

		$this->query_result = ($cache && $cache_ttl) ? $cache->sql_load($query) : false;
		$this->sql_add_num_queries($this->query_result);

		if ($this->query_result === false)
		{
			$query_kind = $this->doctrine_query_kind($query);

			if ($query_kind !== null)
			{
				$this->query_result = $this->execute_historical_query_via_doctrine($query, $query_kind);
			}
			else
			{
				try
				{
					$this->query_result = @mysqli_query($this->db_connect_id, $query);
				}
				catch (\Error $e)
				{
					// phpBB's existing sql_error() path reports the native error below.
				}

			}

			if ($this->query_result === false)
			{
				$this->sql_error($query);
			}

			if ($this->debug_sql_explain)
			{
				$this->sql_report('stop', $query);
			}
			else if ($this->debug_load_time)
			{
				$this->sql_time += microtime(true) - $this->curtime;
			}

			if (!$this->query_result)
			{
				return false;
			}

			// phpBB SQL-result caching only applies to row-producing queries. Do not
			// attempt to cache successful DDL/DML statements that return boolean true.
			if ($cache && $cache_ttl && ($this->query_result instanceof doctrine_result || $this->query_result instanceof \mysqli_result))
			{
				$this->query_result = $cache->sql_save($this, $query, $this->query_result, $cache_ttl);
			}
		}
		else if ($this->debug_sql_explain)
		{
			$this->sql_report('fromcache', $query);
		}

		return $this->query_result;
	}


	/**
	 * {@inheritdoc}
	 *
	 * Stage 11 introduces first-class bound parameters on phpBB's historical DB
	 * abstraction. MySQL/MariaDB uses Doctrine DBAL's native parameter binding,
	 * while callers keep phpBB-style result handles and cache semantics.
	 */
	public function sql_query_params($query, array $params = [], array $types = [], $cache_ttl = 0)
	{
		if ($query === '' || $query === null)
		{
			return false;
		}

		if (!$params)
		{
			return $this->sql_query($query, $cache_ttl);
		}

		global $cache;

		$this->last_query_text = $query;
		$this->reset_doctrine_write_state();
		$cache_query = $this->parameterised_cache_key($query, $params, $types);

		if ($this->debug_load_time)
		{
			$this->curtime = microtime(true);
		}

		$this->query_result = ($cache && $cache_ttl) ? $cache->sql_load($cache_query) : false;
		$this->sql_add_num_queries($this->query_result);

		if ($this->query_result === false)
		{
			$query_kind = $this->doctrine_query_kind($query);
			if ($query_kind === null)
			{
				// Keep the new public API predictable: parameterised execution is never
				// silently downgraded to string interpolation on the migrated MySQL path.
				$this->last_doctrine_error = [
					'message' => 'Unsupported parameterised SQL statement for Doctrine routing.',
					'code' => 0,
				];
				return $this->sql_error($query);
			}

			try
			{
				$connection = $this->get_doctrine_connection();
				if ($query_kind === 'result')
				{
					$this->query_result = new doctrine_result($connection->executeQuery($query, $params, $types));
				}
				else
				{
					$this->last_doctrine_affected_rows = (int) $connection->executeStatement($query, $params, $types);
					$this->last_query_write_via_doctrine = true;
					$this->query_result = true;

					if (preg_match('/^\s*(?:INSERT|REPLACE)\b/i', $query))
					{
						$insert_id = $connection->lastInsertId();
						$this->last_doctrine_insert_id = is_numeric($insert_id) ? (int) $insert_id : $insert_id;
					}
				}
			}
			catch (\Throwable $e)
			{
				$this->last_doctrine_error = [
					'message' => $e->getMessage(),
					'code' => $e->getCode(),
				];
				$this->query_result = false;
			}

			if ($this->debug_load_time)
			{
				$this->sql_time += microtime(true) - $this->curtime;
			}

			if ($this->query_result === false)
			{
				return $this->sql_error($query);
			}

			if ($cache && $cache_ttl && $this->query_result instanceof doctrine_result)
			{
				$this->query_result = $cache->sql_save($this, $cache_query, $this->query_result, $cache_ttl);
			}
		}

		return $this->query_result;
	}

	/**
	 * {@inheritdoc}
	 *
	 * MySQL/MariaDB keeps parameters bound all the way through LIMIT/OFFSET
	 * handling instead of falling back to literal interpolation.
	 */
	public function sql_query_limit_params($query, $total, $offset = 0, array $params = [], array $types = [], $cache_ttl = 0)
	{
		if ($query === '' || $query === null)
		{
			return false;
		}

		$total = ($total < 0) ? 0 : (int) $total;
		$offset = ($offset < 0) ? 0 : (int) $offset;

		if ($total === 0)
		{
			$total = '18446744073709551615';
		}

		$query .= "\n LIMIT " . (($offset > 0) ? $offset . ', ' . $total : $total);

		return $this->sql_query_params($query, $params, $types, $cache_ttl);
	}

	/**
	 * Build a cache identity for one parameterised query without embedding raw
	 * bound values into logs or executable SQL.
	 *
	 * @param string $query
	 * @param array $params
	 * @param array $types
	 * @return string
	 */
	protected function parameterised_cache_key($query, array $params, array $types)
	{
		return $query . "\n/* phpbb-params:" . hash('sha256', serialize([$params, $types])) . ' */';
	}


	/**
	 * Determine whether phpBB's historical sql_multi_insert() call can be
	 * executed as a single prepared Doctrine DBAL statement.
	 *
	 * Stage 17 finalises routing on the shared native connection. Multi-insert
	 * therefore stays on Doctrine even when temporary tables, session variables,
	 * locks or SQL debug mode are involved, as long as the active historical
	 * transaction is Doctrine-backed.
	 *
	 * @param string $table
	 * @param array  $sql_ary
	 * @return bool
	 */
	protected function should_route_multi_insert_to_doctrine($table, $sql_ary)
	{
		if (!$this->doctrine_bridge || ($this->transaction && !$this->doctrine_transaction_active()))
		{
			return false;
		}

		return is_array($sql_ary) && count($sql_ary) > 0;
	}

	/**
	 * Execute phpBB's historical sql_multi_insert() API through one prepared
	 * Doctrine DBAL INSERT statement.
	 *
	 * Values are passed as DBAL parameters rather than being interpolated into
	 * SQL. The public phpBB API and affected-row / insert-id behaviour remain
	 * unchanged.
	 *
	 * @param string $table
	 * @param array  $sql_ary
	 * @return bool
	 */
	protected function execute_multi_insert_via_doctrine($table, $sql_ary)
	{
		$rows = $sql_ary;
		$first = reset($rows);

		// phpBB historically accepts a one-dimensional array here and treats it
		// as a normal single-row INSERT.
		if (!is_array($first))
		{
			$rows = [$sql_ary];
		}

		if (!$rows || !is_array($rows[0]) || !$rows[0])
		{
			return false;
		}

		$fields = array_keys($rows[0]);
		$params = [];
		$value_groups = [];

		foreach ($rows as $row)
		{
			// Preserve phpBB's historical positional field semantics. If a caller
			// supplies a structurally inconsistent row, use the old implementation
			// rather than guessing how to map its values.
			if (!is_array($row) || array_keys($row) !== $fields)
			{
				return false;
			}

			$value_groups[] = '(' . implode(', ', array_fill(0, count($fields), '?')) . ')';
			foreach ($fields as $field)
			{
				$params[] = $row[$field];
			}
		}

		$sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $fields) . ') VALUES ' . implode(', ', $value_groups);
		$this->last_query_text = $sql;
		$this->reset_doctrine_write_state();

		try
		{
			$this->last_doctrine_affected_rows = (int) $this->doctrine_execute_statement($sql, $params);
			$this->last_query_write_via_doctrine = true;

			$connection = $this->get_doctrine_connection();
			$insert_id = $connection ? $connection->lastInsertId() : null;
			$this->last_doctrine_insert_id = is_numeric($insert_id) ? (int) $insert_id : $insert_id;

			return true;
		}
		catch (\Throwable $e)
		{
			$this->last_doctrine_error = [
				'message' => $e->getMessage(),
				'code' => $e->getCode(),
			];
			$this->sql_error($sql);
			return false;
		}
	}

	/**
	 * {@inheritDoc}
	 */
	function sql_multi_insert($table, $sql_ary)
	{
		if ($this->should_route_multi_insert_to_doctrine($table, $sql_ary))
		{
			$result = $this->execute_multi_insert_via_doctrine($table, $sql_ary);
			if ($result !== false)
			{
				return $result;
			}
		}

		return parent::sql_multi_insert($table, $sql_ary);
	}

	/**
	* {@inheritDoc}
	*/
	function sql_affectedrows()
	{
		if ($this->last_query_write_via_doctrine)
		{
			return (int) $this->last_doctrine_affected_rows;
		}

		return ($this->db_connect_id) ? @mysqli_affected_rows($this->db_connect_id) : false;
	}

	/**
	* {@inheritDoc}
	*/
	function sql_fetchrow($query_id = false)
	{
		global $cache;

		if ($query_id === false)
		{
			$query_id = $this->query_result;
		}

		if ($query_id instanceof doctrine_result)
		{
			return $query_id->fetch_associative();
		}

		$safe_query_id = $this->clean_query_id($query_id);
		if ($cache && $cache->sql_exists($safe_query_id))
		{
			return $cache->sql_fetchrow($safe_query_id);
		}

		if ($query_id)
		{
			$result = mysqli_fetch_assoc($query_id);
			return $result !== null ? $result : false;
		}

		return false;
	}

	/**
	* {@inheritDoc}
	*/
	function sql_rowseek($rownum, &$query_id)
	{
		global $cache;

		if ($query_id === false)
		{
			$query_id = $this->query_result;
		}

		if ($query_id instanceof doctrine_result)
		{
			return $query_id->seek($rownum);
		}

		$safe_query_id = $this->clean_query_id($query_id);
		if ($cache && $cache->sql_exists($safe_query_id))
		{
			return $cache->sql_rowseek($rownum, $safe_query_id);
		}

		return ($query_id) ? @mysqli_data_seek($query_id, $rownum) : false;
	}

	/**
	 * {@inheritdoc}
	 */
	public function sql_last_inserted_id()
	{
		if ($this->last_query_write_via_doctrine)
		{
			return $this->last_doctrine_insert_id !== null ? $this->last_doctrine_insert_id : 0;
		}

		return ($this->db_connect_id) ? (int) @mysqli_insert_id($this->db_connect_id) : false;
	}

	/**
	* {@inheritDoc}
	*/
	function sql_freeresult($query_id = false)
	{
		global $cache;

		if ($query_id === false)
		{
			$query_id = $this->query_result;
		}

		if ($query_id instanceof doctrine_result)
		{
			$query_id->free();
			return;
		}

		$safe_query_id = $this->clean_query_id($query_id);
		if ($cache && $cache->sql_exists($safe_query_id))
		{
			$cache->sql_freeresult($safe_query_id);
		}
		else if ($query_id && $query_id !== true)
		{
			mysqli_free_result($query_id);
		}
	}

	/**
	* {@inheritDoc}
	*/
	function sql_escape($msg)
	{
		return @mysqli_real_escape_string($this->db_connect_id, $msg);
	}

	/**
	* {@inheritDoc}
	*/
	protected function _sql_error(): array
	{
		if ($this->last_doctrine_error !== null)
		{
			return $this->last_doctrine_error;
		}

		if ($this->db_connect_id)
		{
			$error = [
				'message'	=> $this->db_connect_id->connect_error ?: $this->db_connect_id->error,
				'code'		=> $this->db_connect_id->connect_errno ?: $this->db_connect_id->errno,
			];
		}
		else
		{
			$error = [
				'message'	=> $this->connect_error,
				'code'		=> '',
			];
		}

		return $error;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function _sql_close(): bool
	{
		if ($this->doctrine_bridge && $this->doctrine_uses_shared_native_connection())
		{
			// Doctrine owns the native mysqli object. Closing the DBAL connection
			// closes the one physical session; do not close the same mysqli twice.
			$this->doctrine_bridge->close();
			$this->doctrine_shared_native_connection = false;
			return true;
		}

		if ($this->doctrine_bridge)
		{
			$this->doctrine_bridge->close();
		}

		return @mysqli_close($this->db_connect_id);
	}

	/**
	* {@inheritDoc}
	*/
	protected function _sql_report(string $mode, string $query = ''): void
	{
		static $test_prof;

		$connection = $this->get_doctrine_connection();
		if (!$connection)
		{
			return;
		}

		// Keep phpBB's historical profiling feature detection, but obtain server
		// information through Doctrine rather than querying the native handle.
		if ($test_prof === null)
		{
			$test_prof = false;
			$server_info = $this->sql_server_info(true, false);
			if (stripos($server_info, 'community') !== false && preg_match('/(\d+\.\d+\.\d+)/', $server_info, $match))
			{
				$version_id = (int) sprintf('%d%02d%02d', ...array_map('intval', explode('.', $match[1])));
				if ($version_id >= 50037 && $version_id < 50100)
				{
					$test_prof = true;
				}
			}
		}

		switch ($mode)
		{
			case 'start':

				$explain_query = $query;
				if (preg_match('/UPDATE ([a-z0-9_]+).*?WHERE(.*)/s', $query, $m))
				{
					$explain_query = 'SELECT * FROM ' . $m[1] . ' WHERE ' . $m[2];
				}
				else if (preg_match('/DELETE FROM ([a-z0-9_]+).*?WHERE(.*)/s', $query, $m))
				{
					$explain_query = 'SELECT * FROM ' . $m[1] . ' WHERE ' . $m[2];
				}

				if (preg_match('/^SELECT/', $explain_query))
				{
					$html_table = false;

					if ($test_prof)
					{
						try
						{
							$connection->executeStatement('SET profiling = 1');
						}
						catch (\Throwable $e)
						{
							// Profiling is optional; EXPLAIN remains available.
						}
					}

					try
					{
						$result = $connection->executeQuery("EXPLAIN $explain_query");
						while (($row = $result->fetchAssociative()) !== false)
						{
							$html_table = $this->sql_report('add_select_row', $query, $html_table, $row);
						}
						$result->free();
					}
					catch (\Throwable $e)
					{
						// Debug reporting must never replace the normal query error path.
					}

					if ($html_table)
					{
						$this->html_hold .= '</table>';
					}

					if ($test_prof)
					{
						$html_table = false;
						try
						{
							$result = $connection->executeQuery('SHOW PROFILE ALL');
							$this->html_hold .= '<br />';
							while (($row = $result->fetchAssociative()) !== false)
							{
								if (!empty($row['Source_function']))
								{
									$row['Source_function'] = str_replace(['<', '>'], ['&lt;', '&gt;'], $row['Source_function']);
								}

								foreach ($row as $key => $val)
								{
									if ($val === null)
									{
										unset($row[$key]);
									}
								}
								$html_table = $this->sql_report('add_select_row', $query, $html_table, $row);
							}
							$result->free();
						}
						catch (\Throwable $e)
						{
							// Optional historical profiling is allowed to be unavailable.
						}

						if ($html_table)
						{
							$this->html_hold .= '</table>';
						}

						try
						{
							$connection->executeStatement('SET profiling = 0');
						}
						catch (\Throwable $e)
						{
						}
					}
				}

			break;

			case 'fromcache':
				$endtime = microtime(true);

				try
				{
					$result = $connection->executeQuery($query);
					while ($result->fetchAssociative() !== false)
					{
						// Take the time spent on parsing rows into account.
					}
					$result->free();
				}
				catch (\Throwable $e)
				{
				}

				$splittime = microtime(true);
				$this->sql_report('record_fromcache', $query, $endtime, $splittime);

			break;
		}
	}

	/**
	* {@inheritDoc}
	*/
	function sql_quote($msg)
	{
		return '`' . $msg . '`';
	}
}

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

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Mysqli\Connection as MysqliDriverConnection;
use Doctrine\DBAL\DriverManager;

/**
 * Transitional bridge between phpBB's historical DBAL API and Doctrine DBAL.
 *
 * Stage 8 makes Doctrine the owner of the primary MySQL/MariaDB connection.
 * phpBB's historical MySQLi driver receives the native mysqli object exposed by
 * Doctrine, so both APIs operate on one physical server session. This removes
 * the previous dual-connection split for temporary tables, session variables,
 * advisory locks and transactions while keeping phpBB's legacy public API.
 */
class doctrine_bridge
{
	/** @var array<string, mixed> */
	protected $connection_params = [];

	/** @var Connection|null */
	protected $connection = null;

	/** @var string|null */
	protected $session_sql_mode = null;

	/** @var bool */
	protected $session_initialised = false;

	/** @var bool */
	protected $persistent = false;

	/**
	 * @param string      $sqlserver   Database host
	 * @param string      $sqluser     Database user
	 * @param string      $sqlpassword Database password
	 * @param string      $database    Database name
	 * @param int|null    $port        TCP port
	 * @param string|null $socket      Unix socket
	 * @param string|null $sql_mode    Session SQL mode to mirror
	 * @param bool        $persistency Whether phpBB requested a persistent connection
	 */
	public function __construct($sqlserver, $sqluser, $sqlpassword, $database, $port = null, $socket = null, $sql_mode = null, $persistency = false)
	{
		// phpBB historically prefixes persistent mysqli hosts with p:. Doctrine has
		// a dedicated persistent parameter, so store the real host here.
		if (is_string($sqlserver) && strpos($sqlserver, 'p:') === 0)
		{
			$sqlserver = substr($sqlserver, 2);
		}

		$this->persistent = (bool) $persistency;
		$this->connection_params = [
			'driver' => 'mysqli',
			'host' => $sqlserver ?: 'localhost',
			'user' => $sqluser,
			'password' => $sqlpassword,
			'dbname' => $database,
			'charset' => 'utf8',
			'persistent' => $this->persistent,
			// Preserve phpBB's MYSQLI_CLIENT_FOUND_ROWS semantics. Doctrine's mysqli
			// driver exposes this dedicated flag option.
			'driverOptions' => [
				MysqliDriverConnection::OPTION_FLAGS => MYSQLI_CLIENT_FOUND_ROWS,
			],
		];

		if ($port !== null && $port !== false && $port !== '')
		{
			$this->connection_params['port'] = (int) $port;
		}

		if ($socket)
		{
			$this->connection_params['unix_socket'] = $socket;
		}

		$this->session_sql_mode = ($sql_mode !== null && $sql_mode !== '') ? (string) $sql_mode : null;
	}

	/**
	 * Return the Doctrine DBAL connection, opening it on first use.
	 *
	 * @return Connection
	 */
	public function get_connection()
	{
		if ($this->connection === null)
		{
			$this->connection = DriverManager::getConnection($this->connection_params);
		}

		// Force the driver connection to exist through DBAL's supported native
		// connection accessor. getNativeConnection() is available on DBAL 3.x.
		$this->connection->getNativeConnection();
		$this->initialise_session();

		return $this->connection;
	}

	/**
	 * Return the native mysqli object owned by Doctrine.
	 *
	 * @return \mysqli|null
	 */
	public function get_native_connection()
	{
		$connection = $this->get_connection();
		if (!method_exists($connection, 'getNativeConnection'))
		{
			return null;
		}

		$native = $connection->getNativeConnection();
		return ($native instanceof \mysqli) ? $native : null;
	}

	/**
	 * Whether Doctrine and phpBB are using the exact same native mysqli object.
	 *
	 * @param mixed $native
	 * @return bool
	 */
	public function shares_native_connection($native)
	{
		if (!($native instanceof \mysqli))
		{
			return false;
		}

		return $this->get_native_connection() === $native;
	}

	/**
	 * Update the SQL mode phpBB established on the shared physical session.
	 *
	 * The mode is applied through Doctrine as well so reconnect-compatible bridge
	 * state remains explicit even though both APIs currently share one session.
	 *
	 * @param string|null $sql_mode
	 * @return void
	 */
	public function set_session_sql_mode($sql_mode)
	{
		$this->session_sql_mode = ($sql_mode !== null && $sql_mode !== '') ? (string) $sql_mode : null;
		$this->session_initialised = false;
		$this->initialise_session();
	}

	/**
	 * Mirror session-level database settings required by phpBB.
	 *
	 * @return void
	 */
	protected function initialise_session()
	{
		if ($this->session_initialised || $this->connection === null)
		{
			return;
		}

		if ($this->session_sql_mode !== null)
		{
			$this->connection->executeStatement('SET SESSION sql_mode = ?', [$this->session_sql_mode]);
		}

		$this->session_initialised = true;
	}

	/**
	 * Whether Doctrine currently owns an active database transaction.
	 *
	 * @return bool
	 */
	public function transaction_active()
	{
		return $this->connection !== null && $this->connection->isTransactionActive();
	}

	/**
	 * Close the Doctrine-owned physical connection if it has been instantiated.
	 *
	 * @return void
	 */
	public function close()
	{
		if ($this->connection !== null)
		{
			$this->connection->close();
			$this->connection = null;
			$this->session_initialised = false;
		}
	}
}

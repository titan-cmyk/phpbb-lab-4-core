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

namespace phpbb\forum\data;

use phpbb\db\driver\driver_interface;
use phpbb\event\dispatcher_interface;

/**
 * Data access used by the legacy viewforum entry point.
 *
 * This repository deliberately preserves the existing viewforum events so
 * extensions can continue to alter the forum query exactly as before.
 */
class viewforum_repository
{
	/** @var driver_interface */
	protected $db;

	/** @var dispatcher_interface */
	protected $dispatcher;

	/** @var string */
	protected $forums_table;

	/** @var string */
	protected $forums_track_table;

	/** @var string */
	protected $forums_watch_table;

	public function __construct(
		driver_interface $db,
		dispatcher_interface $dispatcher,
		string $forums_table,
		string $forums_track_table,
		string $forums_watch_table
	)
	{
		$this->db = $db;
		$this->dispatcher = $dispatcher;
		$this->forums_table = $forums_table;
		$this->forums_track_table = $forums_track_table;
		$this->forums_watch_table = $forums_watch_table;
	}

	/**
	 * Fetch one forum together with optional per-user tracking/watch data.
	 *
	 * @param int  $forum_id
	 * @param bool $load_lastread
	 * @param bool $load_watch
	 * @param int  $user_id
	 *
	 * @return array|false
	 */
	public function get_forum_by_id(int $forum_id, bool $load_lastread, bool $load_watch, int $user_id)
	{
		$sql_ary = [
			'SELECT' => 'f.*',
			'FROM' => [
				$this->forums_table => 'f',
			],
			'WHERE' => 'f.forum_id = ' . $forum_id,
		];

		if ($load_lastread)
		{
			$sql_ary['LEFT_JOIN'][] = [
				'FROM' => [$this->forums_track_table => 'ft'],
				'ON' => 'ft.user_id = ' . $user_id . ' AND ft.forum_id = f.forum_id',
			];
			$sql_ary['SELECT'] .= ', ft.mark_time';
		}

		if ($load_watch)
		{
			$sql_ary['LEFT_JOIN'][] = [
				'FROM' => [$this->forums_watch_table => 'fw'],
				'ON' => 'fw.forum_id = f.forum_id AND fw.user_id = ' . $user_id,
			];
			$sql_ary['SELECT'] .= ', fw.notify_status';
		}

		/**
		 * You can use this event to modify the sql that selects the forum on the viewforum page.
		 *
		 * @event core.viewforum_modify_sql
		 * @var array sql_ary The SQL array to get the data for a forum
		 * @since 3.3.14-RC1
		 */
		$vars = ['sql_ary'];
		extract($this->dispatcher->trigger_event('core.viewforum_modify_sql', compact($vars)));

		$sql = $this->db->sql_build_query('SELECT', $sql_ary);
		$sql_params = [];

		// Keep the historical event payload untouched (literal values), then
		// bind the core-owned scalar conditions after extensions have modified
		// the SQL array. This preserves extension compatibility while executing
		// the unchanged core fragments through Doctrine parameters.
		$sql = $this->parameterize_fragment($sql, 'f.forum_id = ' . $forum_id, 'f.forum_id = :vf_repo_forum_id', ['vf_repo_forum_id' => $forum_id], $sql_params);
		if ($load_lastread)
		{
			$sql = $this->parameterize_fragment($sql, 'ft.user_id = ' . $user_id, 'ft.user_id = :vf_repo_track_user_id', ['vf_repo_track_user_id' => $user_id], $sql_params);
		}
		if ($load_watch)
		{
			$sql = $this->parameterize_fragment($sql, 'fw.user_id = ' . $user_id, 'fw.user_id = :vf_repo_watch_user_id', ['vf_repo_watch_user_id' => $user_id], $sql_params);
		}

		$result = $sql_params ? $this->db->sql_query_params($sql, $sql_params) : $this->db->sql_query($sql);
		$forum_data = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return $forum_data;
	}

	/**
	 * Increment the click counter of a link forum.
	 */
	public function increment_link_click_count(int $forum_id): void
	{
		$sql = 'UPDATE ' . $this->forums_table . '
			SET forum_posts_approved = forum_posts_approved + 1
			WHERE forum_id = :vf_repo_click_forum_id';
		$this->db->sql_query_params($sql, ['vf_repo_click_forum_id' => $forum_id]);
	}

	/**
	 * Replace one exact core-owned SQL fragment after extension events ran.
	 *
	 * Existing event listeners continue to receive the historical literal SQL.
	 * If an extension rewrites a fragment, it is deliberately left untouched.
	 */
	protected function parameterize_fragment(string $sql, string $literal, string $parameterized, array $values, array &$params): string
	{
		$position = strpos($sql, $literal);
		if ($position === false)
		{
			return $sql;
		}

		$sql = substr_replace($sql, $parameterized, $position, strlen($literal));
		foreach ($values as $name => $value)
		{
			$params[$name] = $value;
		}

		return $sql;
	}
}

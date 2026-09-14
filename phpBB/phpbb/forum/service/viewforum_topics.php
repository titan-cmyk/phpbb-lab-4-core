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

namespace phpbb\forum\service;

use phpbb\auth\auth;
use phpbb\config\config;
use phpbb\content_visibility;
use phpbb\db\driver\driver_interface;
use phpbb\event\dispatcher_interface;
use phpbb\pagination;
use phpbb\user;

/**
 * Loads and prepares the topic data required by viewforum.
 *
 * This service owns the SQL-heavy topic retrieval path while preserving the
 * established viewforum events and their ordering for extension compatibility.
 */
class viewforum_topics
{
	/** @var auth */
	protected $auth;
	/** @var config */
	protected $config;
	/** @var content_visibility */
	protected $content_visibility;
	/** @var driver_interface */
	protected $db;
	/** @var dispatcher_interface */
	protected $dispatcher;
	/** @var pagination */
	protected $pagination;
	/** @var user */
	protected $user;
	/** @var string */
	protected $topics_table;
	/** @var string */
	protected $topics_posted_table;
	/** @var string */
	protected $topics_track_table;
	/** @var string */
	protected $forums_table;
	/** @var string */
	protected $forums_track_table;

	public function __construct(
		auth $auth,
		config $config,
		content_visibility $content_visibility,
		driver_interface $db,
		dispatcher_interface $dispatcher,
		pagination $pagination,
		user $user,
		string $topics_table,
		string $topics_posted_table,
		string $topics_track_table,
		string $forums_table,
		string $forums_track_table
	)
	{
		$this->auth = $auth;
		$this->config = $config;
		$this->content_visibility = $content_visibility;
		$this->db = $db;
		$this->dispatcher = $dispatcher;
		$this->pagination = $pagination;
		$this->user = $user;
		$this->topics_table = $topics_table;
		$this->topics_posted_table = $topics_posted_table;
		$this->topics_track_table = $topics_track_table;
		$this->forums_table = $forums_table;
		$this->forums_track_table = $forums_track_table;
	}

	/**
	 * Load one viewforum topic page.
	 *
	 * @return array{
	 *     forum_data: array,
	 *     forum_id: int,
	 *     topics_count: int,
	 *     rowset: array,
	 *     announcement_list: array,
	 *     topic_list: array,
	 *     forum_tracking_info: array,
	 *     store_reverse: bool
	 * }
	 */
	public function load(
		array $forum_data,
		int $forum_id,
		int $topics_count,
		int $sort_days,
		string $sort_key,
		string $sort_dir,
		array $sort_by_sql,
		string $sql_limit_time,
		bool $s_display_active,
		array $active_forum_ary,
		int $start
	): array
	{
		$auth = $this->auth;
		$config = $this->config;
		$phpbb_content_visibility = $this->content_visibility;
		$db = $this->db;
		$phpbb_dispatcher = $this->dispatcher;
		$pagination = $this->pagination;
		$user = $this->user;

		$rowset = $announcement_list = $topic_list = $global_announce_forums = [];

		$sql_array = [
			'SELECT' => 't.*',
			'FROM' => [
				$this->topics_table => 't',
			],
			'LEFT_JOIN' => [],
		];

		/**
		* Event to modify the SQL query before the topic data is retrieved
		*
		* It may also be used to override the above assigned template vars
		*
		* @event core.viewforum_get_topic_data
		* @var	array	forum_data			Array with forum data
		* @var	array	sql_array			The SQL array to get the data of all topics
		* @var	int		forum_id			The forum_id whose topics are being listed
		* @var	int		topics_count		The total number of topics for display
		* @var	int		sort_days			The oldest topic displayable in elapsed days
		* @var	string	sort_key			The sorting by. It is one of the first character of (in low case):
		*									Author, Post time, Replies, Subject, Views
		* @var	string	sort_dir			Either "a" for ascending or "d" for descending
		* @since 3.1.0-a1
		* @changed 3.1.0-RC4 Added forum_data var
		* @changed 3.1.4-RC1 Added forum_id, topics_count, sort_days, sort_key and sort_dir vars
		* @changed 3.1.9-RC1 Fix types of properties
		*/
		$vars = [
			'forum_data',
			'sql_array',
			'forum_id',
			'topics_count',
			'sort_days',
			'sort_key',
			'sort_dir',
		];
		extract($phpbb_dispatcher->trigger_event('core.viewforum_get_topic_data', compact($vars)));

		$sql_approved = ' AND ' . $phpbb_content_visibility->get_visibility_sql('topic', $forum_id, 't.');

		if ($user->data['is_registered'])
		{
			if ($config['load_db_track'])
			{
				$sql_array['LEFT_JOIN'][] = [
					'FROM' => [$this->topics_posted_table => 'tp'],
					'ON' => 'tp.topic_id = t.topic_id AND tp.user_id = ' . $user->data['user_id'],
				];
				$sql_array['SELECT'] .= ', tp.topic_posted';
			}

			if ($config['load_db_lastread'])
			{
				$sql_array['LEFT_JOIN'][] = [
					'FROM' => [$this->topics_track_table => 'tt'],
					'ON' => 'tt.topic_id = t.topic_id AND tt.user_id = ' . $user->data['user_id'],
				];
				$sql_array['SELECT'] .= ', tt.mark_time';

				if ($s_display_active && count($active_forum_ary))
				{
					$sql_array['LEFT_JOIN'][] = [
						'FROM' => [$this->forums_track_table => 'ft'],
						'ON' => 'ft.forum_id = t.forum_id AND ft.user_id = ' . $user->data['user_id'],
					];
					$sql_array['SELECT'] .= ', ft.mark_time AS forum_mark_time';
				}
			}
		}

		if ($forum_data['forum_type'] == FORUM_POST)
		{
			$g_forum_ary = $auth->acl_getf('f_read', true);
			$g_forum_ary = array_unique(array_keys($g_forum_ary));

			$sql_anounce_array = [];
			$sql_anounce_array['LEFT_JOIN'] = $sql_array['LEFT_JOIN'];
			$sql_anounce_array['LEFT_JOIN'][] = [
				'FROM' => [$this->forums_table => 'f'],
				'ON' => 'f.forum_id = t.forum_id',
			];
			$sql_anounce_array['SELECT'] = $sql_array['SELECT'] . ', f.forum_name';

			$global_forum_in_literal = $db->sql_in_set('t.forum_id', $g_forum_ary, false, true);
			$global_forum_params = [];
			$global_forum_in_parameterized = $db->sql_in_set_params('t.forum_id', $g_forum_ary, $global_forum_params, false, true, 'vf_global_forum');

			$sql_ary = [
				'SELECT' => $sql_anounce_array['SELECT'],
				'FROM' => $sql_array['FROM'],
				'LEFT_JOIN' => $sql_anounce_array['LEFT_JOIN'],
				'WHERE' => '(t.forum_id = ' . $forum_id . '
						AND t.topic_type = ' . POST_ANNOUNCE . ') OR
					(' . $global_forum_in_literal . '
						AND t.topic_type = ' . POST_GLOBAL . ')',
				'ORDER_BY' => 't.topic_time DESC',
			];

			/**
			* Event to modify the SQL query before the announcement topic ids data is retrieved
			*
			* @event core.viewforum_get_announcement_topic_ids_data
			* @var	array	forum_data			Data about the forum
			* @var	array	g_forum_ary			Global announcement forums array
			* @var	array	sql_anounce_array	SQL announcement array
			* @var	array	sql_ary				SQL query array to get the announcement topic ids data
			* @var	int		forum_id			The forum ID
			*
			* @since 3.1.10-RC1
			*/
			$vars = [
				'forum_data',
				'g_forum_ary',
				'sql_anounce_array',
				'sql_ary',
				'forum_id',
			];
			extract($phpbb_dispatcher->trigger_event('core.viewforum_get_announcement_topic_ids_data', compact($vars)));

			$sql = $db->sql_build_query('SELECT', $sql_ary);
			$sql_params = [];

			// Extension events still see the historical literal SQL array. Bind only
			// unchanged core-owned fragments after the event has finished.
			$sql = $this->parameterize_fragment($sql, 't.forum_id = ' . $forum_id, 't.forum_id = :vf_announce_forum_id', ['vf_announce_forum_id' => $forum_id], $sql_params);
			$sql = $this->parameterize_fragment($sql, $global_forum_in_literal, $global_forum_in_parameterized, $global_forum_params, $sql_params);
			if ($user->data['is_registered'])
			{
				$sql = $this->parameterize_fragment($sql, 'tp.user_id = ' . $user->data['user_id'], 'tp.user_id = :vf_announce_posted_user_id', ['vf_announce_posted_user_id' => (int) $user->data['user_id']], $sql_params);
				$sql = $this->parameterize_fragment($sql, 'tt.user_id = ' . $user->data['user_id'], 'tt.user_id = :vf_announce_track_user_id', ['vf_announce_track_user_id' => (int) $user->data['user_id']], $sql_params);
				$sql = $this->parameterize_fragment($sql, 'ft.user_id = ' . $user->data['user_id'], 'ft.user_id = :vf_announce_forum_track_user_id', ['vf_announce_forum_track_user_id' => (int) $user->data['user_id']], $sql_params);
			}
			$result = $sql_params ? $db->sql_query_params($sql, $sql_params) : $db->sql_query($sql);

			while ($row = $db->sql_fetchrow($result))
			{
				if (!$phpbb_content_visibility->is_visible('topic', $row['forum_id'], $row))
				{
					continue;
				}

				$rowset[$row['topic_id']] = $row;
				$announcement_list[] = $row['topic_id'];

				if ($forum_id != $row['forum_id'])
				{
					$topics_count++;
					$global_announce_forums[] = $row['forum_id'];
				}
			}
			$db->sql_freeresult($result);
		}

		$forum_tracking_info = [];

		if ($user->data['is_registered'] && $config['load_db_lastread'])
		{
			$forum_tracking_info[$forum_id] = $forum_data['mark_time'];

			if (!empty($global_announce_forums))
			{
				$sql_params = [];
				$sql_forum_ids = $db->sql_in_set_params('forum_id', $global_announce_forums, $sql_params, false, false, 'vf_tracking_forum');
				$sql = 'SELECT forum_id, mark_time
					FROM ' . $this->forums_track_table . '
					WHERE ' . $sql_forum_ids . '
						AND user_id = :vf_tracking_user_id';
				$sql_params['vf_tracking_user_id'] = (int) $user->data['user_id'];
				$result = $db->sql_query_params($sql, $sql_params);

				while ($row = $db->sql_fetchrow($result))
				{
					$forum_tracking_info[$row['forum_id']] = $row['mark_time'];
				}
				$db->sql_freeresult($result);
			}
		}

		$store_reverse = false;
		$sql_limit = $config['topics_per_page'];
		if ($start > $topics_count / 2)
		{
			$store_reverse = true;
			$direction = (($sort_dir == 'd') ? 'ASC' : 'DESC');
			$sql_limit = $pagination->reverse_limit($start, $sql_limit, $topics_count - count($announcement_list));
			$sql_start = $pagination->reverse_start($start, $sql_limit, $topics_count - count($announcement_list));
		}
		else
		{
			$direction = (($sort_dir == 'd') ? 'DESC' : 'ASC');
			$sql_start = $start;
		}

		/**
		 * Modify the topics sort ordering if needed
		 *
		 * @event core.viewforum_modify_sort_direction
		 * @var string	direction	Topics sort order
		 * @since 3.2.5-RC1
		 */
		$vars = ['direction'];
		extract($phpbb_dispatcher->trigger_event('core.viewforum_modify_sort_direction', compact($vars)));

		if (is_array($sort_by_sql[$sort_key]))
		{
			$sql_sort_order = implode(' ' . $direction . ', ', $sort_by_sql[$sort_key]) . ' ' . $direction;
		}
		else
		{
			$sql_sort_order = $sort_by_sql[$sort_key] . ' ' . $direction;
		}

		$sql_where_params = [];
		if ($forum_data['forum_type'] == FORUM_POST || !count($active_forum_ary))
		{
			$sql_where = 't.forum_id = ' . $forum_id;
			$sql_where_parameterized = 't.forum_id = :vf_topic_ids_forum_id';
			$sql_where_params['vf_topic_ids_forum_id'] = $forum_id;
		}
		else if (empty($active_forum_ary['exclude_forum_id']))
		{
			$sql_where = $db->sql_in_set('t.forum_id', $active_forum_ary['forum_id']);
			$sql_where_parameterized = $db->sql_in_set_params('t.forum_id', $active_forum_ary['forum_id'], $sql_where_params, false, false, 'vf_topic_ids_forum');
		}
		else
		{
			$get_forum_ids = array_diff($active_forum_ary['forum_id'], $active_forum_ary['exclude_forum_id']);
			if (count($get_forum_ids))
			{
				$sql_where = $db->sql_in_set('t.forum_id', $get_forum_ids);
				$sql_where_parameterized = $db->sql_in_set_params('t.forum_id', $get_forum_ids, $sql_where_params, false, false, 'vf_topic_ids_forum');
			}
			else
			{
				$sql_where = 't.forum_id = ' . $forum_id;
				$sql_where_parameterized = 't.forum_id = :vf_topic_ids_forum_id';
				$sql_where_params['vf_topic_ids_forum_id'] = $forum_id;
			}
		}
		$sql_where_literal = $sql_where;

		$sql_ary = [
			'SELECT' => 't.topic_id',
			'FROM' => [
				$this->topics_table => 't',
			],
			'WHERE' => "$sql_where
				AND t.topic_type IN (" . POST_NORMAL . ', ' . POST_STICKY . ")
				$sql_approved
				$sql_limit_time",
			'ORDER_BY' => 't.topic_type ' . ((!$store_reverse) ? 'DESC' : 'ASC') . ', ' . $sql_sort_order,
		];

		/**
		* Event to modify the SQL query before the topic ids data is retrieved
		*
		* @event core.viewforum_get_topic_ids_data
		* @var	array	forum_data		Data about the forum
		* @var	array	sql_ary			SQL query array to get the topic ids data
		* @var	string	sql_approved	Topic visibility SQL string
		* @var	int		sql_limit		Number of records to select
		* @var	string	sql_limit_time	SQL string to limit topic_last_post_time data
		* @var	array	sql_sort_order	SQL sorting string
		* @var	int		sql_start		Offset point to start selection from
		* @var	string	sql_where		SQL WHERE clause string
		* @var	bool	store_reverse	Flag indicating if we select from the late pages
		*
		* @since 3.1.0-RC4
		*
		* @changed 3.1.3 Added forum_data
		*/
		$vars = [
			'forum_data',
			'sql_ary',
			'sql_approved',
			'sql_limit',
			'sql_limit_time',
			'sql_sort_order',
			'sql_start',
			'sql_where',
			'store_reverse',
		];
		extract($phpbb_dispatcher->trigger_event('core.viewforum_get_topic_ids_data', compact($vars)));

		$sql = $db->sql_build_query('SELECT', $sql_ary);
		$sql_params = [];
		$sql = $this->parameterize_fragment($sql, $sql_where_literal, $sql_where_parameterized, $sql_where_params, $sql_params);
		if ($user->data['is_registered'])
		{
			$sql = $this->parameterize_fragment($sql, 'tp.user_id = ' . $user->data['user_id'], 'tp.user_id = :vf_topic_ids_posted_user_id', ['vf_topic_ids_posted_user_id' => (int) $user->data['user_id']], $sql_params);
			$sql = $this->parameterize_fragment($sql, 'tt.user_id = ' . $user->data['user_id'], 'tt.user_id = :vf_topic_ids_track_user_id', ['vf_topic_ids_track_user_id' => (int) $user->data['user_id']], $sql_params);
			$sql = $this->parameterize_fragment($sql, 'ft.user_id = ' . $user->data['user_id'], 'ft.user_id = :vf_topic_ids_forum_track_user_id', ['vf_topic_ids_forum_track_user_id' => (int) $user->data['user_id']], $sql_params);
		}
		$result = $sql_params ? $db->sql_query_limit_params($sql, $sql_limit, $sql_start, $sql_params) : $db->sql_query_limit($sql, $sql_limit, $sql_start);

		while ($row = $db->sql_fetchrow($result))
		{
			$topic_list[] = (int) $row['topic_id'];
		}
		$db->sql_freeresult($result);

		$shadow_topic_list = [];

		if (count($topic_list))
		{
			$topic_list_where_literal = $db->sql_in_set('t.topic_id', $topic_list);
			$topic_list_params = [];
			$topic_list_where_parameterized = $db->sql_in_set_params('t.topic_id', $topic_list, $topic_list_params, false, false, 'vf_topic_list_id');
			$topic_sql_array = [
				'SELECT' => $sql_array['SELECT'],
				'FROM' => $sql_array['FROM'],
				'LEFT_JOIN' => $sql_array['LEFT_JOIN'],
				'WHERE' => $topic_list_where_literal,
			];

			/**
			* Event to modify the SQL query before obtaining topics/stickies
			*
			* @event core.viewforum_modify_topic_list_sql
			* @var	int		forum_id			The forum ID
			* @var	array	forum_data			Data about the forum
			* @var	array	topic_list			Topic ids array
			* @var	array	sql_array			SQL query array for obtaining topics/stickies
			*
			* @since 3.2.10-RC1
			* @since 3.3.1-RC1
			*/
			// Preserve the historical event variable name expected by extensions.
			$sql_array = $topic_sql_array;
			$vars = [
				'forum_id',
				'forum_data',
				'topic_list',
				'sql_array',
			];
			extract($phpbb_dispatcher->trigger_event('core.viewforum_modify_topic_list_sql', compact($vars)));

			$sql = $db->sql_build_query('SELECT', $sql_array);
			$sql_params = [];
			$sql = $this->parameterize_fragment($sql, $topic_list_where_literal, $topic_list_where_parameterized, $topic_list_params, $sql_params);
			if ($user->data['is_registered'])
			{
				$sql = $this->parameterize_fragment($sql, 'tp.user_id = ' . $user->data['user_id'], 'tp.user_id = :vf_topic_list_posted_user_id', ['vf_topic_list_posted_user_id' => (int) $user->data['user_id']], $sql_params);
				$sql = $this->parameterize_fragment($sql, 'tt.user_id = ' . $user->data['user_id'], 'tt.user_id = :vf_topic_list_track_user_id', ['vf_topic_list_track_user_id' => (int) $user->data['user_id']], $sql_params);
				$sql = $this->parameterize_fragment($sql, 'ft.user_id = ' . $user->data['user_id'], 'ft.user_id = :vf_topic_list_forum_track_user_id', ['vf_topic_list_forum_track_user_id' => (int) $user->data['user_id']], $sql_params);
			}
			$result = $sql_params ? $db->sql_query_params($sql, $sql_params) : $db->sql_query($sql);

			while ($row = $db->sql_fetchrow($result))
			{
				if ($row['topic_status'] == ITEM_MOVED)
				{
					$shadow_topic_list[$row['topic_moved_id']] = $row['topic_id'];
				}

				$rowset[$row['topic_id']] = $row;
			}
			$db->sql_freeresult($result);
		}

		if (count($shadow_topic_list))
		{
			$shadow_where_literal = $db->sql_in_set('t.topic_id', array_keys($shadow_topic_list));
			$shadow_params = [];
			$shadow_where_parameterized = $db->sql_in_set_params('t.topic_id', array_keys($shadow_topic_list), $shadow_params, false, false, 'vf_shadow_topic_id');
			$sql_array = [
				'SELECT' => 't.*',
				'FROM' => [
					$this->topics_table => 't',
				],
				'WHERE' => $shadow_where_literal,
			];

			/**
			* Event to modify the SQL query before the shadowtopic data is retrieved
			*
			* @event core.viewforum_get_shadowtopic_data
			* @var	array	sql_array		SQL array to get the data of any shadowtopics
			* @since 3.1.0-a1
			*/
			$vars = ['sql_array'];
			extract($phpbb_dispatcher->trigger_event('core.viewforum_get_shadowtopic_data', compact($vars)));

			$sql = $db->sql_build_query('SELECT', $sql_array);
			$sql_params = [];
			$sql = $this->parameterize_fragment($sql, $shadow_where_literal, $shadow_where_parameterized, $shadow_params, $sql_params);
			$result = $sql_params ? $db->sql_query_params($sql, $sql_params) : $db->sql_query($sql);

			while ($row = $db->sql_fetchrow($result))
			{
				$orig_topic_id = $shadow_topic_list[$row['topic_id']];

				if (isset($rowset[$row['topic_id']]))
				{
					unset($rowset[$orig_topic_id]);
					$topic_key = array_search($orig_topic_id, $topic_list);
					if ($topic_key !== false)
					{
						unset($topic_list[$topic_key]);
					}
					$topics_count--;
					continue;
				}

				if (!$auth->acl_gets('f_read', 'f_list_topics', $row['forum_id']))
				{
					unset($rowset[$orig_topic_id]);
					$topic_key = array_search($orig_topic_id, $topic_list);
					if ($topic_key !== false)
					{
						unset($topic_list[$topic_key]);
					}
					$topics_count--;
					continue;
				}

				$row = array_merge($row, [
					'topic_moved_id' => $rowset[$orig_topic_id]['topic_moved_id'],
					'topic_status' => $rowset[$orig_topic_id]['topic_status'],
					'topic_type' => $rowset[$orig_topic_id]['topic_type'],
					'topic_title' => $rowset[$orig_topic_id]['topic_title'],
				]);

				$row['topic_reported'] = 0;
				$rowset[$orig_topic_id] = $row;
			}
			$db->sql_freeresult($result);
		}

		return [
			'forum_data' => $forum_data,
			'forum_id' => (int) $forum_id,
			'topics_count' => (int) $topics_count,
			'rowset' => $rowset,
			'announcement_list' => $announcement_list,
			'topic_list' => $topic_list,
			'forum_tracking_info' => $forum_tracking_info,
			'store_reverse' => (bool) $store_reverse,
		];
	}

	/**
	 * Bind one exact core-owned SQL fragment after viewforum extension events.
	 *
	 * Event listeners continue to receive the historical literal query data.
	 * When an extension rewrites a fragment, the original literal is no longer
	 * found and that fragment deliberately remains under the legacy path.
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

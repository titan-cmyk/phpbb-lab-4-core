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
use phpbb\profilefields\manager as profilefields_manager;
use phpbb\user;

/**
 * Loads and prepares the post/poster data consumed by viewtopic.
 *
 * This service deliberately preserves the legacy viewtopic events so
 * extensions can continue to alter the post list, post query and cached
 * poster data at the same integration points as before.
 */
class viewtopic_posts
{
	/** @var auth */
	protected $auth;

	/** @var object */
	protected $avatar_helper;

	/** @var config */
	protected $config;

	/** @var content_visibility */
	protected $content_visibility;

	/** @var driver_interface */
	protected $db;

	/** @var dispatcher_interface */
	protected $dispatcher;

	/** @var profilefields_manager */
	protected $profilefields_manager;

	/** @var user */
	protected $user;

	/** @var string */
	protected $posts_table;

	/** @var string */
	protected $users_table;

	/** @var string */
	protected $zebra_table;

	/** @var string */
	protected $sessions_table;

	/** @var string */
	protected $root_path;

	/** @var string */
	protected $php_ext;

	public function __construct(
		auth $auth,
		$avatar_helper,
		config $config,
		content_visibility $content_visibility,
		driver_interface $db,
		dispatcher_interface $dispatcher,
		profilefields_manager $profilefields_manager,
		user $user,
		string $posts_table,
		string $users_table,
		string $zebra_table,
		string $sessions_table,
		string $root_path,
		string $php_ext
	)
	{
		$this->auth = $auth;
		$this->avatar_helper = $avatar_helper;
		$this->config = $config;
		$this->content_visibility = $content_visibility;
		$this->db = $db;
		$this->dispatcher = $dispatcher;
		$this->profilefields_manager = $profilefields_manager;
		$this->user = $user;
		$this->posts_table = $posts_table;
		$this->users_table = $users_table;
		$this->zebra_table = $zebra_table;
		$this->sessions_table = $sessions_table;
		$this->root_path = $root_path;
		$this->php_ext = $php_ext;
	}

	/**
	 * Fetch the visible post ids, post rows and cached poster information.
	 *
	 * @return array<string, mixed>
	 */
	public function load(
		int $forum_id,
		int $topic_id,
		array $topic_data,
		int $post_id,
		string $view,
		int $start,
		int $sort_days,
		string $sort_key,
		string $sort_dir,
		int $sql_limit,
		int $sql_start,
		string $sql_sort_order,
		bool $store_reverse,
		string $limit_posts_time,
		bool $join_user,
		array $icons
	): array
	{
		$post_list = [];
		$user_cache = [];
		$id_cache = [];
		$attach_list = [];
		$rowset = [];
		$has_unapproved_attachments = false;
		$has_approved_attachments = false;
		$max_post_time = 0;
		$profile_fields_cache = [];

		// Go ahead and pull all data for this topic.
		$sql = 'SELECT p.post_id
			FROM ' . $this->posts_table . ' p' . ($join_user ? ', ' . $this->users_table . ' u' : '') . "
			WHERE p.topic_id = $topic_id
				AND " . $this->content_visibility->get_visibility_sql('post', $forum_id, 'p.') . "
				" . ($join_user ? 'AND u.user_id = p.poster_id' : '') . "
				$limit_posts_time
			ORDER BY $sql_sort_order";

		/**
		 * Event to modify the SQL query that gets post_list
		 *
		 * @event core.viewtopic_modify_post_list_sql
		 * @var string sql The SQL query to generate the post_list
		 * @var int sql_limit The number of posts the query fetches
		 * @var int sql_start The index the query starts to fetch from
		 * @var string sort_key Key the posts are sorted by
		 * @var string sort_days Display posts of previous x days
		 * @var int forum_id Forum ID
		 * @since 3.2.4-RC1
		 */
		$vars = [
			'sql',
			'sql_limit',
			'sql_start',
			'sort_key',
			'sort_days',
			'forum_id',
		];
		extract($this->dispatcher->trigger_event('core.viewtopic_modify_post_list_sql', compact($vars)));

		$result = $this->db->sql_query_limit($sql, $sql_limit, $sql_start);

		$i = $store_reverse ? $sql_limit - 1 : 0;
		while ($row = $this->db->sql_fetchrow($result))
		{
			$post_list[$i] = (int) $row['post_id'];
			$store_reverse ? $i-- : $i++;
		}
		$this->db->sql_freeresult($result);

		if (!count($post_list))
		{
			if ($sort_days)
			{
				trigger_error('NO_POSTS_TIME_FRAME');
			}

			trigger_error('NO_TOPIC');
		}

		$sql_ary = [
			'SELECT' => 'u.*, z.friend, z.foe, p.*',
			'FROM' => [
				$this->users_table => 'u',
				$this->posts_table => 'p',
			],
			'LEFT_JOIN' => [
				[
					'FROM' => [$this->zebra_table => 'z'],
					'ON' => 'z.user_id = ' . (int) $this->user->data['user_id'] . ' AND z.zebra_id = p.poster_id',
				],
			],
			'WHERE' => $this->db->sql_in_set('p.post_id', $post_list) . '
				AND u.user_id = p.poster_id',
		];

		/**
		 * Event to modify the SQL query before the post and poster data is retrieved
		 *
		 * @event core.viewtopic_get_post_data
		 * @var int forum_id Forum ID
		 * @var int topic_id Topic ID
		 * @var array topic_data Array with topic data
		 * @var array post_list Array with post_ids we are going to retrieve
		 * @var int sort_days Display posts of previous x days
		 * @var string sort_key Key the posts are sorted by
		 * @var string sort_dir Direction the posts are sorted by
		 * @var int start Pagination information
		 * @var array sql_ary The SQL array to get the data of posts and posters
		 * @since 3.1.0-a1
		 * @changed 3.1.0-a2 Added vars forum_id, topic_id, topic_data, post_list, sort_days, sort_key, sort_dir, start
		 */
		$vars = [
			'forum_id',
			'topic_id',
			'topic_data',
			'post_list',
			'sort_days',
			'sort_key',
			'sort_dir',
			'start',
			'sql_ary',
		];
		extract($this->dispatcher->trigger_event('core.viewtopic_get_post_data', compact($vars)));

		$sql = $this->db->sql_build_query('SELECT', $sql_ary);
		$result = $this->db->sql_query($sql);

		$now = $this->user->create_datetime();
		$now = \phpbb_gmgetdate($now->getTimestamp() + $now->getOffset());

		// Posts are stored in rowset while attachment and user caches are built.
		while ($row = $this->db->sql_fetchrow($result))
		{
			if ($row['post_time'] > $max_post_time)
			{
				$max_post_time = $row['post_time'];
			}

			$poster_id = (int) $row['poster_id'];

			if ($row['post_attachment'] && $this->config['allow_attachments'])
			{
				$attach_list[] = (int) $row['post_id'];

				if ($row['post_visibility'] == ITEM_UNAPPROVED || $row['post_visibility'] == ITEM_REAPPROVE)
				{
					$has_unapproved_attachments = true;
				}
				else if ($row['post_visibility'] == ITEM_APPROVED)
				{
					$has_approved_attachments = true;
				}
			}

			$rowset_data = [
				'hide_post' => (($row['foe'] || $row['post_visibility'] == ITEM_DELETED) && ($view != 'show' || $post_id != $row['post_id'])) ? true : false,
				'post_id' => $row['post_id'],
				'post_time' => $row['post_time'],
				'user_id' => $row['user_id'],
				'username' => $row['username'],
				'user_colour' => $row['user_colour'],
				'topic_id' => $row['topic_id'],
				'forum_id' => $row['forum_id'],
				'post_subject' => $row['post_subject'],
				'post_edit_count' => $row['post_edit_count'],
				'post_edit_time' => $row['post_edit_time'],
				'post_edit_reason' => $row['post_edit_reason'],
				'post_edit_user' => $row['post_edit_user'],
				'post_edit_locked' => $row['post_edit_locked'],
				'post_delete_time' => $row['post_delete_time'],
				'post_delete_reason' => $row['post_delete_reason'],
				'post_delete_user' => $row['post_delete_user'],
				'icon_id' => (isset($icons[$row['icon_id']]['img'], $icons[$row['icon_id']]['height'], $icons[$row['icon_id']]['width'])) ? $row['icon_id'] : 0,
				'post_attachment' => $row['post_attachment'],
				'post_visibility' => $row['post_visibility'],
				'post_reported' => $row['post_reported'],
				'post_username' => $row['post_username'],
				'post_text' => $row['post_text'],
				'bbcode_uid' => $row['bbcode_uid'],
				'bbcode_bitfield' => $row['bbcode_bitfield'],
				'enable_smilies' => $row['enable_smilies'],
				'enable_sig' => $row['enable_sig'],
				'friend' => $row['friend'],
				'foe' => $row['foe'],
			];

			/**
			 * Modify the post rowset containing data to be displayed with posts
			 *
			 * @event core.viewtopic_post_rowset_data
			 * @var array rowset_data Array with the rowset data for this post
			 * @var array row Array with original user and post data
			 * @since 3.1.0-a1
			 */
			$vars = ['rowset_data', 'row'];
			extract($this->dispatcher->trigger_event('core.viewtopic_post_rowset_data', compact($vars)));

			$rowset[$row['post_id']] = $rowset_data;

			if (!isset($user_cache[$poster_id]))
			{
				if ($poster_id == ANONYMOUS)
				{
					$user_cache_data = [
						'user_type' => USER_IGNORE,
						'joined' => '',
						'posts' => '',
						'sig' => '',
						'sig_bbcode_uid' => '',
						'sig_bbcode_bitfield' => '',
						'online' => false,
						'avatar' => ($this->user->optionget('viewavatars')) ? $this->avatar_helper->get_user_avatar($row) : [],
						'rank_title' => '',
						'rank_image' => '',
						'rank_image_src' => '',
						'pm' => '',
						'email' => '',
						'search' => '',
						'age' => '',
						'username' => $row['username'],
						'user_colour' => $row['user_colour'],
						'contact_user' => '',
						'warnings' => 0,
						'allow_pm' => 0,
					];

					/**
					 * Modify the guest user's data displayed with the posts
					 *
					 * @event core.viewtopic_cache_guest_data
					 * @var array user_cache_data Array with the user's data
					 * @var int poster_id Poster's user id
					 * @var array row Array with original user and post data
					 * @since 3.1.0-a1
					 */
					$vars = ['user_cache_data', 'poster_id', 'row'];
					extract($this->dispatcher->trigger_event('core.viewtopic_cache_guest_data', compact($vars)));

					$user_cache[$poster_id] = $user_cache_data;

					$user_rank_data = \phpbb_get_user_rank($row, false);
					$user_cache[$poster_id]['rank_title'] = $user_rank_data['title'];
					$user_cache[$poster_id]['rank_image'] = $user_rank_data['img'];
					$user_cache[$poster_id]['rank_image_src'] = $user_rank_data['img_src'];
				}
				else
				{
					$user_sig = '';

					if ($row['user_sig'] && $this->config['allow_sig'] && $this->user->optionget('viewsigs'))
					{
						$user_sig = $row['user_sig'];
					}

					$id_cache[] = $poster_id;

					$user_cache_data = [
						'user_type' => $row['user_type'],
						'user_inactive_reason' => $row['user_inactive_reason'],
						'joined' => $this->user->format_date($row['user_regdate']),
						'posts' => $row['user_posts'],
						'warnings' => (isset($row['user_warnings'])) ? $row['user_warnings'] : 0,
						'sig' => $user_sig,
						'sig_bbcode_uid' => (!empty($row['user_sig_bbcode_uid'])) ? $row['user_sig_bbcode_uid'] : '',
						'sig_bbcode_bitfield' => (!empty($row['user_sig_bbcode_bitfield'])) ? $row['user_sig_bbcode_bitfield'] : '',
						'viewonline' => $row['user_allow_viewonline'],
						'allow_pm' => $row['user_allow_pm'],
						'avatar' => ($this->user->optionget('viewavatars')) ? $this->avatar_helper->get_user_avatar($row) : [],
						'age' => '',
						'rank_title' => '',
						'rank_image' => '',
						'rank_image_src' => '',
						'username' => $row['username'],
						'user_colour' => $row['user_colour'],
						'contact_user' => $this->user->lang('CONTACT_USER', \get_username_string('username', $poster_id, $row['username'], $row['user_colour'], $row['username'])),
						'online' => false,
						'search' => ($this->config['load_search'] && $this->auth->acl_get('u_search')) ? \append_sid("{$this->root_path}search.{$this->php_ext}", "author_id=$poster_id&amp;sr=posts") : '',
						'author_full' => \get_username_string('full', $poster_id, $row['username'], $row['user_colour']),
						'author_colour' => \get_username_string('colour', $poster_id, $row['username'], $row['user_colour']),
						'author_username' => \get_username_string('username', $poster_id, $row['username'], $row['user_colour']),
						'author_profile' => \get_username_string('profile', $poster_id, $row['username'], $row['user_colour']),
					];

					/**
					 * Modify the users' data displayed with their posts
					 *
					 * @event core.viewtopic_cache_user_data
					 * @var array user_cache_data Array with the user's data
					 * @var int poster_id Poster's user id
					 * @var array row Array with original user and post data
					 * @since 3.1.0-a1
					 */
					$vars = ['user_cache_data', 'poster_id', 'row'];
					extract($this->dispatcher->trigger_event('core.viewtopic_cache_user_data', compact($vars)));

					$user_cache[$poster_id] = $user_cache_data;

					$user_rank_data = \phpbb_get_user_rank($row, $row['user_posts']);
					$user_cache[$poster_id]['rank_title'] = $user_rank_data['title'];
					$user_cache[$poster_id]['rank_image'] = $user_rank_data['img'];
					$user_cache[$poster_id]['rank_image_src'] = $user_rank_data['img_src'];

					if ((!empty($row['user_allow_viewemail']) && $this->auth->acl_get('u_sendemail')) || $this->auth->acl_get('a_email'))
					{
						$user_cache[$poster_id]['email'] = ($this->config['board_email_form'] && $this->config['email_enable'])
							? \append_sid("{$this->root_path}memberlist.{$this->php_ext}", "mode=email&amp;u=$poster_id")
							: (($this->config['board_hide_emails'] && !$this->auth->acl_get('a_email')) ? '' : 'mailto:' . $row['user_email']);
					}
					else
					{
						$user_cache[$poster_id]['email'] = '';
					}

					if ($this->config['allow_birthdays'] && !empty($row['user_birthday']))
					{
						[$bday_day, $bday_month, $bday_year] = array_map('intval', explode('-', $row['user_birthday']));

						if ($bday_year)
						{
							$diff = $now['mon'] - $bday_month;
							if ($diff == 0)
							{
								$diff = ($now['mday'] - $bday_day < 0) ? 1 : 0;
							}
							else
							{
								$diff = ($diff < 0) ? 1 : 0;
							}

							$user_cache[$poster_id]['age'] = (int) ($now['year'] - $bday_year - $diff);
						}
					}
				}
			}
		}
		$this->db->sql_freeresult($result);

		if ($this->config['load_cpf_viewtopic'])
		{
			$profile_fields_tmp = $this->profilefields_manager->grab_profile_fields_data($id_cache);

			foreach ($profile_fields_tmp as $profile_user_id => $profile_fields)
			{
				$profile_fields_cache[$profile_user_id] = [];
				foreach ($profile_fields as $used_ident => $profile_field)
				{
					if ($profile_field['data']['field_show_on_vt'])
					{
						$profile_fields_cache[$profile_user_id][$used_ident] = $profile_field;
					}
				}
			}
		}

		if ($this->config['load_onlinetrack'] && count($id_cache))
		{
			$sql_params = [];
			$sql_online_users = $this->db->sql_in_set_params('session_user_id', $id_cache, $sql_params, false, false, 'vt_online_user');
			$sql = 'SELECT session_user_id, MAX(session_time) as online_time, MIN(session_viewonline) AS viewonline
				FROM ' . $this->sessions_table . '
				WHERE ' . $sql_online_users . '
				GROUP BY session_user_id';
			$result = $this->db->sql_query_params($sql, $sql_params);

			$update_time = $this->config['load_online_time'] * 60;
			while ($row = $this->db->sql_fetchrow($result))
			{
				$user_cache[$row['session_user_id']]['online'] = (time() - $update_time < $row['online_time'] && ($row['viewonline'] || $this->auth->acl_get('u_viewonline'))) ? true : false;
			}
			$this->db->sql_freeresult($result);
		}

		return [
			'forum_id' => $forum_id,
			'topic_id' => $topic_id,
			'topic_data' => $topic_data,
			'post_list' => $post_list,
			'rowset' => $rowset,
			'user_cache' => $user_cache,
			'attach_list' => $attach_list,
			'has_unapproved_attachments' => $has_unapproved_attachments,
			'has_approved_attachments' => $has_approved_attachments,
			'max_post_time' => $max_post_time,
			'profile_fields_cache' => $profile_fields_cache,
			'start' => $start,
			'sort_days' => $sort_days,
			'sort_key' => $sort_key,
			'sort_dir' => $sort_dir,
		];
	}
}

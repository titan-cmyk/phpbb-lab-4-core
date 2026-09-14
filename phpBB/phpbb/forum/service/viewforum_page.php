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
use phpbb\controller\helper as controller_helper;
use phpbb\content_visibility;
use phpbb\cron\manager as cron_manager;
use phpbb\db\driver\driver_interface;
use phpbb\event\dispatcher_interface;
use phpbb\forum\data\viewforum_repository;
use phpbb\pagination;
use phpbb\request\request_interface;
use phpbb\template\template;
use phpbb\user;

/**
 * Prepares and completes the viewforum page around topic retrieval/rendering.
 *
 * Access handling, navigation, forum metadata, sorting, page-level Twig data,
 * pagination and mark-read completion live here so the controller can remain
 * focused on orchestration.
 */
class viewforum_page
{
	/** @var auth */
	protected $auth;
	/** @var config */
	protected $config;
	/** @var controller_helper */
	protected $controller_helper;
	/** @var content_visibility */
	protected $content_visibility;
	/** @var cron_manager */
	protected $cron;
	/** @var driver_interface */
	protected $db;
	/** @var dispatcher_interface */
	protected $dispatcher;
	/** @var viewforum_repository */
	protected $forum_repository;
	/** @var pagination */
	protected $pagination;
	/** @var request_interface */
	protected $request;
	/** @var template */
	protected $template;
	/** @var user */
	protected $user;
	/** @var string */
	protected $phpbb_root_path;
	/** @var string */
	protected $php_ext;

	public function __construct(
		auth $auth,
		config $config,
		controller_helper $controller_helper,
		content_visibility $content_visibility,
		cron_manager $cron,
		driver_interface $db,
		dispatcher_interface $dispatcher,
		viewforum_repository $forum_repository,
		pagination $pagination,
		request_interface $request,
		template $template,
		user $user,
		string $phpbb_root_path,
		string $php_ext
	)
	{
		$this->auth = $auth;
		$this->config = $config;
		$this->controller_helper = $controller_helper;
		$this->content_visibility = $content_visibility;
		$this->cron = $cron;
		$this->db = $db;
		$this->dispatcher = $dispatcher;
		$this->forum_repository = $forum_repository;
		$this->pagination = $pagination;
		$this->request = $request;
		$this->template = $template;
		$this->user = $user;
		$this->phpbb_root_path = $phpbb_root_path;
		$this->php_ext = $php_ext;
	}

	/**
	 * Prepare the forum page before topic retrieval.
	 *
	 * @return array<string, mixed>
	 */
	public function prepare(
		array $forum_data,
		int $forum_id,
		int $start,
		int $sort_days,
		string $sort_key,
		string $sort_dir,
		int $default_sort_days,
		string $default_sort_key,
		string $default_sort_dir
	): array
	{
		global $_SID, $_EXTRA_URL;

		$auth = $this->auth;
		$config = $this->config;
		$controller_helper = $this->controller_helper;
		$phpbb_content_visibility = $this->content_visibility;
		$cron = $this->cron;
		$db = $this->db;
		$phpbb_dispatcher = $this->dispatcher;
		$forum_repository = $this->forum_repository;
		$pagination = $this->pagination;
		$request = $this->request;
		$template = $this->template;
		$user = $this->user;
		$phpbb_root_path = $this->phpbb_root_path;
		$phpEx = $this->php_ext;

		// Redirect to login upon emailed notification links
		if (isset($_GET['e']) && !$user->data['is_registered'])
		{
			login_box('', $user->lang['LOGIN_NOTIFY_FORUM']);
		}

		// Permissions check
		if (!$auth->acl_gets('f_list', 'f_list_topics', 'f_read', $forum_id) || ($forum_data['forum_type'] == FORUM_LINK && $forum_data['forum_link'] && !$auth->acl_get('f_read', $forum_id)))
		{
			if ($user->data['user_id'] != ANONYMOUS)
			{
				send_status_line(403, 'Forbidden');
				trigger_error('SORRY_AUTH_READ');
			}

			login_box('', $user->lang['LOGIN_VIEWFORUM']);
		}

		// Forum is passworded ... check whether access has been granted to this
		// user this session, if not show login box
		if ($forum_data['forum_password'])
		{
			login_forum_box($forum_data);
		}

		// Is this forum a link? ... User got here either because the
		// number of clicks is being tracked or they guessed the id
		if ($forum_data['forum_type'] == FORUM_LINK && $forum_data['forum_link'])
		{
			if ($forum_data['forum_flags'] & FORUM_FLAG_LINK_TRACK)
			{
				$forum_repository->increment_link_click_count($forum_id);
			}

			redirect($forum_data['forum_link'], false, true);
			return ['stop' => true];
		}

		// Build navigation links
		generate_forum_nav($forum_data);

		// Forum Rules
		if ($auth->acl_get('f_read', $forum_id))
		{
			generate_forum_rules($forum_data);
		}

		// Do we have subforums?
		$active_forum_ary = $moderators = array();

		if ($forum_data['left_id'] != $forum_data['right_id'] - 1)
		{
			list($active_forum_ary, $moderators) = display_forums($forum_data, $config['load_moderators'], $config['load_moderators']);
		}
		else
		{
			$template->assign_var('S_HAS_SUBFORUM', false);
			if ($config['load_moderators'])
			{
				get_moderators($moderators, $forum_id);
			}
		}

		// Is a forum specific topic count required?
		if ($forum_data['forum_topics_per_page'])
		{
			$config['topics_per_page'] = $forum_data['forum_topics_per_page'];
		}

		// Dump out the page header and load viewforum template
		$topics_count = $phpbb_content_visibility->get_count('forum_topics', $forum_data, $forum_id);
		$start = $pagination->validate_start($start, $config['topics_per_page'], $topics_count);

		$page_title = $forum_data['forum_name'] . ($start ? ' - ' . $user->lang('PAGE_TITLE_NUMBER', $pagination->get_on_page($config['topics_per_page'], $start)) : '');

		/**
		* You can use this event to modify the page title of the viewforum page
		*
		* @event core.viewforum_modify_page_title
		* @var	string	page_title		Title of the viewforum page
		* @var	array	forum_data		Array with forum data
		* @var	int		forum_id		The forum ID
		* @var	int		start			Start offset used to calculate the page
		* @since 3.2.2-RC1
		*/
		$vars = array('page_title', 'forum_data', 'forum_id', 'start');
		extract($phpbb_dispatcher->trigger_event('core.viewforum_modify_page_title', compact($vars)));

		page_header($page_title, true, $forum_id);

		$template->set_filenames(array(
			'body' => 'viewforum_body.html')
		);


		$template->assign_vars(array(
			'U_VIEW_FORUM' => append_sid("{$phpbb_root_path}viewforum.$phpEx", "f=$forum_id" . (($start == 0) ? '' : "&amp;start=$start")),
		));

		// Not postable forum or showing active topics?
		if (!($forum_data['forum_type'] == FORUM_POST || (($forum_data['forum_flags'] & FORUM_FLAG_ACTIVE_TOPICS) && $forum_data['forum_type'] == FORUM_CAT)))
		{
			page_footer();
		}

		// Ok, if someone has only list-access, we only display the forum list.
		if (!$auth->acl_gets('f_read', 'f_list_topics', $forum_id))
		{
			$template->assign_vars(array(
				'S_NO_READ_ACCESS' => true,
			));

			page_footer();
		}

		// Do the forum Prune thang - cron type job ...
		if (!$config['use_system_cron'])
		{
			$task = $cron->find_task('cron.task.core.prune_forum');
			$task->set_forum_data($forum_data);

			if ($task->is_ready())
			{
				$cron_task_tag = $task->get_html_tag();
				$template->assign_var('RUN_CRON_TASK', $cron_task_tag);
			}
			else
			{
				$task = $cron->find_task('cron.task.core.prune_shadow_topics');
				$task->set_forum_data($forum_data);

				if ($task->is_ready())
				{
					$cron_task_tag = $task->get_html_tag();
					$template->assign_var('RUN_CRON_TASK', $cron_task_tag);
				}
			}
		}

		// Forum rules and subscription info
		$s_watching_forum = array(
			'link' => '',
			'link_toggle' => '',
			'title' => '',
			'title_toggle' => '',
			'is_watching' => false,
		);

		if ($config['allow_forum_notify'] && $forum_data['forum_type'] == FORUM_POST && ($auth->acl_get('f_subscribe', $forum_id) || $user->data['user_id'] == ANONYMOUS))
		{
			$notify_status = (isset($forum_data['notify_status'])) ? $forum_data['notify_status'] : NULL;
			watch_topic_forum('forum', $s_watching_forum, $user->data['user_id'], $forum_id, 0, $notify_status, $start, $forum_data['forum_name']);
		}

		$s_forum_rules = '';
		gen_forum_auth_level('forum', $forum_id, $forum_data['forum_status']);

		// Topic ordering options
		$limit_days = array(0 => $user->lang['ALL_TOPICS'], 1 => $user->lang['1_DAY'], 7 => $user->lang['7_DAYS'], 14 => $user->lang['2_WEEKS'], 30 => $user->lang['1_MONTH'], 90 => $user->lang['3_MONTHS'], 180 => $user->lang['6_MONTHS'], 365 => $user->lang['1_YEAR']);

		$sort_by_text = array('a' => $user->lang['AUTHOR'], 't' => $user->lang['POST_TIME'], 'r' => $user->lang['REPLIES'], 's' => $user->lang['SUBJECT'], 'v' => $user->lang['VIEWS']);
		$sort_by_sql = array('a' => 't.topic_first_poster_name', 't' => array('t.topic_last_post_time', 't.topic_last_post_id'), 'r' => (($auth->acl_get('m_approve', $forum_id)) ? 't.topic_posts_approved + t.topic_posts_unapproved + t.topic_posts_softdeleted' : 't.topic_posts_approved'), 's' => 'LOWER(t.topic_title)', 'v' => 't.topic_views');

		/**
		 * Modify the topic ordering if needed
		 *
		 * @event core.viewforum_modify_topic_ordering
		 * @var array	sort_by_text	Topic ordering options
		 * @var array	sort_by_sql		Topic orderings options SQL equivalent
		 * @since 3.2.5-RC1
		 */
		$vars = array(
			'sort_by_text',
			'sort_by_sql',
		);
		extract($phpbb_dispatcher->trigger_event('core.viewforum_modify_topic_ordering', compact($vars)));

		$s_limit_days = $s_sort_key = $s_sort_dir = $u_sort_param = '';
		gen_sort_selects($limit_days, $sort_by_text, $sort_days, $sort_key, $sort_dir, $s_limit_days, $s_sort_key, $s_sort_dir, $u_sort_param, $default_sort_days, $default_sort_key, $default_sort_dir);

		// Limit topics to certain time frame, obtain correct topic count
		if ($sort_days)
		{
			$min_post_time = time() - ($sort_days * 86400);

			$sql_array = array(
				'SELECT' => 'COUNT(t.topic_id) AS num_topics',
				'FROM' => array(
					TOPICS_TABLE => 't',
				),
				'WHERE' => 't.forum_id = ' . $forum_id . '
					AND (t.topic_last_post_time >= ' . $min_post_time . '
						OR t.topic_type = ' . POST_ANNOUNCE . '
						OR t.topic_type = ' . POST_GLOBAL . ')
					AND ' . $phpbb_content_visibility->get_visibility_sql('topic', $forum_id, 't.'),
			);

			/**
			* Modify the sort data SQL query for getting additional fields if needed
			*
			* @event core.viewforum_modify_sort_data_sql
			* @var int		forum_id		The forum_id whose topics are being listed
			* @var int		start			Variable containing start for pagination
			* @var int		sort_days		The oldest topic displayable in elapsed days
			* @var string	sort_key		The sorting by. It is one of the first character of (in low case):
			* @var string	sort_dir		Either "a" for ascending or "d" for descending
			* @var array	sql_array		The SQL array to get the data of all topics
			* @since 3.1.9-RC1
			*/
			$vars = array(
				'forum_id',
				'start',
				'sort_days',
				'sort_key',
				'sort_dir',
				'sql_array',
			);
			extract($phpbb_dispatcher->trigger_event('core.viewforum_modify_sort_data_sql', compact($vars)));

			$sql = $db->sql_build_query('SELECT', $sql_array);
			$sql_params = [];

			// Preserve the historical event payload with literal values, then bind
			// only unchanged core-owned fragments after extensions have run.
			$sql = $this->parameterize_fragment($sql, 't.forum_id = ' . $forum_id, 't.forum_id = :vf_page_forum_id', ['vf_page_forum_id' => $forum_id], $sql_params);
			$sql = $this->parameterize_fragment($sql, 't.topic_last_post_time >= ' . $min_post_time, 't.topic_last_post_time >= :vf_page_min_post_time', ['vf_page_min_post_time' => $min_post_time], $sql_params);

			$result = $sql_params ? $db->sql_query_params($sql, $sql_params) : $db->sql_query($sql);
			$topics_count = (int) $db->sql_fetchfield('num_topics');
			$db->sql_freeresult($result);

			if (isset($_POST['sort']))
			{
				$start = 0;
			}
			$sql_limit_time = "AND t.topic_last_post_time >= $min_post_time";

			$template->assign_var('S_SORT_DAYS', true);
		}
		else
		{
			$sql_limit_time = '';
		}

		// Basic pagewide vars
		$post_alt = ($forum_data['forum_status'] == ITEM_LOCKED) ? $user->lang['FORUM_LOCKED'] : $user->lang['POST_NEW_TOPIC'];

		// Display active topics?
		$s_display_active = ($forum_data['forum_type'] == FORUM_CAT && ($forum_data['forum_flags'] & FORUM_FLAG_ACTIVE_TOPICS)) ? true : false;

		// Send the forum id... and maybe some other fields, depending on permissions
		$s_search_hidden_fields = [
			'fid' => [$forum_id],
		];

		if ($auth->acl_get('f_list_topics', $forum_id) && !$auth->acl_get('f_read', $forum_id))
		{
			$s_search_hidden_fields['sr'] = 'topics';
			$s_search_hidden_fields['sf'] = 'titleonly';
		}

		if ($_SID)
		{
			$s_search_hidden_fields['sid'] = $_SID;
		}

		if (!empty($_EXTRA_URL))
		{
			foreach ($_EXTRA_URL as $url_param)
			{
				$url_param = explode('=', $url_param, 2);
				$s_search_hidden_fields[$url_param[0]] = $url_param[1];
			}
		}

		$template->assign_vars(array(
			'MODERATORS' => (!empty($moderators[$forum_id])) ? implode($user->lang['COMMA_SEPARATOR'], $moderators[$forum_id]) : '',

			'POST_IMG' => ($forum_data['forum_status'] == ITEM_LOCKED) ? $user->img('button_topic_locked', $post_alt) : $user->img('button_topic_new', $post_alt),
			'NEWEST_POST_IMG' => $user->img('icon_topic_newest', 'VIEW_NEWEST_POST'),
			'LAST_POST_IMG' => $user->img('icon_topic_latest', 'VIEW_LATEST_POST'),
			'FOLDER_IMG' => $user->img('topic_read', 'NO_UNREAD_POSTS'),
			'FOLDER_UNREAD_IMG' => $user->img('topic_unread', 'UNREAD_POSTS'),
			'FOLDER_HOT_IMG' => $user->img('topic_read_hot', 'NO_UNREAD_POSTS_HOT'),
			'FOLDER_HOT_UNREAD_IMG' => $user->img('topic_unread_hot', 'UNREAD_POSTS_HOT'),
			'FOLDER_LOCKED_IMG' => $user->img('topic_read_locked', 'NO_UNREAD_POSTS_LOCKED'),
			'FOLDER_LOCKED_UNREAD_IMG' => $user->img('topic_unread_locked', 'UNREAD_POSTS_LOCKED'),
			'FOLDER_STICKY_IMG' => $user->img('sticky_read', 'POST_STICKY'),
			'FOLDER_STICKY_UNREAD_IMG' => $user->img('sticky_unread', 'POST_STICKY'),
			'FOLDER_ANNOUNCE_IMG' => $user->img('announce_read', 'POST_ANNOUNCEMENT'),
			'FOLDER_ANNOUNCE_UNREAD_IMG' => $user->img('announce_unread', 'POST_ANNOUNCEMENT'),
			'FOLDER_MOVED_IMG' => $user->img('topic_moved', 'TOPIC_MOVED'),
			'REPORTED_IMG' => $user->img('icon_topic_reported', 'TOPIC_REPORTED'),
			'UNAPPROVED_IMG' => $user->img('icon_topic_unapproved', 'TOPIC_UNAPPROVED'),
			'DELETED_IMG' => $user->img('icon_topic_deleted', 'TOPIC_DELETED'),
			'POLL_IMG' => $user->img('icon_topic_poll', 'TOPIC_POLL'),
			'GOTO_PAGE_IMG' => $user->img('icon_post_target', 'GOTO_PAGE'),

			'L_NO_TOPICS' => ($forum_data['forum_status'] == ITEM_LOCKED) ? $user->lang['POST_FORUM_LOCKED'] : $user->lang['NO_TOPICS'],

			'S_DISPLAY_POST_INFO' => ($forum_data['forum_type'] == FORUM_POST && ($auth->acl_get('f_post', $forum_id) || $user->data['user_id'] == ANONYMOUS)) ? true : false,

			'S_IS_POSTABLE' => ($forum_data['forum_type'] == FORUM_POST) ? true : false,
			'S_USER_CAN_POST' => ($auth->acl_get('f_post', $forum_id)) ? true : false,
			'S_DISPLAY_ACTIVE' => $s_display_active,
			'S_SELECT_SORT_DIR' => $s_sort_dir,
			'S_SELECT_SORT_KEY' => $s_sort_key,
			'S_SELECT_SORT_DAYS' => $s_limit_days,
			'S_TOPIC_ICONS' => ($s_display_active && count($active_forum_ary)) ? max($active_forum_ary['enable_icons']) : (($forum_data['enable_icons']) ? true : false),
			'U_WATCH_FORUM_LINK' => $s_watching_forum['link'],
			'U_WATCH_FORUM_TOGGLE' => $s_watching_forum['link_toggle'],
			'S_WATCH_FORUM_TITLE' => $s_watching_forum['title'],
			'S_WATCH_FORUM_TOGGLE' => $s_watching_forum['title_toggle'],
			'S_WATCHING_FORUM' => $s_watching_forum['is_watching'],
			'S_FORUM_ACTION' => append_sid("{$phpbb_root_path}viewforum.$phpEx", "f=$forum_id" . (($start == 0) ? '' : "&amp;start=$start")),
			'S_DISPLAY_SEARCHBOX' => ($auth->acl_get('u_search') && $auth->acl_get('f_search', $forum_id) && $config['load_search']) ? true : false,
			'S_SEARCHBOX_ACTION' => append_sid("{$phpbb_root_path}search.$phpEx"),
			'S_SEARCH_LOCAL_HIDDEN_FIELDS' => build_hidden_fields($s_search_hidden_fields),
			'S_SINGLE_MODERATOR' => (!empty($moderators[$forum_id]) && count($moderators[$forum_id]) > 1) ? false : true,
			'S_IS_LOCKED' => ($forum_data['forum_status'] == ITEM_LOCKED) ? true : false,
			'S_VIEWFORUM' => true,

			'U_MCP' => ($auth->acl_get('m_', $forum_id)) ? append_sid("{$phpbb_root_path}mcp.$phpEx", "f=$forum_id&amp;i=main&amp;mode=forum_view") : '',
			'U_POST_NEW_TOPIC' => ($auth->acl_get('f_post', $forum_id) || $user->data['user_id'] == ANONYMOUS) ? append_sid("{$phpbb_root_path}posting.$phpEx", 'mode=post&amp;f=' . $forum_id) : '',
			'U_VIEW_FORUM' => append_sid("{$phpbb_root_path}viewforum.$phpEx", "f=$forum_id" . ((strlen($u_sort_param)) ? "&amp;$u_sort_param" : '') . (($start == 0) ? '' : "&amp;start=$start")),
			'U_CANONICAL' => generate_board_url() . '/' . append_sid("viewforum.$phpEx", "f=$forum_id" . (($start) ? "&amp;start=$start" : ''), true, ''),
			'U_MARK_TOPICS' => ($user->data['is_registered'] || $config['load_anon_lastread']) ? $controller_helper->route('phpbb_notifications_mark_topics_read', ['id' => $forum_id, 'hash' => generate_link_hash('global'), 'mark_time' => time()]) : '',
			'U_SEARCH_FORUM' => append_sid("{$phpbb_root_path}search.$phpEx", 'fid%5B%5D=' . $forum_id),
		));

		return [
			'stop' => false,
			'forum_data' => $forum_data,
			'forum_id' => $forum_id,
			'start' => $start,
			'sort_days' => $sort_days,
			'sort_key' => $sort_key,
			'sort_dir' => $sort_dir,
			'topics_count' => $topics_count,
			'active_forum_ary' => $active_forum_ary,
			'sort_by_sql' => $sort_by_sql,
			'sql_limit_time' => $sql_limit_time,
			's_display_active' => $s_display_active,
			'u_sort_param' => $u_sort_param,
		];
	}

	/**
	 * Prepare pagination and the final ordered topic list after topic retrieval.
	 *
	 * @return array{topics_count:int,total_topic_count:int,topic_list:array}
	 */
	public function prepare_topic_page(
		int $forum_id,
		bool $s_display_active,
		int $topics_count,
		array $announcement_list,
		array $topic_list,
		bool $store_reverse,
		string $u_sort_param,
		int $start
	): array
	{
		$config = $this->config;
		$pagination = $this->pagination;
		$template = $this->template;
		$user = $this->user;
		$phpbb_root_path = $this->phpbb_root_path;
		$phpEx = $this->php_ext;

		if ($s_display_active)
		{
			$topics_count = 1;
		}

		$total_topic_count = $topics_count - count($announcement_list);

		$base_url = append_sid("{$phpbb_root_path}viewforum.$phpEx", "f=$forum_id" . ((strlen($u_sort_param)) ? "&amp;$u_sort_param" : ''));
		$pagination->generate_template_pagination($base_url, 'pagination', 'start', $total_topic_count, $config['topics_per_page'], $start);

		$template->assign_vars(array(
			'TOTAL_TOPICS' => ($s_display_active) ? false : $user->lang('VIEW_FORUM_TOPICS', (int) $total_topic_count),
		));

		$topic_list = ($store_reverse) ? array_merge($announcement_list, array_reverse($topic_list)) : array_merge($announcement_list, $topic_list);

		return [
			'topics_count' => $topics_count,
			'total_topic_count' => $total_topic_count,
			'topic_list' => $topic_list,
		];
	}

	/**
	 * Complete the page after the topic renderer has assigned all topic rows.
	 */
	public function complete(
		array $forum_data,
		int $forum_id,
		array $topic_list,
		bool $mark_forum_read,
		int $mark_time_forum
	): void
	{
		$phpbb_dispatcher = $this->dispatcher;

		/**
		* This event is to perform additional actions on viewforum page
		*
		* @event core.viewforum_generate_page_after
		* @var	array	forum_data	Array with the forum data
		* @since 3.2.2-RC1
		*/
		$vars = array('forum_data');
		extract($phpbb_dispatcher->trigger_event('core.viewforum_generate_page_after', compact($vars)));

		// Preserve legacy forum read tracking behaviour after rendering.
		if ($forum_data['forum_type'] == FORUM_POST && count($topic_list) && $mark_forum_read)
		{
			update_forum_tracking_info($forum_id, $forum_data['forum_last_post_time'], false, $mark_time_forum);
		}

		page_footer();
	}

	/**
	 * Bind one exact core-owned SQL fragment after extension events.
	 *
	 * Event listeners continue to receive the historical literal SQL data.
	 * If an extension rewrites the fragment, the legacy SQL remains untouched.
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

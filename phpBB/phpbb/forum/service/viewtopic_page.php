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
use phpbb\cache\service as cache_service;
use phpbb\config\config;
use phpbb\content_visibility;
use phpbb\db\driver\driver_interface;
use phpbb\event\dispatcher_interface;
use phpbb\pagination;
use phpbb\request\request_interface;
use phpbb\template\template;
use phpbb\user;

/**
 * Prepares the viewtopic page before poll and post rendering.
 *
 * Access checks, tracking state, sorting, subscriptions/bookmarks,
 * moderation controls, navigation, pagination and page-level template
 * variables live here while the historical viewtopic extension events
 * remain at the same logical integration points.
 */
class viewtopic_page
{
	/** @var auth */
	protected $auth;
	/** @var cache_service */
	protected $cache;
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
	/** @var request_interface */
	protected $request;
	/** @var template */
	protected $template;
	/** @var user */
	protected $user;
	/** @var string */
	protected $root_path;
	/** @var string */
	protected $php_ext;

	public function __construct(
		auth $auth,
		cache_service $cache,
		config $config,
		content_visibility $content_visibility,
		driver_interface $db,
		dispatcher_interface $dispatcher,
		pagination $pagination,
		request_interface $request,
		template $template,
		user $user,
		string $root_path,
		string $php_ext
	)
	{
		$this->auth = $auth;
		$this->cache = $cache;
		$this->config = $config;
		$this->content_visibility = $content_visibility;
		$this->db = $db;
		$this->dispatcher = $dispatcher;
		$this->pagination = $pagination;
		$this->request = $request;
		$this->template = $template;
		$this->user = $user;
		$this->root_path = $root_path;
		$this->php_ext = $php_ext;
	}

	/**
	 * Prepare topic-level state and page template data.
	 *
	 * @return array<string, mixed>
	 */
	public function prepare(
		array $topic_data,
		int $forum_id,
		int $topic_id,
		int $post_id,
		string $view,
		int $start,
		int $sort_days,
		string $sort_key,
		string $sort_dir,
		int $default_sort_days,
		string $default_sort_key,
		string $default_sort_dir,
		int $topic_replies,
		string $hilit_words,
		?array $topic_tracking_info
	): array
	{
		global $_SID, $_EXTRA_URL;

		$auth = $this->auth;
		$cache = $this->cache;
		$config = $this->config;
		$phpbb_content_visibility = $this->content_visibility;
		$db = $this->db;
		$phpbb_dispatcher = $this->dispatcher;
		$pagination = $this->pagination;
		$request = $this->request;
		$template = $this->template;
		$user = $this->user;
		$phpbb_root_path = $this->root_path;
		$phpEx = $this->php_ext;

	// Setup look and feel
	$user->setup('viewtopic', $topic_data['forum_style']);

	if ($view == 'print' && !$auth->acl_get('f_print', $forum_id))
	{
		send_status_line(403, 'Forbidden');
		trigger_error('NO_AUTH_PRINT_TOPIC');
	}

	$overrides_f_read_check = false;
	$overrides_forum_password_check = false;
	$topic_tracking_info = isset($topic_tracking_info) ? $topic_tracking_info : null;

	/**
	* Event to apply extra permissions and to override original phpBB's f_read permission and forum password check
	* on viewtopic access
	*
	* @event core.viewtopic_before_f_read_check
	* @var	int		forum_id						The forum id from where the topic belongs
	* @var	int		topic_id						The id of the topic the user tries to access
	* @var	int		post_id							The id of the post the user tries to start viewing at.
	*												It may be 0 for none given.
	* @var	array	topic_data						All the information from the topic and forum tables for this topic
	* 												It includes posts information if post_id is not 0
	* @var	bool	overrides_f_read_check			Set true to remove f_read check afterwards
	* @var	bool	overrides_forum_password_check	Set true to remove forum_password check afterwards
	* @var	array	topic_tracking_info				Information upon calling get_topic_tracking()
	*												Set it to NULL to allow auto-filling later.
	*												Set it to an array to override original data.
	* @since 3.1.3-RC1
	*/
	$vars = array(
		'forum_id',
		'topic_id',
		'post_id',
		'topic_data',
		'overrides_f_read_check',
		'overrides_forum_password_check',
		'topic_tracking_info',
	);
	extract($phpbb_dispatcher->trigger_event('core.viewtopic_before_f_read_check', compact($vars)));

	// Start auth check
	if (!$overrides_f_read_check && !$auth->acl_get('f_read', $forum_id))
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
	if (!$overrides_forum_password_check && $topic_data['forum_password'])
	{
		login_forum_box($topic_data);
	}

	// Redirect to login upon emailed notification links if user is not logged in.
	if (isset($_GET['e']) && $user->data['user_id'] == ANONYMOUS)
	{
		login_box(build_url('e') . '#unread', $user->lang['LOGIN_NOTIFY_TOPIC']);
	}

	// What is start equal to?
	if ($post_id)
	{
		$start = floor(($topic_data['prev_posts']) / $config['posts_per_page']) * $config['posts_per_page'];
	}

	// Get topic tracking info
	if (!isset($topic_tracking_info))
	{
		$topic_tracking_info = array();

		// Get topic tracking info
		if ($config['load_db_lastread'] && $user->data['is_registered'])
		{
			$tmp_topic_data = array($topic_id => $topic_data);
			$topic_tracking_info = get_topic_tracking($forum_id, $topic_id, $tmp_topic_data, array($forum_id => $topic_data['forum_mark_time']));
			unset($tmp_topic_data);
		}
		else if ($config['load_anon_lastread'] || $user->data['is_registered'])
		{
			$topic_tracking_info = get_complete_topic_tracking($forum_id, $topic_id);
		}
	}

	// Post ordering options
	$limit_days = array(0 => $user->lang['ALL_POSTS'], 1 => $user->lang['1_DAY'], 7 => $user->lang['7_DAYS'], 14 => $user->lang['2_WEEKS'], 30 => $user->lang['1_MONTH'], 90 => $user->lang['3_MONTHS'], 180 => $user->lang['6_MONTHS'], 365 => $user->lang['1_YEAR']);

	$sort_by_text = array('a' => $user->lang['AUTHOR'], 't' => $user->lang['POST_TIME'], 's' => $user->lang['SUBJECT']);
	$sort_by_sql = array('a' => array('u.username_clean', 'p.post_id'), 't' => array('p.post_time', 'p.post_id'), 's' => array('p.post_subject', 'p.post_id'));
	$join_user_sql = array('a' => true, 't' => false, 's' => false);

	$s_limit_days = $s_sort_key = $s_sort_dir = $u_sort_param = '';

	/**
	* Event to add new sorting options
	*
	* @event core.viewtopic_gen_sort_selects_before
	* @var	array	limit_days		Limit results by time
	* @var	array	sort_by_text	Language strings for sorting options
	* @var	array	sort_by_sql		SQL conditions for sorting options
	* @var	array	join_user_sql	SQL joins required for sorting options
	* @var	int		sort_days		User selected sort days
	* @var	string	sort_key		User selected sort key
	* @var	string	sort_dir		User selected sort direction
	* @var	string	s_limit_days	Initial value of limit days selectbox
	* @var	string	s_sort_key		Initial value of sort key selectbox
	* @var	string	s_sort_dir		Initial value of sort direction selectbox
	* @var	string	u_sort_param	Initial value of sorting form action
	* @since 3.2.8-RC1
	*/
	$vars = array(
		'limit_days',
		'sort_by_text',
		'sort_by_sql',
		'join_user_sql',
		'sort_days',
		'sort_key',
		'sort_dir',
		's_limit_days',
		's_sort_key',
		's_sort_dir',
		'u_sort_param',
	);
	extract($phpbb_dispatcher->trigger_event('core.viewtopic_gen_sort_selects_before', compact($vars)));

	gen_sort_selects($limit_days, $sort_by_text, $sort_days, $sort_key, $sort_dir, $s_limit_days, $s_sort_key, $s_sort_dir, $u_sort_param, $default_sort_days, $default_sort_key, $default_sort_dir);

	// Obtain correct post count and ordering SQL if user has
	// requested anything different
	if ($sort_days)
	{
		$min_post_time = time() - ($sort_days * 86400);

		$sql = 'SELECT COUNT(post_id) AS num_posts
			FROM ' . POSTS_TABLE . '
			WHERE topic_id = :vt_page_topic_id
				AND post_time >= :vt_page_min_post_time
					AND ' . $phpbb_content_visibility->get_visibility_sql('post', $forum_id);
		$result = $db->sql_query_params($sql, [
			'vt_page_topic_id' => $topic_id,
			'vt_page_min_post_time' => $min_post_time,
		]);
		$total_posts = (int) $db->sql_fetchfield('num_posts');
		$db->sql_freeresult($result);

		$limit_posts_time = "AND p.post_time >= $min_post_time ";

		if (isset($_POST['sort']))
		{
			$start = 0;
		}
	}
	else
	{
		$total_posts = $topic_replies + 1;
		$limit_posts_time = '';
	}

	// Was a highlight request part of the URI?
	$highlight_match = $highlight = '';
	if ($hilit_words)
	{
		$highlight_match = phpbb_clean_search_string($hilit_words);
		$highlight = urlencode($highlight_match);
		$highlight_match = str_replace('\*', '\w+?', preg_quote($highlight_match, '#'));
		$highlight_match = preg_replace('#(?<=^|\s)\\\\w\*\?(?=\s|$)#', '\w+?', $highlight_match);
		$highlight_match = str_replace(' ', '|', $highlight_match);
	}

	// Make sure $start is set to the last page if it exceeds the amount
	$start = $pagination->validate_start($start, $config['posts_per_page'], $total_posts);

	// General Viewtopic URL for return links
	$viewtopic_url = append_sid("{$phpbb_root_path}viewtopic.$phpEx", "t=$topic_id" . (($start == 0) ? '' : "&amp;start=$start") . ((strlen($u_sort_param)) ? "&amp;$u_sort_param" : '') . (($highlight_match) ? "&amp;hilit=$highlight" : ''));

	// Are we watching this topic?
	$s_watching_topic = array(
		'link'			=> '',
		'link_toggle'	=> '',
		'title'			=> '',
		'title_toggle'	=> '',
		'is_watching'	=> false,
	);

	if ($config['allow_topic_notify'])
	{
		$notify_status = (isset($topic_data['notify_status'])) ? $topic_data['notify_status'] : null;
		watch_topic_forum('topic', $s_watching_topic, $user->data['user_id'], $forum_id, $topic_id, $notify_status, $start, $topic_data['topic_title']);

		// Reset forum notification if forum notify is set
		if ($config['allow_forum_notify'] && $auth->acl_get('f_subscribe', $forum_id))
		{
			$s_watching_forum = $s_watching_topic;
			watch_topic_forum('forum', $s_watching_forum, $user->data['user_id'], $forum_id, 0);
		}
	}

	/**
	* Event to modify highlight.
	*
	* @event core.viewtopic_highlight_modify
	* @var	string	highlight			String to be highlighted
	* @var	string	highlight_match		Highlight string to be used in preg_replace
	* @var	array	topic_data			Topic data
	* @var	int		start				Pagination start
	* @var	int		total_posts			Number of posts
	* @var	string	viewtopic_url		Current viewtopic URL
	* @since 3.1.11-RC1
	*/
	$vars = array(
		'highlight',
		'highlight_match',
		'topic_data',
		'start',
		'total_posts',
		'viewtopic_url',
	);
	extract($phpbb_dispatcher->trigger_event('core.viewtopic_highlight_modify', compact($vars)));

	// Bookmarks
	if ($config['allow_bookmarks'] && $user->data['is_registered'] && $request->variable('bookmark', 0))
	{
		if (check_link_hash($request->variable('hash', ''), "topic_$topic_id"))
		{
			if (!$topic_data['bookmarked'])
			{
				$sql_params = [];
				$sql = 'INSERT INTO ' . BOOKMARKS_TABLE . ' ' . $db->sql_build_array_params('INSERT', [
					'user_id' => $user->data['user_id'],
					'topic_id' => $topic_id,
				], $sql_params, 'vt_bookmark');
				$db->sql_query_params($sql, $sql_params);
			}
			else
			{
				$sql = 'DELETE FROM ' . BOOKMARKS_TABLE . '
					WHERE user_id = :vt_bookmark_user_id
						AND topic_id = :vt_bookmark_topic_id';
				$db->sql_query_params($sql, [
					'vt_bookmark_user_id' => $user->data['user_id'],
					'vt_bookmark_topic_id' => $topic_id,
				]);
			}
			$message = (($topic_data['bookmarked']) ? $user->lang['BOOKMARK_REMOVED'] : $user->lang['BOOKMARK_ADDED']);

			if (!$request->is_ajax())
			{
				$message .= '<br /><br />' . $user->lang('RETURN_TOPIC', '<a href="' . $viewtopic_url . '">', '</a>');
			}
		}
		else
		{
			$message = $user->lang['BOOKMARK_ERR'];

			if (!$request->is_ajax())
			{
				$message .= '<br /><br />' . $user->lang('RETURN_TOPIC', '<a href="' . $viewtopic_url . '">', '</a>');
			}
		}
		meta_refresh(3, $viewtopic_url);

		trigger_error($message);
	}

	// Grab icons
	$icons = $cache->obtain_icons();

	// Forum rules listing
	$s_forum_rules = '';
	gen_forum_auth_level('topic', $forum_id, $topic_data['forum_status']);

	// Quick mod tools
	$allow_change_type = ($auth->acl_get('m_', $forum_id) || ($user->data['is_registered'] && $user->data['user_id'] == $topic_data['topic_poster'])) ? true : false;

	$s_quickmod_action = append_sid(
		"{$phpbb_root_path}mcp.$phpEx",
		array(
			'f'	=> $forum_id,
			't'	=> $topic_id,
			'start'		=> $start,
			'quickmod'	=> 1,
			'redirect'	=> urlencode(str_replace('&amp;', '&', $viewtopic_url)),
		)
	);

	$quickmod_array = array(
	//	'key'			=> array('LANG_KEY', $userHasPermissions),

		'lock'					=> array('LOCK_TOPIC', ($topic_data['topic_status'] == ITEM_UNLOCKED) && ($auth->acl_get('m_lock', $forum_id) || ($auth->acl_get('f_user_lock', $forum_id) && $user->data['is_registered'] && $user->data['user_id'] == $topic_data['topic_poster']))),
		'unlock'				=> array('UNLOCK_TOPIC', ($topic_data['topic_status'] != ITEM_UNLOCKED) && ($auth->acl_get('m_lock', $forum_id))),
		'delete_topic'		=> array('DELETE_TOPIC', ($auth->acl_get('m_delete', $forum_id) || (($topic_data['topic_visibility'] != ITEM_DELETED) && $auth->acl_get('m_softdelete', $forum_id)))),
		'restore_topic'		=> array('RESTORE_TOPIC', (($topic_data['topic_visibility'] == ITEM_DELETED) && $auth->acl_get('m_approve', $forum_id))),
		'move'					=> array('MOVE_TOPIC', $auth->acl_get('m_move', $forum_id) && $topic_data['topic_status'] != ITEM_MOVED),
		'split'					=> array('SPLIT_TOPIC', $auth->acl_get('m_split', $forum_id)),
		'merge'					=> array('MERGE_POSTS', $auth->acl_get('m_merge', $forum_id)),
		'merge_topic'		=> array('MERGE_TOPIC', $auth->acl_get('m_merge', $forum_id)),
		'fork'					=> array('FORK_TOPIC', $auth->acl_get('m_move', $forum_id)),
		'make_normal'		=> array('MAKE_NORMAL', ($allow_change_type && $auth->acl_gets('f_sticky', 'f_announce', 'f_announce_global', $forum_id) && $topic_data['topic_type'] != POST_NORMAL)),
		'make_sticky'		=> array('MAKE_STICKY', ($allow_change_type && $auth->acl_get('f_sticky', $forum_id) && $topic_data['topic_type'] != POST_STICKY)),
		'make_announce'	=> array('MAKE_ANNOUNCE', ($allow_change_type && $auth->acl_get('f_announce', $forum_id) && $topic_data['topic_type'] != POST_ANNOUNCE)),
		'make_global'		=> array('MAKE_GLOBAL', ($allow_change_type && $auth->acl_get('f_announce_global', $forum_id) && $topic_data['topic_type'] != POST_GLOBAL)),
		'topic_logs'			=> array('VIEW_TOPIC_LOGS', $auth->acl_get('m_', $forum_id)),
	);

	/**
	* Event to modify data in the quickmod_array before it gets sent to the
	* phpbb_add_quickmod_option function.
	*
	* @event core.viewtopic_add_quickmod_option_before
	* @var	int				forum_id				Forum ID
	* @var	int				post_id					Post ID
	* @var	array			quickmod_array			Array with quick moderation options data
	* @var	array			topic_data				Array with topic data
	* @var	int				topic_id				Topic ID
	* @var	array			topic_tracking_info		Array with topic tracking data
	* @var	string			viewtopic_url			URL to the topic page
	* @var	bool			allow_change_type		Topic change permissions check
	* @since 3.1.9-RC1
	*/
	$vars = array(
		'forum_id',
		'post_id',
		'quickmod_array',
		'topic_data',
		'topic_id',
		'topic_tracking_info',
		'viewtopic_url',
		'allow_change_type',
	);
	extract($phpbb_dispatcher->trigger_event('core.viewtopic_add_quickmod_option_before', compact($vars)));

	foreach ($quickmod_array as $option => $qm_ary)
	{
		if (!empty($qm_ary[1]))
		{
			phpbb_add_quickmod_option($s_quickmod_action, $option, $qm_ary[0]);
		}
	}

	// Navigation links
	generate_forum_nav($topic_data);

	// Forum Rules
	generate_forum_rules($topic_data);

	// Moderators
	$forum_moderators = array();
	if ($config['load_moderators'])
	{
		get_moderators($forum_moderators, $forum_id);
	}

	// This is only used for print view so ...
	$server_path = (!$view) ? $phpbb_root_path : generate_board_url() . '/';

	// Replace naughty words in title
	$topic_data['topic_title'] = censor_text($topic_data['topic_title']);

	$s_search_hidden_fields = array(
		't' => $topic_id,
		'sf' => 'msgonly',
	);
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

	// If we've got a hightlight set pass it on to pagination.
	$base_url = append_sid("{$phpbb_root_path}viewtopic.$phpEx", "t=$topic_id" . ((strlen($u_sort_param)) ? "&amp;$u_sort_param" : '') . (($highlight_match) ? "&amp;hilit=$highlight" : ''));

	/**
	* Event to modify data before template variables are being assigned
	*
	* @event core.viewtopic_assign_template_vars_before
	* @var	string	base_url			URL to be passed to generate pagination
	* @var	int		forum_id			Forum ID
	* @var	int		post_id				Post ID
	* @var	array	quickmod_array		Array with quick moderation options data
	* @var	int		start				Pagination information
	* @var	array	topic_data			Array with topic data
	* @var	int		topic_id			Topic ID
	* @var	array	topic_tracking_info	Array with topic tracking data
	* @var	int		total_posts			Topic total posts count
	* @var	string	viewtopic_url		URL to the topic page
	* @since 3.1.0-RC4
	* @changed 3.1.2-RC1 Added viewtopic_url
	*/
	$vars = array(
		'base_url',
		'forum_id',
		'post_id',
		'quickmod_array',
		'start',
		'topic_data',
		'topic_id',
		'topic_tracking_info',
		'total_posts',
		'viewtopic_url',
	);
	extract($phpbb_dispatcher->trigger_event('core.viewtopic_assign_template_vars_before', compact($vars)));

	$pagination->generate_template_pagination($base_url, 'pagination', 'start', $total_posts, $config['posts_per_page'], $start);

	// Send vars to template
	$template->assign_vars(array(
		'FORUM_ID' 		=> $forum_id,
		'FORUM_NAME' 	=> $topic_data['forum_name'],
		'FORUM_DESC'	=> generate_text_for_display($topic_data['forum_desc'], $topic_data['forum_desc_uid'], $topic_data['forum_desc_bitfield'], $topic_data['forum_desc_options']),
		'TOPIC_ID' 		=> $topic_id,
		'TOPIC_TITLE' 	=> $topic_data['topic_title'],
		'TOPIC_POSTER'	=> $topic_data['topic_poster'],

		'TOPIC_AUTHOR_FULL'		=> get_username_string('full', $topic_data['topic_poster'], $topic_data['topic_first_poster_name'], $topic_data['topic_first_poster_colour']),
		'TOPIC_AUTHOR_COLOUR'	=> get_username_string('colour', $topic_data['topic_poster'], $topic_data['topic_first_poster_name'], $topic_data['topic_first_poster_colour']),
		'TOPIC_AUTHOR'			=> get_username_string('username', $topic_data['topic_poster'], $topic_data['topic_first_poster_name'], $topic_data['topic_first_poster_colour']),

		'TOTAL_POSTS'	=> $user->lang('VIEW_TOPIC_POSTS', (int) $total_posts),
		'U_MCP' 		=> ($auth->acl_get('m_', $forum_id)) ? append_sid("{$phpbb_root_path}mcp.$phpEx", "i=main&amp;mode=topic_view&amp;t=$topic_id" . (($start == 0) ? '' : "&amp;start=$start") . ((strlen($u_sort_param)) ? "&amp;$u_sort_param" : '')) : '',
		'MODERATORS'	=> (isset($forum_moderators[$forum_id]) && count($forum_moderators[$forum_id])) ? implode($user->lang['COMMA_SEPARATOR'], $forum_moderators[$forum_id]) : '',

		'POST_IMG' 			=> ($topic_data['forum_status'] == ITEM_LOCKED) ? $user->img('button_topic_locked', 'FORUM_LOCKED') : $user->img('button_topic_new', 'POST_NEW_TOPIC'),
		'QUOTE_IMG' 		=> $user->img('icon_post_quote', 'REPLY_WITH_QUOTE'),
		'REPLY_IMG'			=> ($topic_data['forum_status'] == ITEM_LOCKED || $topic_data['topic_status'] == ITEM_LOCKED) ? $user->img('button_topic_locked', 'TOPIC_LOCKED') : $user->img('button_topic_reply', 'REPLY_TO_TOPIC'),
		'EDIT_IMG' 			=> $user->img('icon_post_edit', 'EDIT_POST'),
		'DELETE_IMG' 		=> $user->img('icon_post_delete', 'DELETE_POST'),
		'DELETED_IMG'		=> $user->img('icon_topic_deleted', 'POST_DELETED_RESTORE'),
		'INFO_IMG' 			=> $user->img('icon_post_info', 'VIEW_INFO'),
		'PROFILE_IMG'		=> $user->img('icon_user_profile', 'READ_PROFILE'),
		'SEARCH_IMG' 		=> $user->img('icon_user_search', 'SEARCH_USER_POSTS'),
		'PM_IMG' 			=> $user->img('icon_contact_pm', 'SEND_PRIVATE_MESSAGE'),
		'EMAIL_IMG' 		=> $user->img('icon_contact_email', 'SEND_EMAIL'),
		'REPORT_IMG'		=> $user->img('icon_post_report', 'REPORT_POST'),
		'REPORTED_IMG'		=> $user->img('icon_topic_reported', 'POST_REPORTED'),
		'UNAPPROVED_IMG'	=> $user->img('icon_topic_unapproved', 'POST_UNAPPROVED'),
		'WARN_IMG'			=> $user->img('icon_user_warn', 'WARN_USER'),

		'S_IS_LOCKED'			=> ($topic_data['topic_status'] == ITEM_UNLOCKED && $topic_data['forum_status'] == ITEM_UNLOCKED) ? false : true,
		'S_SELECT_SORT_DIR' 	=> $s_sort_dir,
		'S_SELECT_SORT_KEY' 	=> $s_sort_key,
		'S_SELECT_SORT_DAYS' 	=> $s_limit_days,
		'S_SINGLE_MODERATOR'	=> (!empty($forum_moderators[$forum_id]) && count($forum_moderators[$forum_id]) > 1) ? false : true,
		'S_TOPIC_ACTION' 		=> append_sid("{$phpbb_root_path}viewtopic.$phpEx", "t=$topic_id" . (($start == 0) ? '' : "&amp;start=$start")),
		'S_MOD_ACTION' 			=> $s_quickmod_action,

		'L_RETURN_TO_FORUM'		=> $user->lang('RETURN_TO', $topic_data['forum_name']),
		'S_VIEWTOPIC'			=> true,
		'S_UNREAD_VIEW'			=> $view == 'unread',
		'S_DISPLAY_SEARCHBOX'	=> ($auth->acl_get('u_search') && $auth->acl_get('f_search', $forum_id) && $config['load_search']) ? true : false,
		'S_SEARCHBOX_ACTION'	=> append_sid("{$phpbb_root_path}search.$phpEx"),
		'S_SEARCH_LOCAL_HIDDEN_FIELDS'	=> build_hidden_fields($s_search_hidden_fields),

		'S_DISPLAY_POST_INFO'	=> ($topic_data['forum_type'] == FORUM_POST && ($auth->acl_get('f_post', $forum_id) || $user->data['user_id'] == ANONYMOUS)) ? true : false,
		'S_DISPLAY_REPLY_INFO'	=> ($topic_data['forum_type'] == FORUM_POST && ($auth->acl_get('f_reply', $forum_id) || $user->data['user_id'] == ANONYMOUS)) ? true : false,
		'S_ENABLE_FEEDS_TOPIC'	=> ($config['feed_topic'] && !phpbb_optionget(FORUM_OPTION_FEED_EXCLUDE, $topic_data['forum_options'])) ? true : false,

		'U_TOPIC'				=> "{$server_path}viewtopic.$phpEx?t=$topic_id",
		'U_FORUM'				=> $server_path,
		'U_VIEW_TOPIC' 			=> append_sid("{$phpbb_root_path}viewtopic.$phpEx", "t=$topic_id" . (($start == 0) ? '' : "&amp;start=$start") . (strlen($u_sort_param) ? "&amp;$u_sort_param" : '')),
		'U_CANONICAL'			=> generate_board_url() . '/' . append_sid("viewtopic.$phpEx", "t=$topic_id" . (($start) ? "&amp;start=$start" : ''), true, ''),
		'U_VIEW_FORUM' 			=> append_sid("{$phpbb_root_path}viewforum.$phpEx", 'f=' . $forum_id),
		'U_VIEW_OLDER_TOPIC'	=> append_sid("{$phpbb_root_path}viewtopic.$phpEx", "t=$topic_id&amp;view=previous"),
		'U_VIEW_NEWER_TOPIC'	=> append_sid("{$phpbb_root_path}viewtopic.$phpEx", "t=$topic_id&amp;view=next"),
		'U_PRINT_TOPIC'			=> ($auth->acl_get('f_print', $forum_id)) ? $viewtopic_url . '&amp;view=print' : '',
		'U_EMAIL_TOPIC'			=> ($auth->acl_get('f_email', $forum_id) && $config['email_enable']) ? append_sid("{$phpbb_root_path}memberlist.$phpEx", "mode=email&amp;t=$topic_id") : '',

		'U_WATCH_TOPIC'			=> $s_watching_topic['link'],
		'U_WATCH_TOPIC_TOGGLE'	=> $s_watching_topic['link_toggle'],
		'S_WATCH_TOPIC_TITLE'	=> $s_watching_topic['title'],
		'S_WATCH_TOPIC_TOGGLE'	=> $s_watching_topic['title_toggle'],
		'S_WATCHING_TOPIC'		=> $s_watching_topic['is_watching'],

		'U_BOOKMARK_TOPIC'		=> ($user->data['is_registered'] && $config['allow_bookmarks']) ? $viewtopic_url . '&amp;bookmark=1&amp;hash=' . generate_link_hash("topic_$topic_id") : '',
		'S_BOOKMARK_TOPIC'		=> ($user->data['is_registered'] && $config['allow_bookmarks'] && $topic_data['bookmarked']) ? $user->lang['BOOKMARK_TOPIC_REMOVE'] : $user->lang['BOOKMARK_TOPIC'],
		'S_BOOKMARK_TOGGLE'		=> (!$user->data['is_registered'] || !$config['allow_bookmarks'] || !$topic_data['bookmarked']) ? $user->lang['BOOKMARK_TOPIC_REMOVE'] : $user->lang['BOOKMARK_TOPIC'],
		'S_BOOKMARKED_TOPIC'	=> ($user->data['is_registered'] && $config['allow_bookmarks'] && $topic_data['bookmarked']) ? true : false,

		'U_POST_NEW_TOPIC' 		=> ($auth->acl_get('f_post', $forum_id) || $user->data['user_id'] == ANONYMOUS) ? append_sid("{$phpbb_root_path}posting.$phpEx", "mode=post&amp;f=$forum_id") : '',
		'U_POST_REPLY_TOPIC' 	=> ($auth->acl_get('f_reply', $forum_id) || $user->data['user_id'] == ANONYMOUS) ? append_sid("{$phpbb_root_path}posting.$phpEx", "mode=reply&amp;t=$topic_id") : '',
		'U_BUMP_TOPIC'			=> (bump_topic_allowed($forum_id, $topic_data['topic_bumped'], $topic_data['topic_last_post_time'], $topic_data['topic_poster'], $topic_data['topic_last_poster_id'])) ? append_sid("{$phpbb_root_path}posting.$phpEx", "mode=bump&amp;t=$topic_id&amp;hash=" . generate_link_hash("topic_$topic_id")) : '')
	);


		return [
			'forum_id' => (int) $forum_id,
			'topic_id' => (int) $topic_id,
			'post_id' => (int) $post_id,
			'topic_data' => $topic_data,
			'topic_tracking_info' => $topic_tracking_info,
			'start' => (int) $start,
			'sort_days' => (int) $sort_days,
			'sort_key' => $sort_key,
			'sort_dir' => $sort_dir,
			'sort_by_sql' => $sort_by_sql,
			'join_user_sql' => $join_user_sql,
			'total_posts' => (int) $total_posts,
			'limit_posts_time' => $limit_posts_time,
			'highlight_match' => $highlight_match,
			'highlight' => $highlight,
			'viewtopic_url' => $viewtopic_url,
			's_watching_topic' => $s_watching_topic,
			'icons' => $icons,
		];
	}
}

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
use phpbb\avatar\helper as avatar_helper;
use phpbb\config\config;
use phpbb\controller\helper as controller_helper;
use phpbb\db\driver\driver_interface;
use phpbb\event\dispatcher_interface;
use phpbb\profilefields\manager as profilefields_manager;
use phpbb\template\template;
use phpbb\user;

/**
 * Renders the post rows displayed by viewtopic.
 *
 * Attachment preparation, post text/signature parsing, post action permissions,
 * profile/contact fields and postrow template assignment live here while the
 * historical viewtopic extension events remain available at their original
 * integration points.
 */
class viewtopic_post_renderer
{
	/** @var auth */
	protected $auth;
	/** @var avatar_helper */
	protected $avatar_helper;
	/** @var config */
	protected $config;
	/** @var controller_helper */
	protected $controller_helper;
	/** @var driver_interface */
	protected $db;
	/** @var dispatcher_interface */
	protected $dispatcher;
	/** @var profilefields_manager */
	protected $profilefields_manager;
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
		avatar_helper $avatar_helper,
		config $config,
		controller_helper $controller_helper,
		driver_interface $db,
		dispatcher_interface $dispatcher,
		profilefields_manager $profilefields_manager,
		template $template,
		user $user,
		string $root_path,
		string $php_ext
	)
	{
		$this->auth = $auth;
		$this->avatar_helper = $avatar_helper;
		$this->config = $config;
		$this->controller_helper = $controller_helper;
		$this->db = $db;
		$this->dispatcher = $dispatcher;
		$this->profilefields_manager = $profilefields_manager;
		$this->template = $template;
		$this->user = $user;
		$this->root_path = $root_path;
		$this->php_ext = $php_ext;
	}

	/**
	 * Render all posts on the current viewtopic page.
	 *
	 * The context intentionally mirrors the legacy variables still provided by
	 * viewtopic.php so existing phpBB events keep receiving the same data.
	 *
	 * @param array<string, mixed> $context
	 * @return array<string, mixed>
	 */
	public function render(array $context): array
	{
		$auth = $this->auth;
		$avatar_helper = $this->avatar_helper;
		$config = $this->config;
		$controller_helper = $this->controller_helper;
		$db = $this->db;
		$phpbb_dispatcher = $this->dispatcher;
		$cp = $this->profilefields_manager;
		$template = $this->template;
		$user = $this->user;
		$phpbb_root_path = $this->root_path;
		$phpEx = $this->php_ext;

		extract($context, EXTR_SKIP);

	$attachments = $update_count = $post_edit_list = $post_delete_list = array();
	$display_notice = false;
	$i = $i_total = 0;

	// Pull attachment data
	if (count($attach_list))
	{
		if ($auth->acl_get('u_download') && $auth->acl_get('f_download', $forum_id))
		{
			$sql_params = [];
			$sql_post_ids = $db->sql_in_set_params('post_msg_id', $attach_list, $sql_params, false, false, 'vt_attachment_post');
			$sql_params['vt_attachment_in_message'] = 0;
			$sql = 'SELECT *
				FROM ' . ATTACHMENTS_TABLE . '
				WHERE ' . $sql_post_ids . '
					AND in_message = :vt_attachment_in_message
				ORDER BY attach_id DESC, post_msg_id ASC';
			$result = $db->sql_query_params($sql, $sql_params);

			while ($row = $db->sql_fetchrow($result))
			{
				$attachments[$row['post_msg_id']][] = $row;
			}
			$db->sql_freeresult($result);

			// No attachments exist, but post table thinks they do so go ahead and reset post_attach flags
			if (!count($attachments))
			{
				$sql_params = [];
				$sql_post_ids = $db->sql_in_set_params('post_id', $attach_list, $sql_params, false, false, 'vt_reset_attachment_post');
				$sql = 'UPDATE ' . POSTS_TABLE . '
					SET post_attachment = 0
					WHERE ' . $sql_post_ids;
				$db->sql_query_params($sql, $sql_params);

				// We need to update the topic indicator too if the complete topic is now without an attachment
				if (count($rowset) != $total_posts)
				{
					// Not all posts are displayed so we query the db to find if there's any attachment for this topic
					$sql = 'SELECT a.post_msg_id as post_id
						FROM ' . ATTACHMENTS_TABLE . ' a, ' . POSTS_TABLE . ' p
						WHERE p.topic_id = :vt_attachment_topic_id
							AND p.post_visibility = :vt_attachment_visibility
							AND p.topic_id = a.topic_id';
					$result = $db->sql_query_limit_params($sql, 1, 0, [
						'vt_attachment_topic_id' => $topic_id,
						'vt_attachment_visibility' => ITEM_APPROVED,
					]);
					$row = $db->sql_fetchrow($result);
					$db->sql_freeresult($result);

					if (!$row)
					{
						$sql = 'UPDATE ' . TOPICS_TABLE . '
							SET topic_attachment = 0
							WHERE topic_id = :vt_attachment_topic_id';
						$db->sql_query_params($sql, ['vt_attachment_topic_id' => $topic_id]);
					}
				}
				else
				{
					$sql = 'UPDATE ' . TOPICS_TABLE . '
						SET topic_attachment = 0
						WHERE topic_id = :vt_attachment_topic_id';
					$db->sql_query_params($sql, ['vt_attachment_topic_id' => $topic_id]);
				}
			}
			else if ($has_approved_attachments && !$topic_data['topic_attachment'])
			{
				// Topic has approved attachments but its flag is wrong
				$sql = 'UPDATE ' . TOPICS_TABLE . '
					SET topic_attachment = 1
					WHERE topic_id = :vt_attachment_topic_id';
				$db->sql_query_params($sql, ['vt_attachment_topic_id' => $topic_id]);

				$topic_data['topic_attachment'] = 1;
			}
			else if ($has_unapproved_attachments && !$topic_data['topic_attachment'])
			{
				// Topic has only unapproved attachments but we have the right to see and download them
				$topic_data['topic_attachment'] = 1;
			}
		}
		else
		{
			$display_notice = true;
		}
	}

	if ($config['enable_accurate_pm_button'])
	{
		// Get the list of users who can receive private messages
		$can_receive_pm_list = $auth->acl_get_list(array_keys($user_cache), 'u_readpm');
		$can_receive_pm_list = (empty($can_receive_pm_list) || !isset($can_receive_pm_list[0]['u_readpm'])) ? array() : $can_receive_pm_list[0]['u_readpm'];

		// Get the list of permanently banned users
		$permanently_banned_users = phpbb_get_banned_user_ids(array_keys($user_cache), false);
	}
	else
	{
		$can_receive_pm_list = array_keys($user_cache);
		$permanently_banned_users = [];
	}

	$i_total = count($rowset) - 1;
	$prev_post_id = '';

	$template->assign_vars(array(
		'S_HAS_ATTACHMENTS' => $topic_data['topic_attachment'],
		'S_NUM_POSTS' => count($post_list))
	);

	/**
	* Event to modify the post, poster and attachment data before assigning the posts
	*
	* @event core.viewtopic_modify_post_data
	* @var	int		forum_id	Forum ID
	* @var	int		topic_id	Topic ID
	* @var	array	topic_data	Array with topic data
	* @var	array	post_list	Array with post_ids we are going to display
	* @var	array	rowset		Array with post_id => post data
	* @var	array	user_cache	Array with prepared user data
	* @var	int		start		Pagination information
	* @var	int		sort_days	Display posts of previous x days
	* @var	string	sort_key	Key the posts are sorted by
	* @var	string	sort_dir	Direction the posts are sorted by
	* @var	bool	display_notice				Shall we display a notice instead of attachments
	* @var	bool	has_approved_attachments	Does the topic have approved attachments
	* @var	array	attachments					List of attachments post_id => array of attachments
	* @var	array	permanently_banned_users	List of permanently banned users
	* @var	array	can_receive_pm_list			Array with posters that can receive pms
	* @since 3.1.0-RC3
	*/
	$vars = array(
		'forum_id',
		'topic_id',
		'topic_data',
		'post_list',
		'rowset',
		'user_cache',
		'sort_days',
		'sort_key',
		'sort_dir',
		'start',
		'permanently_banned_users',
		'can_receive_pm_list',
		'display_notice',
		'has_approved_attachments',
		'attachments',
	);
	extract($phpbb_dispatcher->trigger_event('core.viewtopic_modify_post_data', compact($vars)));

	// Output the posts
	$first_unread = $post_unread = false;
	for ($i = 0, $end = count($post_list); $i < $end; ++$i)
	{
		// A non-existing rowset only happens if there was no user present for the entered poster_id
		// This could be a broken posts table.
		if (!isset($rowset[$post_list[$i]]))
		{
			continue;
		}

		$row = $rowset[$post_list[$i]];
		$poster_id = $row['user_id'];

		// End signature parsing, only if needed
		if ($user_cache[$poster_id]['sig'] && $row['enable_sig'] && empty($user_cache[$poster_id]['sig_parsed']))
		{
			$parse_flags = ($user_cache[$poster_id]['sig_bbcode_bitfield'] ? OPTION_FLAG_BBCODE : 0) | OPTION_FLAG_SMILIES;
			$user_cache[$poster_id]['sig'] = generate_text_for_display($user_cache[$poster_id]['sig'], $user_cache[$poster_id]['sig_bbcode_uid'], $user_cache[$poster_id]['sig_bbcode_bitfield'],  $parse_flags, true);
			$user_cache[$poster_id]['sig_parsed'] = true;
		}

		// Parse the message and subject
		$parse_flags = ($row['bbcode_bitfield'] ? OPTION_FLAG_BBCODE : 0) | OPTION_FLAG_SMILIES;
		$message = generate_text_for_display($row['post_text'], $row['bbcode_uid'], $row['bbcode_bitfield'], $parse_flags, true);

		if (!empty($attachments[$row['post_id']]))
		{
			parse_attachments($forum_id, $message, $attachments[$row['post_id']], $update_count);
		}

		// Replace naughty words such as farty pants
		$row['post_subject'] = censor_text($row['post_subject']);

		// Highlight active words (primarily for search)
		if ($highlight_match)
		{
			$message = preg_replace('#(?!<.*)(?<!\w)(' . $highlight_match . ')(?!\w|[^<>]*(?:</s(?:cript|tyle))?>)#is', '<span class="posthilit">\1</span>', $message);
			$row['post_subject'] = preg_replace('#(?!<.*)(?<!\w)(' . $highlight_match . ')(?!\w|[^<>]*(?:</s(?:cript|tyle))?>)#is', '<span class="posthilit">\1</span>', $row['post_subject']);
		}

		// Editing information
		if (($row['post_edit_count'] && $config['display_last_edited']) || $row['post_edit_reason'])
		{
			// Get usernames for all following posts if not already stored
			if (!count($post_edit_list) && ($row['post_edit_reason'] || ($row['post_edit_user'] && !isset($user_cache[$row['post_edit_user']]))))
			{
				// Remove all post_ids already parsed (we do not have to check them)
				$post_storage_list = (!$store_reverse) ? array_slice($post_list, $i) : array_slice(array_reverse($post_list), $i);

				$sql_params = [];
				$sql_post_ids = $db->sql_in_set_params('p.post_id', $post_storage_list, $sql_params, false, false, 'vt_edit_post');
				$sql = 'SELECT DISTINCT u.user_id, u.username, u.user_colour
					FROM ' . POSTS_TABLE . ' p, ' . USERS_TABLE . ' u
					WHERE ' . $sql_post_ids . '
						AND p.post_edit_count <> 0
						AND p.post_edit_user <> 0
						AND p.post_edit_user = u.user_id';
				$result2 = $db->sql_query_params($sql, $sql_params);
				while ($user_edit_row = $db->sql_fetchrow($result2))
				{
					$post_edit_list[$user_edit_row['user_id']] = $user_edit_row;
				}
				$db->sql_freeresult($result2);

				unset($post_storage_list);
			}

			if ($row['post_edit_reason'])
			{
				// User having edited the post also being the post author?
				if (!$row['post_edit_user'] || $row['post_edit_user'] == $poster_id)
				{
					$display_username = get_username_string('full', $poster_id, $row['username'], $row['user_colour'], $row['post_username']);
				}
				else
				{
					$display_username = get_username_string('full', $row['post_edit_user'], $post_edit_list[$row['post_edit_user']]['username'], $post_edit_list[$row['post_edit_user']]['user_colour']);
				}

				$l_edited_by = $user->lang('EDITED_TIMES_TOTAL', (int) $row['post_edit_count'], $display_username, $user->format_date($row['post_edit_time'], false, true));
			}
			else
			{
				if ($row['post_edit_user'] && !isset($user_cache[$row['post_edit_user']]))
				{
					$user_cache[$row['post_edit_user']] = $post_edit_list[$row['post_edit_user']];
				}

				// User having edited the post also being the post author?
				if (!$row['post_edit_user'] || $row['post_edit_user'] == $poster_id)
				{
					$display_username = get_username_string('full', $poster_id, $row['username'], $row['user_colour'], $row['post_username']);
				}
				else
				{
					$display_username = get_username_string('full', $row['post_edit_user'], $user_cache[$row['post_edit_user']]['username'], $user_cache[$row['post_edit_user']]['user_colour']);
				}

				$l_edited_by = $user->lang('EDITED_TIMES_TOTAL', (int) $row['post_edit_count'], $display_username, $user->format_date($row['post_edit_time'], false, true));
			}
		}
		else
		{
			$l_edited_by = '';
		}

		// Deleting information
		if ($row['post_visibility'] == ITEM_DELETED && $row['post_delete_user'])
		{
			// Get usernames for all following posts if not already stored
			if (!count($post_delete_list) && ($row['post_delete_reason'] || ($row['post_delete_user'] && !isset($user_cache[$row['post_delete_user']]))))
			{
				// Remove all post_ids already parsed (we do not have to check them)
				$post_storage_list = (!$store_reverse) ? array_slice($post_list, $i) : array_slice(array_reverse($post_list), $i);

				$sql_params = [];
				$sql_post_ids = $db->sql_in_set_params('p.post_id', $post_storage_list, $sql_params, false, false, 'vt_delete_post');
				$sql = 'SELECT DISTINCT u.user_id, u.username, u.user_colour
					FROM ' . POSTS_TABLE . ' p, ' . USERS_TABLE . ' u
					WHERE ' . $sql_post_ids . '
						AND p.post_delete_user <> 0
						AND p.post_delete_user = u.user_id';
				$result2 = $db->sql_query_params($sql, $sql_params);
				while ($user_delete_row = $db->sql_fetchrow($result2))
				{
					$post_delete_list[$user_delete_row['user_id']] = $user_delete_row;
				}
				$db->sql_freeresult($result2);

				unset($post_storage_list);
			}

			if ($row['post_delete_user'] && !isset($user_cache[$row['post_delete_user']]))
			{
				$user_cache[$row['post_delete_user']] = $post_delete_list[$row['post_delete_user']];
			}

			$display_postername = get_username_string('full', $poster_id, $row['username'], $row['user_colour'], $row['post_username']);

			// User having deleted the post also being the post author?
			if (!$row['post_delete_user'] || $row['post_delete_user'] == $poster_id)
			{
				$display_username = $display_postername;
			}
			else
			{
				$display_username = get_username_string('full', $row['post_delete_user'], $user_cache[$row['post_delete_user']]['username'], $user_cache[$row['post_delete_user']]['user_colour']);
			}

			if ($row['post_delete_reason'])
			{
				$l_deleted_message = $user->lang('POST_DELETED_BY_REASON', $display_postername, $display_username, $user->format_date($row['post_delete_time'], false, true), $row['post_delete_reason']);
			}
			else
			{
				$l_deleted_message = $user->lang('POST_DELETED_BY', $display_postername, $display_username, $user->format_date($row['post_delete_time'], false, true));
			}
			$l_deleted_by = $user->lang('DELETED_INFORMATION', $display_username, $user->format_date($row['post_delete_time'], false, true));
		}
		else
		{
			$l_deleted_by = $l_deleted_message = '';
		}

		// Bump information
		if ($topic_data['topic_bumped'] && $row['post_id'] == $topic_data['topic_last_post_id'] && isset($user_cache[$topic_data['topic_bumper']]) )
		{
			// It is safe to grab the username from the user cache array, we are at the last
			// post and only the topic poster and last poster are allowed to bump.
			// Admins and mods are bound to the above rules too...
			$l_bumped_by = sprintf($user->lang['BUMPED_BY'], $user_cache[$topic_data['topic_bumper']]['username'], $user->format_date($topic_data['topic_last_post_time'], false, true));
		}
		else
		{
			$l_bumped_by = '';
		}

		$cp_row = array();

		//
		if ($config['load_cpf_viewtopic'])
		{
			$cp_row = (isset($profile_fields_cache[$poster_id])) ? $cp->generate_profile_fields_template_data($profile_fields_cache[$poster_id]) : array();
		}

		$post_unread = (isset($topic_tracking_info[$topic_id]) && $row['post_time'] > $topic_tracking_info[$topic_id]) ? true : false;

		$s_first_unread = false;
		if (!$first_unread && $post_unread)
		{
			$s_first_unread = $first_unread = true;
		}

		$force_edit_allowed = $force_delete_allowed = $force_softdelete_allowed = false;
		$warn_allowed = true;

		$s_cannot_edit = !$auth->acl_get('f_edit', $forum_id) || $user->data['user_id'] != $poster_id;
		$s_cannot_edit_time = $config['edit_time'] && $row['post_time'] <= time() - ($config['edit_time'] * 60);
		$s_cannot_edit_locked = ($topic_data['topic_status'] == ITEM_LOCKED && !$auth->acl_get('m_lock', $forum_id)) || $row['post_edit_locked'];

		$s_cannot_delete = $user->data['user_id'] != $poster_id || (
				!$auth->acl_get('f_delete', $forum_id) &&
				(!$auth->acl_get('f_softdelete', $forum_id) || $row['post_visibility'] == ITEM_DELETED)
		);
		$s_cannot_delete_lastpost = $topic_data['topic_last_post_id'] != $row['post_id'];
		$s_cannot_delete_time = $config['delete_time'] && $row['post_time'] <= time() - ($config['delete_time'] * 60);
		// we do not want to allow removal of the last post if a moderator locked it!
		$s_cannot_delete_locked = $topic_data['topic_status'] == ITEM_LOCKED || $row['post_edit_locked'];

		/**
		* This event allows you to modify the conditions for the "can edit post" and "can delete post" checks
		*
		* @event core.viewtopic_modify_post_action_conditions
		* @var	array	row			Array with post data
		* @var	array	topic_data	Array with topic data
		* @var	bool	force_edit_allowed		Allow the user to edit the post (all permissions and conditions are ignored)
		* @var	bool	s_cannot_edit			User can not edit the post because it's not his
		* @var	bool	s_cannot_edit_locked	User can not edit the post because it's locked
		* @var	bool	s_cannot_edit_time		User can not edit the post because edit_time has passed
		* @var	bool	force_delete_allowed		Allow the user to delete the post (all permissions and conditions are ignored)
		* @var	bool	s_cannot_delete				User can not delete the post because it's not his
		* @var	bool	s_cannot_delete_lastpost	User can not delete the post because it's not the last post of the topic
		* @var	bool	s_cannot_delete_locked		User can not delete the post because it's locked
		* @var	bool	s_cannot_delete_time		User can not delete the post because edit_time has passed
		* @var	bool	force_softdelete_allowed	Allow the user to ыoftdelete the post (all permissions and conditions are ignored)
		* @var	bool	warn_allowed				Controls whether warning is allowed (default: true)
		* @since 3.1.0-b4
		* @changed 3.1.11-RC1 Added force_softdelete_allowed var
		* @changed 3.3.16-RC1 Added warn_allowed var
		*/
		$vars = array(
			'row',
			'topic_data',
			'force_edit_allowed',
			's_cannot_edit',
			's_cannot_edit_locked',
			's_cannot_edit_time',
			'force_delete_allowed',
			's_cannot_delete',
			's_cannot_delete_lastpost',
			's_cannot_delete_locked',
			's_cannot_delete_time',
			'force_softdelete_allowed',
			'warn_allowed',
		);
		extract($phpbb_dispatcher->trigger_event('core.viewtopic_modify_post_action_conditions', compact($vars)));

		$edit_allowed = $force_edit_allowed || ($user->data['is_registered'] && ($auth->acl_get('m_edit', $forum_id) || (
			!$s_cannot_edit &&
			!$s_cannot_edit_time &&
			!$s_cannot_edit_locked
		)));

		$quote_allowed = $auth->acl_get('m_edit', $forum_id) || ($topic_data['topic_status'] != ITEM_LOCKED &&
			($user->data['user_id'] == ANONYMOUS || $auth->acl_get('f_reply', $forum_id))
		);

		// Only display the quote button if the post is quotable.  Posts not approved are not quotable.
		$quote_allowed = ($quote_allowed && $row['post_visibility'] == ITEM_APPROVED) ? true : false;

		$delete_allowed = $force_delete_allowed || ($user->data['is_registered'] && (
			($auth->acl_get('m_delete', $forum_id) || ($auth->acl_get('m_softdelete', $forum_id) && $row['post_visibility'] != ITEM_DELETED)) ||
			(!$s_cannot_delete && !$s_cannot_delete_lastpost && !$s_cannot_delete_time && !$s_cannot_delete_locked)
		));

		$softdelete_allowed = $force_softdelete_allowed || (($auth->acl_get('m_softdelete', $forum_id) ||
			($auth->acl_get('f_softdelete', $forum_id) && $user->data['user_id'] == $poster_id)) && ($row['post_visibility'] != ITEM_DELETED));

		$permanent_delete_allowed = $force_delete_allowed || ($auth->acl_get('m_delete', $forum_id) ||
			($auth->acl_get('f_delete', $forum_id) && $user->data['user_id'] == $poster_id));

		// Can this user receive a Private Message?
		$can_receive_pm = (
			// They must be a "normal" user
			$user_cache[$poster_id]['user_type'] != USER_IGNORE &&

			// They must not be deactivated by the administrator
			($user_cache[$poster_id]['user_type'] != USER_INACTIVE || $user_cache[$poster_id]['user_inactive_reason'] != INACTIVE_MANUAL) &&

			// They must be able to read PMs
			in_array($poster_id, $can_receive_pm_list) &&

			// They must not be permanently banned
			!in_array($poster_id, $permanently_banned_users) &&

			// They must allow users to contact via PM
			(($auth->acl_gets('a_', 'm_') || $auth->acl_getf_global('m_')) || $user_cache[$poster_id]['allow_pm'])
		);

		$u_pm = '';

		if ($config['allow_privmsg'] && $auth->acl_get('u_sendpm') && $can_receive_pm)
		{
			$u_pm = append_sid("{$phpbb_root_path}ucp.$phpEx", 'i=pm&amp;mode=compose&amp;action=quotepost&amp;p=' . $row['post_id']);
		}

		$post_row = array(
			'POST_AUTHOR_FULL'		=> ($poster_id != ANONYMOUS) ? $user_cache[$poster_id]['author_full'] : get_username_string('full', $poster_id, $row['username'], $row['user_colour'], $row['post_username']),
			'POST_AUTHOR_COLOUR'	=> ($poster_id != ANONYMOUS) ? $user_cache[$poster_id]['author_colour'] : get_username_string('colour', $poster_id, $row['username'], $row['user_colour'], $row['post_username']),
			'POST_AUTHOR'			=> ($poster_id != ANONYMOUS) ? $user_cache[$poster_id]['author_username'] : get_username_string('username', $poster_id, $row['username'], $row['user_colour'], $row['post_username']),
			'U_POST_AUTHOR'			=> ($poster_id != ANONYMOUS) ? $user_cache[$poster_id]['author_profile'] : get_username_string('profile', $poster_id, $row['username'], $row['user_colour'], $row['post_username']),

			'RANK_TITLE'		=> $user_cache[$poster_id]['rank_title'],
			'RANK_IMG'			=> $user_cache[$poster_id]['rank_image'],
			'RANK_IMG_SRC'		=> $user_cache[$poster_id]['rank_image_src'],
			'POSTER_JOINED'		=> $user_cache[$poster_id]['joined'],
			'POSTER_POSTS'		=> $user_cache[$poster_id]['posts'],
			'POSTER_WARNINGS'	=> $auth->acl_get('m_warn') ? $user_cache[$poster_id]['warnings'] : '',
			'POSTER_AGE'		=> $user_cache[$poster_id]['age'],
			'CONTACT_USER'		=> $user_cache[$poster_id]['contact_user'],

			'POST_DATE'			=> $user->format_date($row['post_time'], false, ($view == 'print') ? true : false),
			'POST_DATE_RFC3339'	=> gmdate(DATE_RFC3339, $row['post_time']),
			'POST_SUBJECT'		=> $row['post_subject'],
			'MESSAGE'			=> $message,
			'SIGNATURE'			=> ($row['enable_sig']) ? $user_cache[$poster_id]['sig'] : '',
			'EDITED_MESSAGE'	=> $l_edited_by,
			'EDIT_REASON'		=> $row['post_edit_reason'],
			'DELETED_MESSAGE'	=> $l_deleted_by,
			'DELETE_REASON'		=> $row['post_delete_reason'],
			'BUMPED_MESSAGE'	=> $l_bumped_by,

			'MINI_POST_IMG'			=> ($post_unread) ? $user->img('icon_post_target_unread', 'UNREAD_POST') : $user->img('icon_post_target', 'POST'),
			'POST_ICON_IMG'			=> ($topic_data['enable_icons'] && !empty($row['icon_id'])) ? $icons[$row['icon_id']]['img'] : '',
			'POST_ICON_IMG_WIDTH'	=> ($topic_data['enable_icons'] && !empty($row['icon_id'])) ? $icons[$row['icon_id']]['width'] : '',
			'POST_ICON_IMG_HEIGHT'	=> ($topic_data['enable_icons'] && !empty($row['icon_id'])) ? $icons[$row['icon_id']]['height'] : '',
			'POST_ICON_IMG_ALT' 	=> ($topic_data['enable_icons'] && !empty($row['icon_id'])) ? $icons[$row['icon_id']]['alt'] : '',
			'ONLINE_IMG'			=> ($poster_id == ANONYMOUS || !$config['load_onlinetrack']) ? '' : (($user_cache[$poster_id]['online']) ? $user->img('icon_user_online', 'ONLINE') : $user->img('icon_user_offline', 'OFFLINE')),
			'S_ONLINE'				=> ($poster_id == ANONYMOUS || !$config['load_onlinetrack']) ? false : (($user_cache[$poster_id]['online']) ? true : false),

			'U_EDIT'			=> ($edit_allowed) ? append_sid("{$phpbb_root_path}posting.$phpEx", "mode=edit&amp;p={$row['post_id']}") : '',
			'U_QUOTE'			=> ($quote_allowed) ? append_sid("{$phpbb_root_path}posting.$phpEx", "mode=quote&amp;p={$row['post_id']}") : '',
			'U_INFO'			=> ($auth->acl_get('m_info', $forum_id)) ? append_sid("{$phpbb_root_path}mcp.$phpEx", "i=main&amp;mode=post_details&amp;p=" . $row['post_id']) : '',
			'U_DELETE'			=> ($delete_allowed) ? append_sid("{$phpbb_root_path}posting.$phpEx", 'mode=' . (($softdelete_allowed) ? 'soft_delete' : 'delete') . "&amp;p={$row['post_id']}") : '',

			'U_SEARCH'		=> $user_cache[$poster_id]['search'],
			'U_PM'			=> $u_pm,
			'U_EMAIL'		=> $user_cache[$poster_id]['email'],

			'U_APPROVE_ACTION'	=> append_sid("{$phpbb_root_path}mcp.$phpEx", "i=queue&amp;p={$row['post_id']}&amp;f={$row['forum_id']}&amp;redirect=" . urlencode(str_replace('&amp;', '&', $viewtopic_url . '&amp;p=' . $row['post_id'] . '#p' . $row['post_id']))),
			'U_REPORT'			=> ($auth->acl_get('f_report', $forum_id)) ? $controller_helper->route('phpbb_report_post_controller', array('id' => $row['post_id'])) : '',
			'U_MCP_REPORT'		=> ($auth->acl_get('m_report', $forum_id)) ? append_sid("{$phpbb_root_path}mcp.$phpEx", 'i=reports&amp;mode=report_details&amp;p=' . $row['post_id']) : '',
			'U_MCP_APPROVE'		=> ($auth->acl_get('m_approve', $forum_id)) ? append_sid("{$phpbb_root_path}mcp.$phpEx", 'i=queue&amp;mode=approve_details&amp;p=' . $row['post_id']) : '',
			'U_MCP_RESTORE'		=> ($auth->acl_get('m_approve', $forum_id)) ? append_sid("{$phpbb_root_path}mcp.$phpEx", 'i=queue&amp;mode=' . (($topic_data['topic_visibility'] != ITEM_DELETED) ? 'deleted_posts' : 'deleted_topics') . '&amp;p=' . $row['post_id']) : '',
			'U_MINI_POST'		=> append_sid("{$phpbb_root_path}viewtopic.$phpEx", 'p=' . $row['post_id']) . '#p' . $row['post_id'],
			'U_MINI_POST_VIEW'	=> append_sid("{$phpbb_root_path}viewtopic.$phpEx", 'p=' . $row['post_id']) . '&amp;view=show#p' . $row['post_id'],
			'U_NEXT_POST_ID'	=> ($i < $i_total && isset($rowset[$post_list[$i + 1]])) ? $rowset[$post_list[$i + 1]]['post_id'] : '',
			'U_PREV_POST_ID'	=> $prev_post_id,
			'U_NOTES'			=> ($auth->acl_getf_global('m_')) ? append_sid("{$phpbb_root_path}mcp.$phpEx", 'i=notes&amp;mode=user_notes&amp;u=' . $poster_id) : '',
			'U_WARN'			=> ($warn_allowed && $auth->acl_get('m_warn') && $poster_id != $user->data['user_id'] && $poster_id != ANONYMOUS) ? append_sid("{$phpbb_root_path}mcp.$phpEx", 'i=warn&amp;mode=warn_post&amp;p=' . $row['post_id']) : '',

			'POST_ID'			=> $row['post_id'],
			'POST_NUMBER'		=> $i + $start + 1,
			'POSTER_ID'			=> $poster_id,
			'MINI_POST'			=> ($post_unread) ? $user->lang['UNREAD_POST'] : $user->lang['POST'],


			'S_HAS_ATTACHMENTS'	=> (!empty($attachments[$row['post_id']])) ? true : false,
			'S_MULTIPLE_ATTACHMENTS'	=> !empty($attachments[$row['post_id']]) && count($attachments[$row['post_id']]) > 1,
			'S_POST_UNAPPROVED'	=> ($row['post_visibility'] == ITEM_UNAPPROVED || $row['post_visibility'] == ITEM_REAPPROVE) ? true : false,
			'S_CAN_APPROVE'		=> $auth->acl_get('m_approve', $forum_id),
			'S_POST_DELETED'	=> ($row['post_visibility'] == ITEM_DELETED) ? true : false,
			'L_POST_DELETED_MESSAGE'	=> $l_deleted_message,
			'S_POST_REPORTED'	=> ($row['post_reported'] && $auth->acl_get('m_report', $forum_id)) ? true : false,
			'S_DISPLAY_NOTICE'	=> $display_notice && $row['post_attachment'],
			'S_FRIEND'			=> ($row['friend']) ? true : false,
			'S_UNREAD_POST'		=> $post_unread,
			'S_FIRST_UNREAD'	=> $s_first_unread,
			'S_CUSTOM_FIELDS'	=> (isset($cp_row['row']) && count($cp_row['row'])) ? true : false,
			'S_TOPIC_POSTER'	=> ($topic_data['topic_poster'] == $poster_id) ? true : false,
			'S_FIRST_POST'		=> ($topic_data['topic_first_post_id'] == $row['post_id']) ? true : false,

			'S_IGNORE_POST'		=> ($row['foe']) ? true : false,
			'L_IGNORE_POST'		=> ($row['foe']) ? sprintf($user->lang['POST_BY_FOE'], get_username_string('full', $poster_id, $row['username'], $row['user_colour'], $row['post_username'])) : '',
			'S_POST_HIDDEN'		=> $row['hide_post'],
			'S_DELETE_PERMANENT'	=> $permanent_delete_allowed,
		);

		if ($user_cache[$poster_id]['avatar'])
		{
			$post_row += $avatar_helper->get_template_vars($user_cache[$poster_id]['avatar'], 'POSTER_');
		}

		$user_poster_data = $user_cache[$poster_id];

		$current_row_number = $i;

		/**
		* Modify the posts template block
		*
		* @event core.viewtopic_modify_post_row
		* @var	int		start				Start item of this page
		* @var	int		current_row_number	Number of the post on this page
		* @var	int		end					Number of posts on this page
		* @var	int		total_posts			Total posts count
		* @var	int		poster_id			Post author id
		* @var	array	row					Array with original post and user data
		* @var	array	cp_row				Custom profile field data of the poster
		* @var	array	attachments			List of attachments
		* @var	array	user_poster_data	Poster's data from user cache
		* @var	array	post_row			Template block array of the post
		* @var	array	topic_data			Array with topic data
		* @var	array	user_cache			Array with cached user data
		* @var	array	post_edit_list		Array with post edited list
		* @since 3.1.0-a1
		* @changed 3.1.0-a3 Added vars start, current_row_number, end, attachments
		* @changed 3.1.0-b3 Added topic_data array, total_posts
		* @changed 3.1.0-RC3 Added poster_id
		* @changed 3.2.2-RC1 Added user_cache and post_edit_list
		*/
		$vars = array(
			'start',
			'current_row_number',
			'end',
			'total_posts',
			'poster_id',
			'row',
			'cp_row',
			'attachments',
			'user_poster_data',
			'post_row',
			'topic_data',
			'user_cache',
			'post_edit_list',
		);
		extract($phpbb_dispatcher->trigger_event('core.viewtopic_modify_post_row', compact($vars)));

		$i = $current_row_number;

		if (isset($cp_row['row']) && count($cp_row['row']))
		{
			$post_row = array_merge($post_row, $cp_row['row']);
		}

		// Dump vars into template
		$template->assign_block_vars('postrow', $post_row);

		$contact_fields = array(
			array(
				'ID'		=> 'pm',
				'NAME' 		=> $user->lang['SEND_PRIVATE_MESSAGE'],
				'U_CONTACT'	=> $post_row['U_PM'],
			),
			array(
				'ID'		=> 'email',
				'NAME'		=> $user->lang['SEND_EMAIL'],
				'U_CONTACT'	=> $user_cache[$poster_id]['email'],
			),
		);

		foreach ($contact_fields as $field)
		{
			if ($field['U_CONTACT'])
			{
				$template->assign_block_vars('postrow.contact', $field);
			}
		}

		if (!empty($cp_row['blockrow']))
		{
			foreach ($cp_row['blockrow'] as $field_data)
			{
				$template->assign_block_vars('postrow.custom_fields', $field_data);

				if ($field_data['S_PROFILE_CONTACT'])
				{
					$template->assign_block_vars('postrow.contact', array(
						'ID'		=> $field_data['PROFILE_FIELD_IDENT'],
						'NAME'		=> $field_data['PROFILE_FIELD_NAME'],
						'U_CONTACT'	=> $field_data['PROFILE_FIELD_CONTACT'],
					));
				}
			}
		}

		// Display not already displayed Attachments for this post, we already parsed them. ;)
		if (!empty($attachments[$row['post_id']]))
		{
			foreach ($attachments[$row['post_id']] as $attachment)
			{
				$template->assign_block_vars('postrow.attachment', array(
					'DISPLAY_ATTACHMENT'	=> $attachment)
				);
			}
		}

		$current_row_number = $i;

		/**
		* Event after the post data has been assigned to the template
		*
		* @event core.viewtopic_post_row_after
		* @var	int		start				Start item of this page
		* @var	int		current_row_number	Number of the post on this page
		* @var	int		end					Number of posts on this page
		* @var	int		total_posts			Total posts count
		* @var	array	row					Array with original post and user data
		* @var	array	cp_row				Custom profile field data of the poster
		* @var	array	attachments			List of attachments
		* @var	array	user_poster_data	Poster's data from user cache
		* @var	array	post_row			Template block array of the post
		* @var	array	topic_data			Array with topic data
		* @since 3.1.0-a3
		* @changed 3.1.0-b3 Added topic_data array, total_posts
		*/
		$vars = array(
			'start',
			'current_row_number',
			'end',
			'total_posts',
			'row',
			'cp_row',
			'attachments',
			'user_poster_data',
			'post_row',
			'topic_data',
		);
		extract($phpbb_dispatcher->trigger_event('core.viewtopic_post_row_after', compact($vars)));

		$i = $current_row_number;

		$prev_post_id = $row['post_id'];

		unset($rowset[$post_list[$i]]);
		unset($attachments[$row['post_id']]);
	}
	unset($rowset, $user_cache);

		return [
			'forum_id' => (int) $forum_id,
			'topic_id' => (int) $topic_id,
			'topic_data' => $topic_data,
			'start' => (int) $start,
			'update_count' => $update_count,
			'post_unread' => (bool) $post_unread,
		];
	}
}

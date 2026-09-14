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
use phpbb\event\dispatcher_interface;
use phpbb\pagination;
use phpbb\template\template;
use phpbb\user;

/**
 * Builds and assigns the topic rows displayed by viewforum.
 *
 * Topic tracking, row preparation and Twig block assignment are kept outside
 * the page controller while preserving the legacy viewforum extension events.
 */
class viewforum_topic_renderer
{
	/** @var auth */
	protected $auth;
	/** @var cache_service */
	protected $cache;
	/** @var config */
	protected $config;
	/** @var content_visibility */
	protected $content_visibility;
	/** @var dispatcher_interface */
	protected $dispatcher;
	/** @var pagination */
	protected $pagination;
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
		cache_service $cache,
		config $config,
		content_visibility $content_visibility,
		dispatcher_interface $dispatcher,
		pagination $pagination,
		template $template,
		user $user,
		string $phpbb_root_path,
		string $php_ext
	)
	{
		$this->auth = $auth;
		$this->cache = $cache;
		$this->config = $config;
		$this->content_visibility = $content_visibility;
		$this->dispatcher = $dispatcher;
		$this->pagination = $pagination;
		$this->template = $template;
		$this->user = $user;
		$this->phpbb_root_path = $phpbb_root_path;
		$this->php_ext = $php_ext;
	}

	/**
	 * Render all topic rows for the current viewforum page.
	 *
	 * @return array{
	 *     forum_id: int,
	 *     topic_list: array,
	 *     mark_forum_read: bool,
	 *     mark_time_forum: int
	 * }
	 */
	public function render(
		array $forum_data,
		int $forum_id,
		bool $s_display_active,
		array $rowset,
		array $topic_list,
		array $forum_tracking_info,
		int $total_topic_count
	): array
	{
		$auth = $this->auth;
		$cache = $this->cache;
		$config = $this->config;
		$phpbb_content_visibility = $this->content_visibility;
		$phpbb_dispatcher = $this->dispatcher;
		$pagination = $this->pagination;
		$template = $this->template;
		$user = $this->user;
		$phpbb_root_path = $this->phpbb_root_path;
		$phpEx = $this->php_ext;

		/**
		* Modify topics data before we display the viewforum page
		*
		* @event core.viewforum_modify_topics_data
		* @var array topic_list          Array with current viewforum page topic ids
		* @var array rowset              Array with topics data (in topic_id => topic_data format)
		* @var int   total_topic_count   Forum's total topic count
		* @var int   forum_id            Forum identifier
		* @since 3.1.0-b3
		* @changed 3.1.11-RC1 Added forum_id
		*/
		$vars = array('topic_list', 'rowset', 'total_topic_count', 'forum_id');
		extract($phpbb_dispatcher->trigger_event('core.viewforum_modify_topics_data', compact($vars)));

		$mark_forum_read = false;
		$mark_time_forum = 0;

		if (count($topic_list))
		{
			$mark_forum_read = true;
			$topic_tracking_info = $tracking_topics = array();

			// Generate topic forum list and calculate read tracking once per forum.
			$topic_forum_list = array();
			foreach ($rowset as $t_id => $row)
			{
				if (isset($forum_tracking_info[$row['forum_id']]))
				{
					$row['forum_mark_time'] = $forum_tracking_info[$row['forum_id']];
				}

				$topic_forum_list[$row['forum_id']]['forum_mark_time'] = ($config['load_db_lastread'] && $user->data['is_registered'] && isset($row['forum_mark_time'])) ? $row['forum_mark_time'] : 0;
				$topic_forum_list[$row['forum_id']]['topics'][] = (int) $t_id;
			}

			if ($config['load_db_lastread'] && $user->data['is_registered'])
			{
				foreach ($topic_forum_list as $f_id => $topic_row)
				{
					$topic_tracking_info += get_topic_tracking($f_id, $topic_row['topics'], $rowset, array($f_id => $topic_row['forum_mark_time']));
				}
			}
			else if ($config['load_anon_lastread'] || $user->data['is_registered'])
			{
				foreach ($topic_forum_list as $f_id => $topic_row)
				{
					$topic_tracking_info += get_complete_topic_tracking($f_id, $topic_row['topics']);
				}
			}

			if (!$s_display_active)
			{
				if ($config['load_db_lastread'] && $user->data['is_registered'])
				{
					$mark_time_forum = (!empty($forum_data['mark_time'])) ? $forum_data['mark_time'] : $user->data['user_lastmark'];
				}
				else if ($config['load_anon_lastread'] || $user->data['is_registered'])
				{
					if (!$user->data['is_registered'])
					{
						$user->data['user_lastmark'] = (isset($tracking_topics['l'])) ? (int) (base_convert($tracking_topics['l'], 36, 10) + $config['board_startdate']) : 0;
					}
					$mark_time_forum = (isset($tracking_topics['f'][$forum_id])) ? (int) (base_convert($tracking_topics['f'][$forum_id], 36, 10) + $config['board_startdate']) : $user->data['user_lastmark'];
				}
			}

			$icons = $cache->obtain_icons();
			$s_type_switch = 0;

			foreach ($topic_list as $topic_id)
			{
				$row = &$rowset[$topic_id];

				$topic_forum_id = ($row['forum_id']) ? (int) $row['forum_id'] : $forum_id;
				$s_type_switch_test = ($row['topic_type'] == POST_ANNOUNCE || $row['topic_type'] == POST_GLOBAL) ? 1 : 0;

				$replies = $phpbb_content_visibility->get_count('topic_posts', $row, $topic_forum_id) - 1;
				if ($replies < 0)
				{
					$replies = 0;
				}

				if ($row['topic_status'] == ITEM_MOVED)
				{
					$topic_id = $row['topic_moved_id'];
					$unread_topic = false;
				}
				else
				{
					$unread_topic = (isset($topic_tracking_info[$topic_id]) && $row['topic_last_post_time'] > $topic_tracking_info[$topic_id]) ? true : false;
				}

				$folder_img = $folder_alt = $topic_type = '';
				topic_status($row, $replies, $unread_topic, $folder_img, $folder_alt, $topic_type);

				$view_topic_url_params = 't=' . $topic_id;
				$view_topic_url = $auth->acl_get('f_read', $forum_id) ? append_sid("{$phpbb_root_path}viewtopic.$phpEx", $view_topic_url_params) : false;

				$topic_unapproved = (($row['topic_visibility'] == ITEM_UNAPPROVED || $row['topic_visibility'] == ITEM_REAPPROVE) && $auth->acl_get('m_approve', $row['forum_id']));
				$posts_unapproved = ($row['topic_visibility'] == ITEM_APPROVED && $row['topic_posts_unapproved'] && $auth->acl_get('m_approve', $row['forum_id']));
				$topic_deleted = $row['topic_visibility'] == ITEM_DELETED;

				$u_mcp_queue = ($topic_unapproved || $posts_unapproved) ? append_sid("{$phpbb_root_path}mcp.$phpEx", 'i=queue&amp;mode=' . (($topic_unapproved) ? 'approve_details' : 'unapproved_posts') . "&amp;t=$topic_id") : '';
				$u_mcp_queue = (!$u_mcp_queue && $topic_deleted) ? append_sid("{$phpbb_root_path}mcp.$phpEx", 'i=queue&amp;mode=deleted_topics&amp;t=' . $topic_id) : $u_mcp_queue;

				$topic_row = array(
					'FORUM_ID'                  => $row['forum_id'],
					'TOPIC_ID'                  => $topic_id,
					'TOPIC_AUTHOR'              => get_username_string('username', $row['topic_poster'], $row['topic_first_poster_name'], $row['topic_first_poster_colour']),
					'TOPIC_AUTHOR_COLOUR'       => get_username_string('colour', $row['topic_poster'], $row['topic_first_poster_name'], $row['topic_first_poster_colour']),
					'TOPIC_AUTHOR_FULL'         => get_username_string('full', $row['topic_poster'], $row['topic_first_poster_name'], $row['topic_first_poster_colour']),
					'FIRST_POST_TIME'           => $user->format_date($row['topic_time']),
					'FIRST_POST_TIME_RFC3339'   => gmdate(DATE_RFC3339, $row['topic_time']),
					'LAST_POST_SUBJECT'         => censor_text($row['topic_last_post_subject']),
					'LAST_POST_TIME'            => $user->format_date($row['topic_last_post_time']),
					'LAST_POST_TIME_RFC3339'    => gmdate(DATE_RFC3339, $row['topic_last_post_time']),
					'LAST_VIEW_TIME'            => $user->format_date($row['topic_last_view_time']),
					'LAST_VIEW_TIME_RFC3339'    => gmdate(DATE_RFC3339, $row['topic_last_view_time']),
					'LAST_POST_AUTHOR'          => get_username_string('username', $row['topic_last_poster_id'], $row['topic_last_poster_name'], $row['topic_last_poster_colour']),
					'LAST_POST_AUTHOR_COLOUR'   => get_username_string('colour', $row['topic_last_poster_id'], $row['topic_last_poster_name'], $row['topic_last_poster_colour']),
					'LAST_POST_AUTHOR_FULL'     => get_username_string('full', $row['topic_last_poster_id'], $row['topic_last_poster_name'], $row['topic_last_poster_colour']),

					'REPLIES'                   => $replies,
					'VIEWS'                     => $row['topic_views'],
					'TOPIC_TITLE'               => censor_text($row['topic_title']),
					'TOPIC_TYPE'                => $topic_type,
					'FORUM_NAME'                => (isset($row['forum_name'])) ? $row['forum_name'] : $forum_data['forum_name'],

					'TOPIC_IMG_STYLE'           => $folder_img,
					'TOPIC_FOLDER_IMG'          => $user->img($folder_img, $folder_alt),
					'TOPIC_FOLDER_IMG_ALT'      => $user->lang[$folder_alt],

					'TOPIC_ICON_IMG'            => (!empty($icons[$row['icon_id']])) ? $icons[$row['icon_id']]['img'] : '',
					'TOPIC_ICON_IMG_WIDTH'      => (!empty($icons[$row['icon_id']])) ? $icons[$row['icon_id']]['width'] : '',
					'TOPIC_ICON_IMG_HEIGHT'     => (!empty($icons[$row['icon_id']])) ? $icons[$row['icon_id']]['height'] : '',
					'ATTACH_ICON_IMG'           => ($auth->acl_get('u_download') && $auth->acl_get('f_download', $row['forum_id']) && $row['topic_attachment']) ? $user->img('icon_topic_attach', $user->lang['TOTAL_ATTACHMENTS']) : '',
					'UNAPPROVED_IMG'            => ($topic_unapproved || $posts_unapproved) ? $user->img('icon_topic_unapproved', ($topic_unapproved) ? 'TOPIC_UNAPPROVED' : 'POSTS_UNAPPROVED') : '',

					'S_TOPIC_TYPE'              => $row['topic_type'],
					'S_USER_POSTED'             => isset($row['topic_posted']) && $row['topic_posted'],
					'S_UNREAD_TOPIC'            => $unread_topic,
					'S_TOPIC_REPORTED'          => !empty($row['topic_reported']) && $auth->acl_get('m_report', $row['forum_id']),
					'S_TOPIC_UNAPPROVED'        => $topic_unapproved,
					'S_POSTS_UNAPPROVED'        => $posts_unapproved,
					'S_TOPIC_DELETED'           => $topic_deleted,
					'S_HAS_POLL'                => (bool) $row['poll_start'],
					'S_POST_ANNOUNCE'           => $row['topic_type'] == POST_ANNOUNCE,
					'S_POST_GLOBAL'             => $row['topic_type'] == POST_GLOBAL,
					'S_POST_STICKY'             => $row['topic_type'] == POST_STICKY,
					'S_TOPIC_LOCKED'            => $row['topic_status'] == ITEM_LOCKED,
					'S_TOPIC_MOVED'             => $row['topic_status'] == ITEM_MOVED,
					'S_TOPIC_HOT'               => $config['hot_threshold'] && ($replies + 1) >= $config['hot_threshold'] && $row['topic_status'] != ITEM_LOCKED,

					'U_NEWEST_POST'             => $auth->acl_get('f_read', $forum_id) ? append_sid("{$phpbb_root_path}viewtopic.$phpEx", $view_topic_url_params . '&amp;view=unread') . '#unread' : false,
					'U_LAST_POST'               => $auth->acl_get('f_read', $forum_id) ? append_sid("{$phpbb_root_path}viewtopic.$phpEx", 'p=' . $row['topic_last_post_id']) . '#p' . $row['topic_last_post_id'] : false,
					'U_LAST_POST_AUTHOR'        => get_username_string('profile', $row['topic_last_poster_id'], $row['topic_last_poster_name'], $row['topic_last_poster_colour']),
					'U_TOPIC_AUTHOR'            => get_username_string('profile', $row['topic_poster'], $row['topic_first_poster_name'], $row['topic_first_poster_colour']),
					'U_VIEW_TOPIC'              => $view_topic_url,
					'U_VIEW_FORUM'              => append_sid("{$phpbb_root_path}viewforum.$phpEx", 'f=' . $row['forum_id']),
					'U_MCP_REPORT'              => append_sid("{$phpbb_root_path}mcp.$phpEx", 'i=reports&amp;mode=reports&amp;t=' . $topic_id),
					'U_MCP_QUEUE'               => $u_mcp_queue,

					'S_TOPIC_TYPE_SWITCH'       => ($s_type_switch == $s_type_switch_test) ? -1 : $s_type_switch_test,
				);

				/**
				* Modify the topic data before it is assigned to the template
				*
				* @event core.viewforum_modify_topicrow
				* @var array row                Array with topic data
				* @var array topic_row          Template array with topic data
				* @var bool  s_type_switch      Flag indicating if the topic type is [global] announcement
				* @var bool  s_type_switch_test Flag indicating if the test topic type is [global] announcement
				* @since 3.1.0-a1
				* @changed 3.1.10-RC1 Added s_type_switch, s_type_switch_test
				*/
				$vars = array('row', 'topic_row', 's_type_switch', 's_type_switch_test');
				extract($phpbb_dispatcher->trigger_event('core.viewforum_modify_topicrow', compact($vars)));

				$template->assign_block_vars('topicrow', $topic_row);
				$pagination->generate_template_pagination($topic_row['U_VIEW_TOPIC'], 'topicrow.pagination', 'start', (int) $topic_row['REPLIES'] + 1, $config['posts_per_page'], 1, true, true);

				$s_type_switch = ($row['topic_type'] == POST_ANNOUNCE || $row['topic_type'] == POST_GLOBAL) ? 1 : 0;

				/**
				* Event after the topic data has been assigned to the template
				*
				* @event core.viewforum_topic_row_after
				* @var array row           Array with the topic data
				* @var array rowset        Array with topics data (in topic_id => topic_data format)
				* @var bool  s_type_switch Flag indicating if the topic type is [global] announcement
				* @var int   topic_id      The topic ID
				* @var array topic_list    Array with current viewforum page topic ids
				* @var array topic_row     Template array with topic data
				* @since 3.1.3-RC1
				*/
				$vars = array(
					'row',
					'rowset',
					's_type_switch',
					'topic_id',
					'topic_list',
					'topic_row',
				);
				extract($phpbb_dispatcher->trigger_event('core.viewforum_topic_row_after', compact($vars)));

				if ($unread_topic)
				{
					$mark_forum_read = false;
				}

				unset($rowset[$topic_id]);
			}
		}

		return array(
			'forum_id' => (int) $forum_id,
			'topic_list' => $topic_list,
			'mark_forum_read' => $mark_forum_read,
			'mark_time_forum' => (int) $mark_time_forum,
		);
	}
}

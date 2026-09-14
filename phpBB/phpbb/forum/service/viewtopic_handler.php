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
use phpbb\event\dispatcher_interface;
use phpbb\forum\data\viewtopic_repository;
use phpbb\pagination;
use phpbb\request\request_interface;
use phpbb\user;

/**
 * Application handler for a viewtopic request.
 *
 * The handler owns request-level orchestration while focused services retain
 * navigation, topic lookup, page preparation, post loading, rendering, polls
 * and final page completion. The public legacy entry point is kept intact.
 */
class viewtopic_handler
{
	/** @var auth */
	protected $auth;
	/** @var config */
	protected $config;
	/** @var content_visibility */
	protected $content_visibility;
	/** @var dispatcher_interface */
	protected $dispatcher;
	/** @var pagination */
	protected $pagination;
	/** @var request_interface */
	protected $request;
	/** @var user */
	protected $user;
	/** @var viewtopic_navigation */
	protected $navigation;
	/** @var viewtopic_repository */
	protected $repository;
	/** @var viewtopic_page */
	protected $page_service;
	/** @var viewtopic_poll */
	protected $poll_service;
	/** @var viewtopic_posts */
	protected $posts_service;
	/** @var viewtopic_post_renderer */
	protected $post_renderer;
	/** @var viewtopic_completion */
	protected $completion_service;
	/** @var string */
	protected $root_path;
	/** @var string */
	protected $php_ext;

	public function __construct(
		auth $auth,
		config $config,
		content_visibility $content_visibility,
		dispatcher_interface $dispatcher,
		pagination $pagination,
		request_interface $request,
		user $user,
		viewtopic_navigation $navigation,
		viewtopic_repository $repository,
		viewtopic_page $page_service,
		viewtopic_poll $poll_service,
		viewtopic_posts $posts_service,
		viewtopic_post_renderer $post_renderer,
		viewtopic_completion $completion_service,
		string $root_path,
		string $php_ext
	)
	{
		$this->auth = $auth;
		$this->config = $config;
		$this->content_visibility = $content_visibility;
		$this->dispatcher = $dispatcher;
		$this->pagination = $pagination;
		$this->request = $request;
		$this->user = $user;
		$this->navigation = $navigation;
		$this->repository = $repository;
		$this->page_service = $page_service;
		$this->poll_service = $poll_service;
		$this->posts_service = $posts_service;
		$this->post_renderer = $post_renderer;
		$this->completion_service = $completion_service;
		$this->root_path = $root_path;
		$this->php_ext = $php_ext;
	}

	public function handle(): void
	{
		$auth = $this->auth;
		$config = $this->config;
		$phpbb_content_visibility = $this->content_visibility;
		$phpbb_dispatcher = $this->dispatcher;
		$pagination = $this->pagination;
		$request = $this->request;
		$user = $this->user;
		$viewtopic_navigation = $this->navigation;
		$viewtopic_repository = $this->repository;
		$viewtopic_page = $this->page_service;
		$viewtopic_poll = $this->poll_service;
		$viewtopic_posts = $this->posts_service;
		$viewtopic_post_renderer = $this->post_renderer;
		$viewtopic_completion = $this->completion_service;
		$phpbb_root_path = $this->root_path;
		$phpEx = $this->php_ext;

		// Start session management and initialise permissions.
		$user->session_begin();
		$auth->acl($user->data);

		// Initial request data.
		$forum_id = 0;
		$topic_id = $request->variable('t', 0);
		$post_id = $request->variable('p', 0);
		$voted_id = $request->variable('vote_id', ['' => 0]);
		$voted_id = (count($voted_id) > 1) ? array_unique($voted_id) : $voted_id;

		$start = $request->variable('start', 0);
		$view = $request->variable('view', '');

		$default_sort_days = (!empty($user->data['user_post_show_days'])) ? $user->data['user_post_show_days'] : 0;
		$default_sort_key = (!empty($user->data['user_post_sortby_type'])) ? $user->data['user_post_sortby_type'] : 't';
		$default_sort_dir = (!empty($user->data['user_post_sortby_dir'])) ? $user->data['user_post_sortby_dir'] : 'a';

		$sort_days = $request->variable('st', $default_sort_days);
		$sort_key = $request->variable('sk', $default_sort_key);
		$sort_dir = $request->variable('sd', $default_sort_dir);
		$update = $request->variable('update', false);

		$s_can_vote = false;
		$hilit_words = $request->variable('hilit', '', true);

		if (!$topic_id && !$post_id)
		{
			\trigger_error('NO_TOPIC');
		}

		// Resolve special navigation requests before loading the main topic context.
		$navigation = $viewtopic_navigation->resolve($view, $topic_id, $post_id, $forum_id);
		$forum_id = $navigation['forum_id'];
		$topic_id = $navigation['topic_id'];
		$post_id = $navigation['post_id'];
		if ($navigation['topic_tracking_info'] !== null)
		{
			$topic_tracking_info = $navigation['topic_tracking_info'];
		}

		// Load topic/forum context and per-user watch, bookmark and tracking data.
		$topic_data = $viewtopic_repository->get_topic_data(
			$topic_id,
			$post_id,
			(bool) $user->data['is_registered'],
			(int) $user->data['user_id'],
			(bool) $config['allow_bookmarks'],
			(bool) $config['load_db_lastread']
		);

		if (!$topic_data)
		{
			if ($post_id && $topic_id)
			{
				\redirect(\append_sid("{$phpbb_root_path}viewtopic.$phpEx", "t=$topic_id"));
			}

			\trigger_error('NO_TOPIC');
		}

		$forum_id = (int) $topic_data['forum_id'];

		/**
		 * Modify the forum ID to handle the correct display of viewtopic if needed.
		 *
		 * @event core.viewtopic_modify_forum_id
		 * @var string forum_id forum ID
		 * @var array topic_data array of topic's data
		 * @since 3.2.5-RC1
		 */
		$vars = ['forum_id', 'topic_data'];
		extract($phpbb_dispatcher->trigger_event('core.viewtopic_modify_forum_id', compact($vars)));

		// Keep Who Is Online aware of the real forum even when f= is omitted.
		$user->page['forum'] = $forum_id;

		if (!$phpbb_content_visibility->is_visible('topic', $forum_id, $topic_data))
		{
			\trigger_error('NO_TOPIC');
		}

		if ($post_id)
		{
			if (($topic_data['post_visibility'] == ITEM_UNAPPROVED || $topic_data['post_visibility'] == ITEM_REAPPROVE) && !$auth->acl_get('m_approve', $topic_data['forum_id']))
			{
				if ($topic_id)
				{
					\redirect(\append_sid("{$phpbb_root_path}viewtopic.$phpEx", "t=$topic_id"));
				}

				\trigger_error('NO_TOPIC');
			}

			$topic_data['prev_posts'] = $viewtopic_repository->get_previous_post_count($topic_data, $forum_id, $post_id, $sort_dir);
		}

		$topic_id = (int) $topic_data['topic_id'];
		$topic_replies = $phpbb_content_visibility->get_count('topic_posts', $topic_data, $forum_id) - 1;

		// Expired sticky/announcement topics become normal topics exactly as before.
		if (($topic_data['topic_type'] != POST_NORMAL) && $topic_data['topic_time_limit'] && ($topic_data['topic_time'] + $topic_data['topic_time_limit']) < time())
		{
			$viewtopic_repository->expire_timed_topic($topic_id);
			$topic_data['topic_type'] = POST_NORMAL;
			$topic_data['topic_time_limit'] = 0;
		}

		// Prepare access, tracking, sorting, moderation, navigation and page data.
		$page_data = $viewtopic_page->prepare(
			$topic_data,
			$forum_id,
			$topic_id,
			$post_id,
			$view,
			$start,
			$sort_days,
			$sort_key,
			$sort_dir,
			$default_sort_days,
			$default_sort_key,
			$default_sort_dir,
			$topic_replies,
			$hilit_words,
			isset($topic_tracking_info) ? $topic_tracking_info : null
		);

		$forum_id = $page_data['forum_id'];
		$topic_id = $page_data['topic_id'];
		$post_id = $page_data['post_id'];
		$topic_data = $page_data['topic_data'];
		$topic_tracking_info = $page_data['topic_tracking_info'];
		$start = $page_data['start'];
		$sort_days = $page_data['sort_days'];
		$sort_key = $page_data['sort_key'];
		$sort_dir = $page_data['sort_dir'];
		$sort_by_sql = $page_data['sort_by_sql'];
		$join_user_sql = $page_data['join_user_sql'];
		$total_posts = $page_data['total_posts'];
		$limit_posts_time = $page_data['limit_posts_time'];
		$highlight_match = $page_data['highlight_match'];
		$viewtopic_url = $page_data['viewtopic_url'];
		$s_watching_topic = $page_data['s_watching_topic'];
		$icons = $page_data['icons'];

		// Poll loading, voting and template rendering.
		$poll_data = $viewtopic_poll->process(
			$topic_data,
			$forum_id,
			$topic_id,
			$view,
			$start,
			$viewtopic_url,
			$voted_id,
			$update
		);

		$forum_id = $poll_data['forum_id'];
		$topic_id = $poll_data['topic_id'];
		$topic_data = $poll_data['topic_data'];
		$s_can_vote = $poll_data['s_can_vote'];

		// If the user is trying to reach the second half, fetch starting from the end.
		$store_reverse = false;
		$sql_limit = $config['posts_per_page'];
		$sql_sort_order = $direction = '';

		if ($start > $total_posts / 2)
		{
			$store_reverse = true;
			$direction = (($sort_dir == 'd') ? 'ASC' : 'DESC');
			$sql_limit = $pagination->reverse_limit($start, $sql_limit, $total_posts);
			$sql_start = $pagination->reverse_start($start, $sql_limit, $total_posts);
		}
		else
		{
			$direction = (($sort_dir == 'd') ? 'DESC' : 'ASC');
			$sql_start = $start;
		}

		if (is_array($sort_by_sql[$sort_key]))
		{
			$sql_sort_order = implode(' ' . $direction . ', ', $sort_by_sql[$sort_key]) . ' ' . $direction;
		}
		else
		{
			$sql_sort_order = $sort_by_sql[$sort_key] . ' ' . $direction;
		}

		// Load the visible post list, post rows and cached poster data.
		$post_data = $viewtopic_posts->load(
			$forum_id,
			$topic_id,
			$topic_data,
			$post_id,
			$view,
			$start,
			$sort_days,
			$sort_key,
			$sort_dir,
			$sql_limit,
			$sql_start,
			$sql_sort_order,
			$store_reverse,
			$limit_posts_time,
			(bool) $join_user_sql[$sort_key],
			$icons
		);

		$forum_id = $post_data['forum_id'];
		$topic_id = $post_data['topic_id'];
		$topic_data = $post_data['topic_data'];
		$post_list = $post_data['post_list'];
		$rowset = $post_data['rowset'];
		$user_cache = $post_data['user_cache'];
		$attach_list = $post_data['attach_list'];
		$has_unapproved_attachments = $post_data['has_unapproved_attachments'];
		$has_approved_attachments = $post_data['has_approved_attachments'];
		$max_post_time = $post_data['max_post_time'];
		$profile_fields_cache = $post_data['profile_fields_cache'];
		$start = $post_data['start'];
		$sort_days = $post_data['sort_days'];
		$sort_key = $post_data['sort_key'];
		$sort_dir = $post_data['sort_dir'];

		// Render attachments and post rows through the dedicated renderer.
		$render_data = $viewtopic_post_renderer->render([
			'forum_id' => $forum_id,
			'topic_id' => $topic_id,
			'topic_data' => $topic_data,
			'post_list' => $post_list,
			'rowset' => $rowset,
			'user_cache' => $user_cache,
			'attach_list' => $attach_list,
			'has_unapproved_attachments' => $has_unapproved_attachments,
			'has_approved_attachments' => $has_approved_attachments,
			'profile_fields_cache' => $profile_fields_cache,
			'start' => $start,
			'sort_days' => $sort_days,
			'sort_key' => $sort_key,
			'sort_dir' => $sort_dir,
			'total_posts' => $total_posts,
			'store_reverse' => $store_reverse,
			'highlight_match' => $highlight_match,
			'topic_tracking_info' => $topic_tracking_info,
			'icons' => $icons,
			'view' => $view,
			'viewtopic_url' => $viewtopic_url,
		]);

		// Final tracking, counters, quick reply and page output.
		$viewtopic_completion->complete(
			$render_data,
			$topic_tracking_info,
			$max_post_time,
			$total_posts,
			$s_can_vote,
			$s_watching_topic,
			$post_list,
			$view
		);
	}
}

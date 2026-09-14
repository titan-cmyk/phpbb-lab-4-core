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
use phpbb\forum\data\viewforum_repository;
use phpbb\request\request_interface;
use phpbb\user;

/**
 * Application handler for a viewforum request.
 *
 * It owns the request-level orchestration while the HTTP controller remains a
 * minimal entry point. Focused services continue to own page preparation,
 * topic retrieval and topic rendering.
 */
class viewforum_handler
{
	/** @var auth */
	protected $auth;
	/** @var config */
	protected $config;
	/** @var request_interface */
	protected $request;
	/** @var user */
	protected $user;
	/** @var viewforum_repository */
	protected $forum_repository;
	/** @var viewforum_page */
	protected $page_service;
	/** @var viewforum_topics */
	protected $topics_service;
	/** @var viewforum_topic_renderer */
	protected $topic_renderer;

	public function __construct(
		auth $auth,
		config $config,
		request_interface $request,
		user $user,
		viewforum_repository $forum_repository,
		viewforum_page $page_service,
		viewforum_topics $topics_service,
		viewforum_topic_renderer $topic_renderer
	)
	{
		$this->auth = $auth;
		$this->config = $config;
		$this->request = $request;
		$this->user = $user;
		$this->forum_repository = $forum_repository;
		$this->page_service = $page_service;
		$this->topics_service = $topics_service;
		$this->topic_renderer = $topic_renderer;
	}

	public function handle(): void
	{
		$auth = $this->auth;
		$config = $this->config;
		$request = $this->request;
		$user = $this->user;
		$forum_repository = $this->forum_repository;
		$viewforum_page = $this->page_service;
		$viewforum_topics = $this->topics_service;
		$viewforum_topic_renderer = $this->topic_renderer;

		// Start session and initialise permissions for the current user.
		$user->session_begin();
		$auth->acl($user->data);

		// Initial request data.
		$forum_id = $request->variable('f', 0);
		$start = $request->variable('start', 0);

		$default_sort_days = (!empty($user->data['user_topic_show_days'])) ? $user->data['user_topic_show_days'] : 0;
		$default_sort_key = (!empty($user->data['user_topic_sortby_type'])) ? $user->data['user_topic_sortby_type'] : 't';
		$default_sort_dir = (!empty($user->data['user_topic_sortby_dir'])) ? $user->data['user_topic_sortby_dir'] : 'd';

		$sort_days = $request->variable('st', $default_sort_days);
		$sort_key = $request->variable('sk', $default_sort_key);
		$sort_dir = $request->variable('sd', $default_sort_dir);

		if (!$forum_id)
		{
			trigger_error('NO_FORUM');
		}

		$forum_data = $forum_repository->get_forum_by_id(
			$forum_id,
			$config['load_db_lastread'] && $user->data['is_registered'],
			$user->data['is_registered'],
			(int) $user->data['user_id']
		);

		if (!$forum_data)
		{
			trigger_error('NO_FORUM');
		}

		// Configure style, language, etc. before page-level access handling.
		$user->setup('viewforum', $forum_data['forum_style']);

		$page_data = $viewforum_page->prepare(
			$forum_data,
			$forum_id,
			$start,
			$sort_days,
			$sort_key,
			$sort_dir,
			$default_sort_days,
			$default_sort_key,
			$default_sort_dir
		);

		if (!empty($page_data['stop']))
		{
			return;
		}

		$forum_data = $page_data['forum_data'];
		$forum_id = $page_data['forum_id'];
		$start = $page_data['start'];
		$sort_days = $page_data['sort_days'];
		$sort_key = $page_data['sort_key'];
		$sort_dir = $page_data['sort_dir'];
		$topics_count = $page_data['topics_count'];
		$active_forum_ary = $page_data['active_forum_ary'];
		$sort_by_sql = $page_data['sort_by_sql'];
		$sql_limit_time = $page_data['sql_limit_time'];
		$s_display_active = $page_data['s_display_active'];
		$u_sort_param = $page_data['u_sort_param'];

		// SQL retrieval, announcements, tracking and shadow-topic resolution.
		$topic_data = $viewforum_topics->load(
			$forum_data,
			$forum_id,
			$topics_count,
			$sort_days,
			$sort_key,
			$sort_dir,
			$sort_by_sql,
			$sql_limit_time,
			$s_display_active,
			$active_forum_ary,
			$start
		);

		$forum_data = $topic_data['forum_data'];
		$forum_id = $topic_data['forum_id'];
		$topics_count = $topic_data['topics_count'];
		$rowset = $topic_data['rowset'];
		$announcement_list = $topic_data['announcement_list'];
		$topic_list = $topic_data['topic_list'];
		$forum_tracking_info = $topic_data['forum_tracking_info'];
		$store_reverse = $topic_data['store_reverse'];

		$topic_page = $viewforum_page->prepare_topic_page(
			$forum_id,
			$s_display_active,
			$topics_count,
			$announcement_list,
			$topic_list,
			$store_reverse,
			$u_sort_param,
			$start
		);

		$topic_list = $topic_page['topic_list'];
		$total_topic_count = $topic_page['total_topic_count'];

		$render_result = $viewforum_topic_renderer->render(
			$forum_data,
			$forum_id,
			$s_display_active,
			$rowset,
			$topic_list,
			$forum_tracking_info,
			$total_topic_count
		);

		$viewforum_page->complete(
			$forum_data,
			$render_result['forum_id'],
			$render_result['topic_list'],
			$render_result['mark_forum_read'],
			$render_result['mark_time_forum']
		);
	}
}

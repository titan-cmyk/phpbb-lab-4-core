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
use phpbb\db\driver\driver_interface;
use phpbb\event\dispatcher_interface;
use phpbb\pagination;
use phpbb\request\request_interface;
use phpbb\template\template;
use phpbb\user;

/**
 * Completes a viewtopic request after post rendering.
 *
 * Topic/attachment counters, read tracking, unread links, quick reply and
 * final page output are intentionally kept together as the finalisation phase.
 */
class viewtopic_completion
{
	protected $auth;
	protected $config;
	protected $db;
	protected $dispatcher;
	protected $pagination;
	protected $request;
	protected $template;
	protected $user;
	protected $root_path;
	protected $php_ext;

	public function __construct(
		auth $auth,
		config $config,
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
		$this->config = $config;
		$this->db = $db;
		$this->dispatcher = $dispatcher;
		$this->pagination = $pagination;
		$this->request = $request;
		$this->template = $template;
		$this->user = $user;
		$this->root_path = $root_path;
		$this->php_ext = $php_ext;
	}

	public function complete(
		array $render_data,
		array $topic_tracking_info,
		int $max_post_time,
		int $total_posts,
		bool $s_can_vote,
		array $s_watching_topic,
		array $post_list,
		string $view
	): void
	{
		$auth = $this->auth;
		$config = $this->config;
		$db = $this->db;
		$phpbb_dispatcher = $this->dispatcher;
		$pagination = $this->pagination;
		$request = $this->request;
		$template = $this->template;
		$user = $this->user;
		$phpbb_root_path = $this->root_path;
		$phpEx = $this->php_ext;

		// Preserve the legacy variables used by tracking, counters and quick reply below.
		$forum_id = $render_data['forum_id'];
		$topic_id = $render_data['topic_id'];
		$topic_data = $render_data['topic_data'];
		$start = $render_data['start'];
		$update_count = $render_data['update_count'];
		$post_unread = $render_data['post_unread'];
		unset($render_data);

		// Update topic view and if necessary attachment view counters ... but only for humans and if this is the first 'page view'
		if (isset($user->data['session_page']) && !$user->data['is_bot'] && (strpos($user->data['session_page'], '&t=' . $topic_id) === false || isset($user->data['session_created'])))
		{
			$sql = 'UPDATE ' . TOPICS_TABLE . '
				SET topic_views = topic_views + 1, topic_last_view_time = :vt_view_time
				WHERE topic_id = :vt_view_topic_id';
			$db->sql_query_params($sql, [
				'vt_view_time' => time(),
				'vt_view_topic_id' => $topic_id,
			]);

			// Update the attachment download counts
			if (count($update_count))
			{
				$sql_params = [];
				$sql_attachment_ids = $db->sql_in_set_params('attach_id', array_unique($update_count), $sql_params, false, false, 'vt_download_attach');
				$sql = 'UPDATE ' . ATTACHMENTS_TABLE . '
					SET download_count = download_count + 1
					WHERE ' . $sql_attachment_ids;
				$db->sql_query_params($sql, $sql_params);
			}
		}

		// Only mark topic if it's currently unread. Also make sure we do not set topic tracking back if earlier pages are viewed.
		if (isset($topic_tracking_info[$topic_id]) && $topic_data['topic_last_post_time'] > $topic_tracking_info[$topic_id] && $max_post_time > $topic_tracking_info[$topic_id])
		{
			markread('topic', $forum_id, $topic_id, $max_post_time);

			// Update forum info
			$all_marked_read = update_forum_tracking_info($forum_id, $topic_data['forum_last_post_time'], (isset($topic_data['forum_mark_time'])) ? $topic_data['forum_mark_time'] : false, false);
		}
		else
		{
			$all_marked_read = true;
		}

		// If there are absolutely no more unread posts in this forum
		// and unread posts shown, we can safely show the #unread link
		if ($all_marked_read)
		{
			if ($post_unread)
			{
				$template->assign_vars(array(
					'U_VIEW_UNREAD_POST'	=> '#unread',
				));
			}
			else if (isset($topic_tracking_info[$topic_id]) && $topic_data['topic_last_post_time'] > $topic_tracking_info[$topic_id])
			{
				$template->assign_vars(array(
					'U_VIEW_UNREAD_POST'	=> append_sid("{$phpbb_root_path}viewtopic.$phpEx", "t=$topic_id&amp;view=unread") . '#unread',
				));
			}
		}
		else if (!$all_marked_read)
		{
			$last_page = ((floor($start / $config['posts_per_page']) + 1) == max(ceil($total_posts / $config['posts_per_page']), 1)) ? true : false;

			// What can happen is that we are at the last displayed page. If so, we also display the #unread link based in $post_unread
			if ($last_page && $post_unread)
			{
				$template->assign_vars(array(
					'U_VIEW_UNREAD_POST'	=> '#unread',
				));
			}
			else if (!$last_page)
			{
				$template->assign_vars(array(
					'U_VIEW_UNREAD_POST'	=> append_sid("{$phpbb_root_path}viewtopic.$phpEx", "t=$topic_id&amp;view=unread") . '#unread',
				));
			}
		}

		// let's set up quick_reply
		$s_quick_reply = false;
		if ($user->data['is_registered'] && $config['allow_quick_reply'] && ($topic_data['forum_flags'] & FORUM_FLAG_QUICK_REPLY) && $auth->acl_get('f_reply', $forum_id))
		{
			// Quick reply enabled forum
			$s_quick_reply = (($topic_data['forum_status'] == ITEM_UNLOCKED && $topic_data['topic_status'] == ITEM_UNLOCKED) || $auth->acl_get('m_edit', $forum_id)) ? true : false;
		}

		if ($s_can_vote || $s_quick_reply)
		{
			add_form_key('posting');

			if ($s_quick_reply)
			{
				$s_attach_sig	= $config['allow_sig'] && $user->optionget('attachsig') && $auth->acl_get('f_sigs', $forum_id) && $auth->acl_get('u_sig');
				$s_smilies		= $config['allow_smilies'] && $user->optionget('smilies') && $auth->acl_get('f_smilies', $forum_id);
				$s_bbcode		= $config['allow_bbcode'] && $user->optionget('bbcode') && $auth->acl_get('f_bbcode', $forum_id);
				$s_notify		= $config['allow_topic_notify'] && ($user->data['user_notify'] || $s_watching_topic['is_watching']);

				$qr_hidden_fields = array(
					'topic_cur_post_id'		=> (int) $topic_data['topic_last_post_id'],
					'topic_id'				=> (int) $topic_data['topic_id'],
					'forum_id'				=> (int) $forum_id,
				);

				// Originally we use checkboxes and check with isset(), so we only provide them if they would be checked
				(!$s_bbcode)					? $qr_hidden_fields['disable_bbcode'] = 1		: true;
				(!$s_smilies)					? $qr_hidden_fields['disable_smilies'] = 1		: true;
				(!$config['allow_post_links'])	? $qr_hidden_fields['disable_magic_url'] = 1	: true;
				($s_attach_sig)					? $qr_hidden_fields['attach_sig'] = 1			: true;
				($s_notify)						? $qr_hidden_fields['notify'] = 1				: true;
				($topic_data['topic_status'] == ITEM_LOCKED) ? $qr_hidden_fields['lock_topic'] = 1 : true;

				$tpl_ary = [
					'S_QUICK_REPLY'			=> true,
					'U_QR_ACTION'			=> append_sid("{$phpbb_root_path}posting.$phpEx", "mode=reply&amp;t=$topic_id"),
					'QR_HIDDEN_FIELDS'		=> build_hidden_fields($qr_hidden_fields),
					'SUBJECT'				=> 'Re: ' . censor_text($topic_data['topic_title']),
				];

				/**
				* Event after the quick-reply has been setup
				*
				* @event core.viewtopic_modify_quick_reply_template_vars
				* @var	array	tpl_ary			Array with template data
				* @var	array	topic_data		Array with topic data
				* @since 3.2.9-RC1
				*/
				$vars = ['tpl_ary', 'topic_data'];
				extract($phpbb_dispatcher->trigger_event('core.viewtopic_modify_quick_reply_template_vars', compact($vars)));

				$template->assign_vars($tpl_ary);
			}
		}
		// now I have the urge to wash my hands :(


		// We overwrite $_REQUEST['f'] if there is no forum specified
		// to be able to display the correct online list.
		// One downside is that the user currently viewing this topic/post is not taken into account.
		if (!$request->variable('f', 0))
		{
			$request->overwrite('f', $forum_id);
		}

		// We need to do the same with the topic_id. See #53025.
		if (!$request->variable('t', 0) && !empty($topic_id))
		{
			$request->overwrite('t', $topic_id);
		}

		$page_title = $topic_data['topic_title'] . ($start ? ' - ' . sprintf($user->lang['PAGE_TITLE_NUMBER'], $pagination->get_on_page($config['posts_per_page'], $start)) : '');

		/**
		* You can use this event to modify the page title of the viewtopic page
		*
		* @event core.viewtopic_modify_page_title
		* @var	string	page_title		Title of the viewtopic page
		* @var	array	topic_data		Array with topic data
		* @var	int		forum_id		Forum ID of the topic
		* @var	int		start			Start offset used to calculate the page
		* @var	array	post_list		Array with post_ids we are going to display
		* @since 3.1.0-a1
		* @changed 3.1.0-RC4 Added post_list var
		*/
		$vars = array('page_title', 'topic_data', 'forum_id', 'start', 'post_list');
		extract($phpbb_dispatcher->trigger_event('core.viewtopic_modify_page_title', compact($vars)));

		// Output the page
		page_header($page_title, true, $forum_id);

		$template->set_filenames(array(
			'body' => ($view == 'print') ? 'viewtopic_print.html' : 'viewtopic_body.html')
		);

		page_footer();

	}
}

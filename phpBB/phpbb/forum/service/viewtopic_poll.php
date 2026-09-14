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
use phpbb\request\request_interface;
use phpbb\template\template;
use phpbb\user;

/**
 * Handles viewtopic poll loading, voting and poll template rendering.
 *
 * This keeps the historical poll behaviour and extension events intact while
 * removing the poll implementation from the public viewtopic entry point.
 */
class viewtopic_poll
{
	protected $auth;
	protected $config;
	protected $db;
	protected $dispatcher;
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
		$this->request = $request;
		$this->template = $template;
		$this->user = $user;
		$this->root_path = $root_path;
		$this->php_ext = $php_ext;
	}

	/**
	 * Process and render a topic poll.
	 *
	 * @return array<string, mixed>
	 */
	public function process(
		array $topic_data,
		int $forum_id,
		int $topic_id,
		string $view,
		int $start,
		string $viewtopic_url,
		array $voted_id,
		bool $update
	): array
	{
		$auth = $this->auth;
		$config = $this->config;
		$db = $this->db;
		$phpbb_dispatcher = $this->dispatcher;
		$request = $this->request;
		$template = $this->template;
		$user = $this->user;
		$phpbb_root_path = $this->root_path;
		$phpEx = $this->php_ext;
		$s_can_vote = false;

		// Does this topic contain a poll?
		if (!empty($topic_data['poll_start']))
		{
			$sql = 'SELECT o.*, p.bbcode_bitfield, p.bbcode_uid
				FROM ' . POLL_OPTIONS_TABLE . ' o, ' . POSTS_TABLE . ' p
				WHERE o.topic_id = :vt_poll_topic_id
					AND p.post_id = :vt_poll_first_post_id
					AND p.topic_id = o.topic_id
				ORDER BY o.poll_option_id';
			$result = $db->sql_query_params($sql, [
				'vt_poll_topic_id' => $topic_id,
				'vt_poll_first_post_id' => (int) $topic_data['topic_first_post_id'],
			]);

			$poll_info = $vote_counts = array();
			while ($row = $db->sql_fetchrow($result))
			{
				$poll_info[] = $row;
				$option_id = (int) $row['poll_option_id'];
				$vote_counts[$option_id] = (int) $row['poll_option_total'];
			}
			$db->sql_freeresult($result);

			$cur_voted_id = array();
			if ($user->data['is_registered'])
			{
				$sql = 'SELECT poll_option_id
					FROM ' . POLL_VOTES_TABLE . '
					WHERE topic_id = :vt_poll_vote_topic_id
						AND vote_user_id = :vt_poll_vote_user_id';
				$result = $db->sql_query_params($sql, [
					'vt_poll_vote_topic_id' => $topic_id,
					'vt_poll_vote_user_id' => (int) $user->data['user_id'],
				]);

				while ($row = $db->sql_fetchrow($result))
				{
					$cur_voted_id[] = $row['poll_option_id'];
				}
				$db->sql_freeresult($result);
			}
			else
			{
				// Cookie based guest tracking ... I don't like this but hum ho
				// it's oft requested. This relies on "nice" users who don't feel
				// the need to delete cookies to mess with results.
				if ($request->is_set($config['cookie_name'] . '_poll_' . $topic_id, \phpbb\request\request_interface::COOKIE))
				{
					$cur_voted_id = explode(',', $request->variable($config['cookie_name'] . '_poll_' . $topic_id, '', true, \phpbb\request\request_interface::COOKIE));
					$cur_voted_id = array_map('intval', $cur_voted_id);
				}
			}

			// Can not vote at all if no vote permission
			$s_can_vote = ($auth->acl_get('f_vote', $forum_id) &&
				(($topic_data['poll_length'] != 0 && $topic_data['poll_start'] + $topic_data['poll_length'] > time()) || $topic_data['poll_length'] == 0) &&
				$topic_data['topic_status'] != ITEM_LOCKED &&
				$topic_data['forum_status'] != ITEM_LOCKED &&
				(!count($cur_voted_id) ||
				($auth->acl_get('f_votechg', $forum_id) && $topic_data['poll_vote_change']))) ? true : false;
			$s_display_results = (!$s_can_vote || ($s_can_vote && count($cur_voted_id)) || $view == 'viewpoll') ? true : false;

			/**
			* Event to manipulate the poll data
			*
			* @event core.viewtopic_modify_poll_data
			* @var	array	cur_voted_id				Array with options' IDs current user has voted for
			* @var	int		forum_id					The topic's forum id
			* @var	array	poll_info					Array with the poll information
			* @var	bool	s_can_vote					Flag indicating if a user can vote
			* @var	bool	s_display_results			Flag indicating if results or poll options should be displayed
			* @var	int		topic_id					The id of the topic the user tries to access
			* @var	array	topic_data					All the information from the topic and forum tables for this topic
			* @var	string	viewtopic_url				URL to the topic page
			* @var	array	vote_counts					Array with the vote counts for every poll option
			* @var	array	voted_id					Array with updated options' IDs current user is voting for
			* @since 3.1.5-RC1
			*/
			$vars = array(
				'cur_voted_id',
				'forum_id',
				'poll_info',
				's_can_vote',
				's_display_results',
				'topic_id',
				'topic_data',
				'viewtopic_url',
				'vote_counts',
				'voted_id',
			);
			extract($phpbb_dispatcher->trigger_event('core.viewtopic_modify_poll_data', compact($vars)));

			if ($update && $s_can_vote)
			{

				if (!count($voted_id) || count($voted_id) > $topic_data['poll_max_options'] || in_array(VOTE_CONVERTED, $cur_voted_id) || !check_form_key('posting'))
				{
					$redirect_url = append_sid("{$phpbb_root_path}viewtopic.$phpEx", "t=$topic_id" . (($start == 0) ? '' : "&amp;start=$start"));

					meta_refresh(5, $redirect_url);
					if (!count($voted_id))
					{
						$message = 'NO_VOTE_OPTION';
					}
					else if (count($voted_id) > $topic_data['poll_max_options'])
					{
						$message = 'TOO_MANY_VOTE_OPTIONS';
					}
					else if (in_array(VOTE_CONVERTED, $cur_voted_id))
					{
						$message = 'VOTE_CONVERTED';
					}
					else
					{
						$message = 'FORM_INVALID';
					}

					$message = $user->lang[$message] . '<br /><br />' . sprintf($user->lang['RETURN_TOPIC'], '<a href="' . $redirect_url . '">', '</a>');
					trigger_error($message);
				}

				// Poll updates touch several tables/counters. Keep them atomic now that
				// phpBB's historical transaction API is backed by Doctrine DBAL.
				$db->sql_transaction('begin');

				foreach ($voted_id as $option)
				{
					if (in_array($option, $cur_voted_id))
					{
						continue;
					}

					$sql = 'UPDATE ' . POLL_OPTIONS_TABLE . '
						SET poll_option_total = poll_option_total + 1
						WHERE poll_option_id = :vt_poll_add_option_id
							AND topic_id = :vt_poll_add_topic_id';
					$db->sql_query_params($sql, [
						'vt_poll_add_option_id' => (int) $option,
						'vt_poll_add_topic_id' => $topic_id,
					]);

					$vote_counts[$option]++;

					if ($user->data['is_registered'])
					{
						$sql_ary = array(
							'topic_id'			=> (int) $topic_id,
							'poll_option_id'	=> (int) $option,
							'vote_user_id'		=> (int) $user->data['user_id'],
							'vote_user_ip'		=> (string) $user->ip,
						);

						$sql_params = [];
						$sql = 'INSERT INTO ' . POLL_VOTES_TABLE . ' ' . $db->sql_build_array_params('INSERT', $sql_ary, $sql_params, 'vt_poll_vote_insert');
						$db->sql_query_params($sql, $sql_params);
					}
				}

				foreach ($cur_voted_id as $option)
				{
					if (!in_array($option, $voted_id))
					{
						$sql = 'UPDATE ' . POLL_OPTIONS_TABLE . '
							SET poll_option_total = poll_option_total - 1
							WHERE poll_option_id = :vt_poll_remove_option_id
								AND topic_id = :vt_poll_remove_topic_id';
						$db->sql_query_params($sql, [
							'vt_poll_remove_option_id' => (int) $option,
							'vt_poll_remove_topic_id' => $topic_id,
						]);

						$vote_counts[$option]--;

						if ($user->data['is_registered'])
						{
							$sql = 'DELETE FROM ' . POLL_VOTES_TABLE . '
								WHERE topic_id = :vt_poll_delete_topic_id
									AND poll_option_id = :vt_poll_delete_option_id
									AND vote_user_id = :vt_poll_delete_user_id';
							$db->sql_query_params($sql, [
								'vt_poll_delete_topic_id' => $topic_id,
								'vt_poll_delete_option_id' => (int) $option,
								'vt_poll_delete_user_id' => (int) $user->data['user_id'],
							]);
						}
					}
				}

				if ($user->data['user_id'] == ANONYMOUS && !$user->data['is_bot'])
				{
					$user->set_cookie('poll_' . $topic_id, implode(',', $voted_id), time() + 31536000);
				}

				$sql = 'UPDATE ' . TOPICS_TABLE . '
					SET poll_last_vote = :vt_poll_last_vote
					WHERE topic_id = :vt_poll_last_vote_topic_id';
				// topic_last_post_time intentionally remains unchanged: poll votes do not bump topics.
				$db->sql_query_params($sql, [
					'vt_poll_last_vote' => time(),
					'vt_poll_last_vote_topic_id' => $topic_id,
				]);

				$db->sql_transaction('commit');

				$redirect_url = append_sid("{$phpbb_root_path}viewtopic.$phpEx", "t=$topic_id" . (($start == 0) ? '' : "&amp;start=$start"));
				$message = $user->lang['VOTE_SUBMITTED'] . '<br /><br />' . sprintf($user->lang['RETURN_TOPIC'], '<a href="' . $redirect_url . '">', '</a>');

				if ($request->is_ajax())
				{
					// Filter out invalid options
					$valid_user_votes = array_intersect(array_keys($vote_counts), $voted_id);

					$data = array(
						'NO_VOTES'			=> $user->lang['NO_VOTES'],
						'success'			=> true,
						'user_votes'		=> array_flip($valid_user_votes),
						'vote_counts'		=> $vote_counts,
						'total_votes'		=> array_sum($vote_counts),
						'can_vote'			=> !count($valid_user_votes) || ($auth->acl_get('f_votechg', $forum_id) && $topic_data['poll_vote_change']),
					);

					/**
					* Event to manipulate the poll data sent by AJAX response
					*
					* @event core.viewtopic_modify_poll_ajax_data
					* @var	array	data				JSON response data
					* @var	array	valid_user_votes	Valid user votes
					* @var	array	vote_counts			Vote counts
					* @var	int		forum_id			Forum ID
					* @var	array	topic_data			Topic data
					* @var	array	poll_info			Array with the poll information
					* @since 3.2.4-RC1
					*/
					$vars = array(
						'data',
						'valid_user_votes',
						'vote_counts',
						'forum_id',
						'topic_data',
						'poll_info',
					);
					extract($phpbb_dispatcher->trigger_event('core.viewtopic_modify_poll_ajax_data', compact($vars)));

					$json_response = new \phpbb\json_response();
					$json_response->send($data);
				}

				meta_refresh(5, $redirect_url);
				trigger_error($message);
			}

			$poll_total = 0;
			$poll_most = 0;
			foreach ($poll_info as $poll_option)
			{
				$poll_total += $poll_option['poll_option_total'];
				$poll_most = ($poll_option['poll_option_total'] >= $poll_most) ? $poll_option['poll_option_total'] : $poll_most;
			}

			$parse_flags = ($poll_info[0]['bbcode_bitfield'] ? OPTION_FLAG_BBCODE : 0) | OPTION_FLAG_SMILIES;

			for ($i = 0, $size = count($poll_info); $i < $size; $i++)
			{
				$poll_info[$i]['poll_option_text'] = generate_text_for_display($poll_info[$i]['poll_option_text'], $poll_info[$i]['bbcode_uid'], $poll_option['bbcode_bitfield'], $parse_flags, true);
			}

			$topic_data['poll_title'] = generate_text_for_display($topic_data['poll_title'], $poll_info[0]['bbcode_uid'], $poll_info[0]['bbcode_bitfield'], $parse_flags, true);

			$poll_template_data = $poll_options_template_data = array();
			foreach ($poll_info as $poll_option)
			{
				$option_pct = ($poll_total > 0) ? $poll_option['poll_option_total'] / $poll_total : 0;
				$option_pct_txt = sprintf("%.1d%%", round($option_pct * 100));
				$option_pct_rel = ($poll_most > 0) ? $poll_option['poll_option_total'] / $poll_most : 0;
				$option_pct_rel_txt = sprintf("%.1d%%", round($option_pct_rel * 100));
				$option_most_votes = ($poll_option['poll_option_total'] > 0 && $poll_option['poll_option_total'] == $poll_most) ? true : false;

				$poll_options_template_data[] = array(
					'POLL_OPTION_ID' 			=> $poll_option['poll_option_id'],
					'POLL_OPTION_CAPTION' 		=> $poll_option['poll_option_text'],
					'POLL_OPTION_RESULT' 		=> $poll_option['poll_option_total'],
					'POLL_OPTION_PERCENT' 		=> $option_pct_txt,
					'POLL_OPTION_PERCENT_REL' 	=> $option_pct_rel_txt,
					'POLL_OPTION_PCT'			=> round($option_pct * 100),
					'POLL_OPTION_WIDTH'     	=> round($option_pct * 250),
					'POLL_OPTION_VOTED'			=> (in_array($poll_option['poll_option_id'], $cur_voted_id)) ? true : false,
					'POLL_OPTION_MOST_VOTES'	=> $option_most_votes,
				);
			}

			$poll_end = $topic_data['poll_length'] + $topic_data['poll_start'];

			$poll_template_data = array(
				'POLL_QUESTION'		=> $topic_data['poll_title'],
				'TOTAL_VOTES' 		=> $poll_total,
				'POLL_LEFT_CAP_IMG'	=> $user->img('poll_left'),
				'POLL_RIGHT_CAP_IMG'=> $user->img('poll_right'),

				'L_MAX_VOTES'		=> $user->lang('MAX_OPTIONS_SELECT', (int) $topic_data['poll_max_options']),
				'L_POLL_LENGTH'		=> ($topic_data['poll_length']) ? sprintf($user->lang[($poll_end > time()) ? 'POLL_RUN_TILL' : 'POLL_ENDED_AT'], $user->format_date($poll_end)) : '',

				'S_HAS_POLL'		=> true,
				'S_CAN_VOTE'		=> $s_can_vote,
				'S_DISPLAY_RESULTS'	=> $s_display_results,
				'S_IS_MULTI_CHOICE'	=> ($topic_data['poll_max_options'] > 1) ? true : false,
				'S_POLL_ACTION'		=> $viewtopic_url,

				'U_VIEW_RESULTS'	=> $viewtopic_url . '&amp;view=viewpoll',
			);

			/**
			* Event to add/modify poll template data
			*
			* @event core.viewtopic_modify_poll_template_data
			* @var	array	cur_voted_id					Array with options' IDs current user has voted for
			* @var	int		poll_end						The poll end time
			* @var	array	poll_info						Array with the poll information
			* @var	array	poll_options_template_data		Array with the poll options template data
			* @var	array	poll_template_data				Array with the common poll template data
			* @var	int		poll_total						Total poll votes count
			* @var	int		poll_most						Mostly voted option votes count
			* @var	array	topic_data						All the information from the topic and forum tables for this topic
			* @var	string	viewtopic_url					URL to the topic page
			* @var	array	vote_counts						Array with the vote counts for every poll option
			* @var	array	voted_id						Array with updated options' IDs current user is voting for
			* @since 3.1.5-RC1
			*/
			$vars = array(
				'cur_voted_id',
				'poll_end',
				'poll_info',
				'poll_options_template_data',
				'poll_template_data',
				'poll_total',
				'poll_most',
				'topic_data',
				'viewtopic_url',
				'vote_counts',
				'voted_id',
			);
			extract($phpbb_dispatcher->trigger_event('core.viewtopic_modify_poll_template_data', compact($vars)));

			$template->assign_block_vars_array('poll_option', $poll_options_template_data);

			$template->assign_vars($poll_template_data);

			unset($poll_end, $poll_info, $poll_options_template_data, $poll_template_data, $voted_id);
		}

		return [
			'forum_id' => (int) $forum_id,
			'topic_id' => (int) $topic_id,
			'topic_data' => $topic_data,
			's_can_vote' => (bool) $s_can_vote,
		];
	}
}

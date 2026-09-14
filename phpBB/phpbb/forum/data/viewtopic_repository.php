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

namespace phpbb\forum\data;

use phpbb\content_visibility;
use phpbb\db\driver\driver_interface;

/**
 * Data access for the initial viewtopic topic context.
 */
class viewtopic_repository
{
	/** @var driver_interface */
	protected $db;

	/** @var content_visibility */
	protected $content_visibility;

	/** @var string */
	protected $forums_table;

	/** @var string */
	protected $posts_table;

	/** @var string */
	protected $topics_table;

	/** @var string */
	protected $topics_watch_table;

	/** @var string */
	protected $bookmarks_table;

	/** @var string */
	protected $topics_track_table;

	/** @var string */
	protected $forums_track_table;

	public function __construct(
		driver_interface $db,
		content_visibility $content_visibility,
		string $forums_table,
		string $posts_table,
		string $topics_table,
		string $topics_watch_table,
		string $bookmarks_table,
		string $topics_track_table,
		string $forums_track_table
	)
	{
		$this->db = $db;
		$this->content_visibility = $content_visibility;
		$this->forums_table = $forums_table;
		$this->posts_table = $posts_table;
		$this->topics_table = $topics_table;
		$this->topics_watch_table = $topics_watch_table;
		$this->bookmarks_table = $bookmarks_table;
		$this->topics_track_table = $topics_track_table;
		$this->forums_track_table = $forums_track_table;
	}

	/**
	 * Load the topic/forum context used by viewtopic.
	 *
	 * @return array|false
	 */
	public function get_topic_data(
		int $topic_id,
		int $post_id,
		bool $is_registered,
		int $user_id,
		bool $allow_bookmarks,
		bool $load_db_lastread
	)
	{
		$sql_array = [
			'SELECT' => 't.*, f.*',
			'FROM' => [
				$this->forums_table => 'f',
			],
		];
		$params = [];

		// Keep the same table order as legacy viewtopic.php.
		if ($post_id)
		{
			$sql_array['SELECT'] .= ', p.post_visibility, p.post_time, p.post_id';
			$sql_array['FROM'][$this->posts_table] = 'p';
		}

		$sql_array['FROM'][$this->topics_table] = 't';

		if ($is_registered)
		{
			$sql_array['SELECT'] .= ', tw.notify_status';
			$sql_array['LEFT_JOIN'] = [
				[
					'FROM' => [$this->topics_watch_table => 'tw'],
					'ON' => 'tw.user_id = :vt_uid_tw AND t.topic_id = tw.topic_id',
				],
			];
			$params['vt_uid_tw'] = $user_id;

			if ($allow_bookmarks)
			{
				$sql_array['SELECT'] .= ', bm.topic_id as bookmarked';
				$sql_array['LEFT_JOIN'][] = [
					'FROM' => [$this->bookmarks_table => 'bm'],
					'ON' => 'bm.user_id = :vt_uid_bm AND t.topic_id = bm.topic_id',
				];
				$params['vt_uid_bm'] = $user_id;
			}

			if ($load_db_lastread)
			{
				$sql_array['SELECT'] .= ', tt.mark_time, ft.mark_time as forum_mark_time';
				$sql_array['LEFT_JOIN'][] = [
					'FROM' => [$this->topics_track_table => 'tt'],
					'ON' => 'tt.user_id = :vt_uid_tt AND t.topic_id = tt.topic_id',
				];
				$sql_array['LEFT_JOIN'][] = [
					'FROM' => [$this->forums_track_table => 'ft'],
					'ON' => 'ft.user_id = :vt_uid_ft AND t.forum_id = ft.forum_id',
				];
				$params['vt_uid_tt'] = $user_id;
				$params['vt_uid_ft'] = $user_id;
			}
		}

		if ($post_id)
		{
			$sql_array['WHERE'] = 'p.post_id = :vt_post_id AND t.topic_id = p.topic_id';
			$params['vt_post_id'] = $post_id;
		}
		else
		{
			$sql_array['WHERE'] = 't.topic_id = :vt_topic_id';
			$params['vt_topic_id'] = $topic_id;
		}
		$sql_array['WHERE'] .= ' AND f.forum_id = t.forum_id';

		$sql = $this->db->sql_build_query('SELECT', $sql_array);
		$result = $this->db->sql_query_params($sql, $params);
		$topic_data = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return $topic_data;
	}

	/**
	 * Calculate the number of visible posts preceding a directly linked post.
	 */
	public function get_previous_post_count(array $topic_data, int $forum_id, int $post_id, string $sort_dir): int
	{
		if ($post_id === (int) $topic_data['topic_first_post_id'] || $post_id === (int) $topic_data['topic_last_post_id'])
		{
			$check_sort = ($post_id === (int) $topic_data['topic_first_post_id']) ? 'd' : 'a';

			return ($sort_dir === $check_sort)
				? $this->content_visibility->get_count('topic_posts', $topic_data, $forum_id) - 1
				: 0;
		}

		$params = ['vt_prev_topic_id' => (int) $topic_data['topic_id']];
		$sql = 'SELECT COUNT(p.post_id) AS prev_posts
			FROM ' . $this->posts_table . ' p
			WHERE p.topic_id = :vt_prev_topic_id
				AND ' . $this->content_visibility->get_visibility_sql('post', $forum_id, 'p.');

		if ($sort_dir === 'd')
		{
			$sql .= ' AND (p.post_time > :vt_prev_time_gt OR (p.post_time = :vt_prev_time_eq AND p.post_id >= :vt_prev_post_id))';
			$params['vt_prev_time_gt'] = (int) $topic_data['post_time'];
			$params['vt_prev_time_eq'] = (int) $topic_data['post_time'];
			$params['vt_prev_post_id'] = (int) $topic_data['post_id'];
		}
		else
		{
			$sql .= ' AND (p.post_time < :vt_prev_time_lt OR (p.post_time = :vt_prev_time_eq AND p.post_id <= :vt_prev_post_id))';
			$params['vt_prev_time_lt'] = (int) $topic_data['post_time'];
			$params['vt_prev_time_eq'] = (int) $topic_data['post_time'];
			$params['vt_prev_post_id'] = (int) $topic_data['post_id'];
		}

		$result = $this->db->sql_query_params($sql, $params);
		$previous_posts = (int) $this->db->sql_fetchfield('prev_posts');
		$this->db->sql_freeresult($result);

		return $previous_posts - 1;
	}

	/**
	 * Convert an expired sticky/announcement back to a normal topic.
	 */
	public function expire_timed_topic(int $topic_id): void
	{
		$sql = 'UPDATE ' . $this->topics_table . '
			SET topic_type = :vt_normal_type, topic_time_limit = 0
			WHERE topic_id = :vt_expire_topic_id';
		$this->db->sql_query_params($sql, [
			'vt_normal_type' => POST_NORMAL,
			'vt_expire_topic_id' => $topic_id,
		]);
	}

}

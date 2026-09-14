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

use phpbb\content_visibility;
use phpbb\db\driver\driver_interface;
use phpbb\user;

/**
 * Resolves special viewtopic navigation requests such as unread, next and
 * previous before the main topic data is loaded.
 */
class viewtopic_navigation
{
	/** @var driver_interface */
	protected $db;

	/** @var content_visibility */
	protected $content_visibility;

	/** @var user */
	protected $user;

	/** @var string */
	protected $topics_table;

	/** @var string */
	protected $posts_table;

	/** @var string */
	protected $forums_table;

	public function __construct(
		driver_interface $db,
		content_visibility $content_visibility,
		user $user,
		string $topics_table,
		string $posts_table,
		string $forums_table
	)
	{
		$this->db = $db;
		$this->content_visibility = $content_visibility;
		$this->user = $user;
		$this->topics_table = $topics_table;
		$this->posts_table = $posts_table;
		$this->forums_table = $forums_table;
	}

	/**
	 * Resolve a special navigation request.
	 *
	 * @return array{forum_id:int, topic_id:int, post_id:int, topic_tracking_info:?array}
	 */
	public function resolve(string $view, int $topic_id, int $post_id, int $forum_id): array
	{
		$topic_tracking_info = null;

		if (!$view || $post_id)
		{
			return compact('forum_id', 'topic_id', 'post_id', 'topic_tracking_info');
		}

		if ($view === 'unread')
		{
			$sql = 'SELECT forum_id
				FROM ' . $this->topics_table . '
				WHERE topic_id = :nav_topic_id';
			$result = $this->db->sql_query_params($sql, ['nav_topic_id' => $topic_id]);
			$forum_id = (int) $this->db->sql_fetchfield('forum_id');
			$this->db->sql_freeresult($result);

			if (!$forum_id)
			{
				trigger_error('NO_TOPIC');
			}

			$topic_tracking_info = \get_complete_topic_tracking($forum_id, $topic_id);
			$topic_last_read = $topic_tracking_info[$topic_id] ?? 0;

			$sql = 'SELECT post_id, topic_id, forum_id
				FROM ' . $this->posts_table . '
				WHERE topic_id = :nav_unread_topic_id
					AND ' . $this->content_visibility->get_visibility_sql('post', $forum_id) . '
					AND post_time > :nav_last_read
					AND forum_id = :nav_forum_id
				ORDER BY post_time ASC, post_id ASC';
			$result = $this->db->sql_query_limit_params($sql, 1, 0, [
				'nav_unread_topic_id' => $topic_id,
				'nav_last_read' => (int) $topic_last_read,
				'nav_forum_id' => $forum_id,
			]);
			$row = $this->db->sql_fetchrow($result);
			$this->db->sql_freeresult($result);

			if (!$row)
			{
				$sql = 'SELECT topic_last_post_id AS post_id, topic_id, forum_id
					FROM ' . $this->topics_table . '
					WHERE topic_id = :nav_fallback_topic_id';
				$result = $this->db->sql_query_params($sql, ['nav_fallback_topic_id' => $topic_id]);
				$row = $this->db->sql_fetchrow($result);
				$this->db->sql_freeresult($result);
			}

			if (!$row)
			{
				$this->user->setup('viewtopic');
				trigger_error('NO_TOPIC');
			}

			$post_id = (int) $row['post_id'];
			$topic_id = (int) $row['topic_id'];
			$forum_id = (int) $row['forum_id'];
		}
		else if ($view === 'next' || $view === 'previous')
		{
			$sql_condition = ($view === 'next') ? '>' : '<';
			$sql_ordering = ($view === 'next') ? 'ASC' : 'DESC';

			$sql = 'SELECT forum_id, topic_last_post_time
				FROM ' . $this->topics_table . '
				WHERE topic_id = :nav_current_topic_id';
			$result = $this->db->sql_query_params($sql, ['nav_current_topic_id' => $topic_id]);
			$row = $this->db->sql_fetchrow($result);
			$this->db->sql_freeresult($result);

			if (!$row)
			{
				$this->user->setup('viewtopic');
				trigger_error(($view === 'next') ? 'NO_NEWER_TOPICS' : 'NO_OLDER_TOPICS');
			}

			$forum_id = (int) $row['forum_id'];
			$topic_last_post_time = (int) $row['topic_last_post_time'];

			$sql = 'SELECT topic_id, forum_id
				FROM ' . $this->topics_table . '
				WHERE forum_id = :nav_adjacent_forum_id
					AND topic_moved_id = 0
					AND topic_last_post_time ' . $sql_condition . ' :nav_adjacent_time
					AND ' . $this->content_visibility->get_visibility_sql('topic', $forum_id) . '
				ORDER BY topic_last_post_time ' . $sql_ordering . ', topic_last_post_id ' . $sql_ordering;
			$result = $this->db->sql_query_limit_params($sql, 1, 0, [
				'nav_adjacent_forum_id' => $forum_id,
				'nav_adjacent_time' => $topic_last_post_time,
			]);
			$row = $this->db->sql_fetchrow($result);
			$this->db->sql_freeresult($result);

			if (!$row)
			{
				$sql = 'SELECT forum_style
					FROM ' . $this->forums_table . '
					WHERE forum_id = :nav_style_forum_id';
				$result = $this->db->sql_query_params($sql, ['nav_style_forum_id' => $forum_id]);
				$forum_style = (int) $this->db->sql_fetchfield('forum_style');
				$this->db->sql_freeresult($result);

				$this->user->setup('viewtopic', $forum_style);
				trigger_error(($view === 'next') ? 'NO_NEWER_TOPICS' : 'NO_OLDER_TOPICS');
			}

			$topic_id = (int) $row['topic_id'];
			$forum_id = (int) $row['forum_id'];
		}

		return compact('forum_id', 'topic_id', 'post_id', 'topic_tracking_info');
	}

}

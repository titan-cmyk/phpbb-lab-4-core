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

namespace phpbb\forum\controller;

use phpbb\forum\service\viewforum_handler;

/**
 * HTTP controller for the legacy viewforum.php entry point.
 *
 * The public URL remains viewforum.php for compatibility. Request-level
 * orchestration is delegated to the application handler.
 */
class viewforum
{
	/** @var viewforum_handler */
	protected $handler;

	public function __construct(viewforum_handler $handler)
	{
		$this->handler = $handler;
	}

	public function handle(): void
	{
		$this->handler->handle();
	}
}

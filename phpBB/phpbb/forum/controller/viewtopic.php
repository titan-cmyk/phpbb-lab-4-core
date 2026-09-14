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

use phpbb\forum\service\viewtopic_handler;

/**
 * HTTP controller for the legacy viewtopic.php entry point.
 *
 * The public URL remains viewtopic.php for compatibility while request-level
 * orchestration is delegated to a dedicated application handler.
 */
class viewtopic
{
	/** @var viewtopic_handler */
	protected $handler;

	public function __construct(viewtopic_handler $handler)
	{
		$this->handler = $handler;
	}

	public function handle(): void
	{
		$this->handler->handle();
	}
}

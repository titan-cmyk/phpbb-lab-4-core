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

namespace phpbb\template\twig;

/**
* Builds the data array passed to Twig from the phpBB template context.
*/
class context_data_builder
{
	/** @var \phpbb\template\context */
	protected $context;

	/** @var null|\phpbb\user */
	protected $user;

	public function __construct(\phpbb\template\context $context, \phpbb\user|null $user = null)
	{
		$this->context = $context;
		$this->user = $user;
	}

	/**
	* Build template variables in the format expected by Twig.
	*/
	public function build(): array
	{
		$context_vars = $this->context->get_data_ref();

		$vars = array_merge(
			$context_vars['.'][0],
			[
				'definition' => new definition(),
				'loops' => $context_vars,
			]
		);

		if ($this->user instanceof \phpbb\user)
		{
			$vars['user'] = $this->user;
		}

		unset($vars['loops']['.']);

		foreach ($vars['loops'] as $key => &$value)
		{
			$vars[$key] = $value;
		}
		unset($value);

		return $vars;
	}
}

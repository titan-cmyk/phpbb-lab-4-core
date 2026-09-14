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
* Resolves template handles and delegates rendering to Twig.
*
* Keeping handle resolution and Twig rendering in one collaborator allows the
* main template class to remain focused on phpBB's public template API.
*/
class renderer
{
	/** @var environment */
	protected $twig;

	/** @var loader */
	protected $loader;

	/**
	* Constructor.
	*
	* @param environment $twig Twig environment
	* @param loader $loader Twig/phpBB template loader
	*/
	public function __construct(environment $twig, $loader)
	{
		$this->twig = $twig;
		$this->loader = $loader;
	}

	/**
	* Resolve a phpBB template handle to its configured filename.
	*
	* @param string $handle Template handle
	* @param array $filenames Handle => filename map
	* @return string
	*/
	public function resolve_handle($handle, array $filenames)
	{
		return isset($filenames[$handle]) ? $filenames[$handle] : $handle;
	}

	/**
	* Display a template directly.
	*
	* @param string $handle Template handle
	* @param array $filenames Handle => filename map
	* @param array $vars Variables passed to Twig
	*/
	public function display($handle, array $filenames, array $vars): void
	{
		$this->twig->display($this->resolve_handle($handle, $filenames), $vars);
	}

	/**
	* Render a template and return its contents.
	*
	* @param string $handle Template handle
	* @param array $filenames Handle => filename map
	* @param array $vars Variables passed to Twig
	* @return string
	*/
	public function render($handle, array $filenames, array $vars)
	{
		return $this->twig->render($this->resolve_handle($handle, $filenames), $vars);
	}

	/**
	* Get the physical source file for a template handle.
	*
	* @param string $handle Template handle
	* @param array $filenames Handle => filename map
	* @return string
	*/
	public function get_source_file_for_handle($handle, array $filenames)
	{
		return $this->loader->getCacheKey($this->resolve_handle($handle, $filenames));
	}
}

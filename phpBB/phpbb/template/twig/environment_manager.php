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
* Owns Twig environment bootstrap concerns used by phpBB's template adapter.
*
* This keeps extension registration and loader discovery out of the public
* template implementation while preserving the existing constructor contract.
*/
class environment_manager
{
	/** @var environment */
	protected $twig;

	/** @var loader */
	protected $loader;

	/**
	* Constructor.
	*
	* @param environment $twig Twig environment
	*/
	public function __construct(environment $twig)
	{
		$this->twig = $twig;
		$this->loader = $twig->getLoader();
	}

	/**
	* Return the loader associated with the Twig environment.
	*
	* @return loader
	*/
	public function get_loader()
	{
		return $this->loader;
	}

	/**
	* Register phpBB/Twig extensions that are not already installed.
	*
	* The method intentionally preserves phpBB's historical behaviour: the
	* supplied collection is iterated in its existing order and duplicate
	* extension classes are ignored by consulting Twig first.
	*
	* @param iterable|array|\ArrayAccess $extensions Template extensions
	*/
	public function register_extensions($extensions): void
	{
		foreach ($extensions as $extension)
		{
			if (!$this->twig->hasExtension(get_class($extension)))
			{
				$this->twig->addExtension($extension);
			}
		}
	}
}

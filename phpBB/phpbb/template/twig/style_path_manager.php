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

use phpbb\template\exception\user_object_not_available;

/**
* Manages template style paths and extension template namespaces.
*/
class style_path_manager
{
	/** @var \phpbb\path_helper */
	protected $path_helper;

	/** @var string */
	protected $phpbb_root_path;

	/** @var loader */
	protected $loader;

	/** @var null|\phpbb\user */
	protected $user;

	/** @var null|\phpbb\extension\manager */
	protected $extension_manager;

	/**
	* Constructor.
	*
	* @param \phpbb\path_helper $path_helper
	* @param loader $loader
	* @param null|\phpbb\user $user
	* @param null|\phpbb\extension\manager $extension_manager
	*/
	public function __construct(
		\phpbb\path_helper $path_helper,
		$loader,
		\phpbb\user|null $user = null,
		\phpbb\extension\manager|null $extension_manager = null
	)
	{
		$this->path_helper = $path_helper;
		$this->phpbb_root_path = $path_helper->get_phpbb_root_path();
		$this->loader = $loader;
		$this->user = $user;
		$this->extension_manager = $extension_manager;

		$this->initialize_admin_namespace();
	}

	/**
	* Set the admin template namespace when an ACP style directory is available.
	*/
	protected function initialize_admin_namespace(): void
	{
		$adm_relative_path = $this->path_helper->get_adm_relative_path();

		if ($adm_relative_path !== null
			&& is_dir($this->phpbb_root_path . $adm_relative_path . 'style/'))
		{
			$this->loader->setPaths($this->phpbb_root_path . $adm_relative_path . 'style/', 'admin');
		}
	}

	/**
	* Get the style tree of the style preferred by the current user.
	*
	* @return array Style tree, most specific first
	*
	* @throws user_object_not_available When user service was not set
	*/
	public function get_user_style(): array
	{
		if ($this->user === null)
		{
			throw new user_object_not_available();
		}

		$style_list = [
			$this->user->style['style_path'],
		];

		if ($this->user->style['style_parent_id'])
		{
			$style_list = array_merge($style_list, array_reverse(explode('/', $this->user->style['style_parent_tree'])));
		}

		return $style_list;
	}

	/**
	* Set style location based on the current user's chosen style.
	*
	* @param array $style_directories Directories to add style paths for
	*/
	public function set_style(array $style_directories = ['styles']): void
	{
		if ($style_directories !== ['styles'] && $this->loader->getPaths('core') === [])
		{
			// Set up core style paths first when an extension style is configured before core.
			$this->set_style();
		}

		$paths = [];
		$names = $this->get_user_style();
		$names[] = 'all';

		foreach ($style_directories as $directory)
		{
			foreach ($names as $name)
			{
				$path = $this->phpbb_root_path . trim($directory, '/') . "/{$name}/";
				$handle = @opendir($path);
				$valid = false;

				if ($handle)
				{
					while (($file = readdir($handle)) !== false)
					{
						$dir = $path . $file;

						if ($file[0] !== '.' && is_dir($dir))
						{
							$paths[] = $dir;
							$valid = true;
						}
					}

					closedir($handle);
				}

				if ($valid)
				{
					$this->loader->addSafeDirectory($path);
				}
			}
		}

		if ($style_directories === ['styles'] && $this->loader->getPaths('core') === [])
		{
			$this->loader->setPaths($paths, 'core');
		}

		$this->set_custom_style($names, $paths);
	}

	/**
	* Set a custom style location and extension namespaces.
	*
	* @param string|array $names
	* @param string|array $paths
	*/
	public function set_custom_style($names, $paths): void
	{
		$paths = is_string($paths) ? [$paths] : $paths;
		$names = is_string($names) ? [$names] : $names;

		$this->loader->setPaths($paths);

		if (!$this->extension_manager instanceof \phpbb\extension\manager)
		{
			return;
		}

		$names[] = 'all';

		foreach ($this->extension_manager->all_enabled() as $ext_namespace => $ext_path)
		{
			$namespace = str_replace('/', '_', $ext_namespace);
			$extension_paths = [];

			foreach ($names as $template_dir)
			{
				if (is_array($template_dir))
				{
					if (isset($template_dir['ext_path']))
					{
						$ext_style_template_path = $ext_path . $template_dir['ext_path'];
						$ext_style_path = dirname($ext_style_template_path);
						$ext_style_theme_path = $ext_style_path . 'theme/';
					}
					else
					{
						$ext_style_path = $ext_path . 'styles/' . $template_dir['name'] . '/';
						$ext_style_template_path = $ext_style_path . 'template/';
						$ext_style_theme_path = $ext_style_path . 'theme/';
					}
				}
				else
				{
					$ext_style_path = $ext_path . 'styles/' . $template_dir . '/';
					$ext_style_template_path = $ext_style_path . 'template/';
					$ext_style_theme_path = $ext_style_path . 'theme/';
				}

				$is_valid_dir = false;

				if (is_dir($ext_style_template_path))
				{
					$is_valid_dir = true;
					$extension_paths[] = $ext_style_template_path;
				}

				if (is_dir($ext_style_theme_path))
				{
					$is_valid_dir = true;
					$extension_paths[] = $ext_style_theme_path;
				}

				if ($is_valid_dir)
				{
					$this->loader->addSafeDirectory($ext_style_path);
				}
			}

			$this->loader->setPaths($extension_paths, $namespace);
		}
	}
}

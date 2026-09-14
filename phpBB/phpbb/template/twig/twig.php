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
* Twig Template class.
*
* PHPBB Lab Core refactor: style path resolution and template context building
* are delegated to dedicated collaborators while the public template API,
* constructor signature and historical protected members remain compatible.
*/
class twig extends \phpbb\template\base
{
	/**
	 * Path of the cache directory for the template.
	 * Cannot be changed during runtime.
	 *
	 * @var string
	 */
	private $cachepath = '';

	/** @var \phpbb\path_helper */
	protected $path_helper;

	/** @var string phpBB root path */
	protected $phpbb_root_path;

	/** @var string php File extension */
	protected $php_ext;

	/** @var \phpbb\config\config */
	protected $config;

	/** @var null|\phpbb\user */
	protected $user;

	/** @var null|\phpbb\extension\manager */
	protected $extension_manager;

	/** @var environment */
	protected $twig;

	/** @var loader */
	protected $loader;

	/** @var style_path_manager */
	protected $style_path_manager;

	/** @var context_data_builder */
	protected $context_data_builder;

	/** @var renderer */
	protected $renderer;

	/** @var environment_manager */
	protected $environment_manager;

	/**
	* Constructor.
	*
	* @param \phpbb\path_helper $path_helper Path helper object
	* @param \phpbb\config\config $config Config object
	* @param \phpbb\template\context $context Template context
	* @param environment $twig_environment Twig environment
	* @param string $cache_path Template's cache directory path
	* @param null|\phpbb\user $user User object
	* @param array|\ArrayAccess $extensions Template extensions
	* @param null|\phpbb\extension\manager $extension_manager If null then template events will not be invoked
	*/
	public function __construct(
		\phpbb\path_helper $path_helper,
		\phpbb\config\config $config,
		\phpbb\template\context $context,
		environment $twig_environment,
		$cache_path,
		\phpbb\user|null $user = null,
		$extensions = [],
		\phpbb\extension\manager|null $extension_manager = null
	)
	{
		// Preserve the historical object state for backwards compatibility with
		// subclasses while delegating the implementation responsibilities below.
		$this->path_helper = $path_helper;
		$this->phpbb_root_path = $path_helper->get_phpbb_root_path();
		$this->php_ext = $path_helper->get_php_ext();
		$this->config = $config;
		$this->user = $user;
		$this->context = $context;
		$this->extension_manager = $extension_manager;
		$this->cachepath = $cache_path;
		$this->twig = $twig_environment;

		$this->environment_manager = new environment_manager($twig_environment);
		$this->loader = $this->environment_manager->get_loader();
		$this->environment_manager->register_extensions($extensions);

		$this->style_path_manager = new style_path_manager(
			$path_helper,
			$this->loader,
			$user,
			$extension_manager
		);
		$this->context_data_builder = new context_data_builder($context, $user);
		$this->renderer = new renderer($twig_environment, $this->loader);
	}

	/**
	* {@inheritdoc}
	*/
	public function get_user_style()
	{
		return $this->style_path_manager->get_user_style();
	}

	/**
	* {@inheritdoc}
	*/
	public function set_style($style_directories = ['styles'])
	{
		$this->style_path_manager->set_style($style_directories);

		return $this;
	}

	/**
	* {@inheritdoc}
	*/
	public function set_custom_style($names, $paths)
	{
		$this->style_path_manager->set_custom_style($names, $paths);

		return $this;
	}

	/**
	* {@inheritdoc}
	*/
	public function display($handle)
	{
		$this->renderer->display($handle, $this->filenames, $this->get_template_vars());

		return $this;
	}

	/**
	* {@inheritdoc}
	*/
	public function assign_display($handle, $template_var = '', $return_content = true)
	{
		$content = $this->renderer->render($handle, $this->filenames, $this->get_template_vars());

		if ($return_content)
		{
			return $content;
		}

		$this->assign_var($template_var, $content);

		return $this;
	}

	/**
	* Get template vars in a format Twig will use.
	*
	* Kept as a protected method for compatibility with subclasses while the
	* implementation is delegated to context_data_builder.
	*
	* @return array
	*/
	protected function get_template_vars()
	{
		return $this->context_data_builder->build();
	}

	/**
	* {@inheritdoc}
	*/
	public function get_source_file_for_handle($handle)
	{
		return $this->renderer->get_source_file_for_handle($handle, $this->filenames);
	}
}

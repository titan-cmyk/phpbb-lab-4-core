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

namespace phpbb\extension\di;

use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\Finder\Finder;
use phpbb\filesystem\helper as filesystem_helper;

/**
 * Container core extension
 */
class extension_base extends Extension
{
	/**
	 * Name of the extension (vendor/name)
	 *
	 * @var string
	 */
	protected $extension_name;

	/**
	 * Path to the extension.
	 *
	 * @var string
	 */
	protected $ext_path;

	/**
	 * Constructor
	 *
	 * @param string $extension_name Name of the extension (vendor/name)
	 * @param string $ext_path       Path to the extension
	 */
	public function __construct($extension_name, $ext_path)
	{
		$this->extension_name = $extension_name;
		$this->ext_path = $ext_path;
	}

	/**
	 * Loads a specific configuration.
	 *
	 * @param array            $configs   An array of configuration values
	 * @param ContainerBuilder $container A ContainerBuilder instance
	 *
	 * @throws \InvalidArgumentException When provided tag is not defined in this extension
	 */
	public function load(array $configs, ContainerBuilder $container): void
	{
		$this->load_services($container);
	}

	/**
	 * Loads the extension service configuration.
	 *
	 * The main environment/services file keeps its existing role. Additional
	 * services_*.yml and services_*.yaml files in the same directory are then
	 * loaded automatically in deterministic filename order.
	 *
	 * Files already loaded through an explicit YAML import are skipped so they
	 * are not processed a second time by the automatic loader.
	 *
	 * @param ContainerBuilder $container A ContainerBuilder instance
	 */
	protected function load_services(ContainerBuilder $container): void
	{
		$services_directory = false;
		$services_file = false;
		$environment = (string) $container->getParameter('core.environment');

		if (file_exists($this->ext_path . 'config/' . $environment . '/container/environment.yml'))
		{
			$services_directory = 'config/' . $environment . '/container';
			$services_file = 'environment.yml';
		}
		else if (!is_dir($this->ext_path . 'config/' . $environment))
		{
			if (file_exists($this->ext_path . 'config/default/container/environment.yml'))
			{
				$services_directory = 'config/default/container';
				$services_file = 'environment.yml';
			}
			else if (!is_dir($this->ext_path . 'config/default') && file_exists($this->ext_path . 'config/services.yml'))
			{
				$services_directory = 'config';
				$services_file = 'services.yml';
			}
		}

		if (!$services_directory || !$services_file)
		{
			return;
		}

		$absolute_services_directory = filesystem_helper::realpath($this->ext_path . $services_directory);
		$loader = new YamlFileLoader($container, new FileLocator($absolute_services_directory));
		$loader->load($services_file);

		foreach ($this->get_services_filenames($absolute_services_directory) as $file)
		{
			$absolute_file = filesystem_helper::realpath($absolute_services_directory . '/' . $file);

			if ($this->is_file_resource_loaded($container, $absolute_file))
			{
				continue;
			}

			$loader->load($file);
		}
	}

	/**
	 * Gets automatically loadable service configuration filenames.
	 *
	 * Files are limited to the selected service directory and sorted by name so
	 * service override order is predictable. Numeric prefixes can therefore be
	 * used when an extension needs an explicit order, for example
	 * services_10_repository.yml and services_20_controller.yml.
	 *
	 * @param string $services_directory Absolute directory containing service files
	 *
	 * @return array<string>
	 */
	protected function get_services_filenames(string $services_directory): array
	{
		$finder = new Finder();
		$finder
			->files()
			->depth('== 0')
			->name('/^services_.*\.ya?ml$/')
			->sortByName()
			->in($services_directory);

		$services_files = [];
		foreach ($finder as $file)
		{
			$services_files[] = $file->getFilename();
		}

		return $services_files;
	}

	/**
	 * Checks whether a service file has already been registered as a container resource.
	 *
	 * This prevents an explicitly imported services_*.yml file from being loaded
	 * again by automatic discovery.
	 *
	 * @param ContainerBuilder $container    A ContainerBuilder instance
	 * @param string           $service_file Absolute service file path
	 *
	 * @return bool
	 */
	protected function is_file_resource_loaded(ContainerBuilder $container, string $service_file): bool
	{
		foreach ($container->getResources() as $resource)
		{
			if ($resource instanceof FileResource && filesystem_helper::realpath((string) $resource) === $service_file)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getConfiguration(array $config, ContainerBuilder $container): ConfigurationInterface|null
	{
		$reflected = new \ReflectionClass($this);
		$namespace = $reflected->getNamespaceName();

		$class = $namespace . '\\di\\configuration';
		if (class_exists($class))
		{
			$r = new \ReflectionClass($class);
			$container->addResource(new FileResource($r->getFileName()));

			if (!method_exists($class, '__construct'))
			{
				$configuration = new $class();

				return $configuration;
			}
		}

		return null;
	}

	/**
	 * Returns the recommended alias to use in XML.
	 *
	 * This alias is also the mandatory prefix to use when using YAML.
	 *
	 * @return string The alias
	 */
	public function getAlias(): string
	{
		return str_replace('/', '_', $this->extension_name);
	}
}

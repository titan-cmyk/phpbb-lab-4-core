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

namespace phpbb\template;

/**
* Base template facade.
*
* PHPBB Lab Core refactor: handle registration is delegated to handle_manager
* and batch root-variable operations are delegated directly to context instead
* of being expanded into repeated public API calls.
*/
abstract class base implements template
{
	/**
	* Template context.
	* Stores template data used during template rendering.
	*
	* @var \phpbb\template\context
	*/
	protected $context;

	/**
	* Array of filenames assigned to set_filenames.
	*
	* Kept for backwards compatibility with subclasses that access the
	* historical protected property directly.
	*
	* @var array
	*/
	protected $filenames = array();

	/** @var \phpbb\template\handle_manager|null */
	private $handle_manager;

	/**
	* {@inheritdoc}
	*/
	public function set_filenames(array $filename_array)
	{
		$this->get_handle_manager()->set_filenames($filename_array);

		return $this;
	}

	/**
	* Get a filename from the handle.
	*
	* @param string $handle
	* @return string
	*/
	protected function get_filename_from_handle($handle)
	{
		return $this->get_handle_manager()->get_filename($handle);
	}

	/**
	* Lazily create the handle manager while preserving the historical
	* protected filenames array as the canonical storage.
	*
	* @return \phpbb\template\handle_manager
	*/
	private function get_handle_manager()
	{
		if (!$this->handle_manager)
		{
			$this->handle_manager = new handle_manager($this->filenames);
		}

		return $this->handle_manager;
	}

	/**
	* {@inheritdoc}
	*/
	public function destroy()
	{
		$this->context->clear();

		return $this;
	}

	/**
	* {@inheritdoc}
	*/
	public function destroy_block_vars($blockname)
	{
		$this->context->destroy_block_vars($blockname);

		return $this;
	}

	/**
	* {@inheritdoc}
	*/
	public function assign_vars(array $vararray)
	{
		$this->context->assign_vars($vararray);

		return $this;
	}

	/**
	* {@inheritdoc}
	*/
	public function assign_var(string $varname, mixed $varval)
	{
		$this->context->assign_var($varname, $varval);

		return $this;
	}

	/**
	* {@inheritdoc}
	*/
	public function append_var($varname, $varval)
	{
		$this->context->append_var($varname, $varval);

		return $this;
	}

	/**
	* {@inheritdoc}
	*/
	public function retrieve_vars(array $vararray)
	{
		return $this->context->retrieve_vars($vararray);
	}

	/**
	* {@inheritdoc}
	*/
	public function retrieve_var($varname)
	{
		return $this->context->retrieve_var($varname);
	}

	/**
	* {@inheritdoc}
	*/
	public function assign_block_vars($blockname, array $vararray)
	{
		$this->context->assign_block_vars($blockname, $vararray);

		return $this;
	}

	/**
	* {@inheritdoc}
	*/
	public function assign_block_vars_array($blockname, array $block_vars_array)
	{
		$this->context->assign_block_vars_array($blockname, $block_vars_array);

		return $this;
	}

	/**
	* {@inheritdoc}
	*/
	public function retrieve_block_vars($blockname, array $vararray)
	{
		return $this->context->retrieve_block_vars($blockname, $vararray);
	}

	/**
	* {@inheritdoc}
	*/
	public function alter_block_array($blockname, array $vararray, $key = false, $mode = 'insert')
	{
		return $this->context->alter_block_array($blockname, $vararray, $key, $mode);
	}

	/**
	* {@inheritdoc}
	*/
	public function find_key_index($blockname, $key)
	{
		return $this->context->find_key_index($blockname, $key);
	}
}

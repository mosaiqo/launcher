<?php

namespace Mosaiqo\Launcher\Console;

/**
 * Class Project
 * @package Mosaiqo\Launcher\Console
 */
class Project {

	/**
	 * @var
	 */
	private $config;

	/**
	 * Project constructor.
	 * @param $config
	 */
	public function __construct($config)
	{
		$this->config = $config;
	}

	public function name()
	{
		return $this->config['name'];
	}

	/**
	 * @return mixed
	 */
	public function directory () {
		return $this->config['directory'];
	}

	/**
	 * @return mixed
	 */
	public function network () {
		return $this->config['network'];
	}

	/**
	 * @param null $field
	 * @return mixed
	 */
	public function registry ($field = null) {
		if ($field && $this->config['registry'][$field]) {
			return $this->config['registry'][$field];
		}

		return $this->config['registry'];
	}

	/**
	 * @return mixed
	 */
	public function services () {
		return $this->config['services'];
	}
}
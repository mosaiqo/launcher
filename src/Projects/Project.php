<?php

namespace Mosaiqo\Launcher\Projects;

/**
 * Class Project
 * @package Mosaiqo\Launcher\Console
 */
/**
 * Class Project
 * @package Mosaiqo\Launcher\Projects
 * @author Boudy de Geer <boudydegeer@mosaiqo.com>
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

	/**
	 * @return mixed
	 */
	public function config()
	{
		return $this->config;
	}

	/**
	 * @return mixed
	 */
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
		if ($field) {
			if (!$this->config['registry'][$field]) {
				return "";
			}

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

	/**
	 * @return mixed
	 */
	public function update ($key, $services) {
		if (key_exists($key, $this->config)) {
			$this->config[$key] = $services;
		}
	}

	/**
	 * @return mixed
	 */
	public function repository () {
		return $this->config['repository'];
	}
}
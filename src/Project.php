<?php

namespace Mosaiqo\Launcher\Console;

class Project {

	private $config;

	public function __construct($config)
	{
		$this->config = $config;
	}

	public function directory () {
		return $this->config['directory'];
	}
}
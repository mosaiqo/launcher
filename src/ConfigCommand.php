<?php

namespace Mosaiqo\Launcher\Console;

use Mosaiqo\Launcher\Console\BaseCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

class ConfigCommand extends BaseCommand
{

	protected $defaults = [
		'PROJECTS_DIRECTORY' => [
			'text' => '¿Which is your main folder, where all your projects are saved? (~/): ',
			'default' => '~/'
		]
	];

	protected $configs = [
		'env' => []
	];

	/**
	 * Configure the command options.
	 *
	 * @return void
	 */
	protected function configure()
	{
		$this
			->setName('config')
			->setDescription('Configures "Launcher"!')
			->addArgument('name', InputArgument::OPTIONAL)
			->addOption('default', 'd', InputOption::VALUE_NONE, 'Use default values for config')
			->addOption('force', 'f', InputOption::VALUE_NONE, 'Overrides the files');
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @return void
	 */
	public function execute(InputInterface $input, OutputInterface $output)
	{
		if ($this->envFileExists()) {
			$this->overrideConfig();
			$this->getConfigInformation();
		}

		$this->createConfig();
	}


	protected function overrideConfig()
	{
		$override = $this->input->getOption('force');

		if (!$override) {
			$override = $this->ask(
				new ConfirmationQuestion (
				'There is already a config file, do you want to override it? [yes/no] (no) ',
				false,
				'/^(y|j)/i'
			));
		}
		// If we don´´ want to override the we just exit;
		if (!$override) return 0;

		$this->removeConfig();
	}

	protected function getConfigInformation()
	{
		foreach ($this->defaults as $key => $value) {
			$inputVal = $this->ask(new Question($value['text'], $value['default']));
			if (strstr($inputVal, " ")) { $inputVal = "'$inputVal'";}
			$this->configs['env'][$key] = $inputVal;
		}
	}

	/**
	 *
	 */
	protected function createConfig()
	{
		$envFile = $this->getEnvFile();

		$this->fileSystem->mkdir($this->getEnvDirectory());
		$this->fileSystem->touch($envFile);

		foreach ($this->configs['env'] as $KEY => $value) {
			if ($KEY === 'PROJECTS_DIRECTORY') {
				$value = $this->getDirectory($value);
			}

			$this->fileSystem->appendToFile($envFile, "$KEY=$value\n");
		}

		$this->info("Launcher config created");
	}

	/**
	 *
	 */
	protected function removeConfig()
	{
		if ($this->envFileExists()) {
			$this->fileSystem->remove($this->getEnvFile());
		}
	}
}
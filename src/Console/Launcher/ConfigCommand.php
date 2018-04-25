<?php

namespace Mosaiqo\Launcher\Console\Launcher;

use Mosaiqo\Launcher\Console\BaseCommand;
use Mosaiqo\Launcher\Exceptions\ExitException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

/**
 * Class ConfigCommand
 * @package Mosaiqo\Launcher\Console\Launcher
 * @author Boudy de Geer <boudydegeer@mosaiqo.com>
 */
class ConfigCommand extends BaseCommand
{

	/**
	 * @var array
	 */
	protected $defaults = [
		'PROJECTS_DIRECTORY' => [
			'text' => '¿Which is your main folder, where all your projects are saved?',
			'default' => '~/Code'
		]
	];

	/**
	 * @var array
	 */
	protected $configs = [
		'env' => []
	];

	/**
	 * @var
	 */
	protected $removeCurrentEnvironmentFile = false;


	/**
	 * @var
	 */
	protected $createLauncherDirectory = true;

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
		try {
			if ($this->launcherEnvironmentDirectoryExists()) {
				if ($this->launcherEnvironmentFileExists() && $this->wantsToKeepCurrentConfig()) {
					throw new ExitException("Launcher is already configured!");
				}
				$this->setLauncherDirectoryToNotCreate();
				$this->setCurrentEnvironmentFileForRemove();
			}

			$this->getConfigInformation();
			$this->createLauncherConfig();
			$this->doneMessage();
		} catch (ExitException $exception) {
			$this->handleExitException($exception);
		}
	}


	/**
	 * @return mixed
	 */
	protected function wantsToKeepCurrentConfig()
	{
		// If we don´t want to override the we just exit;
		$wants = $this->force?:$this->askConfirmation(
			"There is already a config file, do you want to override it?",
			false
		);

		return !$wants;
	}


	/**
	 * Gets the config information
	 */
	protected function getConfigInformation()
	{
		$this->header("We need some info from you:");
		if ($this->useDefault) {
			$this->text("Using default values for config!\n");
		}
		foreach ($this->defaults as $key => $value) {
			$inputValue =  $this->askForConfig($value['text'], $value['default']);
			$this->configs['env'][$key] = $inputValue;
		}
	}

	/**
	 *
	 */
	protected function createLauncherConfig()
	{
		$this->header("Creating config...");
		$environmentFile = $this->launcherEnvironmentFile();

		if ($this->createLauncherDirectory) {
			$this->fileSystem->mkdir($this->launcherEnvironmentDirectory());
			$this->fileSystem->mkdir($this->launcherProjectsDirectory());
		}

		if ($this->removeCurrentEnvironmentFile) {
			$this->removeEnvironmentFile();
			$this->fileSystem->touch($environmentFile);
		}

		foreach ($this->configs['env'] as $KEY => $value) {
			if ($KEY === 'PROJECTS_DIRECTORY') {
				$value = $this->getDirectory($value);
				if (!$this->fileSystem->exists($this->getDirectory($value))) {
					throw new ExitException("That directory doesn't exist");
				}
			}

			$this->fileSystem->appendToFile($environmentFile, "$KEY=$value\n");
		}
	}

	/**
	 *
	 */
	protected function setCurrentEnvironmentFileForRemove()
	{
		$this->removeCurrentEnvironmentFile = true;
	}

	/**
	 *
	 */
	protected function removeEnvironmentFile()
	{
		$this->fileSystem->remove($this->launcherEnvironmentFile());
	}

	/**
	 *
	 */
	protected function setLauncherDirectoryToNotCreate()
	{
		$this->createLauncherDirectory = false;
	}

}
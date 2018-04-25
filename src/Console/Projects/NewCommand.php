<?php

namespace Mosaiqo\Launcher\Console\Projects;

use Mosaiqo\Launcher\Console\BaseCommand;
use Mosaiqo\Launcher\Exceptions\ExitException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Finder\Finder;

/**
 * Class NewCommand
 * @package Mosaiqo\Launcher\Console
 */
class NewCommand extends BaseCommand
{
	/**
	 * @var array
	 */
	private $defaults = [
		'LAUNCHER_PROJECT_NAME' => [
			'text' => 'What is your project\'s name',
			'default' => "<projectname>"
		],
		'LAUNCHER_PROJECT_DIRECTORY' => [
			'text' => 'Which directory do you want this project in',
			'default' => "<projectdirectory>"
		],
		'LAUNCHER_REPOSITORY' => [
			'text' => 'The repository url too clone it?',
			'default' => null
		],
		'LAUNCHER_TLD' => [
			'text' => 'Which TLD do you want to use?',
			'default' => "local"
		],
		'LAUNCHER_REGISTRY_URL' => [
			'text' => 'Which docker registry do you want to use?',
			'default' => null,
		],
		'LAUNCHER_REGISTRY_USER' => [
			'text' => 'Which is the user to connect to the registry?',
			'default' => null
		],
		'LAUNCHER_REGISTRY_TOKEN' => [
			'text' => 'We need a token to connect to the registry',
			'default' => null
		],
		'LAUNCHER_NETWORK_NAME' => [
			'text' => 'Which name do you want for your network?',
			'default' => "<projectname>-network"
		],
		'LAUNCHER_EDITOR' => [
			'text' => 'Which is your preferred editor?',
			'default' => "pstorm"
		]
	];

	/**
	 * @var array
	 */
	private $configs = [
		'env' => [],
		'services' => []
	];

	/**
	 * @var
	 */
	protected $projectDirectory;


	/**
	 * @var bool
	 */
	protected $overrideProject = false;

	/**
	 * Configure the command options.
	 *
	 * @return void
	 */
	protected function configure()
	{
		$this
			->setName('project:new')
			->setDescription('Creates a new Project')
			->addArgument('name', InputArgument::OPTIONAL)
			->addOption('repository', 'r', InputOption::VALUE_OPTIONAL, 'Repository to start the project')
			->addOption('directory', 'D', InputOption::VALUE_OPTIONAL, 'Directory where the project would be located')
			->addOption('start', 's', InputOption::VALUE_NONE, 'Start after create')
			->addOption('config', 'c', InputOption::VALUE_NONE, 'Only apply config (This is meant for old projects)')
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
			if (!$this->isLauncherConfigured()) {
				$this->configureLauncher();
			}

			if ($this->doesProjectExists()) {
				$this->askToOverrideProject();

				if (!$this->shouldBeOverWritten()) {
					throw new ExitException("Launcher Project already exists!");
				}

				$this->removeExistentConfigForProject();
			}

			$this->getInfoForConfig();
			$this->showConfigToApply();
			$this->askIfConfigIsCorrect();
			$this->saveConfig();

			$this->loadCommonConfig();

			$this->removeExistentDirectory();
			$this->createProjectDirectory();

			$this->initProject();
			$this->copyFilesToProject();
			$this->addFilesToRepository();

			if ($this->shouldBeStarted()) {
				$this->runStartCommand();
			}

		} catch (ExitException $e) {
			$this->handleExitException($e);
		}
	}

	/**
	 *
	 */
	protected function getInfoForConfig()
	{
		 //$this->useDefault
		foreach ($this->defaults as $key => $value) {
			$ask = true;
			$inputValue = null;

			if ($key === "LAUNCHER_PROJECT_NAME") {
				$name = $this->projectName;
				$name = strpos($name, '/') !== false ? end(explode('/', $name)) : $name;
				$value = $this->replaceForConfig($value, '<projectname>', $name);
			}

			if ($key === "LAUNCHER_PROJECT_DIRECTORY") {
				$directory = $this->input->getOption('directory') ?
					$this->input->getOption('directory') :
					strtolower($this->projectName);
				$value = $this->replaceForConfig($value, '<projectdirectory>', $this->getDirectory($directory));
			}

			// If the user already provided a repo we don't ask him
			if ($key === "LAUNCHER_REPOSITORY" && $this->input->getOption('repository')) {
				$inputValue = $this->input->getOption('repository');
				$ask = false;
			}

			if ($key === "LAUNCHER_NETWORK_NAME") {
				$networkName = strtolower($this->configs['env']['LAUNCHER_PROJECT_NAME']);
				$value = $this->replaceForConfig($value, '<projectname>', $networkName);
			}

			if ($ask) {
				$inputValue = $this->askForConfig($value['text'], $value['default']);
			}

			if ($this->useDefault) {
				$inputValue = $value['default'];
			}

			$this->configs['env'][$key] = $inputValue;
		}
	}

	/**
	 * @return mixed
	 */
	protected function projectDirectoryExists()
	{
		return $this->fileSystem->exists($this->project->directory());
	}

	/**
	 * @return mixed
	 */
	protected function projectConfigExists()
	{
		$this->getLauncherConfigFileForProject($this->projectName);
		return $this->fileSystem->exists($this->getLauncherConfigFileForProject($this->projectName));
	}

	/**
	 *
	 */
	protected function removeExistentDirectory()
	{
		if ($this->projectDirectoryExists()) {
			$this->fileSystem->remove($this->project->directory());
		}
	}

	/**
	 *
	 */
	protected function createProjectDirectory () {
		$this->fileSystem->mkdir($this->project->directory());
	}

	/**
	 *
	 */
	protected function copyFilesToProject()
	{
		$directory = LAUNCHER_DIR . DIRECTORY_SEPARATOR;
		$this->fileSystem->mirror("$directory/files/project", $this->project->directory());
	}

	/**
	 *
	 */
	protected function showConfigToApply()
	{
		$this->text("\n\nThis is the config:\n\n");
		foreach ($this->configs['env'] as $KEY => $config) {
			$key = str_replace("LAUNCHER_", "", $KEY);
			$key = str_replace("_", " ", $key);
			$this->info("$key => $config");
		}

		$this->text("\n");
	}

	/**
	 * @return void
	 */
	protected function askIfConfigIsCorrect()
	{
		try {
			if ($this->force ?: $this->askConfirmation('Does this look ok?', true)) {
				throw new ExitException("Your config is not applied please run the command again");
			}
		} catch (ExitException $exception) {
			$this->handleExitException($exception);
		}
	}

	/**
	 *
	 */
	protected function saveConfig()
	{
		$content = '';
		$finder = new Finder();
		$finder->files()->name("project.json");
		$files = $finder->in(LAUNCHER_DIR . DIRECTORY_SEPARATOR . "files/");

		foreach ($files as $file) {
			$content = $file->getContents();
		}

		foreach ($this->configs['env'] as $KEY => $value) {
			$content = str_replace($KEY, "\"$value\"", $content);
		}
		$fileName = $this->getLauncherConfigFileForProject($this->projectName);
		$this->fileSystem->appendToFile($fileName, $content);

		$this->text("Project config file <comment>$fileName</comment> was saved!\n");
	}

	/**
	 *
	 */
	protected function initProject()
	{
		$repository =  $this->input->getOption('repository');

		$commands = [];

		// If no repository is provided we initialize one
		if (!$repository) {
			array_push($commands,
				"git init",
				"git add -A",
				"git commit -m 'Initial Commit'"
			);
		} else {
			array_push($commands,
				"git clone --recurse-submodules {$repository} ."
			);
		}

		$this->runCommands($commands, $this->project->directory());
	}

	/**
	 *
	 */
	protected function isInitialized()
	{
		return $this->fileSystem->exists($this->projectDirectory . DIRECTORY_SEPARATOR . ".git");
	}

	/**
	 *
	 */
	protected function runStartCommand()
	{
		$command = $this->getApplication()->find('project:start');
		try {
			$command->run(
				new ArrayInput([
					'name' => $this->project->name(),
					'-f' => $this->input->getOption('force'),
					'-d' => $this->input->getOption('default'),
				]), $this->output);
		} catch (\Exception $e) {
			var_dump($e);
		}
	}


	/**
	 *
	 */
	protected function addFilesToRepository()
	{
		$repository =  $this->input->getOption('repository');

		$commands = [];

		// If no repository is provided we initialize one
		if (!$repository) {
			array_push($commands,
				"git add -A",
				"git commit -m 'Initial Commit'"
			);
		} else {
			array_push($commands,
				"git submodule init",
				"git submodule update --merge --remote"
			);
		}

		$this->runCommands($commands, $this->project->directory());
	}

	/**
	 */
	protected function removeExistentConfigForProject()
	{
		$this->fileSystem->remove($this->getLauncherConfigFileForProject($this->projectName));
	}

	/**
	 * @return void
	 */
	protected function askToOverrideProject()
	{
		$this->overrideProject = $this->force ?: $this->askConfirmation(
			"This project already exists,\nit will be over written and this can not be undone are you sure?",
			false
		);
	}

	/**
	 * @return bool
	 */
	protected function shouldBeOverWritten()
	{
		return $this->overrideProject;
	}

	/**
	 * @return mixed
	 */
	protected function shouldBeStarted()
	{
		return $this->input->getOption('start');
	}
}
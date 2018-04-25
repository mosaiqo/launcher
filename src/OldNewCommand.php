<?php

namespace Mosaiqo\Launcher;

use Mosaiqo\Launcher\Console\BaseCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

/**
 * Class OldNewCommand
 * @package Mosaiqo\Launcher\Console
 */
class OldNewCommand extends BaseCommand
{
	/**
	 * @var array
	 */
	private $defaults = [
		'LAUNCHER_PROJECT_NAME' => [
			'text' => 'What is your project\'s name (<projectname>): ',
			'default' => "<projectname>"
		],
		'LAUNCHER_REPOSITORY' => [
			'text' => 'The repository url too clone it?: ',
			'default' => null
		],
		'LAUNCHER_TLD' => [
			'text' => 'Which TLD do you want to use? (local): ',
			'default' => "local"
		],
		'LAUNCHER_REGISTRY_URL' => [
			'text' => 'Which docker registry do you want to use?: ',
			'default' => null,
		],
		'LAUNCHER_REGISTRY_USER' => [
			'text' => 'Which is the user to connect to the registry?: ',
			'default' => null
		],
		'LAUNCHER_REGISTRY_TOKEN' => [
			'text' => 'We need a token to connect to the registry: ',
			'default' => null
		],
		'LAUNCHER_NETWORK_NAME' => [
			'text' => 'Which name do you want for your network? (<projectname>-network): ',
			'default' => "<projectname>-network"
		],
		'LAUNCHER_EDITOR' => [
			'text' => 'Which is your preferred editor? (pstorm) : ',
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
	 * Configure the command options.
	 *
	 * @return void
	 */
	protected function configure()
	{
		$this
			->setName('new')
			->setDescription('Creates a new Project')
			->addArgument('name', InputArgument::OPTIONAL)
			->addOption('repository', 'r', InputOption::VALUE_OPTIONAL, 'Repository to start the project')
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
		if (!$this->envFileExists()) {}
		$this->loadEnv();
		$this->configureNewProject();
		$this->createNewProject();
	}

	protected function configureNewProject()
	{
		die('aki');
	}


	/**
	 * @return int
	 */
	protected function createNewProject()
	{
		$this->projectDirectory = $this->getProjectDirectory();
		$deleteIt = true;
		$force = $this->input->getOption('force');
		$onlyConfig = $this->input->getOption('config');
		$createIt = $force?:$this->ask(new ConfirmationQuestion(
			"Are you sure you want to create a project in [$this->projectDirectory]? [Y|N] (yes): ",
			true,
			'/^(y|j)/i'
		));

		if ($this->projectDirectoryExists() && !$onlyConfig) {
			$deleteIt = $force?:$this->ask(new ConfirmationQuestion(
				"This directory already exists [$this->projectDirectory],\nit will be deleted and this can not be undone are you sure? [Y|N] (no): ",
				false,
				'/^(y|j)/i'
			));
			if (!$deleteIt) return 0;
		}

		if ($createIt) {
			if ($this->input->getOption('default'))
				$this->getDefaultConfig();
			else
				$this->askUserForConfig();
		}

		$this->showConfigToApply();
		if (!$this->askIfConfigIsCorrect()) return 0;

		// We only create a new project if the user don't say it
		if ($deleteIt) {
			$this->removeExistentDirectory();
			$this->createDirectory();
			$this->initProject();
			$this->copyFilesToProject();
			$this->addFilesToRepository();
		}

		$this->applyConfig();

		if ($this->input->getOption('start') ) {
			$this->runStartCommand();
		}
	}

	/**
	 * @return mixed
	 */
	protected function projectDirectoryExists()
	{
		return $this->fileSystem->exists($this->projectDirectory);
	}

	/**
	 *
	 */
	protected function removeExistentDirectory()
	{
		if ($this->projectDirectoryExists()) {
			$this->fileSystem->remove($this->projectDirectory);
		}
	}

	/**
	 *
	 */
	protected function getDefaultConfig()
	{
		foreach ($this->defaults as $key => $value) {
			$inputVal = $value['default'];
			if ($key === "LAUNCHER_PROJECT_NAME") {
				$inputVal = $this->input->getArgument('name');
				$name = strpos($inputVal, '/') !== false ? end(explode('/', $inputVal)) : $inputVal;
				$inputVal = str_replace('<projectname>', $name, $inputVal);
			}

			if ($this->input->getOption('repository') && $key === "LAUNCHER_REPOSITORY") {
				$inputVal = $this->input->getOption('repository');
			}

			if ($key === "LAUNCHER_NETWORK_NAME") {
				$inputVal = str_replace('<projectname>', $this->configs['env']['LAUNCHER_PROJECT_NAME'], $inputVal);
			}
			if (strstr($inputVal, " ")) { $inputVal = "'$inputVal'";}
			$this->configs['env'][$key] = $inputVal;
		}
	}

	/**
	 *
	 */
	protected function askUserForConfig()
	{
		foreach ($this->defaults as $key => $value) {
			$ask = true;
			if ($key === "LAUNCHER_PROJECT_NAME") {
				$name = $this->input->getArgument('name');
				$name = strpos($name, '/') !== false ? end(explode('/', $name)) : $name;
				$value['text'] = str_replace('<projectname>', $name, $value['text']);
				$value['default'] = str_replace('<projectname>', $name, $value['default']);
			}

			// If the user allready provided a repo we dont ask him
			if ($this->input->getOption('repository') && $key === "LAUNCHER_REPOSITORY") {
				$inputVal = $this->input->getOption('repository');
				$ask = false;
			}

			if ($key === "LAUNCHER_NETWORK_NAME") {
				$networkName = strtolower($this->configs['env']['LAUNCHER_PROJECT_NAME']);
				$value['text'] = str_replace('<projectname>',$networkName, $value['text']);
				$value['default'] = str_replace('<projectname>',$networkName, $value['default']);
			}

			if ($ask) {
				$inputVal = $this->ask(new Question($value['text'], $value['default']));
			}

			if (strstr($inputVal, " ")) { $inputVal = "'$inputVal'";}
			$this->configs['env'][$key] = $inputVal;
		}
	}

	/**
	 *
	 */
	protected function createDirectory () {
		$this->fileSystem->mkdir($this->projectDirectory);
	}

	/**
	 *
	 */
	protected function copyFilesToProject()
	{
		$directory = __DIR__ . DIRECTORY_SEPARATOR . "/..";
		$this->fileSystem->mirror("$directory/files", "$this->projectDirectory");
	}

	/**
	 *
	 */
	protected function showConfigToApply()
	{
		$this->header("This is the config:");
		foreach ($this->configs['env'] as $KEY => $config) {
			$this->info("$KEY=$config");
		}

		$this->text("\n");
	}

	/**
	 * @return int
	 */
	protected function askIfConfigIsCorrect()
	{
		$apply = $this->ask(new ConfirmationQuestion(
			'Does this look ok? [yes|no] (yes): ',
			true,
			'/^(y|j)/i'
		));
		if (!$apply) {
			$this->header("Your config is not applied please run the command again");
		}

		return $apply;
	}

	/**
	 *
	 */
	protected function applyConfig()
	{
		$file = $this->getLauncherFileForProject();

		foreach ($this->configs['env'] as $KEY => $value) {
			$this->fileSystem->appendToFile($file, "$KEY=$value\n");
		}

		$this->info("Project Config is created at [$this->projectDirectory]");
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

		$this->runCommands($commands, $this->projectDirectory);
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
		$command = $this->getApplication()->find('start');
		try {
			$command->run(
				new ArrayInput([
					'name' => $this->input->getArgument('name'),
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

		$this->runCommands($commands, $this->projectDirectory);
	}
}
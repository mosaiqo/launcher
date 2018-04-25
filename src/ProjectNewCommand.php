<?php

namespace Mosaiqo\Launcher\Console;

use Mosaiqo\Launcher\Console\BaseCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Finder\Finder;

/**
 * Class ProjectNewCommand
 * @package Mosaiqo\Launcher\Console
 */
class ProjectNewCommand extends BaseCommand
{
	/**
	 * @var array
	 */
	private $defaults = [
		'LAUNCHER_PROJECT_NAME' => [
			'text' => 'What is your project\'s name (<projectname>): ',
			'default' => "<projectname>"
		],
		'LAUNCHER_PROJECT_DIRECTORY' => [
			'text' => 'Which directory do you want this project in (<projectdirectory>): ',
			'default' => "<projectdirectory>"
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
	 * @var
	 */
	protected $force;

	/**
	 * @var
	 */
	protected $projectName;

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
		if (!$this->envFileExists()) {}

		$this->force = $this->input->getOption('force');
		$this->projectDirectory = $this->getProjectDirectory();
		$this->loadEnv();

		$this->configureNewProject();
		if (!$this->askIfConfigIsCorrect()) return 0;

		$this->createNewProject();
	}

	/**
	 *
	 */
	protected function configureNewProject()
	{
		if ($this->input->getOption('default'))
			$this->getDefaultConfig();
		else
			$this->askUserForConfig();

		$this->showConfigToApply();
	}


	/**
	 * @return int
	 */
	protected function createNewProject()
	{
		$deleteIt = true;
		$this->projectName = $this->configs['env']['LAUNCHER_PROJECT_NAME'];

		$onlyConfig = $this->input->getOption('config');

//		$createIt = $this->force?:$this->ask(new ConfirmationQuestion(
//			"Are you sure you want to create a project in [$this->projectDirectory]? [Y|N] (yes): ",
//			true,
//			'/^(y|j)/i'
//		));

//		if ($this->projectDirectoryExists()) {
//			$deleteIt = $this->force?:$this->ask(new ConfirmationQuestion(
//				"This directory already exists [$this->projectDirectory],\nit will be deleted and this can not be undone are you sure? [Y|N] (no): ",
//				false,
//				'/^(y|j)/i'
//			));
//			if (!$deleteIt) return 0;
//		}

		if ($this->projectConfigExists()) {
			$deleteIt = $this->force?:$this->ask(new ConfirmationQuestion(
				"There is already a project called <comment>$this->projectName</comment>,\nit will be deleted and this can not be undone are you sure? [Y|N] (no): ",
				false,
				'/^(y|j)/i'
			));
			if (!$deleteIt) return 0;
		}

		if ($deleteIt) {
			$this->removeExistentConfigFor($this->projectName);
		}

		$this->saveConfig();

		$this->loadConfigForProject($this->projectName);

		// We only create a new project if the user don't say it
		if ($deleteIt) {
			$this->removeExistentDirectory();
			$this->createDirectory();
			$this->initProject();
			$this->copyFilesToProject();
			$this->addFilesToRepository();
		}


		if ($this->input->getOption('start') ) {
			$this->runStartCommand();
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
	protected function getDefaultConfig()
	{
		foreach ($this->defaults as $key => $value) {
			$inputVal = $value['default'];
			if ($key === "LAUNCHER_PROJECT_NAME") {
				$inputVal = $this->input->getArgument('name');
				$name = strpos($inputVal, '/') !== false ? end(explode('/', $inputVal)) : $inputVal;
				$inputVal = str_replace('<projectname>', $name, $inputVal);
			}

			if ($key === "LAUNCHER_PROJECT_DIRECTORY") {
				$inputVal = $this->input->getOption('directory') ?
					$this->getDirectory($this->input->getOption('directory')) :
					$this->input->getArgument('name');

				$inputVal = $this->getDirectory($inputVal);
				$value['text'] = str_replace('<projectdirectory>', $inputVal, $value['text']);
				$value['default'] = str_replace('<projectdirectory>', $inputVal, $value['default']);
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

			if ($key === "LAUNCHER_PROJECT_DIRECTORY") {
				$directory = $this->input->getOption('directory') ?
					$this->getDirectory($this->input->getOption('directory')) :
					$this->input->getArgument('name');

				$directory = $this->getDirectory($directory);
				$value['text'] = str_replace('<projectdirectory>', $directory, $value['text']);
				$value['default'] = str_replace('<projectdirectory>', $directory, $value['default']);
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
		$this->fileSystem->mkdir($this->project->directory());
	}

	/**
	 *
	 */
	protected function copyFilesToProject()
	{
		$directory = __DIR__ . DIRECTORY_SEPARATOR . "/..";
		$this->fileSystem->mirror("$directory/files", $this->project->directory());
	}

	/**
	 *
	 */
	protected function showConfigToApply()
	{
		$this->header("This is the config:");
		foreach ($this->configs['env'] as $KEY => $config) {
			$key = str_replace("LAUNCHER_", "", $KEY);
			$key = str_replace("_", " ", $key);
			$this->info("$key : $config");
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
	protected function saveConfig()
	{
		$name = $this->configs['env']['LAUNCHER_PROJECT_NAME'];
		$directory = __DIR__ . DIRECTORY_SEPARATOR . "/..";

		$finder = new Finder();
		$finder->files()->name("project.json");
		foreach ($finder->in("$directory/files/") as $file) {
			$content = $file->getContents();
			foreach ($this->configs['env'] as $KEY => $value) {
				$content = str_replace($KEY, "\"$value\"", $content);
			}

			$this->fileSystem->appendToFile($this->getLauncherConfigFileForProject($name), $content);
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

	/**
	 * @param $projectName
	 */
	protected function removeExistentConfigFor($projectName)
	{
		$this->fileSystem->remove($this->getLauncherConfigFileForProject($projectName));
	}
}
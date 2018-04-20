<?php

namespace Mosaiqo\Launcher\Console;

use Mosaiqo\Launcher\Console\BaseCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

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
			'text' => 'What is your project\'s name: ',
			'default' => "argument:name"
		],
		'LAUNCHER_TLD' => [
			'text' => 'Which TLD do you want to use?: ',
			'default' => "test"
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
			'default' => null
		],
		'LAUNCHER_EDITOR' => [
			'text' => 'Which is your prefered editor?: ',
			'default' => "pstorm"
		],
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

		$this->createNewProject();

	}

	/**
	 * @return int
	 */
	protected function createNewProject()
	{
		$this->projectDirectory = $this->getProjectDirectory();
		$createIt = $this->ask(new ConfirmationQuestion(
			"Are you sure you want to create a project in [$this->projectDirectory]? [Y|N] (yes): ",
			true,
			'/^(y|j)/i'
		));

		if ($this->projectDirectoryExists()) {
			$deleteIt = $this->ask(new ConfirmationQuestion(
				"This directory already exists [$this->projectDirectory],\n it will be deleted and this can not be undone are you sure? [Y|N] (no): ",
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
		$this->askIfConfigIsCorrect();
		$this->removeExistentDirectory();
		$this->copyFilesToProject();
		$this->applyConfig();
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
		$name = $this->input->getArgument('name');
	}

	/**
	 *
	 */
	protected function askUserForConfig()
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
	protected function copyFilesToProject()
	{
		$directory = __DIR__ . DIRECTORY_SEPARATOR . "/..";

		$this->fileSystem->mkdir($this->projectDirectory);
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
		if (!$this->ask(new ConfirmationQuestion(
			'Does this look ok? [yes|no] (yes)',
			true,
			'/^(y|j)/i'
		))) {
			$this->header("Your config is not applied please run the command again");
			return 0;
		}
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
}
<?php

namespace Mosaiqo\Launcher\Console\Services;

use Mosaiqo\Launcher\Console\BaseCommand;
use Mosaiqo\Launcher\Exceptions\ExitException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Finder\Finder;

/**
 * Class ServiceAddCommand
 * @package Mosaiqo\Launcher\Console
 */
class AddCommand extends BaseCommand
{

	/**
	 * @var
	 */
	protected $projectDirectory;

	/**
	 * @var
	 */
	protected $serviceType;

	/**
	 * @var array
	 */
	protected $serviceTypes = ['laravel-app', 'laravel-api', 'vue-frontend', 'vue-spa', 'vue-mobile', 'git'];

	/**
	 * @var
	 */
	protected $repository;

	/**
	 * Configure the command options.
	 *
	 * @return void
	 */
	protected function configure()
	{
		$this
			->setName('service:add')
			->setDescription('Adds a service to a Launcher Project')
			->addArgument('name', InputArgument::REQUIRED)
			->addArgument('service',  InputArgument::REQUIRED, 'The services you like to create')
			->addOption('type', '-t', InputOption::VALUE_OPTIONAL, 'The type of project')
			->addOption('repository', '-r', InputOption::VALUE_OPTIONAL, 'The repository for an existent git repo.');
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @return int
	 */
	public function execute(InputInterface $input, OutputInterface $output)
	{
		try {
			if (!$this->isLauncherConfigured()) {
				$this->info("Launcher is not configured!");
			}

			$this->loadConfigForProject($this->projectName);

			$this->addService();

		}  catch (ExitException $e) {
			$this->handleExitException($e);
		}
	}

	/**
	 *
	 */
	protected function addService()
	{
		$serviceName = $this->input->getArgument('service');
		$existentServices = $this->project->services();

		if (in_array($serviceName, $existentServices)) {
			$this->write("\nService with the name <comment>$serviceName</comment> already exists.");
			$this->write("\n<comment>=============================</comment>\n\n\n");
			return 0;
		}

		$this->write("Creating new service: <comment>$serviceName</comment>!\n");

		$this->getServiceType();
		$this->createServiceByType($serviceName);
		$this->initGitRepository($serviceName);
		$this->addServiceToSubmodule($serviceName);
	}


	/**
	 * @return mixed
	 */
	protected function getServiceType()
	{
		if ($this->serviceType !== null) return;

		$type = $this->input->getOption('type');

		if (!$type || !in_array($type, $this->serviceTypes)) {
			$type = $this->ask(
				new ChoiceQuestion(
					'Please select the type of service you would like to install ('. $this->serviceTypes[0] .'): ',
					 $this->serviceTypes,
					'1'
				)
			);
		}

		$this->serviceType = $type;
	}

	/**
	 * @param $serviceName
	 * @return int
	 */
	protected function createServiceByType($serviceName)
	{
		$cmd = null;

		$this->repository =  $this->input->getOption('repository');

		switch ($this->serviceType) {
			case 'git':
				$this->repository = $this->repository ? : $this->ask(
					new Question('Please give us the repository to initialize: ')
				);

				if (!$this->repository) {
					$this->info("We need a repository to continue");
					return 1;
				}
				break;
			case 'laravel-app':
			case 'laravel-api':
				$cmd = "laravel new {$serviceName} || exit 1";
				break;
			case 'vue-frontend':
			case 'vue-spa':
			case 'vue-mobile':
				$cmd = "vue init webpack {$serviceName} || exit 1";
				break;
			default;
				return 0;
		}

		if ($cmd) {
			$this->runCommand($cmd, $this->getServicesFolderForProject());
		}
	}

	/**
	 * @return void
	 */
	protected function initGitRepository($serviceName)
	{
		$commands = [];
		$path = $this->getServicesFolderForProject() . DIRECTORY_SEPARATOR . $serviceName;

		// If no repository is provided we initialize one
		if (!$this->fileSystem->exists($path . DIRECTORY_SEPARATOR . ".git")) {
			array_push($commands,
				"git init",
				"git add -A",
				"git commit -m 'Initial Commit'"
			);
		}

		$this->runCommands($commands, $path);
	}

	/**
	 * @param $serviceName
	 * @return void
	 */
	protected function addServiceToSubmodule($serviceName)
	{
		$commands = [];
		$path = $this->getServicesFolderForProject();

		// If no repository is provided we initialize one
		if (!$this->repository) {
			array_push($commands, "git submodule add --name {$serviceName} {$path} services/{$serviceName}");
		} else {
			array_push($commands, "git submodule add --name {$serviceName} {$this->repository} services/{$serviceName}");
		}

		$isClean = $this->runNonTtyCommand('git status --untracked-files=no --porcelain', $this->getProjectDirectory());

		$this->info($isClean);

		if ($isClean) {
			array_push($commands,
				"git add -A",
				"git commit -m 'Add service {$serviceName} as submodule.'"
			);
		} else {
			$this->error("We could not commit because you repository is not clean, you need to commit it your self");
			$this->write("You can do:");
			$this->write("git add services/{$serviceName} .gitmodules");
			$this->write("git commit -m 'Add service {$serviceName} as submodule.'");
		}

		$this->runCommands($commands, $this->getProjectDirectory());

		$this->updateServices();
	}
}
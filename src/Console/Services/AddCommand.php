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
	protected $serviceTypes = ['laravel-app', 'laravel-api', 'vue-frontend', 'vue-spa', 'vue-mobile', 'git', 'existent'];

	/**
	 * @var
	 */
	protected $repository;

	/**
	 * @var
	 */
	protected $isNotClean;

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
			$this->repository = $input->hasOption('repository') ? $input->getOption('repository') : null;
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
		$this->isClean();
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

		if ($this->repository !== null) {
			$key = array_search('git', $this->serviceTypes);
			$type = $this->serviceTypes[$key];
		}

		if (!$type) {
			$type = $this->input->getOption('type');

			if (!in_array($type, $this->serviceTypes)) {
				$type = $this->ask(
					new ChoiceQuestion(
						'Please select the type of service you would like to install ('. $this->serviceTypes[0] .'): ',
						$this->serviceTypes,
						'1'
					)
				);
			}
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

		switch ($this->serviceType) {
			case 'git':
				$this->repository = $this->repository ? : $this->ask(
					new Question('Please give us the repository to initialize: ')
				);

				if (!$this->repository) {
					$this->info("We need a repository to continue");
					return 1;
				}
				$cmd = "git clone {$this->repository} {$serviceName} || exit 1";
				break;
			case 'existent':
				$cmd = null;
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
	 * @return void
	 */
	protected function isClean()
	{
		$this->isNotClean = $this->runNonTtyCommand('git status --untracked-files=no --porcelain', $this->getProjectDirectory());
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

		if (!$this->isNotClean) {
			array_push($commands,
				"git add -A",
				"git commit -m 'Add service {$serviceName} as submodule.'"
			);
		} else {
			$this->error("We could not commit because you repository is not clean, you need to commit it your self");
			$this->write("You can do:\n");
			$this->write("git add services/{$serviceName} .gitmodules \n");
			$this->write("git commit -m 'Add service {$serviceName} as submodule.'");
		}

		$this->runCommands($commands, $this->getProjectDirectory());

		$this->updateServices();
	}
}
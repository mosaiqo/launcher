<?php

namespace Mosaiqo\Launcher;

use Mosaiqo\Launcher\Console\BaseCommand;
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
class ServiceAddCommand extends BaseCommand
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
	protected $serviceTypes = ['laravel-app', 'laravel-api', 'vue-frontend', 'vue-mobile', 'git'];

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
		$this->loadEnv();
		$this->loadLauncherEnv();
		if (!$this->isLauncherProject()) {
			$this->info('This is not a launcher project');
			return 0;
		}

		$this->projectDirectory = $this->getProjectDirectory();
		$this->addService();
	}

	/**
	 *
	 */
	protected function addService()
	{
		$serviceName = $this->input->getArgument('service');
		$existentServices = [];
		$directories = $this->finder->directories()->depth(0)->in($this->getServicesDirectory());

		foreach ($directories as $service) {
			$existentServices[] = $service->getFilename();
		}

		if (in_array($serviceName, $existentServices)) {
			$this->write("\nService with the name <comment>$serviceName</comment> already exists.");
			$this->write("\n<comment>=============================</comment>\n\n\n");
			return 0;
		}

		$this->write("Creating new service: <comment>$serviceName</comment> ! \n");
		$this->loadConfigFileForProject($serviceName);

		$this->getServiceType();

		$this->createServiceByType($serviceName);

		if ($this->serviceType === 'git' && !$this->repository) {
			$this->info("We need a repository to continue");
			return 1;
		}

		$this->initGitRepository($serviceName);
		$this->addServiceToSubmodule($serviceName);
	}


	/**
	 * @param $serviceName
	 */
	protected function loadServiceEnvFile($serviceName)
	{
		$file = $this->getFileForService('.env', $serviceName);
		$dotenv = new Dotenv();
		if ($this->fileSystem->exists($file)) {
			$dotenv->load($file);
		}
		$WWWUSER = (int)posix_getuid();
		$dotenv->populate([
			'UID' => $WWWUSER,
			'WWWUSER' => $WWWUSER,
			'XDEBUG_HOST' => '127.0.0.1',
			'TLD' => getenv('LAUNCHER_TLD'),
		]);
//		$this->runCommand("export WWWUSER=$WWWUSER");
	}


	/**
	 * @return string
	 */
	protected function getServicesDirectory()
	{
		return $this->getProjectDirectory() . DIRECTORY_SEPARATOR . 'services/';
	}


	/**
	 * @param $serviceName
	 * @return string
	 */
	protected function getDirectoryForService($serviceName)
	{
		return $this->getServicesDirectory() . $serviceName;
	}

	/**
	 * @param $file
	 * @param $serviceName
	 * @return string
	 */
	protected function getFileForService($file, $serviceName)
	{
		return $this->getServicesDirectory() . $serviceName . DIRECTORY_SEPARATOR . $file;
	}

	/**
	 * @param $serviceName
	 * @return mixed
	 */
	protected function doesServiceHaveDockerFile($serviceName)
	{
		return $this->fileSystem->exists($this->getFileForService('docker-compose.yml', $serviceName));
	}

	/**
	 * @param $serviceName
	 * @return mixed
	 */
	protected function doesServiceHaveDockerDevFile($serviceName)
	{
		return $this->fileSystem->exists($this->getFileForService('docker-compose.dev.yml', $serviceName));
	}


	/**
	 * @param $serviceName
	 * @return array|mixed
	 */
	protected function loadConfigFileForProject($serviceName)
	{
		$fileName = 'launcher.json';
		$configFile = $this->getFileForService($fileName, $serviceName);
		$config = [];
		if ($this->fileSystem->exists($configFile)) {
			$this->comment("Launcher config file found for $serviceName \n");
			$this->finder->files()->in($this->getDirectoryForService($serviceName))->name($fileName);
			foreach ($this->finder as $file) {
				$content = $file->getContents();
				$config = json_decode($content, true);
			}
		}
		return $config;
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
				break;
			case 'laravel-app':
			case 'laravel-api':
				$cmd = "laravel new {$serviceName} || exit 1";
				break;
			case 'vue-spa':
			case 'vue-mobile':
				$cmd = "vue init webpack {$serviceName} || exit 1";
				break;
			default;
				return 0;
		}

		if ($cmd) {
			$this->runCommand($cmd, $this->getServicesDirectory());
		}
	}

	/**
	 * @param $serviceName
	 * @return void
	 */
	protected function initGitRepository($serviceName)
	{
		$commands = [];
		$path = $this->getDirectoryForService($serviceName);

		// If no repository is provided we initialize one
		if (!$this->repository) {
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
		$path = $this->getDirectoryForService($serviceName);

		// If no repository is provided we initialize one
		if (!$this->repository) {
			array_push($commands, "git submodule add --name {$serviceName} {$path} services/{$serviceName}");
		} else {
			array_push($commands, "git submodule add --name {$serviceName} {$this->repository} services/{$serviceName}");
		}
		$this->comment($this->projectDirectory);
		$this->comment($commands[0]);
		$this->runCommands($commands, $this->projectDirectory);
	}

}
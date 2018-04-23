<?php

namespace Mosaiqo\Launcher\Console;

use Mosaiqo\Launcher\Console\BaseCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Finder\Finder;

/**
 * Class OpenCommand
 * @package Mosaiqo\Launcher\Console
 */
class OpenCommand extends BaseCommand
{

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
			->setName('open')
			->setDescription('Opens a Service from a Launcher Project in your IDE')
			->addArgument('name', InputArgument::REQUIRED)
			->addArgument('services',  InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'The services you like to stop')
			->addOption('editor', 'e',  InputOption::VALUE_OPTIONAL, 'The editor you would like to open in.');
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
		$this->openProjectService();
	}

	/**
	 *
	 */
	protected function openProjectService()
	{
		$this->projectDirectory = $this->getProjectDirectory();
		$name = $this->input->getArgument('name');

		$this->info("Opening services for launcher project $name\n");
		$this->openServices();
	}

	/**
	 *
	 */
	protected function openServices()
	{
		$services = [];
		$existentServices = [];
		$directories = $this->finder->directories()->depth(0)->in($this->getServicesDirectory());

		foreach ($directories as $service) {
			$services[] = $service->getFilename();
			$existentServices[] = $service->getFilename();
		}

		// If the user provides service names we only want to boot this ones up
		if ($this->input->getArgument('services')) {
			$services = $this->input->getArgument('services');
		};

		foreach ($services as $serviceName) {
			// Service does exist.
			if (!in_array($serviceName, $existentServices)) {
				$this->write("\nSkipping Service <comment>$serviceName</comment>, because it does not exist");
				$this->comment("\nAvailable services are " .implode(", ", $existentServices));
				$this->write("\n<comment>=============================</comment>\n\n\n");
				continue;
			}

			$this->write("Opening service: <comment>$serviceName</comment> ! \n");
			$this->loadServiceEnvFile($serviceName);
			$this->loadConfigFileForProject($serviceName);

			$editor = $this->input->getOption('editor') ? $this->input->getOption('editor') : getenv('LAUNCHER_EDITOR', 'pstorm');

			$this->runCommand("$editor .", $this->getDirectoryForService($serviceName));
		}
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

}
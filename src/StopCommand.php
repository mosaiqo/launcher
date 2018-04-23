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
 * Class StopCommand
 * @package Mosaiqo\Launcher\Console
 */
class StopCommand extends BaseCommand
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
			->setName('stop')
			->setDescription('Stops a Launcher Project')
			->addArgument('name', InputArgument::REQUIRED)
			->addArgument('services',  InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'The services you like to stop');
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
		$this->stopsProject();
	}

	/**
	 *
	 */
	protected function stopsProject()
	{
		$this->projectDirectory = $this->getProjectDirectory();
		$name = $this->input->getArgument('name');
		$this->info("Stoping Launcher project $name\n");
		$this->stopServices();
	}

	/**
	 *
	 */
	protected function stopServices()
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
				$this->write("\nNo such service <comment>$serviceName</comment>");
				$this->write("\n<comment>=============================</comment>\n\n\n");
				continue;
			}
			// Service has no docker-composer.yml file therefore we skip it for now.
			if (!$this->doesServiceHaveDockerFile($serviceName)) {
				$this->write("\nSkipping Service <comment>$serviceName</comment>, because there is no docker-compose.yml file");
				$this->write("\n<comment>==========================================================================</comment>\n\n\n");
				continue;
			}
			$this->write("Stopping service: <comment>$serviceName</comment> ! \n");
			$this->loadServiceEnvFile($serviceName);
			$this->loadConfigFileForProject($serviceName);

			$args = " -f docker-compose.yml";

			// If service has also docker-composer.env.yml file we use this one then as well
			if ($this->doesServiceHaveDockerDevFile($serviceName)) {
				$args .= " -f docker-compose.dev.yml";
			}


			$this->runCommand("docker-compose ${args} down --remove-orphans || exit 1", $this->getDirectoryForService($serviceName));
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
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
 * Class StartCommand
 * @package Mosaiqo\Launcher\Console
 */
class StartCommand extends BaseCommand
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
			->setName('starts')
			->setDescription('Starts a Launcher Project')
			->addArgument('name', InputArgument::OPTIONAL)
			->addOption('default', 'd', InputOption::VALUE_NONE, 'Use default values for config')
			->addOption('force', 'f', InputOption::VALUE_NONE, 'Overrides the files');
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
		$this->startsProject();
	}

	/**
	 *
	 */
	protected function startsProject()
	{
		$this->projectDirectory = $this->getProjectDirectory();
		$name = $this->input->getArgument('name');
		$this->info("Starting Launcher project $name\n");
		$this->createNetwork();
		$this->loginToRegistry();
		$this->bootServices();
	}

	/**
	 *
	 */
	protected function loginToRegistry()
	{
		$token = getenv('LAUNCHER_REGISTRY_TOKEN');
		$user = getenv('LAUNCHER_REGISTRY_USER');
		$url = getenv('LAUNCHER_REGISTRY_URL');

		$this->text("Login to registry: <info>$url</info>, with user <info>$user</info>\n");
		$this->runCommand("echo $token | docker login -u $user $url --password-stdin");
	}

	/**
	 *
	 */
	protected function createNetwork()
	{
		$network = getenv('LAUNCHER_NETWORK_NAME');
		$networkExists = $this->runNonTtyCommand("docker network ls -q -f name=$network");

		if ($networkExists) {
			$this->info("Network $network exists already");
		} else {
			$this->info("Network $network doesn't exist yet! \nLet's create it!");
			$this->runCommand("docker network create $network");
		}

	}

	/**
	 *
	 */
	protected function bootServices()
	{
		$services = $this->finder->directories()->depth(0)->in($this->getServicesDirectory());
		foreach ($services as $service) {
			$serviceName = $service->getFilename();
			// Service has no docker-composer.yml file therefore we skip it for now.
			if (!$this->doesServiceHaveDockerFile($serviceName)) {
				$this->write("\nSkipping Service <comment>$serviceName</comment>, because there is no docker-compose.yml file \n");
				continue;
			}
			$config = $this->loadConfigFileForProject($serviceName);

			if ($config && $config['before']) {
				$this->runBeforeConfigCommands($config, $serviceName);
			}


			$this->write("Booting up service: <comment>$serviceName</comment> ! \n");
			$this->loadServiceEnvFile($serviceName);
			$args = " -f docker-compose.yml";

			// If service has also docker-composer.env.yml file we use this one then as well
			if ($this->doesServiceHaveDockerDevFile($serviceName)) {
				$args .= " -f docker-compose.dev.yml";
			}



			if ($config && $config['after']) {
				$this->runAfterConfigCommands($config, $serviceName);
			}

			$this->runCommand("docker-compose ${args} up -d --build --remove-orphans || exit 1",
				$this->getDirectoryForService($serviceName));
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

	/**
	 *
	 */
	protected function createDataBase($serviceName)
	{
		$isMySQLRunning = $this->runNonTtyCommand("docker ps --filter=name=dev-env-mysql -q");
		if ($isMySQLRunning) {
			$this->info("MySQL is Running");
			$databaseName = getenv('DB_DATABASE');
			$dbExists = $this->runNonTtyCommand("docker exec -it dev-env-mysql mysql -e \"SHOW DATABASES LIKE '{$databaseName}';\"");

			if ($dbExists) {
				$this->info("DDBB {$databaseName} already exists!");
				$remove = !$this->input->getOption('force') ? $this->ask(new ConfirmationQuestion(
					"Database already exists, do you want to recreate it again? [Y|N] (No): ",
					false,
					'/^(y|j)/i'
				)) : true;

				if ($remove) {
					$this->info("Removing DDBB {$databaseName}!");
					$this->runCommand("docker exec -it dev-env-mysql mysql -e \"DROP DATABASE {$databaseName};\"");
					$dbExists = $this->runNonTtyCommand("docker exec -it dev-env-mysql mysql  -e \"SHOW DATABASES LIKE '{$databaseName}';\"");
				}
			}

			if (!$dbExists) {
				$this->info("Creating DDBB {$databaseName}!\n");
				$databaseUser = getenv('DB_USERNAME');
				$databasePassword = getenv('DB_PASSWORD');
				$this->runCommand("docker exec -it dev-env-mysql mysql -e \"CREATE DATABASE IF NOT EXISTS {$databaseName};\"");
				$this->runCommand("docker exec -it dev-env-mysql mysql -e \"CREATE USER IF NOT EXISTS {$databaseUser}@'%' IDENTIFIED BY '{$databasePassword}';\"");
				$this->runCommand("docker exec -it dev-env-mysql mysql -e \"GRANT ALL PRIVILEGES ON {$databaseName}.* TO {$databaseUser}@'%';\"");
				$this->runCommand("		docker exec -it dev-env-mysql mysql -e \"FLUSH PRIVILEGES;\"");
			}
		}
	}


	/**
	 * @param $serviceName
	 */
	protected function installNpmDependencies($serviceName)
	{
		$rocketFile = $this->getRocketFileForService($serviceName);
		$npmFile = $this->getPackageFileForService($serviceName);
		if ($this->fileSystem->exists($rocketFile) && $this->fileSystem->exists($npmFile)) {
			$this->runCommand("./rocket npm install", $this->getDirectoryForService($serviceName));
		}

	}

	/**
	 * @param $serviceName
	 */
	protected function installComposerDependencies($serviceName)
	{
		$rocketFile = $this->getRocketFileForService($serviceName);
		$composerFile = $this->getComposerFileForService($serviceName);
		if ($this->fileSystem->exists($rocketFile) && $this->fileSystem->exists($composerFile)) {
			$this->runCommand("./rocket composer install", $this->getDirectoryForService($serviceName));
		}
	}

	/**
	 * @param $serviceName
	 */
	protected function migrateDatabase($serviceName)
	{
		$rocketFile = $this->getRocketFileForService($serviceName);
		$composerFile = $this->getComposerFileForService($serviceName);
		if ($this->fileSystem->exists($rocketFile) && $this->fileSystem->exists($composerFile)) {
			$this->runCommand("art migrate", $this->getDirectoryForService($serviceName));
		}
	}

	/**
	 * @param $serviceName
	 */
	protected function seedDatabase($serviceName)
	{
		$rocketFile = $this->getRocketFileForService($serviceName);
		$composerFile = $this->getComposerFileForService($serviceName);
		if ($this->fileSystem->exists($rocketFile) && $this->fileSystem->exists($composerFile)) {
			$this->runCommand("art db:seed", $this->getDirectoryForService($serviceName));
		}
	}


	/**
	 * @param $config
	 * @param $serviceName
	 */
	protected function runBeforeConfigCommands($config, $serviceName)
	{
		return $this->runConfigCommands($config['before'], $serviceName);
	}

	/**
	 * @param $config
	 * @param $serviceName
	 */
	protected function runAfterConfigCommands($config, $serviceName) {
		return $this->runConfigCommands($config['after'], $serviceName);
	}


	protected function runConfigCommands($config, $serviceName)
	{
		foreach ($config as $command) {
			switch ($command) {
				case 'db-create': $this->createDataBase($serviceName);
					break;
				case 'npm': $this->createDataBase($serviceName);
					break;
				case 'composer': $this->installComposerDependencies($serviceName);
					break;
				case 'migration': $this->migrateDatabase($serviceName);
					break;
				case 'seed': $this->seedDatabase($serviceName);
					break;
			}

		}


	}
}
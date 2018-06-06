<?php

namespace Mosaiqo\Launcher\Console\Projects;

use Mosaiqo\Launcher\Console\BaseCommand;
use Mosaiqo\Launcher\Exceptions\ExitException;
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
	protected $removeDB;

	/**
	 * @var
	 */
	protected $migrateDB;

	/**
	 * @var
	 */
	protected $seedDB;

	/**
	 * Configure the command options.
	 *
	 * @return void
	 */
	protected function configure()
	{
		$this
			->setName('project:start')
			->setDescription('Starts a Launcher Project')
			->addArgument('name', InputArgument::REQUIRED)
			->addOption('service', 's',  InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL, 'The services you like to boot')
			->addOption('default', 'd', InputOption::VALUE_NONE, 'Use default values for config')
			->addOption('force', 'f', InputOption::VALUE_NONE, 'Overrides the files')
			->addOption('pull', 'p', InputOption::VALUE_NONE, 'Forces pull of latest commit in current branch!');
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @return void
	 */
	public function execute(InputInterface $input, OutputInterface $output)
	{
		try {
			$this->loadConfigForProject($this->projectName);
			$this->updateServices();
			$this->info("Starting Launcher project {$this->projectName}\n");
			$this->createNetwork();
//			$this->loginToRegistry();

			$this->bootServices();

		} catch (ExitException $e) {
		 $this->handleExitException($e);
		}
	}


	/**
	 *
	 */
	protected function loginToRegistry()
	{
		$token = $this->project->registry("token");;
		$user =  $this->project->registry("user");;
		$url =  $this->project->registry("url");;

		$this->text("Login to registry: <info>$url</info>, with user <info>$user</info>\n");
		$this->runCommand("echo $token | docker login -u $user $url --password-stdin");
	}

	/**
	 *
	 */
	protected function createNetwork()
	{
		$network = $this->project->network();
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
		$services = $this->project->services();

		// If the user provides service names we only want to boot this ones up
		if (!empty($this->input->getOption('service'))) {
			$services = array_filter($services, function ($service) {
				if (in_array($service['name'], $this->input->getOption('service'))) {
					return $service;
				}
			});
		};

		foreach ($services as $service) {
			$serviceName = $service['name'];

			if ( !$this->serviceExists($service) ) {
				$this->write("\nNo such service <comment>$serviceName</comment>\n\n");
				continue;
			}
			$this->write($this->getServiceFolderForService($service)."\n\n");
			// Service has no docker-composer.yml file therefore we skip it for now.
			if (!$this->doesServiceHaveDockerFile($service)) {
				$this->write("\nSkipping Service <comment>$serviceName</comment>, because there is no docker-compose.yml file\n\n");
				continue;
			}

			$this->write("Booting up service: <comment>$serviceName</comment>! \n");
			$this->loadServiceEnvironmentFile($service);
			$config = $this->loadLauncherConfigFileForProject($service);
			$this->write("Let's boot the scripts\n");

			if ($config && (!$config['git-pull'] || $config['git-pull'] === false)) {
				$this->pullLatest($service);
			}
			if ($config && $config['before']) {
				$this->runBeforeConfigCommands($config, $service);
			}

			$args = "-f docker-compose.yml";

			// If service has also docker-composer.env.yml file we use this one then as well
			if ($this->doesServiceHaveDockerDevFile($service)) {
				$args .= " -f docker-compose.dev.yml";
			}

			if ($config && $config['after']) {
				$this->runAfterConfigCommands($config, $service);
			}

			$args .= " -p {$this->projectName}";

			$this->runCommand(
				"docker-compose ${args} up -d --build --remove-orphans || exit 1",
				$this->getServiceFolderForService($service)
			);

		}
	}


	/**
	 * @param $service
	 */
	protected function loadServiceEnvironmentFile($service)
	{
		$envExampleFile = $this->getFileForService('.env.example', $service);
		$envFile = $this->getFileForService('.env', $service);
		$dotenv = new Dotenv();

		if (!$this->fileSystem->exists($envExampleFile)) {
			return;
		}

		if (!$this->fileSystem->exists($envFile)) {
			$this->fileSystem->copy($envExampleFile, $envFile);
		}

		$dotenv->load($envFile);

		$WWWUSER = (int)posix_getuid()?:501 ;
		$dotenv->populate([
			'UID' => $WWWUSER,
			'WWWUSER' => $WWWUSER,
			'XDEBUG_HOST' => '127.0.0.1',
			'TLD' => $this->project->tld(),
		]);
//		$this->runCommand("export WWWUSER=$WWWUSER");
	}

	/**
	 * @param $file
	 * @param $service
	 * @return string
	 */
	protected function getFileForService($file, $service)
	{
		return $this->getServiceFolderForService($service) . DIRECTORY_SEPARATOR . $file;
	}

	/**
	 * @param $service
	 * @return mixed
	 */
	protected function doesServiceHaveDockerFile($service)
	{
		return $this->fileSystem->exists($this->getFileForService('docker-compose.yml', $service));
	}

	/**
	 * @param $service
	 * @return mixed
	 */
	protected function doesServiceHaveDockerDevFile($service)
	{
		return $this->fileSystem->exists($this->getFileForService('docker-compose.dev.yml', $service));
	}

	/**
	 *
	 */
	protected function createDataBase($service)
	{
		$isMySQLRunning = $this->runNonTtyCommand("docker ps --filter=name=dev-env-mysql -q");
		if ($isMySQLRunning) {
			$this->info("MySQL is Running");
			$databaseName = getenv('DB_DATABASE');
			$dbExists = $this->runNonTtyCommand("docker exec dev-env-mysql mysql -N -s -r -e \"SHOW DATABASES LIKE '{$databaseName}';\"");
			if ($dbExists) {
				$this->info("DDBB {$databaseName} already exists!");
				$this->removeDB = !$this->input->getOption('force') ? $this->ask(new ConfirmationQuestion(
					"Database already exists, do you want to recreate it again? [Y|N] (No): ",
					false,
					'/^(y|j)/i'
				)) : true;

				if ($this->removeDB) {
					$this->info("Removing DDBB {$databaseName}!");
					$this->runCommand("docker exec -it dev-env-mysql mysql -e \"DROP DATABASE {$databaseName};\"");
					$dbExists = $this->runNonTtyCommand("docker exec dev-env-mysql mysql -N -s -r -e \"SHOW DATABASES LIKE '{$databaseName}';\"");
				}
			}

			if (!$dbExists) {
				$this->migrateDB = true;
				$this->seedDB = true;
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
	 * @param $service
	 */
	protected function installNpmDependencies($service)
	{
		$rocketFile = $this->getRocketFileForService($service);
		$npmFile = $this->getPackageFileForService($service);
		if ($this->fileSystem->exists($rocketFile) && $this->fileSystem->exists($npmFile)) {
			$this->runCommand("./rocket npm install", $this->getServiceFolderForService($service));
		}

	}

	/**
	 * @param $service
	 */
	protected function installComposerDependencies($service)
	{
		$rocketFile = $this->getRocketFileForService($service);
		$composerFile = $this->getComposerFileForService($service);
		if ($this->fileSystem->exists($rocketFile) && $this->fileSystem->exists($composerFile)) {
			$this->runCommand("./rocket composer install", $this->getServiceFolderForService($service));
		}
	}

	/**
	 * @param $service
	 */
	protected function migrateDatabase($service)
	{
		$rocketFile = $this->getRocketFileForService($service);
		$composerFile = $this->getComposerFileForService($service);
		if ($this->migrateDB && $this->fileSystem->exists($rocketFile) && $this->fileSystem->exists($composerFile)) {
			$this->runCommand("./rocket art migrate", $this->getServiceFolderForService($service));
		}
	}

	/**
	 * @param $service
	 */
	protected function seedDatabase($service)
	{
		$rocketFile = $this->getRocketFileForService($service);
		$composerFile = $this->getComposerFileForService($service);
		if ($this->seedDB && $this->fileSystem->exists($rocketFile) && $this->fileSystem->exists($composerFile)) {
			$this->runCommand("./rocket art db:seed", $this->getServiceFolderForService($service));
		}
	}


	/**
	 * @param $config
	 * @param $service
	 */
	protected function runBeforeConfigCommands($config, $service)
	{
		return $this->runConfigCommands($config['before'], $service);
	}

	/**
	 * @param $config
	 * @param $service
	 */
	protected function runAfterConfigCommands($config, $service) {
		return $this->runConfigCommands($config['after'], $service);
	}


	protected function runConfigCommands($config, $service)
	{
		foreach ($config as $command) {
			switch ($command) {
				case 'db-create': $this->createDataBase($service);
					break;
					break;
				default: $this->runSomeCommand($command, $service);
			}
		}
	}

	/**
	 * @param $command
	 * @param $service
	 */
	protected function runSomeCommand($command, $service)
	{
		$this->write("\nRunning Command <info>{$command}</info> for service <comment>{$service['name']}</comment>\n");

		$this->runCommand($command, $this->getServiceFolderForService($service));
	}

	/**
	 * @param $service
	 */
	protected function pullLatest($service)
	{
		$git = $this->getGitForService($service);
		if ($this->fileSystem->exists($git) &&  $this->input->getOption('pull')) {
			$hasChanges = false;
			$this->write("\nPulling latest commit for <comment>{$service['name']}</comment>\n");

			$gitStatus = $this->runNonTtyCommand("git status", $this->getServiceFolderForService($service));

			if(strstr($gitStatus, "Nothing to commit") !== -1) {
				$hasChanges = true;
			}
			$force = $this->input->getOption('force');

			if ($hasChanges) {
				$cleanUp = !$force ? $this->ask(new ConfirmationQuestion(
					"Git repository fot {$service['name']} not clean, do you want to clean it? [Y|N] (No): ",
					false,
					'/^(y|j)/i'
				)) : true;
				if ($cleanUp) {
					$this->runCommand("git checkout -f", $this->getServiceFolderForService($service));
				}
			}

			$this->runCommand("git pull", $this->getServiceFolderForService($service));
		}
	}

	/**
	 * @param $service
	 * @return mixed
	 */
	protected function serviceExists($service)
	{
		return $this->fileSystem->exists($this->getServiceFolderForService($service));
	}

	/**
	 * @param $service
	 * @return array|mixed
	 */
	protected function loadLauncherConfigFileForProject($service)
	{
		$fileName = 'launcher.json';
		$configFile = $this->getFileForService($fileName, $service);
		$config = [];

		if ($this->fileSystem->exists($configFile)) {
			$this->text("Launcher config file found for <comment>{$service['name']}</comment>\n");
			$this->finder->files()->in($this->getServiceFolderForService($service))->name($fileName);
			foreach ($this->finder as $file) {
				$content = $file->getContents();
				$config = json_decode($content, true);
			}
		}
		return $config;
	}
}
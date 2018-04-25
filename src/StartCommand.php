<?php

namespace Mosaiqo\Launcher;

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
			->setName('starts')
			->setDescription('Starts a Launcher Project')
			->addArgument('name', InputArgument::REQUIRED)
			->addArgument('services',  InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'The services you like to boot')
			->addOption('default', 'd', InputOption::VALUE_NONE, 'Use default values for config')
			->addOption('force', 'f', InputOption::VALUE_NONE, 'Overrides the files')
			->addOption('pull', 'p', InputOption::VALUE_NONE, 'Forces pull of latest commit in current branch!');
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

			$this->write("Booting up service: <comment>$serviceName</comment> ! \n");
			$this->loadServiceEnvFile($serviceName);
			$config = $this->loadConfigFileForProject($serviceName);

			if ($config && (!$config['git-pull'] || $config['git-pull'] === false)) {
				$this->pullLatest($serviceName);
			}

			if ($config && $config['before']) {
				$this->runBeforeConfigCommands($config, $serviceName);
			}

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
		if ($this->migrateDB && $this->fileSystem->exists($rocketFile) && $this->fileSystem->exists($composerFile)) {
			$this->runCommand("./rocket art migrate", $this->getDirectoryForService($serviceName));
		}
	}

	/**
	 * @param $serviceName
	 */
	protected function seedDatabase($serviceName)
	{
		$rocketFile = $this->getRocketFileForService($serviceName);
		$composerFile = $this->getComposerFileForService($serviceName);
		if ($this->seedDB && $this->fileSystem->exists($rocketFile) && $this->fileSystem->exists($composerFile)) {
			$this->runCommand("./rocket art db:seed", $this->getDirectoryForService($serviceName));
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
				case 'migrate': $this->migrateDatabase($serviceName);
					break;
				case 'seed': $this->seedDatabase($serviceName);
					break;
				case 'npm': $this->installNpmDependencies($serviceName);
					break;
				case 'composer': $this->installComposerDependencies($serviceName);
					break;
				default: $this->runSomeCommand($command, $serviceName);
			}
		}
	}

	protected function runSomeCommand($command, $serviceName)
	{
		$this->runCommand($command, $this->getDirectoryForService($serviceName));
	}

	/**
	 * @param $serviceName
	 */
	protected function pullLatest($serviceName)
	{
		$git = $this->getGitForService($serviceName);
		if ($this->fileSystem->exists($git) &&  $this->input->getOption('pull')) {
			$hasChanges = false;
			$this->write("\nPulling latest commit for <comment>$serviceName</comment>\n");

			$gitStatus = $this->runNonTtyCommand("git status", $this->getDirectoryForService($serviceName));

			if(strstr($gitStatus, "Nothing to commit") !== -1) {
				$hasChanges = true;
			}
			$force = $this->input->getOption('force');

			if ($hasChanges) {
				$cleanUp = !$force ? $this->ask(new ConfirmationQuestion(
					"Git repository fot {$serviceName} not clean, do you want to clean it? [Y|N] (No): ",
					false,
					'/^(y|j)/i'
				)) : true;
				if ($cleanUp) {
					$this->runCommand("git checkout -f", $this->getDirectoryForService($serviceName));
				}
			}

			$this->runCommand("git pull", $this->getDirectoryForService($serviceName));
		}
	}
}
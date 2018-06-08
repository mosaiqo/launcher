<?php
namespace Mosaiqo\Launcher\Console;

use Mosaiqo\Launcher\Exceptions\ExitException;
use Mosaiqo\Launcher\Projects\Project;
use Symfony\Component\Console\Exception\LogicException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class BaseCommand
 * @package Mosaiqo\Launcher\Console
 */
class BaseCommand extends Command
{
	/**
	 * @var
	 */
	protected $output;
	/**
	 * @var
	 */
	protected $input;
	/**
	 * @var
	 */
	protected $fileSystem;

	/**
	 * @var
	 */
	protected $finder;

	/**
	 * @var
	 */
	protected $project;


	protected $force;


	protected $useDefault;


	protected $projectName;

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 */
	protected function initialize(InputInterface $input, OutputInterface $output)
	{
		parent::initialize($input, $output);
		$this->finder = new Finder;
		$this->fileSystem = new Filesystem();
		$this->output = $output;
		$this->input = $input;
		$this->force = $input->hasOption('force') ? $input->getOption('force') : false;
		$this->useDefault = $input->hasOption('default') ? $input->getOption('default') : false;
		$this->projectName = $input->hasArgument('name') ? $input->getArgument('name') : null;
		$this->loadEnvironment($this->launcherEnvironmentFile());
	}

	/**
	 * @param string $message
	 */
	protected function doneMessage($message = 'Done!')
	{
		$this->info($message);
	}

	/**
	 * @param string $message
	 * @param bool $newLine
	 */
	protected function info ($message = "", $newLine = true) {
		$this->write("<info>$message</info>", $newLine);
	}

	/**
	 * @param string $message
	 * @param bool $newLine
	 */
	protected function text ($message = "", $newLine = false) {
		$this->write("$message", $newLine);
	}

	/**
	 * @param string $message
	 * @param bool $newLine
	 */
	protected function error ($message = "", $newLine = true) {
		$this->write("<error>$message</error>", $newLine);
	}

	/**
	 * @param string $message
	 * @param bool $newLine
	 */
	protected function comment ($message = "", $newLine = true) {
		$this->write("<comment>$message</comment>", $newLine);
	}

	/**
	 * @param string $message
	 * @param bool $newLine
	 */
	protected function question ($message = "", $newLine = true) {
		$this->write("<question>$message</question>", $newLine);
	}

	/**
	 * @param Question $question
	 * @return mixed
	 */
	protected function ask (Question $question) {
		$helper = $this->getHelper('question');
		return $helper->ask($this->input, $this->output, $question);
	}

	/**
	 * @param string $question
	 * @param bool $default
	 * @param string $trueAnswerRegex
	 * @return mixed
	 */
	protected function askConfirmation ($question, $default = true, $trueAnswerRegex = '/^(y|j)/i') {
		$helper = $this->getHelper('question');
		$defaultTrue = $default? 'yes' : 'no';
		$question = "<comment>$question</comment> [yes/no] ($defaultTrue): ";
		return $helper->ask($this->input, $this->output, 	new ConfirmationQuestion ($question, $default, $trueAnswerRegex));
	}

	/**
	 * @param string $question
	 * @param null $default

	 * @return mixed
	 */
	protected function askForConfig ($question, $default = null) {
		$helper = $this->getHelper('question');
		$question = "<comment>$question</comment>";
		$question .= $default ? " ($default): " : ": ";
		$inputValue = $this->useDefault ? $default : $helper->ask($this->input, $this->output, 	new Question ($question, $default));
		if (strstr($inputValue, " ")) { $inputValue = "'$inputValue'";}

		return $inputValue;
	}

	/**
	 * @param string $message
	 * @param bool $newLine
	 */
	protected function header ($message = "", $newLine = true) {
		$this->write("<fg=blue>$message</>", $newLine);
	}

	/**
	 * @param string $message
	 * @param bool $newLine
	 */
	protected function write ($message = "", $newLine = false) {
		if ($newLine) {
			$this->output->writeln($message);
		} else {
			$this->output->write($message);
		}
	}

	/**
	 * @param string $cmd
	 * @param null $directory
	 * @param null $env
	 * @param null $input
	 * @param null $timeout
	 * @param bool $tty
	 * @return string
	 */
	protected function runCommand($cmd = '', $directory = null, $env = null, $input = null, $timeout = null, $tty = true) {
		$process = new Process($cmd, $directory, $env, $input, $timeout);
		if ('\\' !== DIRECTORY_SEPARATOR && file_exists('/dev/tty') && is_readable('/dev/tty')) {
			$process->setTty($tty);
		}
		$process->run();
		if ($tty) $this->text("\n");
		return $process->getOutput();
	}

	/**
	 * @param string $cmd
	 * @param null $directory
	 * @param null $env
	 * @param null $input
	 * @param null $timeout
	 * @return string
	 */
	protected function runNonTtyCommand($cmd = '', $directory = null, $env = null, $input = null, $timeout = null) {
		return $this->runCommand($cmd, $directory, $env, $input, $timeout, false);
	}

	/**
	 * @param array $commands
	 * @param null $directory
	 */
	protected function runCommands (array $commands = [], $directory = null) {
		array_map(function ($cmd) use ($directory) {
			$this->runCommand($cmd, $directory);
		}, $commands);
	}

	/**
	 * @return array|false|null|string
	 */
	protected function getHomeDirectory() {
		// Cannot use $_SERVER superglobal since that's empty during UnitUnishTestCase
		// getenv('HOME') isn't set on Windows and generates a Notice.
		$home = getenv('HOME');
		if (!empty($home)) {
			// home should never end with a trailing slash.
			$home = rtrim($home, '/');
		}
		elseif (!empty($_SERVER['HOMEDRIVE']) && !empty($_SERVER['HOMEPATH'])) {
			// home on windows
			$home = $_SERVER['HOMEDRIVE'] . $_SERVER['HOMEPATH'];
			// If HOMEPATH is a root directory the path can end with a slash. Make sure
			// that doesn't happen.
			$home = rtrim($home, '\\/');
		}

		return empty($home) ? NULL : $home;
	}


	/**
	 * @return string
	 */
	protected function getProjectDirectory () {
		return $this->getDirectory($this->project->directory());
	}

	/**
	 * @param $projectName
	 * @return string
	 * @throws ExitException
	 */
	protected function loadConfigForProject ($projectName) {
		$config = [];
		$files = $this->loadConfigFileForProject();

		if (count($files) === 0) {
			throw new ExitException("Config file for {$projectName} could not be found!");
		}

		foreach ($files as $file) {
			$config = json_decode($file->getContents(), true);
			if ($config === null) {
				throw new ExitException("Config file for {$projectName} could not be loaded. Not a valid json?");
			}
		}

		$this->project = new Project($config);
	}

	/**
	 * @return string
	 * @throws ExitException
	 */
	protected function saveConfigForProject () {
		$config = json_encode($this->project->config());
		$files = $this->loadConfigFileForProject();

		if (count($files) === 0) {
			throw new ExitException("Config file for {$this->projectName} could not be found!");
		}
		$fileName = $this->getLauncherConfigFileForProject($this->projectName);

		$this->fileSystem->remove($files);
		$this->fileSystem->dumpFile($fileName, $config);
		$this->text("Project config file <comment>$fileName</comment> was saved!\n");
	}

	/**
	 * @return string
	 */
	protected function launcherProjectsDirectory () {
		return $this->launcherEnvironmentDirectory() . DIRECTORY_SEPARATOR . 'projects';
	}

	/**
	 * @param $name
	 * @return string
	 */
	protected function getLauncherConfigFileForProject ($name) {
		$name = strtolower($name);
		return $this->launcherProjectsDirectory() . DIRECTORY_SEPARATOR . "$name.json";
	}

	/**
	 * @param $service
	 * @return string
	 */
	protected function getServiceFolderForService ($service) {
		return $this->getProjectDirectory() . DIRECTORY_SEPARATOR . $service['path'];
	}

	/**
	 * @return string
	 */
	protected function getServicesFolderForProject () {
		return $this->getProjectDirectory() . DIRECTORY_SEPARATOR . 'services';
	}

	/**
	 * @param $service
	 * @return string
	 */
	protected function getRocketFileForService ($service) {
		return $this->getServiceFolderForService($service) . DIRECTORY_SEPARATOR . "rocket";
	}

	/**
	 * @param $service
	 * @return string
	 */
	protected function getComposerFileForService ($service) {
		return $this->getServiceFolderForService($service) . DIRECTORY_SEPARATOR . "composer.json";
	}

	/**
	 * @param $service
	 * @return string
	 */
	protected function getGitForService ($service) {
		return $this->getServiceFolderForService($service) . DIRECTORY_SEPARATOR . ".git";
	}

	/**
	 * @param $service
	 * @return string
	 */
	protected function getPackageFileForService ($service) {
		return $this->getServiceFolderForService($service) . DIRECTORY_SEPARATOR . "package.json";
	}

	/**
	 * @param $dir
	 * @return null|string
	 */
	protected function getDirectory ($dir) {
		$directory = null;

		// We asume we want to create the project in the current working directory
		if ($dir === "." || !$dir) {
			 // die('.');
			$directory = getcwd();
		}

		// The user provides us with an absolute route
		if ($dir[0] === '/') {
			 // die('/');
			$directory = $dir;
		}
		// The user provides us with a Home Directory
		if ($dir[0].$dir[1] === "~/") {
			 // die('~/');
			$directory = $this->getHomeDirectory() . DIRECTORY_SEPARATOR . substr($dir, 2);
		}

		// If he wants to create it at the current directory
		if ($dir[0].$dir[1] === "./") {
			 // die('./');
			$directory = getcwd();
		}

		if (!$directory) {
			// If not then we just return the PROJECTS_DIRECTORY
			$directory = (getenv('PROJECTS_DIRECTORY'))
				? getenv('PROJECTS_DIRECTORY') . DIRECTORY_SEPARATOR . $dir
				: getcwd() . DIRECTORY_SEPARATOR . $dir;
		}

		return $directory;
	}

	/**
	 * Returns launcher Environment File Path
	 * @return string
	 */
	protected function launcherEnvironmentFile () {
		return $this->launcherEnvironmentDirectory(). DIRECTORY_SEPARATOR . '.env';
	}


	/**
	 * Returns launcher Environment Path
	 * @return string
	 */
	protected function launcherEnvironmentDirectory () {
		return $this->getHomeDirectory() . DIRECTORY_SEPARATOR . '.launcher';
	}

	/**
	 * @return mixed
	 */
	protected function launcherEnvironmentFileExists()
	{
		return $this->fileSystem->exists($this->launcherEnvironmentFile());
	}


	/**
	 * @return mixed
	 */
	protected function launcherEnvironmentDirectoryExists()
	{
		return $this->fileSystem->exists($this->launcherEnvironmentDirectory());
	}

	/**
	 *
	 */
	protected function loadLauncherEnvironment()
	{
		$this->loadEnvironment($this->launcherEnvironmentFile());
	}

	/**
	 * @param null $path
	 */
	protected function loadEnvironment ($path = null)
	{
		if ($this->fileSystem->exists($path)) {
			$dotenv = new Dotenv();
			$dotenv->load($path);
		}
	}


	/**
	 * @return mixed
	 */
	protected function isLauncherProject()
	{
		return $this->fileSystem->exists($this->getLauncherFileForProject());
	}

	/**
	 * @param ExitException $e
	 */
	protected function handleExitException(ExitException $e)
	{
		$this->comment($e->getMessage());
	}

	/**
	 * @param Exception $e
	 */
	protected function handleException(\Exception $e)
	{
		$this->comment($e->getMessage());
	}


	/**
	 * Whether launcher is already configured in the system or not.
	 */
	protected function isLauncherConfigured ()
	{
		return $this->launcherEnvironmentDirectoryExists()
			&& $this->launcherEnvironmentFileExists();
	}

	/**
	 *
	 */
	protected function loadCommonConfig ()
	{
		try {
			$this->loadConfigForProject($this->projectName);
		} catch (ExitException $e) {
			$this->handleExitException($e);
		}
	}



	/**
	 * @return int
	 */
	protected function doesProjectExists () {
		return count($this->loadConfigFileForProject()) !== 0 ;
	}


	protected function configureLauncher ()
	{
		$command = $this->getApplication()->find('config');
		try {
			$command->run(
				new ArrayInput([
					'-f' => $this->input->getOption('force'),
					'-d' => $this->input->getOption('default'),
				]), $this->output);
		} catch (\Exception $e) {
			$this->handleException($e);
		}
	}

	/**
	 * @return Finder
	 */
	protected function loadConfigFileForProject()
	{
		$name = strtolower($this->projectName);
		$finder = new Finder();
		$finder->files()->name("{$name}.json");

		return $finder->in($this->launcherProjectsDirectory());
	}

	/**
	 * @param array $value
	 * @param string $template
	 * @param string $replaceWith
	 * @return array|mixed
	 */
	protected function replaceForConfig ($value = [], $template = '', $replaceWith = '')
	{
		if ($value['text'] && $value['default']) {
			$value = str_replace($template, $replaceWith, $value);
		}

		return $value;
	}

	/**
	 *
	 */
	protected function updateServices()
	{
		$names = $this->runNonTtyCommand(
			'git submodule foreach --quiet \'echo {\"name\": \"${name}\", \"path\": \"${path}\"}===\''
			, $this->project->directory());

		$services = explode("===", $names);
		$services = array_map(
			function ($service) { return json_decode($service, true);},
			array_filter($services, function($service) { return json_decode($service) !== null; })
		);

		// Lets read the docker config for this service
		foreach ($services as $key => $service) {
			if ( !$this->serviceExists($service) ) {
				$this->write("\nNo such service <comment>$serviceName</comment>\n\n");
				continue;
			}

			$dockerConfig = $this->runNonTtyCommand(
				"docker inspect --format='{{json .Config}}' {$this->projectName}-{$service['name']}"
				, $this->project->directory());

			$jsonDockerConfig = json_decode($dockerConfig);
			$virtualHosts = [];
			if (isset($jsonDockerConfig->Env)) {
				foreach ($jsonDockerConfig->Env as $env) {
					if (strpos($env, 'VIRTUAL_HOST=') !== false) {
						$virtualHosts = str_replace("VIRTUAL_HOST=", "", $env);
						if (strpos($virtualHosts, ',') !== false) {
							$virtualHosts = explode(',', $virtualHosts);
						} else {
							$virtualHosts = [$virtualHosts];
						}
					}
				}

				foreach ($virtualHosts as $virtualHost) {
					$services[$key]['hosts'][] = trim($virtualHost);
				}
			}
		}
		$this->project->update('services', $services);
		$this->saveConfigForProject();
	}


	/**
	 * @param $service
	 * @return mixed
	 */
	protected function serviceExists($service)
	{
		return $this->fileSystem->exists($this->getServiceFolderForService($service));
	}
}
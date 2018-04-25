<?php
namespace Mosaiqo\Launcher\Console;

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

	protected function ask (Question $question) {
		$helper = $this->getHelper('question');
		return $helper->ask($this->input, $this->output, $question);
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
	protected function getEnvDirectory () {
		return $this->getHomeDirectory() . DIRECTORY_SEPARATOR . '.launcher';
	}

	/**
	 * @return string
	 */
	protected function getProjectDirectory () {
		return $this->getDirectory($this->input->getArgument('name'));
	}

	/**
	 * @return string
	 */
	protected function loadConfigForProject ($project) {
		$finder = new Finder();
		$finder->files()->name("{$project}.json");
		$config = [];
		$files = $finder->in($this->getLauncherProjectsConfigDirectory());

		if (count($files) === 0) {
			$this->comment("Config file for {$project} could not be found!");
		}

		foreach ($files as $file) {
			$config = json_decode($file->getContents(), true);

			if ($config === null) {
				$this->comment("Config file for {$project} could not be loaded. Not a valid json?");
				return;
			}
		}

		$this->project = new Project($config);
	}

	/**
	 * @return string
	 */
	protected function getLauncherProjectsConfigDirectory () {
		return $this->getEnvDirectory() . DIRECTORY_SEPARATOR . 'projects';
	}

	/**
	 * @param $name
	 * @return string
	 */
	protected function getLauncherConfigFileForProject ($name) {
		return $this->getLauncherProjectsConfigDirectory() . DIRECTORY_SEPARATOR . "$name.json";
	}

	/**
	 * @param $serviceName
	 * @return string
	 */
	protected function getServiceFolderForService ($serviceName) {
		return $this->getProjectDirectory() . DIRECTORY_SEPARATOR . 'services' . DIRECTORY_SEPARATOR . $serviceName;
	}

	/**
	 * @param $serviceName
	 * @return string
	 */
	protected function getRocketFileForService ($serviceName) {
		return $this->getServiceFolderForService($serviceName) . DIRECTORY_SEPARATOR . "rocket";
	}

	/**
	 * @param $serviceName
	 * @return string
	 */
	protected function getComposerFileForService ($serviceName) {
		return $this->getServiceFolderForService($serviceName) . DIRECTORY_SEPARATOR . "composer.json";
	}

	/**
	 * @param $serviceName
	 * @return string
	 */
	protected function getGitForService ($serviceName) {
		return $this->getServiceFolderForService($serviceName) . DIRECTORY_SEPARATOR . ".git";
	}

	/**
	 * @param $serviceName
	 * @return string
	 */
	protected function getPackageFileForService ($serviceName) {
		return $this->getServiceFolderForService($serviceName) . DIRECTORY_SEPARATOR . "package.json";
	}

	/**
	 * @param $dir
	 * @return null|string
	 */
	protected function getDirectory ($dir) {
		$directory = null;

		// We asume we want to create the project in the current working directory
		if ($dir === "." || !$dir) {
			$directory = getcwd();
		}

		// The user provides us with an absolute route
		if ($dir[0] === '/') {
			$directory = $dir;
		}
		// The user provides us with a Home Directory
		if ($dir[0].$dir[1] === "~/") {
			$directory = $this->getHomeDirectory() . DIRECTORY_SEPARATOR . substr($dir, 2);
		}

		// If he wants to create it at the current directory
		if ($dir[0].$dir[1] === "./") {
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
	 * @return string
	 */
	protected function getEnvFile () {
		$directory = $this->getEnvDirectory();
		return $directory. DIRECTORY_SEPARATOR . '.env';
	}

	/**
	 *
	 */
	protected function loadEnv()
	{
		$dotenv = new Dotenv();
		$dotenv->load($this->getEnvFile());
	}

	/**
	 *
	 */
	protected function loadLauncherEnv()
	{
		$dotenv = new Dotenv();
		$dotenv->load($this->getLauncherFileForProject());
	}

	/**
	 * @return mixed
	 */
	protected function envFileExists()
	{
		return $this->fileSystem->exists($this->getEnvFile());
	}

	/**
	 * @return mixed
	 */
	protected function isLauncherProject()
	{
		return $this->fileSystem->exists($this->getLauncherFileForProject());
	}
}
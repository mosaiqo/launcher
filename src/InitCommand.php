<?php

namespace Mosaiqo\Launcher\Console;

use Mosaiqo\Launcher\Console\BaseCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

/**
 * Class NewCommand
 * @package Mosaiqo\Launcher\Console
 */
class InitCommand extends BaseCommand
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
			->setName('init')
			->setDescription('Initializes a Launcher Project')
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

		if (!$this->isLauncherProject()) {
			$this->info('This is not a launcher project');
			return 0;
		}
		$this->projectDirectory = $this->getProjectDirectory();
		$this->initializeProject();
	}

	protected function initializeProject()
	{
		$name = $this->input->getArgument('name');
		$this->info("Initializing Launcher project $name");

		$this->runCommands([
			"git init",
			"git submodule init",
			"git submodule update --merge --remote",
			"git add -A",
			"git commit -m 'Initial Commit'",
		], $this->projectDirectory);
	}
}
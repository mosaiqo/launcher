<?php

namespace Mosaiqo\Launcher\Console\Projects;

use Mosaiqo\Launcher\Console\BaseCommand;
use Mosaiqo\Launcher\Exceptions\ExitException;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Finder\Finder;

/**
 * Class RemoveCommand
 * @package Mosaiqo\Launcher\Console
 */
class RemoveCommand extends BaseCommand
{

	/**
	 * @var array
	 */
	private $files = [];

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
			->setName('project:remove')
			->addArgument('name', InputArgument::REQUIRED)
			->setDescription('Removes a given project');
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @return void
	 */
	public function execute(InputInterface $input, OutputInterface $output)
	{
		try {
			if (!$this->isLauncherConfigured()) {
				$this->info("Launcher is not configured!");
			}

			$this->loadProjectFiles();
			$this->removeProject();

		} catch (ExitException $e) {
			$this->handleExitException($e);
		}
	}

	/**
	 *
	 */
	public function loadProjectFiles()
	{
		$finder = new Finder();
		$this->files = $finder->files()->in($this->launcherProjectsDirectory());
	}

	public function removeProject()
	{
		$path = null;
		$exists = false;
		$name =  $this->input->getArgument('name');
		foreach ($this->files as $file) {
			$project = json_decode($file->getContents(), true);
			if ($project['name'] === $this->input->getArgument('name')) {
				$fileToRemove = $file->getRealPath();
				$exists = true;
				$path = $project['directory'];
				$remove = $this->askConfirmation("Are you sure to remove <info>{$name}</info>");
			}
		}

		if (!$exists) {
			$this->text("No such project <info>{$name}</info> configured in Launcher");
			return;
		}


		if ($path && $remove) {
			$this->text("Removing <info>{$name}</info>...\n");
			$this->runNonTtyCommand("rm -Rf {$path}");
			$this->runNonTtyCommand("rm {$fileToRemove}");
			$this->text("Removed <info>{$name}</info>!\n");
		}


	}
}
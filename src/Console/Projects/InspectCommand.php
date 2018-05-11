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
 * Class InspectCommand
 * @package Mosaiqo\Launcher\Console
 */
class InspectCommand extends BaseCommand
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
			->setName('project:inspect')
			->addArgument('name', InputArgument::REQUIRED)
			->setDescription('Inspect a given project');
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
			$this->inspectProject();

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

	public function inspectProject()
	{
		$output = '';
		foreach ($this->files as $file) {
			$project = json_decode($file->getContents(), true);
			if ($project['name'] === $this->input->getArgument('name')) {
				$this->text(json_encode($project, JSON_PRETTY_PRINT));
			}
		}
	}
}
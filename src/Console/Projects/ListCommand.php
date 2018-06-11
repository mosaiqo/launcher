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
 * Class ListCommand
 * @package Mosaiqo\Launcher\Console
 */
class ListCommand extends BaseCommand
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
			->setName('project:list')
			->setDescription('List all the projects');
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
			$this->listProjects();

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

	/**
	 *
	 */
	public function listProjects()
	{
		$table = new Table($this->output);
		$table->setHeaders(['Name', 'Path', 'Services', 'Hosts']);

		$rows = [];

		foreach ($this->files as $file) {
			$project = json_decode($file->getContents(), true);
			$services = '';
			$hosts = '';
			$this->projectName = strtolower($project['name']);
			$this->loadConfigForProject($this->projectName);
			$this->updateServices();
			foreach ($project['services'] as $service) {
				$services .= $service['name'] . "\n";
				$hosts .= isset($service['hosts']) ? implode(',', $service['hosts']) . "\n" : "\n";
			}

			$rows[] = [
				$project['name'],
				$project['directory'],
				$services,
				$hosts
			];

		}
		$table->setRows($rows);
		$table->render();
	}
}
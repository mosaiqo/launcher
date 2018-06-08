<?php

namespace Mosaiqo\Launcher\Console\Services;

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
			->setName('service:list')
			->addArgument('name', InputArgument::REQUIRED)
			->setDescription('List all the services for a certain project');
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
			$this->loadConfigForProject($this->projectName);
			$this->updateServices();
			$this->listServices();

		} catch (ExitException $e) {
			$this->handleExitException($e);
		}
	}

	/**
	 *
	 */
	public function listServices()
	{
		$table = new Table($this->output);
		$table->setHeaders(['Service', 'Path', 'Hosts']);
		$rows = [];
		$this->info("Project: {$this->project->name()} ({$this->project->directory()})");
		foreach ($this->project->services() as $service) {
			$rows[] = [
				"{$this->projectName}-{$service['name']}",
				"{$this->project->directory()}/{$service['path']}",
				isset($service['hosts']) ? implode(',', $service['hosts']) : ''];
		}
		$table->setRows($rows);
		$table->render();
	}
}
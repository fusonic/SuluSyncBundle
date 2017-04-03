<?php

namespace Fusonic\SuluSyncBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class ExportCommand extends Command
{
    /**
     * @var InputInterface
     */
    private $input;

    /**
     * @var OutputInterface
     */
    private $output;

    private $secret;
    private $databaseHost;
    private $databaseUser;
    private $databaseName;
    private $databasePassword;
    private $kernelRootDir;
    private $exportDirectory;

    public function __construct(
        $secret,
        $databaseHost,
        $databaseUser,
        $databaseName,
        $databasePassword,
        $kernelRootDir
    ) {
        parent::__construct();

        $this->secret = $secret;
        $this->databaseHost = $databaseHost;
        $this->databaseUser = $databaseUser;
        $this->databaseName = $databaseName;
        $this->databasePassword = $databasePassword;
        $this->kernelRootDir = $kernelRootDir;
        $this->exportDirectory = $kernelRootDir . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "web";
    }

    protected function configure()
    {
        $this
            ->setName("sulu:export")
            ->setDescription("Exports all Sulu contents (PHPCR, database, uploads) to the web directory.");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;

        $this->exportPHPCR();
        $this->exportDatabase();
        $this->exportUploads();
    }

    private function exportPHPCR()
    {
        $this->executeCommand(
            "doctrine:phpcr:workspace:export",
            [
                "-p" => "/cmf",
                "filename" => $this->exportDirectory . DIRECTORY_SEPARATOR . "{$this->secret}.phpcr"
            ]
        );
    }

    private function exportDatabase()
    {
        $command =
            "mysqldump -h {$this->databaseHost} -u " . escapeshellarg($this->databaseUser) .
            ($this->databasePassword ? " -p" . escapeshellarg($this->databasePassword) : "") .
            " " . escapeshellarg($this->databaseName) . " > " . $this->exportDirectory . DIRECTORY_SEPARATOR . "{$this->secret}.sql";

        $process = new Process($command);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    private function exportUploads()
    {
        $exportPath = $this->kernelRootDir . DIRECTORY_SEPARATOR . ".."  . DIRECTORY_SEPARATOR . "var" . DIRECTORY_SEPARATOR . "uploads";
        $process = new Process(
            "tar cvf " . $this->exportDirectory . DIRECTORY_SEPARATOR . $this->secret . ".tar.gz {$exportPath}"
        );
        $process->setTimeout(300);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    private function executeCommand($cmd, array $params)
    {
        $command = $this->getApplication()->find($cmd);
        $command->run(
            new ArrayInput(
                ["command" => $cmd] + $params
            ),
            $this->output
        );
    }
}

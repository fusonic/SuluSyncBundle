<?php

namespace Fusonic\SuluSyncBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\NullOutput;
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

    /**
     * @var ProgressBar
     */
    private $progressBar;

    private $secret;
    private $databaseHost;
    private $databasePort;
    private $databaseUser;
    private $databaseName;
    private $databasePassword;
    private $kernelRootDir;
    private $exportDirectory;

    public function __construct(
        $secret,
        $databaseHost,
        $databasePort,
        $databaseName,
        $databaseUser,
        $databasePassword,
        $kernelRootDir
    ) {
        parent::__construct();

        $this->secret = $secret;
        $this->databaseHost = $databaseHost;
        $this->databasePort = is_null($databasePort) ? 3306 : $databasePort;
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

        $this->progressBar = new ProgressBar($this->output, 3);
        $this->progressBar->setFormat("%current%/%max% [%bar%] %percent:3s%% <info>%message%</info>");

        $this->exportPHPCR();
        $this->exportDatabase();
        $this->exportUploads();
        $this->progressBar->finish();

        $this->output->writeln(
            PHP_EOL . "<info>Successfully exported contents.</info>"
        );
    }

    private function exportPHPCR()
    {
        $this->progressBar->setMessage("Exporting PHPCR repository...");
        $this->executeCommand(
            "doctrine:phpcr:workspace:export",
            [
                "-p" => "/cmf",
                "filename" => $this->exportDirectory . DIRECTORY_SEPARATOR . "{$this->secret}.phpcr"
            ]
        );
        $this->progressBar->advance();
    }

    private function exportDatabase()
    {
        $this->progressBar->setMessage("Exporting database...");
        $command =
            "mysqldump -h {$this->databaseHost} -P {$this->databasePort} -u " . escapeshellarg($this->databaseUser) .
            ($this->databasePassword ? " -p" . escapeshellarg($this->databasePassword) : "") .
            " " . escapeshellarg($this->databaseName) . " > " . $this->exportDirectory . DIRECTORY_SEPARATOR . "{$this->secret}.sql";

        $process = new Process($command);
        $process->run();
        $this->progressBar->advance();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    private function exportUploads()
    {
        $this->progressBar->setMessage("Exporting uploads...");

        // Directory path with new Symfony directory structure - i.e. var/uploads.
        $exportPath = $this->kernelRootDir . DIRECTORY_SEPARATOR . ".."  . DIRECTORY_SEPARATOR . "var" . DIRECTORY_SEPARATOR . "uploads";
        if (!file_exists($exportPath)) {
            // Old-fashioned directory structure.
            $exportPath = $this->kernelRootDir . DIRECTORY_SEPARATOR . ".."  . DIRECTORY_SEPARATOR . "uploads";
        }

        $process = new Process(
            "tar cvf " . $this->exportDirectory . DIRECTORY_SEPARATOR . $this->secret . ".tar.gz {$exportPath}"
        );
        $process->setTimeout(300);
        $process->run();
        $this->progressBar->advance();

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
            new NullOutput()
        );
    }
}

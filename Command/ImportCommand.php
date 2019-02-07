<?php

namespace Fusonic\SuluSyncBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class ImportCommand extends Command
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
    }

    protected function configure()
    {
        $this
            ->setName("sulu:import")
            ->setDescription("Imports contents exported with the sulu:export command from the remote host.")
            ->addArgument(
                "host",
                InputArgument::REQUIRED,
                "The live system's URI."
            )
            ->addOption(
                "skip-assets",
                null,
                null,
                "Skip the download of assets."
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;
        $skipAssets = $this->input->getOption("skip-assets");

        $this->progressBar = new ProgressBar($this->output, $skipAssets ? 4 : 6);
        $this->progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% <info>%message%</info>');

        $this->downloadFiles($input->getArgument("host"));
        $this->importPHPCR();
        $this->importDatabase();

        if (!$skipAssets) {
            $this->importUploads();
        }

        $this->progressBar->finish();

        $this->output->writeln(
            PHP_EOL . "<info>Successfully imported contents. You're good to go!</info>"
        );
    }

    private function downloadFile($source, $target)
    {
        $context = stream_context_create();
        $resource = fopen($source, "r", null, $context);
        file_put_contents($target, $resource);

        return $resource;
    }

    private function downloadFiles($host)
    {
        $this->progressBar->setMessage("Downloading files...");
        $filename = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $this->secret;

        $files = [
            "{$host}/{$this->secret}.phpcr" => "{$filename}.phpcr",
            "{$host}/{$this->secret}.sql" => "{$filename}.sql",
        ];

        if (!$this->input->getOption("skip-assets")) {
            $files["{$host}/{$this->secret}.tar.gz"] = "{$filename}.tar.gz";
        }

        $result = true;
        foreach($files as $source => $target) {
            $result &= (bool)$this->downloadFile($source, $target);
            $this->progressBar->advance();

            if (!$result) {
                break;
            }
        }

        if (!$result) {
            throw new \Exception(
                "Some of the backup files could not be downloaded. Please make sure you have executed" .
                " 'sulu:export' on the remote host before and that you use the same secret."
            );
        }
    }

    private function importPHPCR()
    {
        $this->progressBar->setMessage("Importing PHPCR repository...");
        $this->executeCommand(
            "doctrine:phpcr:workspace:purge",
            [
                "--force" => true,
            ],
            new NullOutput()
        );
        $this->executeCommand(
            "doctrine:phpcr:workspace:import",
            [
                "filename" => $this->getTempPath(".phpcr")
            ],
            new NullOutput()
        );
        $this->progressBar->advance();
    }

    private function importDatabase()
    {
        $this->progressBar->setMessage("Importing database...");
        $filename = $this->getTempPath(".sql");
        $command =
            "mysql -h {$this->databaseHost} -P {$this->databasePort} -u " . escapeshellarg($this->databaseUser) .
            ($this->databasePassword ? " -p" . escapeshellarg($this->databasePassword) : "") .
            " " . escapeshellarg($this->databaseName) . " < " . "{$filename}";

        $process = new Process($command);
        $process->run();
        $this->progressBar->advance();
        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    private function importUploads()
    {
        $this->progressBar->setMessage("Importing uploads...");
        $filename = $this->getTempPath(".tar.gz");

        // Directory path with new Symfony directory structure - i.e. var/uploads.
        $path = $this->kernelRootDir . DIRECTORY_SEPARATOR . ".."  . DIRECTORY_SEPARATOR . "var" . DIRECTORY_SEPARATOR . "uploads";
        if (file_exists($path)) {
            $path = "var/uploads";
        } else {
            $path = "uploads";
        }

        $process = new Process("tar -xvf {$filename} {$path}  --no-overwrite-dir");
        $process->run();
        $this->progressBar->advance();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    private function executeCommand($cmd, array $params, OutputInterface $output)
    {
        $command = $this->getApplication()->find($cmd);
        $command->run(
            new ArrayInput(
                ["command" => $cmd] + $params
            ),
            $output
        );
    }

    /**
     * Returns the path to a file in the system's temporary directory.
     *
     * @param string    $extension      e.g. <code>.tar.gz</code>, <code>.phpcr</code> etc.
     *                                  Should start with a dot.
     *
     * @return string
     */
    private function getTempPath($extension)
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . $this->secret . $extension;
    }
}

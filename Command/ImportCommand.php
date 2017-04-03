<?php

namespace Fusonic\SuluSyncBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
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
    private $databaseUser;
    private $databaseName;
    private $databasePassword;

    public function __construct(
        $secret,
        $databaseHost,
        $databaseName,
        $databaseUser,
        $databasePassword
    ) {
        parent::__construct();

        $this->secret = $secret;
        $this->databaseHost = $databaseHost;
        $this->databaseUser = $databaseUser;
        $this->databaseName = $databaseName;
        $this->databasePassword = $databasePassword;
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

        $this->downloadFiles($input->getArgument("host"));
        $this->importPHPCR();
        $this->importDatabase();

        if (!$this->input->getOption("skip-assets")) {
            $this->importUploads();
        }

        $this->output->writeln(
            "<info>Successfully imported contents. You're good to go!</info>"
        );
    }

    private function downloadFile($source, $target)
    {
        $context = stream_context_create([], ["notification" => [$this, "progress"]]);
        $resource = fopen($source, "r", null, $context);
        file_put_contents($target, $resource);

        return $resource;
    }

    private function downloadFiles($host)
    {
        $this->output->writeln("Downloading files...");
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
        $this->output->write(PHP_EOL);
        $this->output->writeln("Importing PHPCR...");
        $this->executeCommand(
            "doctrine:phpcr:workspace:purge",
            [
                "--force" => true
            ],
            $this->output
        );
        $this->executeCommand(
            "doctrine:phpcr:workspace:import",
            [
                "filename" => $this->getTempPath(".phpcr")
            ],
            $this->output
        );
    }

    private function importDatabase()
    {
        $this->output->writeln("Importing database...");
        $filename = $this->getTempPath(".sql");
        $command =
            "mysql -h {$this->databaseHost} -u " . escapeshellarg($this->databaseUser) .
            ($this->databasePassword ? " -p" . escapeshellarg($this->databasePassword) : "") .
            " " . escapeshellarg($this->databaseName) . " < " . "{$filename}";

        $process = new Process($command);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    private function importUploads()
    {
        $filename = $this->getTempPath(".tar.gz");
        $process = new Process("tar -xvf {$filename} var/uploads  --no-overwrite-dir");
        $process->run();

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

    private function progress($notificationCode, $severity, $message, $messageCode, $bytesTransferred, $bytesMax)
    {
        if (STREAM_NOTIFY_REDIRECTED === $notificationCode && $this->progressBar) {
            $this->progressBar->clear();
            $this->progressBar = null;
            return;
        }

        if (STREAM_NOTIFY_FILE_SIZE_IS === $notificationCode) {
            if ($this->progressBar) {
                $this->progressBar->clear();
            }
            $this->progressBar = new ProgressBar($this->output, $bytesMax);
        }

        if (STREAM_NOTIFY_PROGRESS === $notificationCode) {
            if (is_null($this->progressBar)) {
                $this->progressBar = new ProgressBar($this->output);
            }
            $this->progressBar->setProgress($bytesTransferred);
        }

        if (STREAM_NOTIFY_COMPLETED === $notificationCode) {
            $this->finish($bytesTransferred);
        }
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

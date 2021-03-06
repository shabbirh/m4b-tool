<?php


namespace M4bTool\Command;

use Exception;
use M4bTool\Parser\FfmetaDataParser;
use M4bTool\Time\TimeUnit;
use SplFileInfo;
use Symfony\Component\Cache\Adapter\AbstractAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\ProcessBuilder;

class AbstractCommand extends Command
{

    const ARGUMENT_INPUT = "input";

    const OPTION_DEBUG = "debug";
    const OPTION_DEBUG_FILENAME = "debug-filename";
    const OPTION_FORCE = "force";
    const OPTION_NO_CACHE = "no-cache";

    const OPTION_MUSICBRAINZ_ID = "musicbrainz-id";

    /**
     * @var AbstractAdapter
     */
    protected $cache;

    /**
     * @var InputInterface
     */
    protected $input;

    /**
     * @var OutputInterface
     */
    protected $output;


    /**
     * @var SplFileInfo
     */
    protected $argInputFile;

    /**
     * @var bool
     */
    protected $optForce = false;

    /**
     * @var bool
     */
    protected $optNoCache = false;

    /**
     * @var bool
     */
    protected $optDebug = false;

    /**
     * @var SplFileInfo
     */
    protected $optDebugFile;

    protected function configure()
    {
        $className = get_class($this);
        $commandName = $this->dasherize(substr($className, strrpos($className, '\\') + 1));
        $this->setName(str_replace("-command", "", $commandName));
        $this->addArgument(static::ARGUMENT_INPUT, InputArgument::REQUIRED, 'Input file or folder');
        $this->addOption(static::OPTION_DEBUG, "d", InputOption::VALUE_NONE, "file to dump debugging info");
        $this->addOption(static::OPTION_DEBUG_FILENAME, null, InputOption::VALUE_OPTIONAL, "file to dump debugging info", "m4b-tool_debug.log");
        $this->addOption(static::OPTION_FORCE, "f", InputOption::VALUE_NONE, "force overwrite of existing files");
        $this->addOption(static::OPTION_NO_CACHE, null, InputOption::VALUE_NONE, "do not use cached values and clear cache completely");
    }

    function dasherize($string)
    {
        return strtolower(preg_replace('/(?<!^)([A-Z])/', '-$1', str_replace('_', '-', $string)));
    }

    protected function initExecution(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;
        $this->cache = new FilesystemAdapter();

        $this->loadArguments();

        if ($this->input->getOption(static::OPTION_NO_CACHE)) {
            $this->cache->clear();
        }
    }

    protected function loadArguments()
    {
        $this->argInputFile = new SplFileInfo($this->input->getArgument(static::ARGUMENT_INPUT));
        $this->optForce = $this->input->getOption(static::OPTION_FORCE);
        $this->optNoCache = $this->input->getOption(static::OPTION_NO_CACHE);
        $this->optDebug = $this->input->getOption(static::OPTION_DEBUG);
        $this->optDebugFile = new SplFileInfo($this->input->getOption(static::OPTION_DEBUG_FILENAME));
    }

    protected function ensureInputFileIsFile()
    {
        if (!$this->argInputFile->isFile()) {
            throw new Exception("Input is not a file");
        }
    }

    protected function audioFileToChaptersFile(SplFileInfo $audioFile)
    {
        $dirName = dirname($audioFile);
        $fileName = $audioFile->getBasename("." . $audioFile->getExtension());
        return new SplFileInfo($dirName . DIRECTORY_SEPARATOR . $fileName . ".chapters.txt");
    }

    protected function chaptersFileToAudioFile(SplFileInfo $chaptersFile, $audioExtension = "m4b")
    {
        $dirName = dirname($chaptersFile);
        $fileName = $chaptersFile->getBasename(".chapters." . $chaptersFile->getExtension());
        return new SplFileInfo($dirName . DIRECTORY_SEPARATOR . $fileName . "." . $audioExtension);
    }

    protected function audioFileToExtractedCoverFile(SplFileInfo $audioFile, $index = 0)
    {
        $dirName = dirname($audioFile);
        $fileName = $audioFile->getBasename("." . $audioFile->getExtension());
        return new SplFileInfo($dirName . DIRECTORY_SEPARATOR . $fileName . ".art[" . $index . "].jpg");
    }

    protected function audioFileToCoverFile(SplFileInfo $audioFile)
    {
        $dirName = dirname($audioFile);
        return new SplFileInfo($dirName . DIRECTORY_SEPARATOR . "cover.jpg");
    }

    protected function stripInvalidFilenameChars($fileName)
    {
        if ($this->isWindows()) {
            $invalidFilenameChars = [
                ' < ',
                '>',
                ':',
                '"',
                '/',
                '\\',
                '|',
                '?',
                '*',
            ];
            $replacedFileName = str_replace($invalidFilenameChars, '-', $fileName);
            return mb_convert_encoding($replacedFileName, 'Windows-1252', 'UTF-8');
        }
        $invalidFilenameChars = [" / ", "\0"];
        return str_replace($invalidFilenameChars, '-', $fileName);
    }

    protected function isWindows()
    {
        return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }

    protected function appendParameterToCommand(&$command, $parameterName, $parameterValue = null)
    {
        if (is_bool($parameterValue)) {
            $command[] = $parameterName;
            return;
        }

        if ($parameterValue) {
            $command[] = $parameterName;
            $command[] = $parameterValue;
        }
    }

    protected function splitLines($chapterString)
    {
        return preg_split("/\r\n|\n|\r/", $chapterString);
    }

    protected function readDuration(SplFileInfo $file)
    {
        if ($file->getExtension() == "mp4" || $file->getExtension() == "m4b") {

            $cacheItem = $this->cache->getItem("duration." . hash('sha256', $file->getRealPath()));
            if($cacheItem->isHit()) {
                return new TimeUnit($cacheItem->get(), TimeUnit::SECOND);
            }
            $proc = $this->shell([
                "mp4info", $file
            ], "getting duration for " . $file);
            $output = $proc->getOutput() . $proc->getErrorOutput();
            preg_match("/([1-9][0-9]*\.[0-9]{3}) secs,/isU", $output, $matches);
            $seconds = $matches[1];
            if (!$seconds) {
                return null;
            }
            $cacheItem->set($seconds);
            $this->cache->save($cacheItem);
            return new TimeUnit($seconds, TimeUnit::SECOND);
        }

        $meta = $this->readFileMetaData($file);
        if (!$meta) {
            return null;
        }
        return $meta->getDuration();


    }

    protected function shell(array $command, $introductionMessage = null)
    {
        $this->debug($this->formatShellCommand($command));
        $builder = new ProcessBuilder($command);
        $process = $builder->getProcess();
        $process->start();
        if ($introductionMessage) {
            $this->output->writeln($introductionMessage);
        }

        usleep(250000);
        $shouldShowEmptyLine = false;
        while ($process->isRunning()) {
            $shouldShowEmptyLine = true;
            $this->updateProgress();

        }
        if ($shouldShowEmptyLine) {
            $this->output->writeln('');
        }

        if ($process->getExitCode() != 0) {
            $this->debug($process->getOutput() . $process->getErrorOutput());
        }

        return $process;
    }

    protected function debug($message)
    {
        if (!$this->optDebug) {
            return;
        }

        if (!touch($this->optDebugFile) || !$this->optDebugFile->isWritable()) {
            throw new Exception("Debug file " . $this->optDebugFile . " is not writable");
        }

        if (!is_scalar($message)) {
            $message = var_export($message, true);
        }
        file_put_contents($this->optDebugFile, $message . PHP_EOL, FILE_APPEND);
    }

    protected function formatShellCommand(array $command)
    {

        $cmd = array_map(function ($part) {
            if (preg_match('/\s/', $part)) {
                return '"' . $part . '"';
            }
            return $part;
        }, $command);
        return implode(" ", $cmd);
    }

    protected function updateProgress()
    {
        static $i = 0;
        if (++$i % 60 == 0) {
            $this->output->writeln('+');
        } else {
            $this->output->write('+');
            usleep(1000000);
        }
    }

    protected function readFileMetaData(SplFileInfo $file)
    {
        if (!$file->isFile()) {
            throw new Exception("cannot read metadata, file " . $file . " does not exist");
        }

        $metaData = new FfmetaDataParser();
        $metaData->parse($this->readFileMetaDataOutput($file));
        return $metaData;
    }

    private function readFileMetaDataOutput(SplFileInfo $file)
    {
        $cacheItem = $this->cache->getItem("metadata." . hash('sha256', $file->getRealPath()));
        $cacheItem->expiresAt(new \DateTime("+12 hours"));
        if ($cacheItem->isHit()) {
            return $cacheItem->get();
        }

        $command = [
            "-i", $file,
            "-f", "ffmetadata",
            "-"
        ];
        $process = $this->ffmpeg($command, "reading metadata for file " . $file);
        $metaDataOutput = $process->getOutput() . PHP_EOL . $process->getErrorOutput();

        $this->debug($metaDataOutput);

        $cacheItem->set($metaDataOutput);
        $this->cache->save($cacheItem);
        return $metaDataOutput;

    }

    protected function ffmpeg($command, $introductionMessage = null)
    {
        array_unshift($command, "ffmpeg");
        return $this->shell($command, $introductionMessage);
    }

    protected function mp4chaps($command, $introductionMessage = null)
    {
        array_unshift($command, "mp4chaps");
        return $this->shell($command, $introductionMessage);
    }

    protected function mp4art($command, $introductionMessage = null)
    {
        array_unshift($command, "mp4art");
        return $this->shell($command, $introductionMessage);
    }


    protected function mp4tags($command, $introductionMessage = null)
    {
        array_unshift($command, "mp4tags");
        return $this->shell($command, $introductionMessage);
    }

}
<?php

namespace Ingenerator\ScheduledTaskRunner;

use Symfony\Component\Process\Process;
use function explode;
use function fwrite;
use function sprintf;
use function str_starts_with;

class SymfonyCronTaskProcessRunner implements CronTaskProcessRunner
{
    /**
     * @var resource
     */
    private $output_stream;

    private string $working_directory;

    /**
     * @param resource $output_stream
     */
    public function __construct(
        string $working_directory,
        $output_stream
    ) {
        $this->working_directory = $working_directory;
        $this->output_stream = $output_stream;
    }

    public function run(string $task_name, CronTaskStepDefinition $step, int $timeout_seconds): Process
    {
        $process = new Process($step->getCommand(), $this->working_directory, [], null, $timeout_seconds);
        // Disable symfony collecting process output into memory
        $process->disableOutput();
        $process->start(function ($type, $data) use ($task_name) {
            $lines = explode("\n", trim($data));
            foreach ($lines as $line) {
                if ( ! str_starts_with($line, '{')) {
                    // If it is a log entry from the child process it will have a `{` leading character because it's JSON
                    // Otherwise it must be old / low-level console output from legacy code, in which case we need to mark
                    // what task it came from.
                    // @todo: If/when we were confident none of the crons actually ever rendered raw output, we could pipe the child process direct to our STDOUT which would reduce log forwarding latency and probably memory consumption etc.
                    $line = sprintf('[%s] %s: %s', $task_name, $type, $line);
                }
                fwrite($this->output_stream, $line."\n");
            }
        });

        return $process;
    }
}

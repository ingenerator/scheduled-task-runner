<?php

namespace Ingenerator\ScheduledTaskRunner;

use Symfony\Component\Process\Process;

interface CronTaskProcessRunner
{
    public function run(string $task_name, CronTaskStepDefinition $step, int $timeout_seconds): Process;
}

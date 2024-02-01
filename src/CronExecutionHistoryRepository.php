<?php

namespace Ingenerator\ScheduledTaskRunner;

use DateTimeImmutable;

interface CronExecutionHistoryRepository
{
    public function recordCompletion(string $task_group, string $step_name, DateTimeImmutable $end, int $exit_code);

    public function listCurrentStates(): array;
}

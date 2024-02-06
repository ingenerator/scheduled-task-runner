<?php

namespace Ingenerator\ScheduledTaskRunner;

interface CronTaskExecutionManagerInterface
{
    public function executeNextTasks(): void;

    public function checkRunningTaskState(): void;

    public function hasRunningTasks(): bool;
}

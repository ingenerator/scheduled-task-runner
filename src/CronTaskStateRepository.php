<?php

namespace Ingenerator\ScheduledTaskRunner;

interface CronTaskStateRepository
{
    /**
     * Will create the task state if required
     */
    public function getState(string $group_name): TaskExecutionState;

    public function save(TaskExecutionState $state);
}

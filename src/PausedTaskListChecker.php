<?php

namespace Ingenerator\ScheduledTaskRunner;;

interface PausedTaskListChecker
{
    public function isPaused(string $task_group_name): bool;
}

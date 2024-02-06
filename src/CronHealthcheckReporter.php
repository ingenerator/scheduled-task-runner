<?php

namespace Ingenerator\ScheduledTaskRunner;

use DateTimeImmutable;
use Ingenerator\PHPUtils\DateTime\Clock\RealtimeClock;

class CronHealthcheckReporter
{
    protected RealtimeClock                  $clock;

    protected CronConfigLoader               $config;

    protected CronExecutionHistoryRepository $history_repo;

    public function __construct(
        CronConfigLoader $config,
        RealtimeClock $clock,
        CronExecutionHistoryRepository $history_repo
    ) {
        $this->history_repo = $history_repo;
        $this->config = $config;
        $this->clock = $clock;
    }

    public function getHealthState(): array
    {
        $missing_tasks = $this->findMissingTasks();
        $is_healthy = $missing_tasks === [];

        return [
            'is_healthy' => $is_healthy,
            'missing_tasks' => $missing_tasks,
            'http_status' => $is_healthy ? 200 : 599,
            'http_status_name' => $is_healthy ? 'OK' : 'Missing expected tasks',
        ];
    }

    private function findMissingTasks(): array
    {
        $missing = [];
        $last_successes = $this->loadLastSuccesses();
        $now = $this->clock->getDateTime();

        foreach ($this->config->getActiveTaskDefinitions() as $group) {
            $group_name = $group->getGroupName();
            $need_since = $now->sub($group->getHealthcheckTimeout());
            foreach ($group->getStepNames() as $step_name) {
                $last_success = $last_successes[$group_name][$step_name] ?? null;
                if ($last_success and (new DateTimeImmutable($last_success) > $need_since)) {
                    continue;
                }

                $missing["$group_name--$step_name"] = $last_success;
            }
        }

        return $missing;
    }

    private function loadLastSuccesses(): array
    {
        $map = [];
        foreach ($this->history_repo->listCurrentStates() as $state) {
            $map[$state['group_name']][$state['step_name']] = $state['last_success_at'];
        }

        return $map;
    }
}

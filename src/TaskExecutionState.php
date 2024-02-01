<?php

namespace Ingenerator\ScheduledTaskRunner;

use DateInterval;
use DateTimeImmutable;

class TaskExecutionState
{
    private string              $group_name;

    private bool                $is_running;

    private string              $last_step_executed;

    private DateTimeImmutable  $last_run_started_at;

    private DateTimeImmutable  $last_run_timeout_at;

    private DateTimeImmutable  $last_run_completed_at;

    private ?DateTimeImmutable $refresh_at;

    public static function forNewTask(string $group_name, DateTimeImmutable $now): self
    {
        $i = new static();
        $i->group_name = $group_name;
        $i->is_running = false;
        // Irrelevant placeholder value, just to ensure the task is treated as needing to start from the first step
        $i->last_step_executed = '###none###';

        // Mark all the execution times as *now* that way the task will just run on the next scheduled window
        // e.g. if we deploy a new task scheduled for 15 past the hour, it'll run at the next XX:15, not immediately
        $i->last_run_started_at = $now;
        $i->last_run_completed_at = $now;
        $i->last_run_timeout_at = $now;
        $i->refresh_at = null;

        return $i;
    }

    public static function fromDbRow(array $vars, ?DateTimeImmutable $refresh_at): self
    {
        $i = new static();
        $i->group_name = $vars['group_name'];
        $i->is_running = $vars['is_running'];
        $i->last_run_completed_at = new DateTimeImmutable($vars['last_run_completed_at']);
        $i->last_run_started_at = new DateTimeImmutable($vars['last_run_started_at']);
        $i->last_run_timeout_at = new DateTimeImmutable($vars['last_run_timeout_at']);
        $i->last_step_executed = $vars['last_step_executed'];
        $i->refresh_at = $refresh_at;

        return $i;
    }

    public function getGroupName(): string
    {
        return $this->group_name;
    }

    public function getLastRunCompletedAt(): DateTimeImmutable
    {
        return $this->last_run_completed_at;
    }

    public function getLastRunStartedAt(): DateTimeImmutable
    {
        return $this->last_run_started_at;
    }

    public function getLastStepExecuted(): string
    {
        return $this->last_step_executed;
    }

    public function getRefreshAt(): ?DateTimeImmutable
    {
        return $this->refresh_at;
    }

    public function isRunning(): bool
    {
        return $this->is_running;
    }

    public function isTimedOutAt(DateTimeImmutable $now): bool
    {
        return $this->is_running && ($this->last_run_timeout_at <= $now);
    }

    public function markStepComplete(DateTimeImmutable $now, bool $was_successful): void
    {
        $this->is_running = false;
        $this->last_run_completed_at = $now;
        if ( ! $was_successful) {
            // As with ###timed-out### this is a hack to prevent the group progressing to the next step
            $this->last_step_executed = '###failed###';
        }
    }

    public function markStepRunning(string $step, DateTimeImmutable $started, int $timeout_seconds): void
    {
        $this->last_step_executed = $step;
        $this->last_run_started_at = $started;
        $this->last_run_timeout_at = $started->add(new DateInterval('PT'.$timeout_seconds.'S'));
        $this->is_running = true;
    }

    public function markTimedOut(DateTimeImmutable $now): void
    {
        $this->is_running = false;
        $this->last_run_completed_at = $now;
        // The '##timed-out##' is a hack, to force us to treat that as though the last step of the task group
        // had run, so that we wait till the next scheduled window and run the group from scratch again.
        // Otherwise if an intermediate step timed out, the system would schedule the next step in the group
        // immediately but we don't want that - groups must either run to completion or fail and skip remaining
        // steps to mirror the behaviour of the old all-in-one sequential scripts.
        $this->last_step_executed = '###timed-out###';
    }

    public function needsRefresh(DateTimeImmutable $now): bool
    {
        if ($this->refresh_at === null) {
            return false;
        }

        return $this->refresh_at <= $now;
    }
}

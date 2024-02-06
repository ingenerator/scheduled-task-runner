<?php

namespace Ingenerator\ScheduledTaskRunner;

use DateTimeImmutable;
use Ingenerator\PHPUtils\DateTime\Clock\RealtimeClock;
use Symfony\Component\Process\Process;

class CronTaskExecutionManager implements CronTaskExecutionManagerInterface
{
    private RealtimeClock         $clock;

    private PausedTaskListChecker $paused_task_list;

    private CronTaskProcessRunner $process_runner;

    /**
     * @var array<string,Process>
     */
    private array $running_tasks = [];

    /**
     * @var CronTaskGroupDefinition[]
     */
    private array                   $task_definitions;

    private CronTaskStateRepository $task_state_repo;

    private CronStatusReporter      $status_reporter;

    public function __construct(
        RealtimeClock $clock,
        CronTaskStateRepository $task_state_repo,
        CronTaskProcessRunner $process_runner,
        CronStatusReporter $status_reporter,
        CronConfigLoader $config_loader,
        PausedTaskListChecker $paused_task_list,
    ) {
        $this->task_state_repo = $task_state_repo;
        $this->clock = $clock;
        $this->process_runner = $process_runner;
        $this->task_definitions = $config_loader->getActiveTaskDefinitions();
        $this->status_reporter = $status_reporter;
        $this->paused_task_list = $paused_task_list;
    }

    public function executeNextTasks(): void
    {
        $this->checkRunningTaskState();

        foreach ($this->task_definitions as $task) {
            $state = $this->task_state_repo->getState($task->getGroupName());
            $this->executeTaskIfReady($task, $state);
        }
    }

    public function checkRunningTaskState(): void
    {
        // This is also called at the start of every executeNextTasks.
        // We just need to check all the processes we've started so far and see if they've finished yet
        $still_running = [];
        foreach ($this->running_tasks as $task_group_name => $process) {
            if ($process->isTerminated()) {
                $this->handleCompletedTask($task_group_name, $process);
            } else {
                $still_running[$task_group_name] = $process;
            }
        }
        $this->running_tasks = $still_running;
    }

    private function handleCompletedTask(string $task_group_name, Process $process): void
    {
        $state = $this->task_state_repo->getState($task_group_name);

        $now = $this->clock->getDateTime();
        $exit_code = $process->getExitCode();
        $this->status_reporter->reportCompleted(
            $task_group_name,
            $state->getLastStepExecuted(),
            $state->getLastRunStartedAt(),
            $now,
            $exit_code
        );

        $state->markStepComplete($now, $exit_code === 0);
        $this->task_state_repo->save($state);
    }

    private function executeTaskIfReady(CronTaskGroupDefinition $task, TaskExecutionState $state): void
    {
        $now = $this->clock->getDateTime();

        if ($state->isRunning()) {
            // We can't execute a task that's already running, regardless of any other consideration
            // But we might need to mark that it has timed out
            if ($state->isTimedOutAt($now)) {
                $this->handleTimedOutTask($state, $now);
            }

            return;
        }

        if ($this->paused_task_list->isPaused($task->getGroupName())) {
            return;
        }

        $next_step = $task->getStepAfter($state->getLastStepExecuted());
        if ($next_step) {
            // This is a multi-step task, and there is a step available after the last one that ran.
            // That means that we should run the next step immediately.
            $this->launchNewTask($state, $next_step, $now, $task);

            return;
        }

        // Either:
        // - This is a single step task
        // - This is a multi step task but the last step that ran was the last step of the process
        // - This is a single or multi step task but the last step that ran is not recognised as a valid step e.g.
        //   because it has a placeholder value like ###timed-out### or because the task definition has changed on a
        //   deployment
        //
        // Either way that means we should wait till the next scheduled time to run the first(/only) step of the task.
        // We calculate from last completion, so that if e.g. a task scheduled to run every minute starts at 00:01:00
        // and runs for 90 seconds, then the next run will start at 00:03:00 - the run that should have happened at
        // 00:02:00 is just ignored, it doesn't run at 00:02:30.

        $next_due = $task->getScheduledTimeAfter($state->getLastRunCompletedAt());

        if ($next_due > $now) {
            // The task is not yet due to run again
            return;
        }

        // OK the task is due to run now, start the first step
        $this->launchNewTask($state, $task->getFirstStep(), $now, $task);
    }

    private function handleTimedOutTask(TaskExecutionState $state, DateTimeImmutable $now): void
    {
        // Note that we don't actually do anything with the running process, even if it belongs to us.
        // Most likely it doesn't belong to us, it was started by a previous primary which was then forcibly terminated
        // or crashed before it could update the final state of the task.
        //
        // But there's a chance the process is one we started and is still running e.g. if it's blocking on network
        // or some external service. But in that case we want to assume that there's a chance a new iteration of the
        // task will succeed. It may well be anyway that trying to send a kill signal wouldn't do anything, and
        // there are plenty edge cases trying to detect if a process is in a state it can be signaled.
        //
        // The `timeout` in the task definition is essentially just a lock timeout, not a process timeout. So we just
        // expire the lock and let the next scheduled execution run as planned.
        $this->status_reporter->reportTimedOut(
            $state->getGroupName(),
            $state->getLastStepExecuted(),
            $state->getLastRunStartedAt(),
            $now
        );
        $state->markTimedOut($now);
        $this->task_state_repo->save($state);
    }

    private function launchNewTask(
        TaskExecutionState $state,
        CronTaskStepDefinition $step,
        DateTimeImmutable $now,
        CronTaskGroupDefinition $task
    ): void {
        // Record state that we're running before we start the process : if something crashes it's better that
        // the db prevents a second copy of the process running too soon, even if that means there's a risk it
        // doesn't actually get run at all.
        $state->markStepRunning($step->getName(), $now, $task->getTimeoutSeconds());
        $this->task_state_repo->save($state);

        $this->status_reporter->reportStarting($task->getGroupName(), $step->getName());

        $this->running_tasks[$task->getGroupName()] = $this->process_runner->run(
            $state->getGroupName(),
            $step,
            $task->getTimeoutSeconds()
        );
    }

    public function hasRunningTasks(): bool
    {
        // Terminated processes are always removed from this array when we check state, so all we need to know
        // is whether there are any currently in the array.
        return $this->running_tasks !== [];
    }
}

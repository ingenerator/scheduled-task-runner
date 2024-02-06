<?php

namespace Ingenerator\ScheduledTaskRunner;

use DateTimeImmutable;
use Ingenerator\PHPUtils\DateTime\DateTimeDiff;
use Ingenerator\PHPUtils\Monitoring\MetricId;
use Ingenerator\PHPUtils\Monitoring\MetricsAgent;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use function preg_replace;
use function sprintf;

class CronStatusReporter
{
    private CronExecutionHistoryRepository $history_repo;

    private MetricsAgent                   $metrics;

    private LoggerInterface                $logger;

    public function __construct(
        LoggerInterface $logger,
        MetricsAgent $metrics,
        CronExecutionHistoryRepository $history_repo
    ) {
        $this->logger = $logger;
        $this->metrics = $metrics;
        $this->history_repo = $history_repo;
    }

    public function reportStarting(
        string $task_group_name,
        string $step_name
    ): void {
        $this->logger->info(sprintf('Starting task %s:%s', $task_group_name, $step_name));
    }

    public function reportCompleted(
        string $task_group_name,
        string $step_name,
        DateTimeImmutable $started,
        DateTimeImmutable $ended,
        int $exit_code
    ): void {
        $this->logger->log(
            $exit_code === 0 ? LogLevel::NOTICE : LogLevel::ERROR,
            sprintf(
                'Task %s:%s completed with code %s after %.1fs',
                $task_group_name,
                $step_name,
                $exit_code,
                $this->calcElapsedMillis($started, $ended) / 1_000
            )
        );

        if ($exit_code === 0) {
            $this->metrics->addTimer($this->calcMetricId($task_group_name, $step_name), $started, $ended);
        }

        $this->history_repo->recordCompletion($task_group_name, $step_name, $ended, $exit_code);
    }

    private function calcMetricId(string $task_group_name, string $step_name): MetricId
    {
        return MetricId::nameAndSource(
            'cron-runtime-ms',
            // Get rid of colons and other things that might cause trouble
            preg_replace('/[^a-z0-9\-]/', '-', $task_group_name.'--'.$step_name),
        );
    }

    public function reportTimedOut(
        string $task_group_name,
        string $step_name,
        DateTimeImmutable $started,
        DateTimeImmutable $timed_out_at
    ): void {
        $this->logger->alert(
            sprintf(
                'Task %s:%s lock timed out after %ds',
                $task_group_name,
                $step_name,
                $this->calcElapsedMillis($started, $timed_out_at) / 1_000
            )
        );
    }

    private function calcElapsedMillis(DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        return (int) round(DateTimeDiff::microsBetween($start, $end) / 1_000);
    }
}

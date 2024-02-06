<?php

namespace test\unit\Ingenerator\ScheduledTaskRunner;

use DateTimeImmutable;
use Ingenerator\PHPUtils\Monitoring\ArrayMetricsAgent;
use Ingenerator\PHPUtils\Monitoring\AssertMetrics;
use Ingenerator\PHPUtils\Monitoring\MetricId;
use Ingenerator\ScheduledTaskRunner\CronExecutionHistoryRepository;
use Ingenerator\ScheduledTaskRunner\CronStatusReporter;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use test\mock\Ingenerator\ScheduledTaskRunner\Logging\SpyingLoggerStub;

class CronStatusReporterTest extends BaseTestCase
{
    private CronExecutionHistoryRepository $history_repo;

    private LoggerInterface $logger;

    private ArrayMetricsAgent $metrics;

    public function test_it_is_initialisable()
    {
        $this->assertInstanceOf(CronStatusReporter::class, $this->newSubject());
    }

    public function test_it_logs_starting_tasks()
    {
        $this->newSubject()->reportStarting('foo', 'foo-2');
        $this->logger->assertLoggedOnceMatching(LogLevel::INFO, '/^Starting task foo:foo-2$/', []);
    }

    /**
     * @testWith [0, "notice"]
     *           [15, "error"]
     */
    public function test_it_logs_completed_tasks_with_errorlevel_if_nonzero_exit($exit_code, $expect_loglevel)
    {
        $this->newSubject()->reportCompleted(
            'foo',
            'foo-2',
            new DateTimeImmutable('2022-03-02 16:03:02.000000'),
            new DateTimeImmutable('2022-03-02 16:03:03.340000'),
            $exit_code
        );

        $this->logger->assertLoggedOnceMatching(
            $expect_loglevel,
            '/^Task foo:foo-2 completed with code '.$exit_code.' after 1\.3s$/',
            []
        );
    }

    public function test_it_logs_timed_out_tasks()
    {
        $this->newSubject()->reportTimedOut(
            'my-task',
            'first',
            new DateTimeImmutable('2022-03-04 10:02:03.000000'),
            new DateTimeImmutable('2022-03-04 10:32:03.000000')
        );
        $this->logger->assertLoggedOnceMatching(
            LogLevel::ALERT,
            '/^Task my-task:first lock timed out after 1800s$/',
            []
        );
    }

    public function test_it_does_not_report_metric_on_task_failure()
    {
        $this->newSubject()->reportCompleted(
            'any',
            'thing',
            new DateTimeImmutable('2022-03-07 16:00:00.230142'),
            new DateTimeImmutable('2022-03-07 16:00:00.238109'),
            15
        );
        AssertMetrics::assertNoMetricsCaptured($this->metrics->getMetrics());
    }

    /**
     * @testWith ["any", "group:thing", "any--group-thing"]
     *           ["friends-sync", "friends-sync", "friends-sync--friends-sync"]
     */
    public function test_it_reports_metric_on_task_success_with_valid_source_name($group, $step, $expect_source)
    {
        $this->newSubject()->reportCompleted(
            $group,
            $step,
            new DateTimeImmutable('2022-03-07 16:00:00.230142'),
            new DateTimeImmutable('2022-03-07 16:00:01.238109'),
            0
        );

        AssertMetrics::assertTimerValues(
            $this->metrics->getMetrics(),
            MetricId::nameAndSource('cron-runtime-ms', $expect_source),
            [1008],
            0.1
        );
    }

    public function test_it_records_last_execution_state_in_database()
    {
        $this->history_repo = new class() implements CronExecutionHistoryRepository {
            private array $states = [];

            public function recordCompletion(
                string            $task_group,
                string            $step_name,
                DateTimeImmutable $end,
                int               $exit_code
            ) {
                $this->states[] = [
                    'task' => $task_group,
                    'step' => $step_name,
                    'end'  => $end->format('Y-m-d H:i:s'),
                    'exit' => $exit_code,
                ];
            }

            public function listCurrentStates(): array
            {
                return $this->states;
            }
        };
        $this->newSubject()->reportCompleted(
            'any-group',
            'my-step',
            new DateTimeImmutable('2022-03-07 16:00:00.230142'),
            new DateTimeImmutable('2022-03-07 16:00:01.238109'),
            0
        );
        $this->assertSame(
            [
                [
                    'task' => 'any-group',
                    'step' => 'my-step',
                    'end'  => '2022-03-07 16:00:01',
                    'exit' => 0,
                ],
            ],
            $this->history_repo->listCurrentStates()
        );
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger       = new SpyingLoggerStub();
        $this->metrics      = new ArrayMetricsAgent();
        $this->history_repo = $this->getDummy(CronExecutionHistoryRepository::class);
    }

    private function newSubject(): CronStatusReporter
    {
        return new CronStatusReporter(
            $this->logger,
            $this->metrics,
            $this->history_repo
        );
    }
}

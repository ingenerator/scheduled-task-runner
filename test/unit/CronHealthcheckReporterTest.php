<?php

namespace test\unit\Ingenerator\ScheduledTaskRunner;

use BadMethodCallException;
use DateTimeImmutable;
use Ingenerator\PHPUtils\DateTime\Clock\StoppedMockClock;
use Ingenerator\ScheduledTaskRunner\CronExecutionHistoryRepository;
use Ingenerator\ScheduledTaskRunner\CronHealthcheckReporter;
use Ingenerator\ScheduledTaskRunner\TestUtils\CronConfigLoaderStub;
use PHPUnit\Framework\TestCase;

class CronHealthcheckReporterTest extends TestCase
{
    private StoppedMockClock $clock;

    private array $task_definitions = [];

    private array $history_states = [];

    public function test_it_is_initialisable()
    {
        $this->assertInstanceOf(CronHealthcheckReporter::class, $this->newSubject());
    }

    public function test_it_reports_healthy_if_all_configured_crons_have_succeeded_within_expected_timeframe()
    {
        $this->task_definitions = [
            'job-1' => [
                'steps'               => ['first'],
                'healthcheck_timeout' => 'PT5M',
            ],
            'job-2' => [
                'steps'               => ['first', 'second'],
                'healthcheck_timeout' => 'PT1H',
            ],
        ];
        $this->clock            = StoppedMockClock::at('2022-03-08 10:01:59');
        $this->history_states   = [
            ['group_name' => 'job-1', 'step_name' => 'first', 'last_success_at' => '2022-03-08 09:57:00'], // 4m59 ago
            ['group_name' => 'job-2', 'step_name' => 'first', 'last_success_at' => '2022-03-08 09:02:00'], // 59m59 ago
            ['group_name' => 'job-2', 'step_name' => 'second', 'last_success_at' => '2022-03-08 09:05:05'], // within hr
        ];

        $this->assertSame(
            [
                'is_healthy'       => TRUE,
                'missing_tasks'    => [],
                'http_status'      => 200,
                'http_status_name' => 'OK',
            ],
            $this->newSubject()->getHealthState()
        );
    }

    /**
     * @testWith [{"j1-1": "2022-03-08 09:56:59"}, {"job-1--first": "2022-03-08 09:56:59"}]
     *           [{"j2-1": "2022-03-08 09:01:59"}, {"job-2--first": "2022-03-08 09:01:59"}]
     *           [{"j2-2": "2022-03-08 09:01:59"}, {"job-2--second": "2022-03-08 09:01:59"}]
     *           [{"j1-1": "2022-03-07 10:00:00", "j2-1":"2022-03-07 09:45:00", "j2-2": "2022-03-07 09:50:00"}, {"job-1--first":"2022-03-07 10:00:00","job-2--first":"2022-03-07 09:45:00","job-2--second":"2022-03-07 09:50:00"}]
     *           [{"j2-1": null}, {"job-2--first":null}]
     */
    public function test_it_reports_unhealthy_if_any_configured_cron_last_succeeded_before_configured_timeframe(
        $last_successes,
        $expect_missing
    ) {
        $last_successes = \array_merge(
            [
                'j1-1' => '2022-03-08 09:57:00',
                'j2-1' => '2022-03-08 09:02:00',
                'j2-2' => '2022-03-08 09:05:05',
            ],
            $last_successes
        );

        $this->task_definitions = [
            'job-1' => [
                'steps'               => ['first'],
                'healthcheck_timeout' => 'PT5M',
            ],
            'job-2' => [
                'steps'               => ['first', 'second'],
                'healthcheck_timeout' => 'PT1H',
            ],
        ];
        $this->clock            = StoppedMockClock::at('2022-03-08 10:01:59');
        $this->history_states   = [
            ['group_name' => 'job-1', 'step_name' => 'first', 'last_success_at' => $last_successes['j1-1']],
            ['group_name' => 'job-2', 'step_name' => 'first', 'last_success_at' => $last_successes['j2-1']],
            ['group_name' => 'job-2', 'step_name' => 'second', 'last_success_at' => $last_successes['j2-2']],
        ];

        $this->assertSame(
            [
                'is_healthy'       => FALSE,
                'missing_tasks'    => $expect_missing,
                'http_status'      => 599,
                'http_status_name' => 'Missing expected tasks',
            ],
            $this->newSubject()->getHealthState()
        );
    }

    public function test_it_reports_unhealthy_if_any_configured_cron_has_never_reported_status()
    {
        $this->task_definitions = [
            'job-2'   => [
                'steps'               => ['first', 'new', 'second'],
                'healthcheck_timeout' => 'PT1H',
            ],
            'new-job' => [
                'steps'               => ['first'],
                'healthcheck_timeout' => 'PT5M',
            ],
        ];
        $this->clock            = StoppedMockClock::at('2022-03-08 10:01:59');
        $this->history_states   = [
            ['group_name' => 'job-2', 'step_name' => 'first', 'last_success_at' => '2022-03-08 10:00:00'],
            ['group_name' => 'job-2', 'step_name' => 'second', 'last_success_at' => '2022-03-08 10:30:00'],
        ];

        $this->assertSame(
            [
                'is_healthy'       => FALSE,
                'missing_tasks'    => ['job-2--new' => NULL, 'new-job--first' => NULL],
                'http_status'      => 599,
                'http_status_name' => 'Missing expected tasks',
            ],
            $this->newSubject()->getHealthState()
        );
    }

    public function test_it_ignores_cron_executions_that_are_no_longer_defined_or_disabled()
    {
        $this->task_definitions = [
            'disabled_job' => [
                'is_enabled'          => FALSE,
                'steps'               => ['first'],
                'healthcheck_timeout' => 'PT5M',
            ],
            'job-1'        => [
                'steps'               => ['first'],
                'healthcheck_timeout' => 'PT1H',
            ],
        ];
        $this->clock            = StoppedMockClock::at('2022-03-08 10:01:59');
        $this->history_states   = [
            ['group_name' => 'job-1', 'step_name' => 'first', 'last_success_at' => '2022-03-08 10:00:00'],
            ['group_name' => 'old-job', 'step_name' => 'anything', 'last_success_at' => '2021-01-08 10:30:00'],
            ['group_name' => 'disabled-job', 'step_name' => 'anything', 'last_success_at' => '2021-01-08 10:15:00'],
        ];

        $this->assertSame(
            [
                'is_healthy'       => TRUE,
                'missing_tasks'    => [],
                'http_status'      => 200,
                'http_status_name' => 'OK',
            ],
            $this->newSubject()->getHealthState()
        );
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = StoppedMockClock::atNow();
    }

    private function newSubject(): CronHealthcheckReporter
    {
        return new CronHealthcheckReporter(
            CronConfigLoaderStub::withTaskDefinitions($this->task_definitions),
            $this->clock,
            $this->createHistoryRepo()
        );
    }

    /**
     * @return CronExecutionHistoryRepository
     */
    private function createHistoryRepo()
    {
        return new class($this->history_states) implements CronExecutionHistoryRepository {
            private array $states;

            public function __construct(array $states)
            {
                $this->states = $states;
            }

            public function recordCompletion(
                string            $task_group,
                string            $step_name,
                DateTimeImmutable $end,
                int               $exit_code
            ) {
                throw new BadMethodCallException('Implement '.__METHOD__);
            }

            public function listCurrentStates(): array
            {
                return array_map(
                    fn($s) => array_merge(
                        [
                            'group_name'      => 'foo',
                            'step_name'       => 'bar',
                            'last_exit_code'  => NULL,
                            'last_success_at' => NULL,
                            'last_failure_at' => NULL,
                        ],
                        $s
                    ),
                    $this->states
                );
            }
        };
    }
}

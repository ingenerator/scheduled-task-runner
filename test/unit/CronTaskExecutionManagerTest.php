<?php

namespace test\unit\Ingenerator\ScheduledTaskRunner;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Ingenerator\PHPUtils\DateTime\Clock\RealtimeClock;
use Ingenerator\PHPUtils\DateTime\Clock\StoppedMockClock;
use Ingenerator\PHPUtils\Object\ObjectPropertyRipper;
use Ingenerator\PHPUtils\Repository\AbstractArrayRepository;
use Ingenerator\ScheduledTaskRunner\CronStatusReporter;
use Ingenerator\ScheduledTaskRunner\CronTaskExecutionManager;
use Ingenerator\ScheduledTaskRunner\CronTaskStateRepository;
use Ingenerator\ScheduledTaskRunner\CronTaskStepDefinition;
use Ingenerator\ScheduledTaskRunner\PausedTaskListChecker;
use Ingenerator\ScheduledTaskRunner\SymfonyCronTaskProcessRunner;
use Ingenerator\ScheduledTaskRunner\TaskExecutionState;
use Ingenerator\ScheduledTaskRunner\TestUtils\CronConfigLoaderStub;
use PHPUnit\Framework\Assert;
use Symfony\Component\Process\Process;
use function array_map;
use function array_merge;
use function fopen;

class CronTaskExecutionManagerTest extends BaseTestCase
{
    private RealtimeClock $clock;

    private ArrayCronTaskStateRepository $task_state_repo;

    private array $task_definitions = [];

    private ProcessRunnerSpy $process_runner;

    private CronStatusReporter $reporter;

    private array $paused_task_state = [];

    public function test_it_is_initialisable()
    {
        $this->assertInstanceOf(CronTaskExecutionManager::class, $this->newSubject());
    }

    public function test_its_execute_next_does_nothing_with_no_enabled_crons()
    {
        $this->task_definitions = [
            'anything' => ['is_enabled' => FALSE, 'schedule' => ['minute' => '*']],
        ];
        // If a task isn't enabled then we don't even fetch state for it, it just doesn't exist as far as we care
        $this->task_state_repo = $this->getDummyExpectingNoCalls(ArrayCronTaskStateRepository::class);
        $this->newSubject()->executeNextTasks();
        $this->process_runner->assertRanNothing();
    }

    public function provider_execute_tasks()
    {
        $single_step_task_at_five_past = [
            'steps'           => ['run-something'],
            'schedule'        => ['minute' => 5],
            'timeout_seconds' => 300,
        ];
        $multi_step_task_at_20_past    = [
            'steps'           => ['first-step', 'second-step'],
            'schedule'        => ['minute' => 20],
            'timeout_seconds' => 600,
        ];

        return [
            /*
             * Single step task, not currently running
             */
            [
                $single_step_task_at_five_past,
                ['is_running' => FALSE, 'last_run_completed_at' => '2022-03-01 19:08:00'],
                '2022-03-01 20:04:59.999999',
                FALSE,
                'Not yet due',
            ],
            [
                $single_step_task_at_five_past,
                ['is_running' => FALSE, 'last_run_completed_at' => '2022-03-01 19:08:00'],
                '2022-03-01 20:05:00.000000',
                [
                    'ran_step'   => 'run-something',
                    'started_at' => '2022-03-01 20:05:00',
                    'timeout'    => '2022-03-01 20:10:00',
                ],
                'Due now',
            ],
            [
                $single_step_task_at_five_past,
                ['is_running' => FALSE, 'last_run_completed_at' => '2022-03-01 19:08:00'],
                '2022-03-01 20:05:03.123723',
                [
                    'ran_step'   => 'run-something',
                    'started_at' => '2022-03-01 20:05:03',
                    'timeout'    => '2022-03-01 20:10:03',
                ],
                'Late checking, still due',
            ],
            [
                $single_step_task_at_five_past,
                ['is_running' => FALSE, 'last_run_completed_at' => '2022-03-01 19:08:00'],
                '2022-03-01 22:04:03.123723',
                [
                    'ran_step'   => 'run-something',
                    'started_at' => '2022-03-01 22:04:03',
                    'timeout'    => '2022-03-01 22:09:03',
                ],
                'Missed some hours altogether, still due',
            ],
            /*
             * Task is currently running but not timed out - behaviour identical for single & multi
             */
            [
                $single_step_task_at_five_past,
                [
                    'is_running'            => TRUE,
                    'last_run_timeout_at'   => '2022-03-01 20:20:00',
                    'last_run_completed_at' => '2022-03-01 19:08:00',
                ],
                '2022-03-01 20:19:59.999999',
                FALSE,
                'Task is running and not yet timed out',
            ],
            [
                $multi_step_task_at_20_past,
                [
                    'is_running'            => TRUE,
                    'last_run_timeout_at'   => '2022-03-01 20:25:00',
                    'last_run_completed_at' => '2022-03-01 19:08:00',
                ],
                '2022-03-01 20:24:59.999999',
                FALSE,
                'Task is running and not yet timed out',
            ],
            // NB: Task running but timed out is handled in a separate test as the first loop just clears the state
            /*
             * Multi-step tasks
             */
            [
                $multi_step_task_at_20_past,
                ['last_step_executed' => 'second-step', 'last_run_completed_at' => '2022-03-01 19:25:00'],
                '2022-03-01 20:19:59.999999',
                FALSE,
                'Multi step task ran to completion, not yet at scheduled time',
            ],
            [
                $multi_step_task_at_20_past,
                ['last_step_executed' => 'second-step', 'last_run_completed_at' => '2022-03-01 19:25:00'],
                '2022-03-01 20:20:00.000000',
                [
                    'ran_step'   => 'first-step',
                    'started_at' => '2022-03-01 20:20:00',
                    'timeout'    => '2022-03-01 20:30:00',
                ],
                'Multi step task ran to completion, not yet at scheduled time',
            ],
            [
                $multi_step_task_at_20_past,
                ['last_step_executed' => 'first-step', 'last_run_completed_at' => '2022-03-01 20:21:02'],
                '2022-03-01 20:21:02.873322',
                [
                    'ran_step'   => 'second-step',
                    'started_at' => '2022-03-01 20:21:02',
                    'timeout'    => '2022-03-01 20:31:02',
                ],
                'Multi step task ran previous step, run next immediately',
            ],
            [
                $multi_step_task_at_20_past,
                ['last_step_executed' => '###unknown-value###', 'last_run_completed_at' => '2022-03-01 20:21:02'],
                '2022-03-01 21:19:59.999999',
                FALSE,
                'Multi step task ran unknown step, don\'t run before next schedule time',
            ],
            [
                $multi_step_task_at_20_past,
                ['last_step_executed' => '###unknown-value###', 'last_run_completed_at' => '2022-03-01 20:21:02'],
                '2022-03-01 21:20:00.000000',
                [
                    'ran_step'   => 'first-step',
                    'started_at' => '2022-03-01 21:20:00',
                    'timeout'    => '2022-03-01 21:30:00',
                ],
                'Multi step task ran unknown step, run first step at next schedule time',
            ],
        ];
    }

    /**
     * @dataProvider provider_execute_tasks
     */
    public function test_its_execute_next_runs_single_step_task_on_schedule(
        $task_def,
        $initial_state,
        $at_time,
        $expect_run
    ) {
        $this->clock            = StoppedMockClock::at($at_time);
        $this->task_definitions = ['my-task' => $task_def];
        $this->task_state_repo  = ArrayCronTaskStateRepository::with(
            array_merge(['group_name' => 'my-task'], $initial_state)
        );

        $this->newSubject()->executeNextTasks();

        if ($expect_run === FALSE) {
            $this->process_runner->assertRanNothing();
            $this->task_state_repo->assertNothingSaved();
        } else {
            $this->process_runner->assertRanOnlyStep($expect_run['ran_step']);
            $state = $this->task_state_repo->assertSavedTask('my-task');
            $this->assertSameStateValues(
                [
                    'group_name'            => 'my-task',
                    'is_running'            => TRUE,
                    'last_step_executed'    => $expect_run['ran_step'],
                    'last_run_started_at'   => $expect_run['started_at'],
                    'last_run_timeout_at'   => $expect_run['timeout'],
                    'last_run_completed_at' => $initial_state['last_run_completed_at'],
                    'refresh_at'            => NULL,
                ],
                $state
            );
        }
    }

    public function test_its_execute_next_only_runs_steps_of_a_multi_step_group_if_the_previous_step_succeeded_otherwise_waits_to_next_schedule(
    )
    {
        $this->clock            = StoppedMockClock::at('2022-03-04 02:03:05');
        $this->task_definitions = [
            'my-task' => [
                'steps'           => ['first', 'second', 'third'],
                'schedule'        => ['minute' => 0],
                'timeout_seconds' => 60,
            ],
        ];
        $this->task_state_repo  = ArrayCronTaskStateRepository::with(
            [
                'group_name'            => 'my-task',
                'is_running'            => FALSE,
                'last_step_executed'    => 'first',
                'last_run_completed_at' => '2022-03-04 02:03:04',
            ]
        );
        $subject                = $this->newSubject();
        $this->process_runner->willExitCode(15);

        // Launch the task
        $subject->executeNextTasks();
        $state = $this->task_state_repo->assertSavedTask('my-task');
        $this->assertSameStateValues(
            [
                'group_name'            => 'my-task',
                'is_running'            => TRUE,
                'last_step_executed'    => 'second',
                'last_run_started_at'   => '2022-03-04 02:03:05',
                'last_run_timeout_at'   => '2022-03-04 02:04:05',
                'last_run_completed_at' => '2022-03-04 02:03:04',
                'refresh_at'            => NULL,
            ],
            $state
        );

        // A second later we'll check and it will have failed
        $this->clock->tickMicroseconds(1_000_000);
        $subject->executeNextTasks();
        $this->assertSameStateValues(
            [
                'group_name'            => 'my-task',
                'is_running'            => FALSE,
                'last_step_executed'    => '###failed###',
                'last_run_started_at'   => '2022-03-04 02:03:05',
                'last_run_timeout_at'   => '2022-03-04 02:04:05',
                'last_run_completed_at' => '2022-03-04 02:03:06',
                'refresh_at'            => NULL,
            ],
            $state
        );

        // Still nothing should have run by the end of the hour
        // @todo: tickToTime
        $this->clock->tickMicroseconds(3413_000_000);
        $this->assertEquals(new DateTimeImmutable('2022-03-04 02:59:59'), $this->clock->getDateTime());

        $subject->executeNextTasks();
        $this->assertSameStateValues(
            [
                'group_name'            => 'my-task',
                'is_running'            => FALSE,
                'last_step_executed'    => '###failed###',
                'last_run_started_at'   => '2022-03-04 02:03:05',
                'last_run_timeout_at'   => '2022-03-04 02:04:05',
                'last_run_completed_at' => '2022-03-04 02:03:06',
                'refresh_at'            => NULL,
            ],
            $state
        );

        // At the next hour it should start from the first step
        $this->clock->tickMicroseconds(1_000_000);
        $subject->executeNextTasks();
        $this->assertSameStateValues(
            [
                'group_name'            => 'my-task',
                'is_running'            => TRUE,
                'last_step_executed'    => 'first',
                'last_run_started_at'   => '2022-03-04 03:00:00',
                'last_run_timeout_at'   => '2022-03-04 03:01:00',
                'last_run_completed_at' => '2022-03-04 02:03:06',
                'refresh_at'            => NULL,
            ],
            $state
        );
        $this->process_runner->assertRanSteps(['second', 'first']);
    }

    /**
     * @testWith  [["first"]]
     *            [["first", "second"]]
     */
    public function test_its_execute_next_clears_timed_out_tasks_and_they_rerun_at_next_scheduled_time($steps)
    {
        $this->clock            = StoppedMockClock::at('2022-03-04 02:30:03');
        $this->task_definitions = [
            'my-task' => ['steps' => $steps, 'schedule' => '@hourly', 'timeout_seconds' => 300],
        ];
        $this->task_state_repo  = ArrayCronTaskStateRepository::with(
            [
                'group_name'            => 'my-task',
                'is_running'            => TRUE,
                'last_step_executed'    => 'first',
                'last_run_completed_at' => '2022-03-04 01:02:03',
                'last_run_started_at'   => '2022-03-04 02:00:03',
                'last_run_timeout_at'   => '2022-03-04 02:30:02',
            ]
        );
        $subject                = $this->newSubject();

        // First loop - detects the timeout, updates the state, runs nothing
        $subject->executeNextTasks();
        $this->process_runner->assertRanNothing();
        $state = $this->task_state_repo->assertSavedTask('my-task');
        $this->assertSameStateValues(
            [
                'group_name'            => 'my-task',
                'is_running'            => FALSE,
                'last_step_executed'    => '###timed-out###',
                'last_run_started_at'   => '2022-03-04 02:00:03',
                'last_run_timeout_at'   => '2022-03-04 02:30:02',
                'last_run_completed_at' => '2022-03-04 02:30:03',
                'refresh_at'            => NULL,
            ],
            $state
        );
        $this->reporter->assertExactCumulativeReports(
            ['timed-out', 'my-task', 'first', '2022-03-04 02:00:03.000000', '2022-03-04 02:30:03.000000']
        );

        // Next loop - nothing to do
        $subject->executeNextTasks();
        $this->process_runner->assertRanNothing();

        // 29m 57s later, loops still do nothing
        // @todo: we want a tickToTime on the clock interface
        $this->clock->tick(new DateInterval('PT29M56S'));
        $this->assertEquals(new DateTimeImmutable('2022-03-04 02:59:59'), $this->clock->getDateTime());
        $subject->executeNextTasks();
        $this->process_runner->assertRanNothing();

        // 1s after that, it is now time to run the next one
        $this->clock->tick(new DateInterval('PT1S'));
        $this->assertEquals(new DateTimeImmutable('2022-03-04 03:00:00'), $this->clock->getDateTime());
        $subject->executeNextTasks();
        $this->process_runner->assertRanOnlyStep('first');
        $this->assertSameStateValues(
            [
                'group_name'            => 'my-task',
                'is_running'            => TRUE,
                'last_step_executed'    => 'first',
                'last_run_started_at'   => '2022-03-04 03:00:00',
                'last_run_timeout_at'   => '2022-03-04 03:05:00',
                'last_run_completed_at' => '2022-03-04 02:30:03',
                'refresh_at'            => NULL,
            ],
            $state
        );
    }

    public function test_its_execute_next_operates_as_expected_with_multiple_defined_tasks()
    {
        $this->clock            = StoppedMockClock::at('2022-03-02 16:03:02');
        $this->task_definitions = [
            't1' => ['schedule' => ['minute' => '*'], 'steps' => ['t1-r1']],
            't2' => ['schedule' => ['minute' => '*/2'], 'steps' => ['t2-r1', 't2-r2']],
            't3' => ['schedule' => ['minute' => '*'], 'steps' => ['t3-r1']],
        ];
        $this->task_state_repo  = ArrayCronTaskStateRepository::with(
            ['group_name' => 't1', 'last_run_completed_at' => '2022-03-02 16:03:01'],
            ['group_name' => 't2', 'last_run_completed_at' => '2022-03-02 16:03:01', 'last_step_executed' => 't2-r1'],
            ['group_name' => 't3', 'is_running' => TRUE, 'last_run_timeout_at' => '2022-03-02 16:03:01'],
        );

        $this->newSubject()->executeNextTasks();
        $this->process_runner->assertRanOnlyStep('t2-r2');
        $t3_state = $this->task_state_repo->getState('t3');
        $this->assertSame('2022-03-02 16:03:02', $t3_state->getLastRunCompletedAt()->format('Y-m-d H:i:s'), '');
    }

    public function test_its_execute_next_can_run_multiple_tasks_if_due()
    {
        $this->clock            = StoppedMockClock::at('2022-03-02 16:04:02');
        $this->task_definitions = [
            't1' => ['schedule' => ['minute' => '*'], 'steps' => ['t1-r1']],
            't2' => ['schedule' => ['minute' => '*/2'], 'steps' => ['t2-r1', 't2-r2']],
        ];
        $this->task_state_repo  = ArrayCronTaskStateRepository::with(
            ['group_name' => 't1', 'last_run_completed_at' => '2022-03-02 16:03:01'],
            ['group_name' => 't2', 'last_run_completed_at' => '2022-03-02 16:03:01'],
        );

        $subject = $this->newSubject();
        $subject->executeNextTasks();
        $this->process_runner->assertRanSteps(
            [
                't1-r1',
                't2-r1',
            ]
        );
    }

    /**
     * @testWith [{}, ["t1-r1", "t2-r2", "t3-r1"], "nothing paused, all run"]
     *           [{"t1":true}, ["t2-r2", "t3-r1"], "t1 paused so never starts"]
     *           [{"t2":true}, ["t1-r1", "t3-r1"], "multi-step job t2 does not run any new steps even though it is part way"]
     */
    public function test_its_execute_next_does_not_start_any_step_of_a_task_that_is_paused($pause_state, $expect_ran)
    {
        $this->clock             = StoppedMockClock::at('2022-03-02 16:03:02');
        $this->task_definitions  = [
            't1' => ['schedule' => ['minute' => '*'], 'steps' => ['t1-r1']],
            't2' => ['schedule' => ['minute' => '*/2'], 'steps' => ['t2-r1', 't2-r2']],
            't3' => ['schedule' => ['minute' => '*'], 'steps' => ['t3-r1']],
        ];
        $this->task_state_repo   = ArrayCronTaskStateRepository::with(
            ['group_name' => 't1', 'last_run_completed_at' => '2022-03-02 16:02:01'],
            ['group_name' => 't2', 'last_run_completed_at' => '2022-03-02 16:03:01', 'last_step_executed' => 't2-r1'],
            ['group_name' => 't3', 'last_run_completed_at' => '2022-03-02 16:02:01'],
        );
        $this->paused_task_state = $pause_state;

        $this->newSubject()->executeNextTasks();
        $this->process_runner->assertRanSteps($expect_ran);
    }

    /**
     * @testWith ["executeNext"]
     *           ["checkRunning"]
     */
    public function test_it_tracks_whether_tasks_are_still_running_and_updates_db_on_completion($check_method)
    {
        $this->clock            = StoppedMockClock::at('2022-03-02 16:04:02');
        $this->task_definitions = ['t1' => ['schedule' => ['minute' => '*'], 'steps' => ['t1-r1']]];
        $this->task_state_repo  = ArrayCronTaskStateRepository::with(
            ['group_name' => 't1', 'last_run_completed_at' => '2022-03-02 16:03:01'],
        );

        $subject = $this->newSubject();
        $subject->executeNextTasks();
        $this->task_state_repo->assertSavedTaskTimes('t1', 1);
        $this->assertTrue($subject->hasRunningTasks(), 'Should have running tasks');
        $state = $this->task_state_repo->getState('t1');
        $this->assertTrue($state->isRunning(), 'Should have running state after executing');
        // Just tick the clock so we get a new time we can assert for the completed_at
        $this->clock->tick(new DateInterval('PT1S'));

        // OK there are two ways task state gets updated : on every executeNextTasks loop, and during shutdown on the
        // explicit checkRunningTaskState. They have identical behaviour, so cover them both from this test.
        if ($check_method === 'executeNext') {
            $subject->executeNextTasks();
        } elseif ($check_method === 'checkRunning') {
            $subject->checkRunningTaskState();
        }

        $this->assertFalse($subject->hasRunningTasks(), 'Should not have running tasks after next execute');
        $this->assertSameStateValues(
            [
                'group_name'            => 't1',
                'is_running'            => FALSE,
                'last_step_executed'    => 't1-r1',
                'last_run_started_at'   => '2022-03-02 16:04:02',
                'last_run_timeout_at'   => '2022-03-02 16:05:02',
                'last_run_completed_at' => '2022-03-02 16:04:03',
                'refresh_at'            => NULL,
            ],
            $state
        );
        $this->task_state_repo->assertSavedTaskTimes('t1', 2);
    }

    /**
     * @testWith [0]
     *           [16]
     */
    public function test_it_reports_starting_and_completing_tasks($exit_code)
    {
        $this->process_runner->willExitCode($exit_code);
        $this->clock            = StoppedMockClock::at('2022-03-02 16:03:02');
        $this->task_definitions = [
            'foo' => ['schedule' => ['minute' => '*/2'], 'steps' => ['foo-1', 'foo-2']],
        ];
        $this->task_state_repo  = ArrayCronTaskStateRepository::with(
            ['group_name' => 'foo', 'last_run_completed_at' => '2022-03-02 16:03:01', 'last_step_executed' => 'foo-1'],
        );

        $subject = $this->newSubject();
        $subject->executeNextTasks();

        $this->reporter->assertExactCumulativeReports(
            ['starting', 'foo', 'foo-2'],
        );

        $this->clock->tickMicroseconds(1_340_000);
        $subject->checkRunningTaskState();

        $this->reporter->assertExactCumulativeReports(
            ['starting', 'foo', 'foo-2'],
            ['completed', 'foo', 'foo-2', '2022-03-02 16:03:02.000000', '2022-03-02 16:03:03.340000', $exit_code],
        );
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock           = StoppedMockClock::atNow();
        $this->task_state_repo = ArrayCronTaskStateRepository::withNothing();
        $this->process_runner  = new ProcessRunnerSpy();
        $this->reporter        = new StatusReporterSpy();
    }

    private function newSubject(): CronTaskExecutionManager
    {
        return new CronTaskExecutionManager(
            $this->clock,
            $this->task_state_repo,
            $this->process_runner,
            $this->reporter,
            CronConfigLoaderStub::withTaskDefinitions($this->task_definitions),
            new class($this->paused_task_state) implements PausedTaskListChecker {
                public function __construct(
                    private array $paused_task_state
                ) {
                }

                public function isPaused(string $task_group_name): bool
                {
                    return $this->paused_task_state[$task_group_name] ?? FALSE;
                }
            }
        );
    }

    private function assertSameStateValues(array $expect, TaskExecutionState $state): void
    {
        $vars = array_map(
            fn($v) => $v instanceof DateTimeInterface ? $v->format('Y-m-d H:i:s') : $v,
            ObjectPropertyRipper::ripAll($state)
        );
        $this->assertSame(
            $expect,
            $vars
        );
    }
}

class ArrayCronTaskStateRepository extends AbstractArrayRepository implements CronTaskStateRepository
{
    private $save_count = [];

    protected static function getEntityBaseClass()
    {
        return TaskExecutionState::class;
    }

    protected static function stubEntity(array $data)
    {
        $data = array_merge(
            [
                'group_name'            => 'any',
                'is_running'            => FALSE,
                'last_step_executed'    => '###none###',
                'last_run_started_at'   => 'now',
                'last_run_timeout_at'   => 'now',
                'last_run_completed_at' => 'now',
            ],
            $data
        );

        return TaskExecutionState::fromDbRow($data, NULL);
    }

    public function getState(string $group_name): TaskExecutionState
    {
        return $this->findWith(fn(TaskExecutionState $s) => $s->getGroupName() === $group_name);
    }

    public function save(TaskExecutionState $state)
    {
        $group_name = $state->getGroupName();
        Assert::assertSame($state, $this->getState($group_name), 'Expect to only save entities we provided');
        $this->save_count[$group_name] ??= 0;
        $this->save_count[$group_name]++;
        $this->saveEntity($state);
    }

    public function assertSavedTaskTimes(string $task_name, int $count)
    {
        Assert::assertSame(
            $count,
            $this->save_count[$task_name] ?? 0,
            'Should have saved task '.$task_name.' the expected number of times'
        );
    }

    public function assertSavedTask(string $group_name): TaskExecutionState
    {
        $state = $this->getState($group_name);
        $this->assertSavedOnly($state);

        return $state;
    }

    public function assertNothingSaved()
    {
        parent::assertNothingSaved();
    }
}

class ProcessRunnerSpy extends SymfonyCronTaskProcessRunner
{
    private array $ran = [];

    private int $will_exit_code = 0;

    public function __construct()
    {
        parent::__construct(__DIR__, fopen('php://memory', 'w'));
    }

    public function willExitCode(int $code): void
    {
        $this->will_exit_code = $code;
    }

    public function run(string $task_name, CronTaskStepDefinition $step, int $timeout_seconds): Process
    {
        $this->ran[] = $step->getName();

        $ps = parent::run(
            $task_name,
            new CronTaskStepDefinition($step->getName(), ['sh', '-c', 'exit '.$this->will_exit_code]),
            $timeout_seconds
        );
        // The real implementation doesn't wait for the process to run, but it really sleeps. Because
        // we're just ticking the fake clock, we need to allow the process to run to completion before
        // we return it otherwise it may or may not have completed by the time the next step of the test
        // runs.
        $ps->wait();

        return $ps;
    }

    public function assertRanOnlyStep(string $step_name): void
    {
        Assert::assertSame([$step_name], $this->ran, 'Should have run exactly the expected step');
    }

    public function assertRanSteps(array $steps): void
    {
        Assert::assertSame($this->ran, $steps, 'Should have run exactly the expected steps');
    }

    public function assertRanNothing(): void
    {
        Assert::assertSame([], $this->ran, 'Should not have run any tasks');
    }
}

class StatusReporterSpy extends CronStatusReporter
{
    private $reports = [];

    public function __construct()
    {
    }

    public function reportStarting(string $task_group_name, string $step_name): void
    {
        $this->reports[] = [
            'starting',
            $task_group_name,
            $step_name,
        ];
    }

    public function reportCompleted(
        string            $task_group_name,
        string            $step_name,
        DateTimeImmutable $started,
        DateTimeImmutable $ended,
        int               $exit_code
    ): void {
        $this->reports[] = [
            'completed',
            $task_group_name,
            $step_name,
            $started->format('Y-m-d H:i:s.u'),
            $ended->format('Y-m-d H:i:s.u'),
            $exit_code,
        ];
    }

    public function reportTimedOut(
        string            $task_group_name,
        string            $step_name,
        DateTimeImmutable $started,
        DateTimeImmutable $timed_out_at
    ): void {
        $this->reports[] = [
            'timed-out',
            $task_group_name,
            $step_name,
            $started->format('Y-m-d H:i:s.u'),
            $timed_out_at->format('Y-m-d H:i:s.u'),
        ];
    }

    public function assertExactCumulativeReports(array ...$expect_reports)
    {
        Assert::assertSame($this->reports, $expect_reports);
    }
}

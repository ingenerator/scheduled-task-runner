<?php

namespace test\integration\Ingenerator\ScheduledTaskRunner;

use Closure;
use DateInterval;
use DateTimeImmutable;
use Ingenerator\PHPUtils\Monitoring\NullMetricsAgent;
use Ingenerator\ScheduledTaskRunner\CronConfigLoader;
use Ingenerator\ScheduledTaskRunner\CronStatusReporter;
use Ingenerator\ScheduledTaskRunner\CronTaskExecutionManager;
use Ingenerator\ScheduledTaskRunner\PDOCronExecutionHistoryRepository;
use Ingenerator\ScheduledTaskRunner\PDOCronTaskStateRepository;
use Ingenerator\ScheduledTaskRunner\PDOPausedTaskListChecker;
use Ingenerator\ScheduledTaskRunner\SymfonyCronTaskProcessRunner;
use Ingenerator\ScheduledTaskRunner\TaskExecutionState;
use Ingenerator\PHPUtils\DateTime\Clock\RealtimeClock;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use RuntimeException;
use test\mock\Ingenerator\ScheduledTaskRunner\Logging\SpyingLoggerStub;
use function fwrite;
use function is_file;
use function sprintf;
use function str_replace;
use function stream_get_contents;
use const PHP_EOL;

class CronExecutionIntegrationTest extends TestCase
{
    private CONST WORKING_DIR = __DIR__.'/working_dir';

    private RealtimeClock                     $clock;
    
    private \PDO                              $pdo;

    private PDOCronExecutionHistoryRepository $history_repo;

    private PDOCronTaskStateRepository        $state_repo;

    private SymfonyCronTaskProcessRunner      $process_runner;

    private AbstractLogger                    $logger;

    private array                             $task_definitions = [];

    private $output_stream;

    public function test_it_runs_expected_processes_correctly_and_identifies_completion_as_expected()
    {
        $script = $this->getTestScriptRelativePath();

        $this->task_definitions = [
            'per-minute' => [
                'is_enabled' => true,
                'schedule' => ['minute' => '*'],
                'steps' => [
                    ['name' => 'sleep-300', 'cmd' => [$script, '--sleep-ms=300', '--name=per-min-0']],
                ],
            ],
            'per-hour' => [
                'is_enabled' => true,
                'schedule' => ['minute' => '0'],
                'steps' => [
                    ['name' => 'sleep-500', 'cmd' => [$script, '--sleep-ms=500', '--name=per-hr-0']],
                ],
            ],
            'multi-step' => [
                'is_enabled' => true,
                'schedule' => ['minute' => '*/5'],
                'steps' => [
                    ['name' => 'sleep-500', 'cmd' => [$script, '--sleep-ms=500', '--name=multi-0']],
                    ['name' => 'sleep-1250', 'cmd' => [$script, '--sleep-ms=1250', '--name=multi-1']],
                ],
            ],
            'not-due' => [
                'is_enabled' => true,
                'schedule' => '@hourly',
                'steps' => [
                    ['name' => 'not-due', 'cmd' => ['echo', 'not due']],
                ],
            ],
        ];

        // Define some existing database state for these tasks
        $this->initState('per-minute', function (TaskExecutionState $s) {
            // This last completed 90 seconds ago so it should be due
            $s->markStepComplete(new DateTimeImmutable('-90 seconds'), true);
        });
        $this->initState('per-hour', function (TaskExecutionState $s) {
            // This started a while ago and is about to time out
            $s->markStepRunning('anything', new DateTimeImmutable('-500 seconds'), 502);
        });
        $this->initState('multi-step', function (TaskExecutionState $s) {
            // This last completed before the last 5 min boundary so is also due to start a new loop
            $s->markStepComplete(new DateTimeImmutable('-10 minutes'), true);
        });
        $this->initState('not-due', function (TaskExecutionState $s) {
            // This can never be valid to run, even if we cross an hour boundary between now and the start of the test
            // So we have to give a completion in the future
            $s->markStepRunning('not-due', new DateTimeImmutable('now'), 10);
            $s->markStepComplete(new DateTimeImmutable('+10 minutes'), true);
        });

        $subject = $this->newSubject();
        $start = $this->clock->getDateTime();

        // It should immediately launch the per-minute (sleep 500) and first step of the multi-step (sleep 500)
        // as these are due.
        $subject->executeNextTasks();
        $this->assertExecutionState(
            [
                'has_running' => true,
                'task_state' => [
                    'per-minute' => ['is_running' => true, 'step' => 'sleep-300'],
                    'per-hour' => ['is_running' => true, 'step' => 'anything'],
                    'multi-step' => ['is_running' => true, 'step' => 'sleep-500'],
                    'not-due' => ['is_running' => false, 'step' => 'not-due'],
                ],
            ],
            $subject,
            $start
        );

        // After a second it should detect that the per-minute and first step of the multi are complete. It should then
        // immediately launch the second step of the multi.
        $this->clock->usleep(1_000_000);
        fwrite($this->output_stream, "\n--slept 1s--\n");
        $subject->executeNextTasks();

        $this->assertExecutionState(
            [
                'has_running' => true,
                'task_state' => [
                    'per-minute' => ['is_running' => false, 'step' => 'sleep-300'],
                    'per-hour' => ['is_running' => true, 'step' => 'anything'],
                    'multi-step' => ['is_running' => true, 'step' => 'sleep-1250'],
                    'not-due' => ['is_running' => false, 'step' => 'not-due'],
                ],
            ],
            $subject,
            $start
        );

        // After another second (2 seconds since starting) it should detect that the per-hour has timed out but the
        // second multi step (sleep 1.25 seconds) is still going
        $this->clock->usleep(1_000_000);
        fwrite($this->output_stream, "\n--slept 1s--\n");
        $subject->executeNextTasks();
        $this->assertExecutionState(
            [
                'has_running' => true,
                'task_state' => [
                    'per-minute' => ['is_running' => false, 'step' => 'sleep-300'],
                    'per-hour' => ['is_running' => false, 'step' => '###timed-out###'],
                    'multi-step' => ['is_running' => true, 'step' => 'sleep-1250'],
                    'not-due' => ['is_running' => false, 'step' => 'not-due'],
                ],
            ],
            $subject,
            $start
        );

        // Finally after a third second the multi will also have finished and nothing should be running.
        $this->clock->usleep(1_000_000);
        fwrite($this->output_stream, "\n--slept 1s--\n");
        $subject->executeNextTasks();
        $this->assertExecutionState(
            [
                'has_running' => false,
                'task_state' => [
                    'per-minute' => ['is_running' => false, 'step' => 'sleep-300'],
                    'per-hour' => ['is_running' => false, 'step' => '###timed-out###'],
                    'multi-step' => ['is_running' => false, 'step' => 'sleep-1250'],
                    'not-due' => ['is_running' => false, 'step' => 'not-due'],

                ],
            ],
            $subject,
            $start
        );

        $output = stream_get_contents($this->output_stream, -1, 0);
        // Note that the process runner only outputs data from the command when we explicitly check it - e.g.
        // on an executeNextTasks() loop.
        // So the log entries here will consistently come out with this timing and sequence, regardless of fine
        // detail of execution.
        $this->assertSame(
            <<<LOG
                
                --slept 1s--
                [per-minute] out: (per-min-0) Starting - will sleep 300ms
                {"severity":"INFO","message":"I am a log from per-min-0"}
                [per-minute] out: (per-min-0) Done
                [multi-step] out: (multi-0) Starting - will sleep 500ms
                {"severity":"INFO","message":"I am a log from multi-0"}
                [multi-step] out: (multi-0) Done

                --slept 1s--
                [multi-step] out: (multi-1) Starting - will sleep 1250ms
                {"severity":"INFO","message":"I am a log from multi-1"}

                --slept 1s--
                [multi-step] out: (multi-1) Done

                LOG,
            $output
        );

        $history = $this->history_repo->listCurrentStates();
        $this->assertSame(
            [
                [
                    'group_name' => 'multi-step',
                    'step_name' => 'sleep-1250',
                    'last_exit_code' => 0,
                    'last_failure_at' => null,
                    'last_success_recent' => true,
                ],
                [
                    'group_name' => 'multi-step',
                    'step_name' => 'sleep-500',
                    'last_exit_code' => 0,
                    'last_failure_at' => null,
                    'last_success_recent' => true,
                ],
                [
                    'group_name' => 'per-minute',
                    'step_name' => 'sleep-300',
                    'last_exit_code' => 0,
                    'last_failure_at' => null,
                    'last_success_recent' => true,
                ],
            ],
            array_map(
                function (array $h) {
                    // Can't assert the time exactly because we're using RealtimeClock so just check it's recent
                    $h['last_success_recent'] = (time() - strtotime($h['last_success_at'])) < 10;
                    unset($h['last_success_at']);
                    return $h;
                },
                $history
            )
        );
    }

    protected function setUp(): void
    {
        parent::setUp();
        DbMaintainer::initDb();
        $this->pdo = DbMaintainer::makePdoConnection();
        $this->output_stream = fopen('php://memory', 'rw');
        $this->clock = new RealtimeClock();
        $this->logger = new class extends AbstractLogger {
            public function log($level, $message, array $context = [])
            {
                echo sprintf('[%s] %s'.PHP_EOL, $level, $message);
            }
        };
        $this->state_repo = new PDOCronTaskStateRepository($this->pdo, $this->clock, $this->logger);
        $this->history_repo = new PDOCronExecutionHistoryRepository($this->pdo);
        $this->process_runner = new SymfonyCronTaskProcessRunner(self::WORKING_DIR, $this->output_stream);
    }

    protected function tearDown(): void
    {
        fclose($this->output_stream);
        parent::tearDown();
    }

    private function newSubject(): CronTaskExecutionManager
    {
        return new CronTaskExecutionManager(
            $this->clock,
            $this->state_repo,
            $this->process_runner,
            new CronStatusReporter($this->logger, new NullMetricsAgent(), $this->history_repo),
            new CronConfigLoader($this->task_definitions),
            new PDOPausedTaskListChecker(
                $this->pdo,
                $this->clock,
                new DateInterval('PT30S'),
                "SELECT '{}' AS value"
            )
        );
    }

    private function initState(string $group_name, Closure $initialiser)
    {
        $state = $this->state_repo->getState($group_name);
        $initialiser($state);
        $this->state_repo->save($state);
    }

    private function getTestScriptRelativePath(): string
    {
        $script = str_replace(self::WORKING_DIR.'/', '', __DIR__.'/working_dir/tasks/fake_cron_task.php');
        if ( ! is_file(self::WORKING_DIR.'/'.$script)) {
            throw new RuntimeException('The test script is not present in '.$script);
        }


        return $script;
    }

    private function getTaskRunningState(array $task_names): array
    {
        $repo = new PDOCronTaskStateRepository($this->pdo, $this->clock, new NullLogger());
        $actual = [];
        foreach ($task_names as $task) {
            $state = $repo->getState($task);
            $actual[$task] = ['is_running' => $state->isRunning(), 'step' => $state->getLastStepExecuted()];
        }

        return $actual;
    }

    private function assertExecutionState(
        array $expected,
        CronTaskExecutionManager $subject,
        DateTimeImmutable $start
    ): void {
        $elapsed = $this->clock->getDateTime()->format('U.u') - $start->format('U.u');

        $this->assertSame(
            $expected,
            [
                'has_running' => $subject->hasRunningTasks(),
                'task_state' => $this->getTaskRunningState(array_keys($expected['task_state'])),
            ],
            sprintf('Expected correct state after %0.6f seconds elapsed', $elapsed)
        );
    }
}

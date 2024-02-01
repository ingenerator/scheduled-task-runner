<?php

namespace test\unit\Ingenerator\ScheduledTaskRunner;

use DateInterval;
use Ingenerator\PHPUtils\DateTime\Clock\StoppedMockClock;
use Ingenerator\ScheduledTaskRunner\CronController;
use Ingenerator\ScheduledTaskRunner\CronTaskExecutionManagerInterface;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\Store\InMemoryStore;
use test\mock\Ingenerator\ScheduledTaskRunner\Logging\SpyingLoggerStub;
use function array_fill;
use function preg_quote;

class CronControllerTest extends TestCase
{
    private LockFactory $lock_factory;

    private StubExecutionManagerInterface $execution_manager;

    private HookableClock $clock;

    private int $lock_check_interval_seconds = 300;

    private LockInterface $current_test_lock;

    private DateInterval $max_runtime;

    private LoggerInterface $logger;

    public function test_it_is_initialisable()
    {
        $this->assertInstanceOf(CronController::class, $this->newSubject());
    }

    public function test_it_attempts_to_claim_the_primary_controller_lock_and_runs_tasks_if_acquired()
    {
        $lock_states = [];
        $subject     = $this->newSubject();

        $this->execution_manager->onExecuteNextTasks(
            function () use (&$lock_states, $subject) {
                $lock_states['during_execution'] = $this->isControllerLockTaken();
                // Exit from the run loop straight away
                $subject->signalTermination();
            }
        );

        $subject->execute();
        $lock_states['after_execution'] = $this->isControllerLockTaken();
        $this->assertSame(
            [
                'during_execution' => TRUE,
                'after_execution'  => FALSE,
            ],
            $lock_states,
            'Should have expected locking behaviour'
        );
    }

    public function test_it_sleeps_and_waits_to_get_the_lock_if_it_is_already_taken_on_boot()
    {
        $lock                              = $this->takeControllerLock();
        $this->lock_check_interval_seconds = 60;
        $this->clock->expectMaxSleeps(3);
        $this->clock->onSleep(
            function () use ($lock) {
                if ($this->clock->getSleepCount() === 3) {
                    $lock->release();
                }
            }
        );

        $subject = $this->newSubject();

        $sleeps_before_execute = NULL;
        $this->execution_manager->onExecuteNextTasks(
            function () use ($subject, &$sleeps_before_execute) {
                $sleeps_before_execute = $this->clock->getSleepCount();
                // Exit immediately on the first loop, so we can focus the sleep assertions etc on the clock
                $subject->signalTermination();
            }
        );

        $subject->execute();

        $this->clock->assertSlept([60_000_000, 60_000_000, 60_000_000]);
        $this->assertSame(
            1,
            $this->execution_manager->getExecutionCount(),
            'Should have executed tasks once it became primary'
        );
        $this->assertSame(3, $sleeps_before_execute, 'Should not have started running till it got the lock');
    }

    public function test_it_returns_immediately_without_executing_if_signaled_to_quit_while_waiting_for_the_primary_lock(
    )
    {
        $this->lock_check_interval_seconds = 10;
        $subject                           = $this->newSubject();

        $this->clock->expectMaxSleeps(2);

        $this->clock->onSleep(
            function () use ($subject) {
                if ($this->clock->getSleepCount() === 2) {
                    $subject->signalTermination();
                }
            }
        );

        $this->takeControllerLock();
        $subject->execute();

        $this->clock->assertSlept([10_000_000, 10_000_000]);
        $this->assertSame(0, $this->execution_manager->getExecutionCount(), 'Should never have executed tasks');
    }

    public function test_it_returns_without_executing_anything_if_it_times_out_waiting_for_the_primary_lock()
    {
        $this->max_runtime                 = new DateInterval('PT30M');
        $this->lock_check_interval_seconds = 300; // 5 minutes, so there'll be 6 sleeps before it hits half an hour
        $this->clock->expectMaxSleeps(6);

        $this->takeControllerLock();
        $this->newSubject()->execute();

        $this->clock->assertSlept(array_fill(0, 6, 300_000_000));
        $this->assertSame(0, $this->execution_manager->getExecutionCount(), 'Should never have executed tasks');
    }

    public function test_when_running_tasks_it_exits_if_signaled_to_quit()
    {
        $subject = $this->newSubject();

        $this->execution_manager->onExecuteNextTasks(
            function (int $exec_count) use ($subject) {
                if ($exec_count >= 3) {
                    $subject->signalTermination();
                }
            }
        );

        $subject->execute();
        $this->assertSame(3, $this->execution_manager->getExecutionCount(), 'Should have executed twice');
        $this->clock->assertSlept(
            [
                // After first run
                1_000_000,
                // After second run
                1_000_000,
                // No sleep when exiting loop
            ]
        );
    }

    public function test_when_running_tasks_it_exits_after_the_total_maximum_runtime()
    {
        $this->max_runtime                 = new DateInterval('PT6M');
        $this->lock_check_interval_seconds = 300;
        $lock                              = $this->takeControllerLock();

        $subject = $this->newSubject();
        // We'll get the lock after one 5 minute wait
        $this->clock->onSleep(fn() => $lock->release());

        $subject->execute();

        // We had to wait 5 minutes for the lock, so that means that we get 60 1-second loops before we hit the 6 minute
        // timeout - because the execution time includes both those phases
        $this->assertSame(
            61,
            $this->execution_manager->getExecutionCount(),
            'Should have run the correct number of loops before timeout'
        );
    }

    public function test_it_waits_for_all_running_tasks_to_exit_before_finally_returning_even_if_it_has_been_signaled()
    {
        $subject = $this->newSubject();

        // Terminate immediately, number of execute loops is not relevant
        $this->execution_manager->onExecuteNextTasks(fn() => $subject->signalTermination());

        $running_check_count = 0;
        $this->execution_manager->onCheckRunningTasks(
            function () use (&$running_check_count) {
                $running_check_count++;

                return $running_check_count < 5;
            }
        );

        $subject->execute();
        $this->assertSame(5, $running_check_count, 'Should have updated running state 5 times');
        $this->clock->assertSlept(
            [1_000_000, 1_000_000, 1_000_000, 1_000_000],
            'Should have slept 1 second after each check'
        );
    }

    public function test_once_it_has_primary_it_releases_lock_as_soon_as_it_stops_scheduling_new_tasks()
    {
        $subject    = $this->newSubject();
        $lock_state = [];

        $this->execution_manager->onExecuteNextTasks(fn() => $subject->signalTermination());
        $this->execution_manager->onCheckRunningTasks(
            function () use (&$lock_state) {
                $lock_state['at-check-running'] = $this->isControllerLockTaken();

                return FALSE;
            }
        );

        $subject->execute();

        $this->assertSame(
            ['at-check-running' => FALSE],
            $lock_state,
            'Should have released lock before draining tasks'
        );
    }

    public function test_it_extends_the_primary_lock_ttl_while_running_the_task_loop()
    {
        // It does, but I don't exactly know how to test this because the API for it isn't exposed in the symfony interface
        $this->markTestIncomplete(
            'This is implemented (probably) but tricky to unit test'
        );
    }

    public function test_it_still_waits_for_running_jobs_if_there_were_exceptions_during_the_task_loop()
    {
        $this->logger = new SpyingLoggerStub();
        $e            = new RuntimeException('Anything');
        $this->execution_manager->onExecuteNextTasks(function () use ($e) { throw $e; });

        $running_check_count = 0;
        $this->execution_manager->onCheckRunningTasks(
            function () use (&$running_check_count) {
                $running_check_count++;

                return $running_check_count < 3;
            }
        );

        $this->newSubject()->execute();
        $this->assertSame(3, $running_check_count, 'Should have updated running state 3 times');
        $this->clock->assertSlept(
            [1_000_000, 1_000_000],
            'Should have slept 1 second after each check'
        );
        $this->logger->assertLoggedMatching(
            LogLevel::ALERT,
            '/^'.preg_quote('Unhandled exception while running tasks: [RuntimeException] Anything').'/',
            [
                'exception' => $e,
            ]
        );
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->lock_factory      = new LockFactory(new InMemoryStore());
        $this->execution_manager = StubExecutionManagerInterface::withNoBehaviour();
        $this->clock             = HookableClock::atNow();
        $this->logger            = new NullLogger();
        $this->max_runtime       = new DateInterval('PT30M');
    }

    private function newSubject(): CronController
    {
        return new CronController(
            $this->lock_factory,
            $this->execution_manager,
            $this->clock,
            $this->logger,
            $this->lock_check_interval_seconds,
            $this->max_runtime
        );
    }

    private function isControllerLockTaken(): bool
    {
        // It's taken if we can't acquire it
        return ! $this->lock_factory->createLock(CronController::LOCK_NAME)->acquire(FALSE);
    }

    private function takeControllerLock(): LockInterface
    {
        $lock = $this->lock_factory->createLock(CronController::LOCK_NAME);
        $this->assertTrue($lock->acquire(FALSE), 'Should be able to get lock');

        // Take a copy so it doesn't go out of scope and get auto-released
        $this->current_test_lock = $lock;

        return $lock;
    }
}

class StubExecutionManagerInterface implements CronTaskExecutionManagerInterface
{
    private $on_execute;

    private $on_check_running;

    private int $execution_count = 0;

    private bool $has_had_termination_signal = FALSE;

    private bool $has_running_tasks = FALSE;

    public static function withNoBehaviour()
    {
        return new static();
    }

    private function __construct()
    {
        $this->on_execute       = function () { };
        $this->on_check_running = fn() => FALSE;
    }

    public function executeNextTasks(): void
    {
        $this->execution_count++;
        ($this->on_execute)($this->execution_count);
    }

    public function checkRunningTaskState(): void
    {
        $this->has_running_tasks = ($this->on_check_running)();
    }

    public function hasHadTerminationSignal(): bool
    {
        return $this->has_had_termination_signal;
    }

    public function hasRunningTasks(): bool
    {
        return $this->has_running_tasks;
    }

    public function getExecutionCount(): int
    {
        return $this->execution_count;
    }

    public function onCheckRunningTasks(callable $callback)
    {
        $this->on_check_running = $callback;
    }

    public function onExecuteNextTasks(callable $on_execute)
    {
        $this->on_execute = $on_execute;
    }
}

class HookableClock extends StoppedMockClock
{
    private $on_sleep = NULL;

    private ?int $expect_max_sleeps = 100;

    public function onSleep(callable $callback)
    {
        $this->on_sleep = $callback;
    }

    public function usleep($microseconds)
    {
        parent::usleep($microseconds);
        if (($this->expect_max_sleeps !== NULL) && (count($this->sleeps) > $this->expect_max_sleeps)) {
            throw new LogicException('Expected a max of '.$this->expect_max_sleeps.' sleeps, still sleeping');
        }
        if ($this->on_sleep) {
            ($this->on_sleep)($microseconds);
        }
    }

    public function expectMaxSleeps(int $max): void
    {
        $this->expect_max_sleeps = $max;
    }

    public function getSleepCount(): int
    {
        return count($this->sleeps);
    }
}

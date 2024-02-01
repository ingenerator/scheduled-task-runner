<?php

namespace Ingenerator\ScheduledTaskRunner;

use DateInterval;
use DateTimeImmutable;
use Ingenerator\PHPUtils\DateTime\Clock\RealtimeClock;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Throwable;
use function sprintf;

class CronController
{
    public const LOCK_NAME = 'primary-cron-controller';

    /**
     * The lock TTL wants to be long enough that we're not having to refresh all the time, short enough that another
     * primary will take over not too long after we die if the lock goes stale. The lock will be refreshed halfway
     * through this time, assuming our process doesn't get gummed up.
     */
    public const LOCK_TTL_SECONDS = 300;

    protected LoggerInterface                 $logger;

    private RealtimeClock                     $clock;

    private CronTaskExecutionManagerInterface $execution_manager;

    private bool                              $has_been_signaled_to_quit = false;

    private int                               $lock_check_interval_micros;

    private LockFactory                       $lock_factory;

    private DateTimeImmutable                 $quit_at;

    public function __construct(
        LockFactory $lock_factory,
        CronTaskExecutionManagerInterface $execution_manager,
        RealtimeClock $clock,
        LoggerInterface $logger,
        int $lock_check_interval_seconds,
        DateInterval $max_runtime
    ) {
        $this->execution_manager = $execution_manager;
        $this->lock_factory = $lock_factory;
        $this->lock_check_interval_micros = $lock_check_interval_seconds * 1000 * 1000;
        $this->clock = $clock;
        $this->logger = $logger;
        $this->quit_at = $this->clock->getDateTime()->add($max_runtime);
    }

    public function execute()
    {
        $lock = $this->lock_factory->createLock(static::LOCK_NAME, static::LOCK_TTL_SECONDS);
        $is_primary = $this->waitToBecomePrimaryController($lock);

        if ($is_primary) {
            $this->logger->notice('CronController starting task exec loop');

            try {
                $this->runTaskExecutionLoop($lock);

                // Release the lock *now* so that another primary can start running while we're waiting for tasks to finish
                $lock->release();
            } catch (Throwable $e) {
                // There should be very few things that could cause an exception in our outer runner. Most task-specific
                // errors will be caught and dealt with further down. Coding / state errors should be caught by the
                // tests. So really we're limited to unexpected resource exhaustion of some kind, or problems
                // reading / updating database state. If the DB is gone then the tasks, lock release, etcetera will
                // probably also all fail. All we can do is log it, see if it's still viable to gracefully wait for our
                // children, then terminate.
                $this->logExecutionError($e);
            }
        }

        $this->logger->debug('CronController will close after running jobs complete');
        $this->waitUntilRunningJobsAreComplete();
    }

    private function waitToBecomePrimaryController(LockInterface $lock): bool
    {
        do {
            $this->logger->debug('Attempting to become primary CronController');
            if ($lock->acquire()) {
                // Break out of the loop as soon as we get the lock
                return true;
            }

            $should_loop = $this->shouldKeepLooping();
            if ($should_loop) {
                $this->clock->usleep($this->lock_check_interval_micros);
            }
        } while ($should_loop);

        return false;
    }

    private function shouldKeepLooping(): bool
    {
        if ($this->has_been_signaled_to_quit) {
            return false;
        }

        if ($this->clock->getDateTime() >= $this->quit_at) {
            $this->logger->debug('CronController has reached max execution time');

            return false;
        }

        // @todo: Ideally I think we'd also just have a memory usage trigger to quit here, easier than guessing at execution time

        return true;
    }

    private function runTaskExecutionLoop(LockInterface $lock): void
    {
        do {
            // Extend the lock TTL if we need to - if there's less than half the TTL remaining. So e.g. a 5
            // minute TTL will in theory be extended roughly every 2.5 minutes. But if the process stalls on
            // anything, it will cope with up to 5 mins before another process can take over.
            if ($lock->getRemainingLifetime() < (static::LOCK_TTL_SECONDS / 2)) {
                $lock->refresh(static::LOCK_TTL_SECONDS);
            }

            // Check and see if there are any new jobs to start, and execute them if so
            $this->execution_manager->executeNextTasks();

            $should_loop = $this->shouldKeepLooping();
            if ($should_loop) {
                // This will mean that the loop is not always exactly on the second, it will drift about a bit
                // according to how long it took to run. However the task executor already deals with whether it
                // is at / past / before the time the next job(s) should start, so there's no need to run that at
                // a precise time, waiting a second between loop end and loop start is fine for our purpose.
                $this->clock->usleep(1_000_000);
            }
        } while ($should_loop);
    }

    private function logExecutionError(Throwable $e)
    {
        $this->logger->alert(
            sprintf(
                'Unhandled exception while running tasks: [%s] %s (%s:%s)',
                $e::class,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ),
            ['exception' => $e]
        );
    }

    private function waitUntilRunningJobsAreComplete(): void
    {
        do {
            $this->execution_manager->checkRunningTaskState();
            $should_wait = $this->execution_manager->hasRunningTasks();

            // Note that this loop does not care about signals or max runtime, it will *always* wait indefinitely for
            // tasks to finish, unless it is forcibly killed.
            if ($should_wait) {
                $this->clock->usleep(1_000_000);
            }
        } while ($should_wait);
    }

    public function signalTermination()
    {
        $this->has_been_signaled_to_quit = true;
    }
}

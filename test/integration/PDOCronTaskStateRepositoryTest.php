<?php

namespace test\integration\Ingenerator\ScheduledTaskRunner;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Ingenerator\ScheduledTaskRunner\PDOCronTaskStateRepository;
use Ingenerator\ScheduledTaskRunner\TaskExecutionState;
use Ingenerator\PHPUtils\DateTime\Clock\RealtimeClock;
use Ingenerator\PHPUtils\DateTime\Clock\StoppedMockClock;
use Ingenerator\PHPUtils\DateTime\DateString;
use Ingenerator\PHPUtils\Object\ObjectPropertyRipper;
use InvalidArgumentException;
use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use test\mock\Ingenerator\ScheduledTaskRunner\Logging\SpyingLoggerStub;
use test\unit\Ingenerator\ScheduledTaskRunner\BaseTestCase;
use function array_map;

class PDOCronTaskStateRepositoryTest extends BaseTestCase
{
    private PDO             $pdo;

    private RealtimeClock   $clock;

    private LoggerInterface $log;

    public function test_it_is_initialisable()
    {
        $this->assertInstanceOf(PDOCronTaskStateRepository::class, $this->newSubject());
    }

    public function test_its_get_state_returns_existing_state_from_db()
    {
        $this->log = $this->getDummyExpectingNoCalls(LoggerInterface::class);
        $this->insertDbState(
            [
                'group_name' => 'my-task-group',
                'is_running' => 0,
                'last_step_executed' => 'echo true',
                'last_run_started_at' => '2022-01-03 02:03:40',
                'last_run_timeout_at' => '2022-01-03 02:33:40',
                'last_run_completed_at' => '2022-01-03 02:05:23',
            ]
        );

        $state = $this->newSubject()->getState('my-task-group');

        $this->assertSameStateValues(
            [
                'group_name' => 'my-task-group',
                'is_running' => false,
                'last_step_executed' => 'echo true',
                'last_run_started_at' => '2022-01-03 02:03:40',
                'last_run_timeout_at' => '2022-01-03 02:33:40',
                'last_run_completed_at' => '2022-01-03 02:05:23',
                'refresh_at' => null,
            ],
            $state
        );
    }

    public function test_its_get_state_never_reloads_from_db_if_task_was_not_running()
    {
        $this->log = $this->getDummyExpectingNoCalls(LoggerInterface::class);
        $this->insertDbState(['group_name' => 'anything', 'is_running' => 0]);
        $subject = $this->newSubject();
        $state1 = $subject->getState('anything');
        $this->assertFalse($state1->isRunning(), 'Should not be running');

        // This should never be able to happen, and if it does then the problem is our primary controller election, not
        // this repo. I just want to conclusively prove it's not loading from the DB.
        $this->pdo->exec("UPDATE task_execution_state SET is_running = 1 WHERE group_name = 'anything';");
        $this->clock->tick(new DateInterval('PT1H'));

        $state2 = $subject->getState('anything');
        $this->assertSame($state1, $state2, 'Same physical state instance returned');
        $this->assertFalse($state2->isRunning(), 'Still not running as far as we\'re concerned');
    }

    public function test_its_get_state_refreshes_from_db_every_minute_if_it_was_running_when_first_loaded()
    {
        $this->log = $this->getDummyExpectingNoCalls(LoggerInterface::class);
        $this->insertDbState(['group_name' => 'anything', 'is_running' => 1]);
        $this->clock = StoppedMockClock::at('2022-03-01 20:30:20');
        $subject = $this->newSubject();
        $state1 = $subject->getState('anything');

        $this->assertSameRefreshAt('2022-03-01 20:31:20', $state1, 'Populates refresh_at');

        $this->clock->tick(new DateInterval('PT59S'));
        $this->assertSame($state1, $subject->getState('anything'), 'Still same state within the minute');
        $this->assertSameRefreshAt('2022-03-01 20:31:20', $state1, 'Same refresh_at');

        $this->clock->tick(new DateInterval('PT1S'));
        $state2 = $subject->getState('anything');
        $this->assertNotSame($state1, $state2, 'Returns new updated state after a minute');
        $this->assertSameRefreshAt('2022-03-01 20:32:20', $state2, 'Task still running so refresh_at extended');

        // The previous primary has now finished running the job
        $this->pdo->exec("UPDATE task_execution_state SET is_running = 0 WHERE group_name = 'anything';");
        $this->clock->tick(new DateInterval('PT1M'));

        $state3 = $subject->getState('anything');
        $this->assertNotSame($state2, $state3, 'Returns new updated state after another minute');
        $this->assertNull($state3->getRefreshAt(), 'No need to refresh once it stops running');
    }

    public function test_its_get_state_lazily_creates_database_state_for_tasks_that_do_not_exist()
    {
        $this->log = new SpyingLoggerStub();
        $this->clock = StoppedMockClock::at('2022-03-01 19:38:29');
        $subject = $this->newSubject();
        $state = $subject->getState('new-task');
        $this->assertSameStateValues(
            [
                'group_name' => 'new-task',
                'is_running' => false,
                'last_step_executed' => '###none###',
                'last_run_started_at' => '2022-03-01 19:38:29',
                'last_run_timeout_at' => '2022-03-01 19:38:29',
                'last_run_completed_at' => '2022-03-01 19:38:29',
                'refresh_at' => null,
            ],
            $state
        );
        $this->assertSame($state, $subject->getState('new-task'));

        // Now change the clock and use another instance to check it definitely went to the database
        $this->clock->tick(new DateInterval('PT15M'));
        $this->assertSameStateValues(
            [
                'group_name' => 'new-task',
                'is_running' => false,
                'last_step_executed' => '###none###',
                'last_run_started_at' => '2022-03-01 19:38:29',
                'last_run_timeout_at' => '2022-03-01 19:38:29',
                'last_run_completed_at' => '2022-03-01 19:38:29',
                'refresh_at' => null,
            ],
            $this->newSubject()->getState('new-task')
        );

        $this->log->assertLoggedOnceMatching(
            LogLevel::WARNING,
            '/Initialised state for new task `new-task`/',
            []
        );
    }

    /**
     * @testWith [true]
     *           [false]
     */
    public function test_its_save_throws_if_state_not_in_local_collection($knows_task)
    {
        // Guard against randomly passing in states that do not belong to the repo
        $state = TaskExecutionState::forNewTask('dunno', new DateTimeImmutable());
        $subject = $this->newSubject();
        if ($knows_task) {
            // This check applies whether or not the repo has a record for this task already
            $subject->getState('dunno');
        }

        $this->expectException(InvalidArgumentException::class);
        $subject->save($state);
    }

    public function test_its_save_can_update_existing_state()
    {
        $this->clock = StoppedMockClock::at('2022-01-03 20:30:02');
        $subject = $this->newSubject();
        $state = $subject->getState('my-task');
        $state->markStepRunning('first-step', new DateTimeImmutable('2022-01-03 23:47:42'), 3600);
        $subject->save($state);

        $this->assertSameStateValues(
            [
                'group_name' => 'my-task',
                'is_running' => true,
                'last_step_executed' => 'first-step',
                'last_run_started_at' => '2022-01-03 23:47:42',
                'last_run_timeout_at' => '2022-01-04 00:47:42',
                // last_run_completed wasn't updated, it keeps the old value
                'last_run_completed_at' => '2022-01-03 20:30:02',
                // refresh_at is populated to the mock clock value because we're loading this in a new repo
                'refresh_at' => '2022-01-03 20:31:02',
            ],
            $this->newSubject()->getState('my-task')
        );

        $state->markStepComplete(new DateTimeImmutable('2022-01-03 23:49:13'), true);
        $subject->save($state);
        $this->assertSameStateValues(
            [
                'group_name' => 'my-task',
                'is_running' => false,
                'last_step_executed' => 'first-step',
                'last_run_started_at' => '2022-01-03 23:47:42',
                'last_run_timeout_at' => '2022-01-04 00:47:42',
                'last_run_completed_at' => '2022-01-03 23:49:13',
                'refresh_at' => null,
            ],
            $this->newSubject()->getState('my-task')
        );
    }

    protected function setUp(): void
    {
        parent::setUp();
        DbMaintainer::initDb();
        $this->pdo = DbMaintainer::makePdoConnection();
        $this->log = new NullLogger();
        $this->clock = StoppedMockClock::atNow();
    }

    private function newSubject(): PDOCronTaskStateRepository
    {
        return new PDOCronTaskStateRepository(
            $this->pdo,
            $this->clock,
            $this->log
        );
    }

    private function insertDbState(array $values): void
    {
        $values = array_merge(
            [
                'group_name' => 'my-task-group',
                'is_running' => 0,
                'last_step_executed' => 'echo true',
                'last_run_started_at' => '2022-01-03 02:03:40',
                'last_run_timeout_at' => '2022-01-03 02:33:40',
                'last_run_completed_at' => '2022-01-03 02:05:23',
            ],
            $values
        );
        $stm = $this->pdo->prepare(
            <<<SQL
                INSERT INTO task_execution_state
                (group_name, is_running, last_step_executed, last_run_started_at, last_run_timeout_at, last_run_completed_at)
                VALUES 
                (:group_name,:is_running,:last_step_executed,:last_run_started_at,:last_run_timeout_at,:last_run_completed_at);
                SQL
        );
        $stm->execute($values);
    }

    private function assertSameStateValues(array $expect, TaskExecutionState $state): void
    {
        $vars = array_map(
            fn ($v) => $v instanceof DateTimeInterface ? $v->format('Y-m-d H:i:s') : $v,
            ObjectPropertyRipper::ripAll($state)
        );
        $this->assertSame(
            $expect,
            $vars
        );
    }

    private function assertSameRefreshAt(string $expect, TaskExecutionState $state1, string $msg): void
    {
        $this->assertSame($expect, DateString::ymdhis($state1->getRefreshAt()), $msg);
    }
}

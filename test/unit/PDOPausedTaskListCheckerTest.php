<?php

namespace test\unit\Ingenerator\ScheduledTaskRunner;

use DateInterval;
use DateTimeImmutable;
use Ingenerator\PHPUtils\DateTime\Clock\RealtimeClock;
use Ingenerator\PHPUtils\DateTime\Clock\StoppedMockClock;
use Ingenerator\ScheduledTaskRunner\PausedTaskListChecker;
use Ingenerator\ScheduledTaskRunner\PDOPausedTaskListChecker;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use test\mock\Ingenerator\ScheduledTaskRunner\PDO\PDOStub;


class PDOPausedTaskListCheckerTest extends TestCase
{
    private string $fetch_query = 'SELECT foo FROM bar';

    private DateInterval $refresh_interval;

    private RealtimeClock $clock;

    private PDOStub $pdo;

    public function test_it_is_initialisable()
    {
        $this->assertInstanceOf(PausedTaskListChecker::class, $this->newSubject());
    }

    public function test_it_loads_from_database()
    {
        $this->givenDatabaseWillProvideConfig('{}', 'SELECT foo AS value FROM bar WHERE whatever');
        $this->newSubject()->isPaused('anything');
        $this->pdo->assertPerformedExactQueries(['SELECT foo AS value FROM bar WHERE whatever']);
    }

    /**
     * @testWith  [[], "0 results"]
     *            [[{"foo": "ab"}, {"foo": "bc"}], "2 results"]
     */
    public function test_it_throws_if_db_query_returns_zero_or_more_than_one_results($results, $expect_msg)
    {
        $this->pdo = PDOStub::willReturnData(
            [
                $this->fetch_query => $results,
            ]
        );
        $subject   = $this->newSubject();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($expect_msg);
        $subject->isPaused('anything');
    }

    public function test_it_only_refreshes_from_database_at_configured_interval()
    {
        $this->givenDatabaseWillProvideConfig('{}');
        $this->clock            = StoppedMockClock::at('2022-06-01 00:00:00');
        $this->refresh_interval = new DateInterval('PT30S');
        $subject                = $this->newSubject();
        $subject->isPaused('anything');
        $subject->isPaused('something else');
        $this->pdo->assertPerformedQueryCount(1, '1 query initially');

        $this->clock->tick(new DateInterval('PT29S'));
        $subject->isPaused('whatever');
        $this->pdo->assertPerformedQueryCount(1, 'Still 1 after 29 seconds');

        $this->clock->tick(new DateInterval('PT1S'));
        $subject->isPaused('anything');
        $this->pdo->assertPerformedQueryCount(2, 'Refresh query after 30 seconds elapsed');

        $subject->isPaused('anything');
        $this->pdo->assertPerformedQueryCount(2, 'Still 2 queries');

        $this->clock->tick(new DateInterval('PT29S'));
        $subject->isPaused('whatever');
        $this->pdo->assertPerformedQueryCount(2, 'Still 1 after another 29 seconds');

        $this->clock->tick(new DateInterval('PT5S'));
        $subject->isPaused('anything');
        $this->pdo->assertPerformedQueryCount(
            3,
            'Refresh query next time after 30 seconds even if it misses the exact time'
        );
    }

    public function provider_task_paused()
    {
        return [
            [
                // Nothing paused, task is not paused. Obvs.
                '{}',
                ['2022-05-01 02:03:02' => FALSE],
            ],
            [
                // A different task is paused, we aren't. Obvs
                '{"some-other-task": "2022-05-03 03:01:00"}',
                [
                    '2022-05-01 02:03:02' => FALSE,
                    '2022-05-01 03:01:00' => FALSE,
                    '2022-05-01 03:01:01' => FALSE,
                ],
            ],
            [
                // We are paused until time
                '{"my-task": "2022-05-03 03:01:00"}',
                [
                    '2022-04-01 02:03:02' => TRUE,
                    '2022-05-03 03:00:59' => TRUE,
                    '2022-05-03 03:01:00' => FALSE,
                    '2022-05-09 03:01:00' => FALSE,
                ],
            ],
        ];
    }

    /**
     * @dataProvider provider_task_paused
     */
    public function test_it_reports_task_is_paused_if_in_config_map_and_before_configured_time(
        string $config_json,
        array  $expect_paused_at
    ) {
        $this->givenDatabaseWillProvideConfig($config_json);

        $this->clock = new class() extends StoppedMockClock {
            public function __construct()
            {
                parent::__construct(new DateTimeImmutable('2022-05-01 00:00'));
            }

            public function tickTo(DateTimeImmutable $new_time)
            {
                $this->current_microtime = (float) $new_time->format('U.u');
            }
        };

        $subject = $this->newSubject();
        $actual  = [];
        foreach (array_keys($expect_paused_at) as $at_time) {
            $this->clock->tickTo(new DateTimeImmutable($at_time));
            $actual[$at_time] = $subject->isPaused('my-task');
        }

        $this->assertSame($expect_paused_at, $actual);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo              = new PDOStub();
        $this->refresh_interval = new DateInterval('PT1S');
        $this->clock            = StoppedMockClock::atNow();
    }

    private function newSubject(): PDOPausedTaskListChecker
    {
        return new PDOPausedTaskListChecker(
            $this->pdo,
            $this->clock,
            $this->refresh_interval,
            $this->fetch_query,
        );
    }

    private function givenDatabaseWillProvideConfig(
        string $cfg,
        string $query = 'SELECT foo AS value FROM bar WHERE whatever'
    ): void {
        $this->fetch_query = $query;
        $this->pdo         = PDOStub::willReturnData(
            [
                $query => [['value' => $cfg]],
            ]
        );
    }
}

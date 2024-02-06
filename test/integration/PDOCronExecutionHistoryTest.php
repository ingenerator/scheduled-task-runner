<?php

namespace test\integration\Ingenerator\ScheduledTaskRunner;

use DateTimeImmutable;
use Ingenerator\ScheduledTaskRunner\PDOCronExecutionHistoryRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class PDOCronExecutionHistoryTest extends TestCase
{
    private PDO $pdo;

    public function test_it_is_initialisable()
    {
        $this->assertInstanceOf(PDOCronExecutionHistoryRepository::class, $this->newSubject());
    }

    /**
     * @testWith [0, {"group_name": "my-group", "step_name": "whatever", "last_exit_code": 0, "last_success_at": "2021-03-02 03:02:04", "last_failure_at": null}]
     *           [15, {"group_name": "my-group", "step_name": "whatever", "last_exit_code": 15, "last_success_at": null, "last_failure_at": "2021-03-02 03:02:04"}]
     */
    public function test_it_inserts_new_task_states_if_required($exit, $expect)
    {
        $this->newSubject()->recordCompletion(
            'my-group',
            'whatever',
            new DateTimeImmutable('2021-03-02 03:02:04'),
            $exit
        );

        $this->assertSame([$expect], $this->newSubject()->listCurrentStates());
    }

    public function test_it_updates_correct_task_with_correct_status()
    {
        $s = $this->newSubject();

        // Initial states
        $s->recordCompletion('g1', 's1', new DateTimeImmutable('2021-03-01 01:02:03'), 0);
        $s->recordCompletion('g1', 's2', new DateTimeImmutable('2021-03-02 01:02:03'), 0);
        $s->recordCompletion('g2', 's2', new DateTimeImmutable('2021-03-03 01:02:03'), 15);
        $s->recordCompletion('g2', 's9', new DateTimeImmutable('2021-03-04 01:02:03'), 15);

        // Now apply some updates
        $s->recordCompletion('g1', 's1', new DateTimeImmutable('2021-03-05 04:08:01'), 0);
        $s->recordCompletion('g1', 's2', new DateTimeImmutable('2021-03-06 04:08:01'), 15);
        $s->recordCompletion('g2', 's2', new DateTimeImmutable('2021-03-07 04:08:01'), 15);
        $s->recordCompletion('g2', 's9', new DateTimeImmutable('2021-03-08 04:08:01'), 0);

        // Verify against a new repo for isolation
        $this->assertSame(
            [
                [
                    'group_name' => 'g1',
                    'step_name' => 's1',
                    'last_exit_code' => 0,
                    'last_success_at' => '2021-03-05 04:08:01',
                    'last_failure_at' => null,
                ],
                [
                    'group_name' => 'g1',
                    'step_name' => 's2',
                    'last_exit_code' => 15,
                    'last_success_at' => '2021-03-02 01:02:03',
                    'last_failure_at' => '2021-03-06 04:08:01',
                ],
                [
                    'group_name' => 'g2',
                    'step_name' => 's2',
                    'last_exit_code' => 15,
                    'last_success_at' => null,
                    'last_failure_at' => '2021-03-07 04:08:01',
                ],
                [
                    'group_name' => 'g2',
                    'step_name' => 's9',
                    'last_exit_code' => 0,
                    'last_success_at' => '2021-03-08 04:08:01',
                    'last_failure_at' => '2021-03-04 01:02:03',
                ],
            ],
            $this->newSubject()->listCurrentStates()
        );
    }

    protected function setUp(): void
    {
        parent::setUp();
        DbMaintainer::initDb();
        $this->pdo = DbMaintainer::makePdoConnection();
    }

    private function newSubject(): PDOCronExecutionHistoryRepository
    {
        return new PDOCronExecutionHistoryRepository($this->pdo);
    }
}

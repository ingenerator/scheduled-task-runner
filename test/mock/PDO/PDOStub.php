<?php

namespace test\mock\Ingenerator\ScheduledTaskRunner\PDO;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Assert;


class PDOStub extends PDO
{
    private array $queries = [];

    private array $stub_results = [];

    public static function willReturnData(array $results): static
    {
        $i               = new static();
        $i->stub_results = $results;

        return $i;
    }

    public function __construct()
    {
    }

    public function query($statement, $mode = PDO::ATTR_DEFAULT_FETCH_MODE, ...$fetch_mode_args): PDOStatement|false
    {
        $this->queries[] = $statement;
        $result          = $this->stub_results[$statement] ?? [];

        return new ArrayResultPDOStatementStub($result);
    }

    public function assertPerformedQueryCount(int $expected_count, string $msg): void
    {
        Assert::assertCount($expected_count, $this->queries, $msg);
    }

    public function assertPerformedExactQueries(array $expect): void
    {
        Assert::assertSame($expect, $this->queries);
    }
}

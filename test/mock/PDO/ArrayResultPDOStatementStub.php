<?php

namespace test\mock\Ingenerator\ScheduledTaskRunner\PDO;

use ArrayIterator;
use InvalidArgumentException;
use Iterator;
use IteratorAggregate;
use PDO;
use PDOStatement;

class ArrayResultPDOStatementStub extends PDOStatement implements IteratorAggregate
{
    protected $data;

    public function __construct(array $results = [])
    {
        $this->data = $results;
    }

    public function rowCount(): int
    {
        return count($this->data);
    }

    public function fetchAll($fetch_style = null, $fetch_argument = null, ...$args): array
    {
        if ($fetch_style !== PDO::FETCH_ASSOC) {
            throw new InvalidArgumentException('Can only handle PDO::FETCH_ASSOC in '.__METHOD__);
        }

        return $this->data;
    }

    public function getIterator(): Iterator
    {
        return new ArrayIterator($this->data);
    }
}

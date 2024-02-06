<?php

namespace Ingenerator\ScheduledTaskRunner;

class CronTaskStepDefinition
{
    private string $name;

    private array  $command;

    public function __construct(string $name, array $command)
    {
        $this->name = $name;
        $this->command = $command;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCommand(): array
    {
        return $this->command;
    }
}

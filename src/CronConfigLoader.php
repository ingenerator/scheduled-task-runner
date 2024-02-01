<?php

namespace Ingenerator\ScheduledTaskRunner;

class CronConfigLoader
{
    /**
     * @var CronTaskGroupDefinition[]
     */
    private array $definitions;

    public function __construct(array $config)
    {
        $this->definitions = [];
        foreach ($config as $group_name => $values) {
            $values['group_name'] = $group_name;
            $this->definitions[] = CronTaskGroupDefinition::fromArray($values);
        }
    }

    /**
     * @return CronTaskGroupDefinition[]
     */
    public function getAllTaskDefinitions(): array
    {
        return $this->definitions;
    }

    /**
     * @return CronTaskGroupDefinition[]
     */
    public function getActiveTaskDefinitions(): array
    {
        return array_values(array_filter($this->definitions, fn (CronTaskGroupDefinition $d) => $d->isEnabled()));
    }
}

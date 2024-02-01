<?php

namespace test\unit\Ingenerator\ScheduledTaskRunner;

use Ingenerator\ScheduledTaskRunner\CronConfigLoader;
use Ingenerator\ScheduledTaskRunner\CronTaskGroupDefinition;
use Ingenerator\ScheduledTaskRunner\CronTaskStepDefinition;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CronConfigLoaderTest extends TestCase
{
    private array $definitions = [];

    public function test_it_is_initialisable()
    {
        $this->assertInstanceOf(CronConfigLoader::class, $this->newSubject());
    }

    public function test_it_has_empty_definitions_with_no_tasks()
    {
        $this->definitions = [];
        $this->assertSame([], $this->newSubject()->getActiveTaskDefinitions());
    }

    public function test_it_filters_enabled_and_disabled_tasks()
    {
        $this->definitions = [
            'task-1' => [
                'is_enabled' => TRUE,
                'schedule'   => '@hourly',
                'steps'      => [],
            ],
            'task-2' => [
                'is_enabled' => FALSE,
                'schedule'   => '@hourly',
                'steps'      => [],
            ],
            'task-3' => [
                'is_enabled' => TRUE,
                'schedule'   => '@hourly',
                'steps'      => [],
            ],
        ];
        $defs              = $this->newSubject()->getActiveTaskDefinitions();
        $this->assertSame(
            ['task-1', 'task-3'],
            array_map(fn(CronTaskGroupDefinition $t) => $t->getGroupName(), $defs)
        );
    }

    /**
     * @testWith ["some-old-string"]
     *           [{"some":"junk"}]
     *           [{"name": "mine", "cmd":["whatever"], "extra": "foo"}]
     */
    public function test_it_cannot_be_constructed_with_invalid_steps($step)
    {
        $this->definitions = [
            'task1' => [
                'is_enabled' => TRUE,
                'schedule'   => '@hourly',
                'steps'      => [$step],
            ],
        ];
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(CronTaskStepDefinition::class);
        $this->newSubject();
    }

    public function test_it_can_create_steps_from_array_with_valid_keys()
    {
        $this->definitions = [
            'task1' => [
                'is_enabled' => TRUE,
                'schedule'   => '@hourly',
                'steps'      => [
                    ['name' => 'mystep', 'cmd' => ['foo', 'bar']],
                    ['name' => 'otherstep', 'cmd' => ['bar', 'baz']],
                ],
            ],
        ];
        $task              = $this->newSubject()->getAllTaskDefinitions()[0];
        $this->assertEquals(
            new CronTaskStepDefinition('mystep', ['foo', 'bar']),
            $task->getFirstStep()
        );
        $this->assertEquals(
            new CronTaskStepDefinition('otherstep', ['bar', 'baz']),
            $task->getStepAfter('mystep')
        );
    }

    public function test_it_cannot_be_constructed_if_task_has_missing_key()
    {
        $this->markTestIncomplete();
    }

    public function test_its_tasks_have_default_values_for_optional_keys()
    {
        $this->markTestIncomplete();
    }

    public function test_its_tasks_can_have_values_for_optional_keys()
    {
        $this->markTestIncomplete();
    }

    private function newSubject(): CronConfigLoader
    {
        return new CronConfigLoader($this->definitions);
    }
}

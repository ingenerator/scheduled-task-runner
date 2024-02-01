<?php

namespace Ingenerator\ScheduledTaskRunner\TestUtils;


use Ingenerator\ScheduledTaskRunner\CronConfigLoader;
use Ingenerator\ScheduledTaskRunner\CronTaskStepDefinition;

class CronConfigLoaderStub
{
    public static function withTaskDefinitions(array $definitions): CronConfigLoader
    {
        return new CronConfigLoader(
            array_map(
                function (array $d) {
                    $d          = array_merge(
                        [
                            'is_enabled' => TRUE,
                            'schedule'   => ['minute' => '*'],
                            'steps'      => ['foo-step'],
                        ],
                        $d
                    );
                    $d['steps'] = array_map(fn(string $s) => new CronTaskStepDefinition($s, ['echo', $s]), $d['steps']);

                    return $d;
                },
                $definitions
            )
        );
    }
}

<?php

namespace Ingenerator\ScheduledTaskRunner;

use Cron\CronExpression;
use DateInterval;
use DateTimeImmutable;
use Ingenerator\ScheduledTaskRunner\Schedule\CronExpresssionSchedule;
use Ingenerator\ScheduledTaskRunner\Schedule\CronSchedule;
use Ingenerator\ScheduledTaskRunner\Schedule\EveryXSecondsSchedule;
use InvalidArgumentException;
use function implode;
use function is_string;
use function preg_match;

class CronTaskGroupDefinition
{
    private string $group_name;

    private bool   $is_enabled;

    /**
     * @var CronTaskStepDefinition[]
     */
    private array          $steps;

    private int            $timeout_seconds;

    private CronSchedule   $schedule;

    private DateInterval  $healthcheck_timeout;

    public static function fromArray(array $vars): self
    {
        $i = new static(static::parseSchedule($vars['schedule']));
        $i->group_name = $vars['group_name'];
        $i->is_enabled = $vars['is_enabled'];
        $i->steps = static::validateSteps($vars['steps']);
        $i->timeout_seconds = $vars['timeout_seconds'] ?? 60;
        $i->healthcheck_timeout = new DateInterval($vars['healthcheck_timeout'] ?? 'PT24H');

        return $i;
    }

    private static function parseSchedule(string|array $schedule): CronSchedule
    {
        if (is_string($schedule) && preg_match('/^every (\d+) seconds$/', $schedule, $matches)) {
            return new EveryXSecondsSchedule($matches[1]);
        }

        if (\is_array($schedule)) {
            $schedule = array_merge(
                ['minute' => '*', 'hour' => '*', 'day_of_month' => '*', 'month' => '*', 'day_of_week' => '*'],
                $schedule
            );
            $schedule = implode(' ', $schedule);
        }

        return new CronExpresssionSchedule(new CronExpression($schedule));
    }

    private static function validateSteps(array $steps): array
    {
        return array_map(
            function ($s) {
                if ($s instanceof CronTaskStepDefinition) {
                    return $s;
                }
                if (is_array($s) && (array_keys($s) == ['name', 'cmd'])) {
                    return new CronTaskStepDefinition($s['name'], $s['cmd']);
                }
                throw new InvalidArgumentException(
                    'Task step definitions must all be instances of '.CronTaskStepDefinition::class.
                    ' or an array like <name:string,cmd:array>'
                );
            },
            $steps
        );
    }

    private function __construct(CronSchedule $schedule)
    {
        $this->schedule = $schedule;
    }

    public function getGroupName(): string
    {
        return $this->group_name;
    }

    /**
     * How long ago we should have seen at least one success of each step in this task to count it as healthy
     */
    public function getHealthcheckTimeout(): DateInterval
    {
        return $this->healthcheck_timeout;
    }

    public function getScheduledTimeAfter(DateTimeImmutable $time): DateTimeImmutable
    {
        return $this->schedule->getNextRunDate($time);
    }

    public function getStepAfter(string $previous_step): ?CronTaskStepDefinition
    {
        $max_step_idx = count($this->steps) - 1;
        for ($i = 0; $i < $max_step_idx; $i++) {
            if ($this->steps[$i]->getName() === $previous_step) {
                return $this->steps[$i + 1];
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    public function getStepNames(): array
    {
        return array_map(fn (CronTaskStepDefinition $s) => $s->getName(), $this->steps);
    }

    public function isEnabled(): bool
    {
        return $this->is_enabled;
    }

    public function getTimeoutSeconds(): int
    {
        return $this->timeout_seconds;
    }

    public function getFirstStep(): CronTaskStepDefinition
    {
        return $this->steps[0];
    }
}

<?php

namespace Ingenerator\ScheduledTaskRunner\Schedule;

use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeInterface;

class CronExpresssionSchedule implements CronSchedule
{
    public function __construct(
        private CronExpression $cron_expression
    ) {
    }

    public function getNextRunDate(DateTimeInterface $current_time): DateTimeImmutable
    {
        return DateTimeImmutable::createFromMutable($this->cron_expression->getNextRunDate($current_time));
    }
}

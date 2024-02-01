<?php

namespace Ingenerator\ScheduledTaskRunner\Schedule;

use DateTimeImmutable;
use DateTimeInterface;

interface CronSchedule
{
    public function getNextRunDate(DateTimeInterface $current_time): DateTimeImmutable;
}

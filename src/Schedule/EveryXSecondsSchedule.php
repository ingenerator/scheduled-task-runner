<?php

namespace Ingenerator\ScheduledTaskRunner\Schedule;

use DateTimeImmutable;
use DateTimeInterface;
use Ingenerator\PHPUtils\DateTime\DateTimeImmutableFactory;
use function ceil;

class EveryXSecondsSchedule implements CronSchedule
{
    public function __construct(
        private int $seconds_interval
    ) {
    }

    public function getNextRunDate(DateTimeInterface $current_time): DateTimeImmutable
    {
        $ts = $current_time->getTimestamp();
        // Add 1 so it definitely rolls over at the moment it's due to start
        $next = $this->seconds_interval * ceil(($ts + 1) / $this->seconds_interval);

        return DateTimeImmutableFactory::atUnixSeconds($next);
    }
}

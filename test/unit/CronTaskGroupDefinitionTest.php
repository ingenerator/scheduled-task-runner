<?php

namespace test\unit\Ingenerator\ScheduledTaskRunner;

use DateTimeImmutable;
use Ingenerator\PHPUtils\DateTime\DateString;
use Ingenerator\ScheduledTaskRunner\CronTaskGroupDefinition;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CronTaskGroupDefinitionTest extends TestCase
{
    public function test_it_is_initialisable()
    {
        $this->assertInstanceOf(CronTaskGroupDefinition::class, $this->newSubject());
    }

    public static function provider_scheduled_times(): array
    {
        return [
            /*
             * These cases are based on the cron-expression library, don't need to cover every possible
             */
            [
                // Simple, cron string every minute
                '* * * * *',
                '2022-02-01 20:03:59.252200',
                '2022-02-01 20:04:00.000000',
            ],
            [
                // Simple, cron string every 5 minutes
                '*/5 * * * *',
                '2022-02-01 20:02:59.252200',
                '2022-02-01 20:05:00.000000',
            ],
            [
                // Simple, cron string every other hour at 15
                '15 */2 * * *',
                '2022-03-01 20:15:00.123456',
                '2022-03-01 22:15:00.000000',
            ],
            /*
             * These cases are based on our shorthands - just cover the kinds of things we map to cron strings and
             * at least one schedule for each variable type to make sure they're all mapped to cron string
             * in the right order.
             */
            [
                // Also an every 5 minute job
                ['minute' => '*/5'],
                '2022-03-02 08:05:00.000011',
                '2022-03-02 08:10:00.000000',
            ],
            [
                // Hourly on 15 & 45
                ['minute' => '15,45'],
                '2022-03-02 08:17:00.000011',
                '2022-03-02 08:45:00.000000',
            ],
            [
                // 04:10, 12:10, 18:10
                ['minute' => '10', 'hour' => '4,12,18'],
                '2022-03-02 19:20:00.000011',
                '2022-03-03 04:10:00.000000',
            ],
            [
                // 1st of each month
                ['minute' => '0', 'hour' => '2', 'day_of_month' => '1'],
                '2022-03-02 19:20:00.000011',
                '2022-04-01 02:00:00.000000',
            ],
            [
                // Only in June
                ['minute' => '0', 'hour' => '2', 'month' => '6'],
                '2022-03-02 19:20:00.000011',
                '2022-06-01 02:00:00.000000',
            ],
            [
                // Monday nights
                ['minute' => '15', 'hour' => '4', 'day_of_week' => '1'],
                '2022-03-02 19:20:00.000011',
                '2022-03-07 04:15:00.000000',
            ],
            [
                // Per-seconds are done by rounding the current unix timestamp to the next multiple
                // of the given interval. E.g. if `every 23 seconds` it theoretically ran at
                // 1970-01-01 00:00:00 and then at 00:00:23, 00:00:46, 00:01:09, etc
                // This gives nice even times if the timings are a factor of 60
                'every 23 seconds',
                '2022-06-21 23:20:58.123456+00:00',
                '2022-06-21 23:21:14.000000+00:00',
            ],
            [
                'every 23 seconds',
                '2022-06-21 23:21:25.999999+00:00',
                '2022-06-21 23:21:37.000000+00:00',
            ],
            [
                'every 23 seconds',
                '2022-06-21 23:21:26.000000+00:00',
                '2022-06-21 23:21:37.000000+00:00',
            ],
        ];
    }

    /**
     * @dataProvider provider_scheduled_times
     */
    public function test_it_can_advise_next_scheduled_time_after_a_time($schedule, $time_now, $expect_next)
    {
        $subject = $this->newSubject(['schedule' => $schedule]);
        $this->assertSame(
            DateString::isoMS(new DateTimeImmutable($expect_next)),
            DateString::isoMS($subject->getScheduledTimeAfter(new DateTimeImmutable($time_now)))
        );
    }

    /**
     * @testWith ["junk"]
     *           ["every 95 years"]
     *           [{"hour": "95"}]
     */
    public function test_it_throws_with_invalid_schedule($schedule)
    {
        $this->expectException(InvalidArgumentException::class);
        $this->newSubject(['schedule' => $schedule]);
    }

    private function newSubject(array $vars = []): CronTaskGroupDefinition
    {
        return CronTaskGroupDefinition::fromArray(
            array_merge(
                [
                    'group_name' => 'anything',
                    'is_enabled' => FALSE,
                    'schedule'   => '* * * * *',
                    'steps'      => [],
                ],
                $vars
            ),
        );
    }
}

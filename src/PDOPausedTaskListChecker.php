<?php

namespace Ingenerator\ScheduledTaskRunner;

use DateInterval;
use DateTimeImmutable;
use Ingenerator\PHPUtils\DateTime\Clock\RealtimeClock;
use Ingenerator\PHPUtils\StringEncoding\JSON;
use PDO;
use RuntimeException;
use function array_map;

class PDOPausedTaskListChecker implements PausedTaskListChecker
{
    private ?DateTimeImmutable $last_loaded = null;

    private array               $paused_until;

    /**
     * @param string        $fetch_config_query_sql SQL query to run, expected to return a single row with a `value` column
     */
    public function __construct(
        private PDO $pdo,
        private RealtimeClock $clock,
        private DateInterval $refresh_interval,
        private string $fetch_config_query_sql,
    ) {
    }

    public function isPaused(string $task_group_name): bool
    {
        $cfg = $this->getPausedUntilConfig();
        $paused_until = $cfg[$task_group_name] ?? null;

        if ($this->clock->getDateTime() < $paused_until) {
            return true;
        }

        return false;
    }

    private function getPausedUntilConfig(): array
    {
        $now = $this->clock->getDateTime();
        if ($this->last_loaded && ($this->last_loaded->add($this->refresh_interval) > $now)) {
            return $this->paused_until;
        }

        $this->paused_until = $this->loadConfigFromDB();
        $this->last_loaded = $now;

        return $this->paused_until;
    }

    private function loadConfigFromDB(): array|false
    {
        $result = $this->pdo->query($this->fetch_config_query_sql);
        $rows = $result->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1) {
            throw new RuntimeException(
                sprintf(
                    'Got %d results when attempting to load paused cron list with %s',
                    count($rows),
                    $this->fetch_config_query_sql
                )
            );
        }

        return array_map(
            fn (string $dt) => new DateTimeImmutable($dt),
            JSON::decodeArray($rows[0]['value'])
        );
    }
}

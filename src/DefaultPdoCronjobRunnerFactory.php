<?php

namespace Ingenerator\ScheduledTaskRunner;

use DateInterval;
use Ingenerator\PHPUtils\DateTime\Clock\RealtimeClock;
use Ingenerator\PHPUtils\Monitoring\MetricsAgent;
use PDO;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\PdoStore;

class DefaultPdoCronjobRunnerFactory implements CronjobRunnerFactory
{
    private CronController $controller;

    public function __construct(
        PDO $pdo,
        LoggerInterface $logger,
        MetricsAgent $metrics,
        string $fetch_config_query_sql,
        string $runner_working_dir,
        $output_stream,
        array $task_config,
        int $lock_check_interval_seconds,
        DateInterval $max_runtime,
        DateInterval $refresh_interval = new DateInterval('PT60S')
    )
    {
        $clock = new RealtimeClock();
        $lock_factory = new LockFactory(new PdoStore($pdo, ['db_table' => 'sf_lock_keys']));
        $lock_factory->setLogger($logger);

        $this->controller = new CronController(
            $lock_factory,
            new CronTaskExecutionManager(
                $clock,
                new PDOCronTaskStateRepository($pdo, $clock, $logger),
                new SymfonyCronTaskProcessRunner($runner_working_dir, $output_stream),
                new CronStatusReporter(
                    $logger,
                    $metrics,
                    new PDOCronExecutionHistoryRepository($pdo)
                ),
                new CronConfigLoader($task_config),
                new PDOPausedTaskListChecker(
                    $pdo,
                    $clock,
                    // Refresh the paused task state from the database once a minute
                    $refresh_interval,
                    $fetch_config_query_sql
                )
            ),
            $clock,
            $logger,
            $lock_check_interval_seconds,
            $max_runtime
        );
    }


    public function getController(): CronController
    {
        return $this->controller;
    }
}

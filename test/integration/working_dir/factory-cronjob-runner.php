<?php

use Ingenerator\PHPUtils\Monitoring\NullMetricsAgent;
use Ingenerator\ScheduledTaskRunner\DefaultPdoCronjobRunnerFactory;
use Psr\Log\AbstractLogger;
use test\integration\Ingenerator\ScheduledTaskRunner\DbMaintainer;

require_once(__DIR__.'/../../../vendor/autoload.php');

$task_config = require_once($argv[2]);

return new DefaultPdoCronjobRunnerFactory(
    DbMaintainer::makePdoConnection(),
    new class extends AbstractLogger {
        public function log($level, $message, array $context = [])
        {
            echo sprintf('[%s] %s'.PHP_EOL, $level, $message);
        }
    },
    new NullMetricsAgent(),
    "SELECT '{}' AS value;",
    __DIR__,
    STDOUT,
    $task_config,
    10,
    new DateInterval('PT60S'),
    new DateInterval('PT60S')
);

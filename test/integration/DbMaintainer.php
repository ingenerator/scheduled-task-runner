<?php
/**
 * @author    Craig Gosman <craig@ingenerator.com>
 * @licence   proprietary
 */

namespace test\integration\Ingenerator\ScheduledTaskRunner;

use PDO;
use function sprintf;

class DbMaintainer
{

    private const DB_NAME = 'scheduled-tasks';
    private const USER    = 'root';
    private const PASS    = 'root';
    private const HOST    = '127.0.0.1';
    private const PORT    = 3306;

    public static function makePdoConnection(): PDO
    {
        return new PDO(
            sprintf("mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4", static::HOST, static::PORT, static::DB_NAME),
            static::USER,
            static::PASS
        );
    }

    public static function initDb(): void
    {
        $pdo = new PDO(
            sprintf("mysql:host=%s;port=%s;charset=utf8mb4", static::HOST, static::PORT),
            static::USER,
            static::PASS
        );
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `".static::DB_NAME."`;");


        $pdo = static::makePdoConnection();

        $pdo->exec("DROP TABLE IF EXISTS sf_lock_keys");
        $pdo->exec("DROP TABLE IF EXISTS task_execution_history");
        $pdo->exec("DROP TABLE IF EXISTS task_execution_state");

        $pdo->exec(
            <<<SQL
        CREATE TABLE `task_execution_history` (
          `group_name` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
          `step_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
          `last_exit_code` int NOT NULL,
          `last_success_at` datetime DEFAULT NULL,
          `last_failure_at` datetime DEFAULT NULL,
          PRIMARY KEY (`group_name`,`step_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
        SQL
        );
        $pdo->exec(
            <<<SQL
        CREATE TABLE `task_execution_state` (
            `group_name` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
            `is_running` tinyint(1) NOT NULL,
            `last_step_executed` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
            `last_run_started_at` datetime NOT NULL,
            `last_run_timeout_at` datetime NOT NULL,
            `last_run_completed_at` datetime NOT NULL,
            PRIMARY KEY (`group_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
        SQL
        );
        $pdo->exec(
            <<<SQL
        CREATE TABLE `sf_lock_keys` (
            `key_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
            `key_token` varchar(44) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
            `key_expiration` int unsigned NOT NULL,
            PRIMARY KEY (`key_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
        SQL
        );
    }

}

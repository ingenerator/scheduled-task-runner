<?php

namespace Ingenerator\ScheduledTaskRunner;

use DateTimeImmutable;
use PDO;
use PDOStatement;

class PDOCronExecutionHistoryRepository implements CronExecutionHistoryRepository
{
    protected PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function recordCompletion(string $task_group, string $step_name, DateTimeImmutable $end, int $exit_code)
    {
        $ts_field = $exit_code === 0 ? 'last_success_at' : 'last_failure_at';
        $params = [
            'timestamp' => $end->format('Y-m-d H:i:s'),
            'exit_code' => $exit_code,
            'group_name' => $task_group,
            'step_name' => $step_name,
        ];

        // It will almost always be an update, so try an update first
        $stm = $this->prepareAndExecute(
            <<<SQL
                UPDATE task_execution_history
                SET $ts_field = :timestamp, 
                    last_exit_code = :exit_code
                WHERE group_name = :group_name
                AND   step_name = :step_name
                SQL,
            $params
        );
        if ($stm->rowCount() === 0) {
            // Nothing updated, so fall back to an insert. There is a tiny chance of a race condition (if things go
            // wrong) so use an INSERT ON DUPLICATE KEY just to eliminate the possibility of throwing a key violation
            $this->prepareAndExecute(
                <<<SQL
                    INSERT INTO task_execution_history
                        (group_name, step_name, last_exit_code, $ts_field)
                    VALUES
                        (:group_name, :step_name, :exit_code, :timestamp)
                    ON DUPLICATE KEY 
                    UPDATE last_exit_code = VALUES(last_exit_code),
                           $ts_field = VALUES($ts_field)            
                    SQL,
                $params
            );
        }
    }

    public function listCurrentStates(): array
    {
        $stm = $this->pdo->query('SELECT * FROM task_execution_history ORDER BY group_name, step_name');

        return $stm->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return false|PDOStatement
     */
    protected function prepareAndExecute(string $sql, array $params)
    {
        $stm = $this->pdo->prepare($sql);
        $stm->execute($params);

        return $stm;
    }
}

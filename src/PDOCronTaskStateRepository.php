<?php

namespace Ingenerator\ScheduledTaskRunner;

use DateInterval;
use Ingenerator\PHPUtils\DateTime\Clock\RealtimeClock;
use Ingenerator\PHPUtils\Object\ObjectPropertyRipper;
use InvalidArgumentException;
use PDO;
use PDOStatement;
use Psr\Log\LoggerInterface;

class PDOCronTaskStateRepository implements CronTaskStateRepository
{
    protected RealtimeClock $clock;

    private LoggerInterface $log;

    private PDO             $pdo;

    /**
     * @var array<string, TaskExecutionState>
     */
    private array $states;

    public function __construct(
        PDO $pdo,
        RealtimeClock $clock,
        LoggerInterface $log
    ) {
        $this->pdo = $pdo;
        $this->log = $log;
        $this->clock = $clock;
    }

    public function getState(string $group_name): TaskExecutionState
    {
        $memory_state = $this->states[$group_name] ?? null;
        if (
            ($memory_state === null)
            ||
            $memory_state->needsRefresh($this->clock->getDateTime())
        ) {
            $this->states[$group_name] = $this->loadDatabaseState($group_name);
        }

        return $this->states[$group_name];
    }

    private function loadDatabaseState(string $group_name): TaskExecutionState
    {
        $stm = $this->prepareAndExecute(
            'SELECT * FROM task_execution_state WHERE group_name = :group_name',
            ['group_name' => $group_name]
        );
        $result = $stm->fetchAll(PDO::FETCH_ASSOC);

        if (empty($result)) {
            // This task has never run, it must be newly deployed. Create a new state record for it.
            return $this->createNewTaskState($group_name);
        }

        $row = $result[0];
        if ($row['is_running'] === 1) {
            $refresh_at = $this->clock->getDateTime()->add(new DateInterval('PT1M'));
        } else {
            $refresh_at = null;
        }

        return TaskExecutionState::fromDbRow($row, $refresh_at);
    }

    private function prepareAndExecute(string $sql, array $vars): PDOStatement
    {
        $stm = $this->pdo->prepare($sql);
        $stm->execute($vars);

        return $stm;
    }

    private function createNewTaskState(string $group_name): TaskExecutionState
    {
        $state = TaskExecutionState::forNewTask($group_name, $this->clock->getDateTime());
        $this->prepareAndExecute(
            <<<SQL
                INSERT INTO task_execution_state
                (group_name, is_running, last_step_executed, last_run_started_at, last_run_timeout_at, last_run_completed_at)
                VALUES 
                (:group_name,:is_running,:last_step_executed,:last_run_started_at,:last_run_timeout_at,:last_run_completed_at);
                SQL,
            $this->toDbParams($state)
        );
        $this->log->warning("Initialised state for new task `$group_name`");

        return $state;
    }

    private function toDbParams(TaskExecutionState $state): array
    {
        $vars = ObjectPropertyRipper::ripAll($state);
        unset($vars['refresh_at']);
        $vars['is_running'] = $vars['is_running'] ? 1 : 0;
        $vars['last_run_started_at'] = $vars['last_run_started_at']->format('Y-m-d H:i:s');
        $vars['last_run_timeout_at'] = $vars['last_run_timeout_at']->format('Y-m-d H:i:s');
        $vars['last_run_completed_at'] = $vars['last_run_completed_at']->format('Y-m-d H:i:s');

        return $vars;
    }

    public function save(TaskExecutionState $state)
    {
        if ($state !== ($this->states[$state->getGroupName()] ?? null)) {
            throw new InvalidArgumentException('State object provided is out of sync with the repo');
        }

        $this->prepareAndExecute(
            <<<SQL
                    UPDATE task_execution_state
                    SET 
                        is_running = :is_running,  
                        last_step_executed = :last_step_executed,
                        last_run_started_at = :last_run_started_at,
                        last_run_timeout_at = :last_run_timeout_at,
                        last_run_completed_at = :last_run_completed_at
                    WHERE group_name = :group_name
                    ;
                SQL,
            $this->toDbParams($state)
        );
    }
}

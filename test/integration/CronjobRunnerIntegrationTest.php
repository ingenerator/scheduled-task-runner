<?php

namespace test\integration\Ingenerator\ScheduledTaskRunner;

use DateTimeImmutable;
use Ingenerator\ScheduledTaskRunner\CronController;
use Ingenerator\ScheduledTaskRunner\PDOCronTaskStateRepository;
use Ingenerator\PHPUtils\DateTime\Clock\RealtimeClock;
use PDO;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\PdoStore;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use function file_get_contents;
use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function var_export;

class CronjobRunnerIntegrationTest extends TestCase
{
    private PDO                        $pdo;

    private PDOCronTaskStateRepository $state_repo;

    private array                      $tmpfiles = [];

    public function test_it_can_launch_and_complete_without_errors()
    {
        // Create a temporary config and make sure the task is due to run
        $tmp_output = $this->getTemporaryFilename('job-file');
        $tmp_config = $this->createTemporaryConfigFile(
            [
                'my-job' => [
                    'is_enabled' => true,
                    'schedule' => ['minute' => '*'],
                    'steps' => [
                        [
                            'name' => 'do-something',
                            'cmd' => [
                                __DIR__.'/working_dir/tasks/fake_cron_task.php',
                                '--sleep-ms=100',
                                '--name=doit',
                                '--write-file='.$tmp_output,
                            ],
                        ],
                    ],
                ],
            ]
        );
        $state = $this->state_repo->getState('my-job');
        $state->markStepComplete(new DateTimeImmutable('-2 minutes'), true);
        $this->state_repo->save($state);

        // Launch the primary
        $process = new Process(
            [
                __DIR__.'/../../bin/cronjob-runner',
                __DIR__.'/working_dir/factory-cronjob-runner.php',
                $tmp_config,
            ]
        );
        $process->setTimeout(15);
        $process->start();
        $this->assertProcessRunning($process);

        // Wait long enough for it to run
        sleep(3);
        $this->assertProcessRunning($process);

        // Send it a termination signal and wait for it to complete
        $process->signal(SIGTERM);
        if ($process->wait() !== 0) {
            throw new ProcessFailedException($process);
        }

        // Verify that the task ran
        $this->assertSame(
            'Wrote from doit',
            file_get_contents($tmp_output),
            'Task should have run and written to file'
        );
    }

    protected function setUp(): void
    {
        parent::setUp();
        DbMaintainer::initDb();
        $this->pdo = DbMaintainer::makePdoConnection();
        $this->ensureCronLockAvailable();
        $this->state_repo = new PDOCronTaskStateRepository($this->pdo, new RealtimeClock(), new NullLogger());
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpfiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    private function assertProcessRunning(Process $process): void
    {
        if ( ! $process->isRunning()) {
            throw new ExpectationFailedException(
                sprintf(
                    "Unexpected process termination\nExit Code: %s\nOutput: \n%s\n\nError output: \n%s",
                    $process->getExitCode(),
                    $process->getOutput(),
                    $process->getErrorOutput()
                )
            );
        }
    }

    private function createTemporaryConfigFile(array $cfg): string
    {
        $tmp_config = $this->getTemporaryFilename('task-config.php');
        file_put_contents(
            $tmp_config,
            "<?php\n return ".var_export($cfg, true).";\n"
        );
        $this->tmpfiles[] = $tmp_config;

        return $tmp_config;
    }

    private function ensureCronLockAvailable(): void
    {
        // This is a bit ugly, symfony/lock doesn't provide a way to forcibly remove locks that you don't hold and it
        // obfuscates the id in the database so it's not easy to do them. Borrowed the hashing mechanism they use to
        // delete matching locks, then make sure we can get the lock using the actual implementation to keep it in sync.
        $this->pdo->exec(
            sprintf(
                "DELETE FROM sf_lock_keys WHERE key_id = '%s'",
                hash('sha256', CronController::LOCK_NAME)
            )
        );
        $locks = new LockFactory(new PdoStore($this->pdo, ['db_table' => 'sf_lock_keys']));
        $lock = $locks->createLock(CronController::LOCK_NAME);
        $this->assertTrue($lock->acquire(), 'Should be able to get cron controller lock');
        $lock->release();
    }

    private function getTemporaryFilename(string $name): string
    {
        $file = tempnam(sys_get_temp_dir(), $name);
        $this->tmpfiles[] = $file;

        return $file;
    }
}

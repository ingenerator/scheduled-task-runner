<?php

namespace test\mock\Ingenerator\ScheduledTaskRunner\Logging;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
use Psr\Log\AbstractLogger;
use function array_map;

class SpyingLoggerStub extends AbstractLogger
{
    protected $logs = [];

    public function log($level, $message, array $context = []): void
    {
        $this->logs[] = ['level' => $level, 'message' => $message, 'context' => $context];
    }

    public function assertNothingLogged(): void
    {
        Assert::assertEquals('', $this->logs ? json_encode($this->logs, JSON_PRETTY_PRINT) : '');
    }

    public function assertLoggedOnceMatching($level, $message_pattern, $context = []): void
    {
        Assert::assertCount(1, $this->logs, $this->getLogsAsText());
        $this->assertLoggedMatching($level, $message_pattern, $context);
    }

    public function assertLoggedMatching($level, $message_pattern, $context): void
    {
        Assert::assertNotEmpty($this->logs, 'Nothing has been logged');
        foreach ($this->logs as $log) {
            if (preg_match($message_pattern, $log['message'])) {
                Assert::assertEquals(
                    $level,
                    $log['level'],
                    $log['message'].' should have correct level'
                );
                Assert::assertEquals(
                    $context,
                    array_intersect_key($log['context'], $context),
                    $log['message'].' should contain expected context'
                );

                return;
            }
        }

        throw new AssertionFailedError(
            "No message matching $message_pattern was logged:\n".$this->getLogsAsText()
        );
    }

    public function assertLoggedTimesMatching($count, $level, $message_pattern): void
    {
        Assert::assertNotEmpty($this->logs, 'Nothing has been logged');
        $matching = array_filter(
            $this->logs,
            function (array $log) use ($message_pattern) {
                return (bool) preg_match($message_pattern, $log['message']);
            }
        );

        Assert::assertCount($count, $matching, "No message matching $message_pattern logged:\n".$this->getLogsAsText());
        foreach ($matching as $log) {
            Assert::assertSame($level, $log['level'], 'Message should have correct level');
        }
    }

    public function assertNoLogMatching($pattern): void
    {
        Assert::assertDoesNotMatchRegularExpression($pattern, $this->getLogsAsText());
    }

    protected function getLogsAsText(): string
    {
        return implode("\n", $this->getLogLinesAsText());
    }

    protected function getLogLinesAsText(): array
    {
        return array_map(
            fn($l) => $l['level'].': '.$l['message'],
            $this->logs
        );
    }

    public function assertExactTextLogMessages(array $expect): void
    {
        Assert::assertSame($expect, $this->getLogLinesAsText(), 'Expected exact log messages');
    }
}

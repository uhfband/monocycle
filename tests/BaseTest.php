<?php

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Uhf\Monocycle\Monocycle;

/**
 * Drives the Monocycle loop by hand instead of Monocycle::run(), which would
 * block until stop(). The helpers are static so setUpBeforeClass() and
 * tearDownAfterClass() can use them too; $this->tick() etc. still work.
 */
abstract class BaseTest extends TestCase
{
    /** Monocycle's logger statics as they were before captureLog(), null if not captured */
    private ?array $loggerBeforeCapture = null;

    /**
     * Sends Monocycle's log records to the returned TestHandler instead of
     * stdout, for the rest of the test; the previous logger is restored after
     * it (also when called from setUp()).
     */
    protected function captureLog(int $level = Logger::DEBUG): TestHandler
    {
        if ($this->loggerBeforeCapture === null) {
            $this->loggerBeforeCapture = [];
            foreach (['logger', 'defaultLogHandler'] as $name) {
                $this->loggerBeforeCapture[$name] = self::monocycleProperty($name)->getValue();
            }
        }

        $handler = new TestHandler($level);
        Monocycle::setLogger(new Logger('test', [$handler]));

        return $handler;
    }

    /**
     * Runs after tearDown(), so subclasses need not call anything.
     *
     * @after
     */
    public function restoreCapturedLogger(): void
    {
        if ($this->loggerBeforeCapture === null) {
            return;
        }
        foreach ($this->loggerBeforeCapture as $name => $value) {
            self::monocycleProperty($name)->setValue(null, $value);
        }
        $this->loggerBeforeCapture = null;
    }

    /**
     * One loop iteration: select on the registered streams for up to
     * $timeout seconds and dispatch their listeners, then, with $runTimers,
     * run due timers and reap exited processes. $now (default: the real
     * microtime(true)) is what timers are due against, for tests that mock the
     * clock.
     */
    protected static function tick(float $timeout = 1.0, bool $runTimers = false, ?float $now = null): void
    {
        $method = new ReflectionMethod(Monocycle::class, 'tick');
        $method->setAccessible(true);
        $method->invoke(null, $timeout, $runTimers, $now);
    }

    /**
     * Ticks the loop until $condition returns true, failing the test if it
     * doesn't within $timeoutSeconds.
     */
    protected static function pumpUntil(callable $condition, float $timeoutSeconds = 5.0, bool $runTimers = false): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (!$condition() && microtime(true) < $deadline) {
            self::tick(1.0, $runTimers);
        }

        self::assertTrue($condition(), 'Condition was not met within timeout');
    }

    /**
     * Empties the given static Monocycle registries ('sockets', 'timers',
     * 'processes', 'readStreams', ...). Processes are killed and closed first
     * so no child outlives the test.
     */
    protected static function resetMonocycle(string ...$properties): void
    {
        foreach ($properties as $name) {
            $property = self::monocycleProperty($name);

            if ($name === 'processes') {
                foreach ($property->getValue() as $process) {
                    $process->kill();
                    $process->close();
                }
            }

            $property->setValue(null, []);
        }
    }

    /**
     * Number of entries in a static Monocycle registry, e.g. 'timers'.
     */
    protected static function countMonocycle(string $property): int
    {
        return count(self::monocycleProperty($property)->getValue());
    }

    private static function monocycleProperty(string $name): ReflectionProperty
    {
        $property = new ReflectionProperty(Monocycle::class, $name);
        $property->setAccessible(true);

        return $property;
    }
}

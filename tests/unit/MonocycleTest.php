<?php

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Uhf\Monocycle\Monocycle;

class MonocycleTest extends BaseTest
{
    private int $freq;

    /** Monocycle's logger statics, restored after each test */
    private array $logState = [];

    protected function setUp(): void
    {
        $this->freq = Monocycle::getFreq();
        foreach (['logger', 'defaultLogHandler', 'logLevel'] as $name) {
            $this->logState[$name] = $this->monocycleStatic($name)->getValue();
        }
    }

    protected function tearDown(): void
    {
        Monocycle::setFreq($this->freq);
        foreach ($this->logState as $name => $value) {
            $this->monocycleStatic($name)->setValue(null, $value);
        }
    }

    private function monocycleStatic(string $name): ReflectionProperty
    {
        $property = new ReflectionProperty(Monocycle::class, $name);
        $property->setAccessible(true);

        return $property;
    }

    public function testFreqDefaultsToNoThrottling(): void
    {
        // the declared default, whatever an earlier test may have set
        $this->assertSame(0, (new ReflectionClass(Monocycle::class))->getDefaultProperties()['freq']);
    }

    public function testSetFreq(): void
    {
        Monocycle::setFreq(100);
        $this->assertSame(100, Monocycle::getFreq());

        Monocycle::setFreq(0);
        $this->assertSame(0, Monocycle::getFreq());
    }

    public function testNegativeFreqIsRejected(): void
    {
        Monocycle::setFreq(10);

        try {
            Monocycle::setFreq(-1);
            $this->fail('No exception for a negative frequency');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(10, Monocycle::getFreq(), 'the old value is kept');
        }
    }

    public function testMaxCallDurationDefaultsTo100ms(): void
    {
        $this->assertSame(0.1, (new ReflectionClass(Monocycle::class))->getDefaultProperties()['maxCallDuration']);
    }

    public function testLogLevelDefaultsToWarning(): void
    {
        $this->assertSame(Logger::WARNING, (new ReflectionClass(Monocycle::class))->getDefaultProperties()['logLevel']);
    }

    public function testSetLogLevel(): void
    {
        Monocycle::setLogLevel(Logger::DEBUG);
        $this->assertSame(Logger::DEBUG, Monocycle::getLogLevel());
    }

    public function testUnknownLogLevelIsRejected(): void
    {
        Monocycle::setLogLevel(Logger::ERROR);

        try {
            Monocycle::setLogLevel(42);
            $this->fail('No exception for an unknown log level');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(Logger::ERROR, Monocycle::getLogLevel(), 'the old value is kept');
        }
    }

    public function testDefaultLoggerUsesLogLevel(): void
    {
        $this->monocycleStatic('logger')->setValue(null, null);
        Monocycle::setLogLevel(Logger::ERROR);

        $logger = Monocycle::getLogger();
        $this->assertInstanceOf(Logger::class, $logger);
        $this->assertFalse($logger->isHandling(Logger::WARNING));
        $this->assertTrue($logger->isHandling(Logger::ERROR));
    }

    public function testSetLogLevelUpdatesBuiltDefaultLogger(): void
    {
        $this->monocycleStatic('logger')->setValue(null, null);
        Monocycle::setLogLevel(Logger::WARNING);
        $logger = Monocycle::getLogger();
        $this->assertFalse($logger->isHandling(Logger::INFO));

        Monocycle::setLogLevel(Logger::INFO);

        $this->assertSame($logger, Monocycle::getLogger(), 'not rebuilt');
        $this->assertTrue($logger->isHandling(Logger::INFO));
    }

    public function testSetLogLevelLeavesCustomLoggerAlone(): void
    {
        $handler = new TestHandler(Logger::ERROR);
        Monocycle::setLogger(new Logger('test', [$handler]));

        Monocycle::setLogLevel(Logger::DEBUG);

        $this->assertSame(Logger::ERROR, $handler->getLevel());
    }
}

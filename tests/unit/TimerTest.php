<?php

namespace {

    require_once __DIR__ . '/../TimerMock.php';

    use Uhf\Monocycle\Timer\Timer;
    use Uhf\Monocycle\Timer\TimerException;

    class TimerTest extends BaseTest
{
    private const MOCK_TIME = 1700000000;

    protected function setUp(): void
    {
        $this->resetMonocycle('timers');
        \Uhf\Monocycle\Timer\setMockTime(self::MOCK_TIME);
    }

    protected function tearDown(): void
    {
        \Uhf\Monocycle\Timer\setMockTime(null);
        $this->resetMonocycle('timers');
    }

    public function testSetDelaySetsNextRunTime(): void
    {
        $timer = new Timer(static function () {
        });

        $this->assertSame($timer, $timer->setDelay(2));
        $this->assertSame(self::MOCK_TIME + 2, $timer->getNextRunTimestamp());
    }

    public function testSetIntervalSetsNextRunTime(): void
    {
        $timer = new Timer(static function () {
        });

        $this->assertSame($timer, $timer->setInterval(3));
        $this->assertSame(self::MOCK_TIME + 3, $timer->getNextRunTimestamp());
    }

    public function testSetTimestampSetsNextRunTime(): void
    {
        $timer = new Timer(static function () {
        });

        $this->assertSame($timer, $timer->setTimestamp(self::MOCK_TIME + 100));
        $this->assertSame(self::MOCK_TIME + 100, $timer->getNextRunTimestamp());
    }

    public function testSetDateTimeSetsNextRunTime(): void
    {
        $timer = new Timer(static function () {
        });

        $dateTime = (new DateTimeImmutable())->setTimestamp(self::MOCK_TIME + 50);

        $this->assertSame($timer, $timer->setDateTime($dateTime));
        $this->assertSame(self::MOCK_TIME + 50, $timer->getNextRunTimestamp());
    }

    public function testSetCronExpressionSetsNextRunTime(): void
    {
        $timer = new Timer(static function () {
        });

        // CronExpression uses the real clock, not the mocked one
        $this->assertSame($timer, $timer->setCronExpression('* * * * *'));
        $this->assertGreaterThan(\time(), $timer->getNextRunTimestamp());
    }

    public function testInvalidCronExpressionThrowsException(): void
    {
        $timer = new Timer(static function () {
        });

        $this->expectException(InvalidArgumentException::class);
        $timer->setCronExpression('invalid');
    }

    public function testStartWithoutScheduleThrowsException(): void
    {
        $timer = new Timer(static function () {
        });

        try {
            $timer->start();
            $this->fail('Expected TimerException');
        } catch (TimerException $e) {
            $this->assertSame('Timer is not set', $e->getMessage());
        }

        $this->assertFalse($timer->isRunning());
        $this->assertSame(0, $this->countMonocycle('timers'));
    }

    public function testStartAndStopManageMonocycleTimersList(): void
    {
        $timer = new Timer(static function () {
        });
        $timer->setDelay(1);

        $this->assertFalse($timer->isRunning());

        $timer->start();
        $this->assertTrue($timer->isRunning());
        $this->assertSame(1, $this->countMonocycle('timers'));

        $timer->stop();
        $this->assertFalse($timer->isRunning());
        $this->assertSame(0, $this->countMonocycle('timers'));
    }

    public function testStartWhenAlreadyRunningThrowsException(): void
    {
        $timer = new Timer(static function () {
        });
        $timer->setDelay(5)->start();

        \Uhf\Monocycle\Timer\setMockTime(self::MOCK_TIME + 3);

        try {
            $timer->start();
            $this->fail('Expected exception');
        } catch (Exception $e) {
            $this->assertSame('Timer is already running.', $e->getMessage());
        }

        // still registered once, deadline not re-armed
        $this->assertTrue($timer->isRunning());
        $this->assertSame(1, $this->countMonocycle('timers'));
        $this->assertSame(self::MOCK_TIME + 5, $timer->getNextRunTimestamp());
    }

    public function testTimerCanBeStartedAgainAfterStop(): void
    {
        $timer = new Timer(static function () {
        });
        $timer->setDelay(5)->start();
        $timer->stop();

        \Uhf\Monocycle\Timer\setMockTime(self::MOCK_TIME + 3);
        $timer->start();

        $this->assertTrue($timer->isRunning());
        $this->assertSame(1, $this->countMonocycle('timers'));
        $this->assertSame(self::MOCK_TIME + 8, $timer->getNextRunTimestamp());
    }

    public function testStartRearmsDelayFromCurrentTime(): void
    {
        $timer = new Timer(static function () {
        });
        $timer->setDelay(5);

        \Uhf\Monocycle\Timer\setMockTime(self::MOCK_TIME + 10);
        $timer->start();

        $this->assertSame(self::MOCK_TIME + 15, $timer->getNextRunTimestamp());
    }

    public function testStartRearmsIntervalFromCurrentTime(): void
    {
        $timer = new Timer(static function () {
        });
        $timer->setInterval(5);

        \Uhf\Monocycle\Timer\setMockTime(self::MOCK_TIME + 10);
        $timer->start();

        $this->assertSame(self::MOCK_TIME + 15, $timer->getNextRunTimestamp());
    }

    public function testStartKeepsAbsoluteTimestamp(): void
    {
        $timer = new Timer(static function () {
        });
        $timer->setTimestamp(self::MOCK_TIME + 100);

        \Uhf\Monocycle\Timer\setMockTime(self::MOCK_TIME + 10);
        $timer->start();

        $this->assertSame(self::MOCK_TIME + 100, $timer->getNextRunTimestamp());
    }

    public function testRestartRearmsDelay(): void
    {
        $timer = new Timer(static function () {
        });
        $timer->setDelay(5)->start();

        \Uhf\Monocycle\Timer\setMockTime(self::MOCK_TIME + 3);

        $this->assertSame($timer, $timer->reset());
        $this->assertSame(self::MOCK_TIME + 8, $timer->getNextRunTimestamp());
    }

    public function testOnceTimerRunCallsCallbackAndStops(): void
    {
        $calledWith = null;
        $timer = new Timer(static function (Timer $t) use (&$calledWith) {
            $calledWith = $t;
        });
        $timer->setDelay(1)->start();

        $timer->run();

        $this->assertSame($timer, $calledWith);
        $this->assertFalse($timer->isRunning());
        $this->assertSame(0, $this->countMonocycle('timers'));
    }

    public function testIntervalTimerRunReschedulesWithSameInterval(): void
    {
        $called = 0;
        $timer = new Timer(static function () use (&$called) {
            $called++;
        });
        $timer->setInterval(3)->start();

        $timer->run();

        $this->assertSame(1, $called);
        $this->assertTrue($timer->isRunning());
        $this->assertSame(self::MOCK_TIME + 6, $timer->getNextRunTimestamp());
    }

    public function testIntervalTimerCatchesUpAfterStall(): void
    {
        $timer = new Timer(static function () {
        });
        $timer->setInterval(3)->start();

        \Uhf\Monocycle\Timer\setMockTime(self::MOCK_TIME + 100);
        $timer->run();

        // advances from the previous deadline, not from now
        $this->assertSame(self::MOCK_TIME + 6, $timer->getNextRunTimestamp());
    }

    public function testCronTimerRunCallsCallbackAndContinues(): void
    {
        $called = false;
        $timer = new Timer(static function () use (&$called) {
            $called = true;
        });
        $timer->setCronExpression('* * * * *')->start();

        $timer->run();

        $this->assertTrue($called);
        $this->assertTrue($timer->isRunning());
        $this->assertGreaterThan(\time(), $timer->getNextRunTimestamp());
    }

    public function testCallbackExceptionPropagatesAndTimerIsRescheduled(): void
    {
        $timer = new Timer(static function () {
            throw new TimerException('boom');
        });
        $timer->setInterval(3)->start();

        try {
            $timer->run();
            $this->fail('Expected TimerException');
        } catch (TimerException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(self::MOCK_TIME + 6, $timer->getNextRunTimestamp());
    }


}
}

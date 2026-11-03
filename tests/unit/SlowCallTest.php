<?php

use Monolog\Handler\TestHandler;
use Uhf\Monocycle\Monocycle;
use Uhf\Monocycle\Socket\UdpSocket;
use Uhf\Monocycle\Timer\Timer;

class SlowCallTest extends BaseTest
{
    private TestHandler $handler;

    protected function setUp(): void
    {
        $this->handler = $this->captureLog();
        Monocycle::setMaxCallDuration(0.05);
        $this->resetMonocycle('sockets', 'timers');
    }

    protected function tearDown(): void
    {
        Monocycle::setMaxCallDuration(0.1);
        $this->resetMonocycle('sockets', 'timers');
    }

    public function testSlowTimerCallbackIsReported(): void
    {
        $line = __LINE__ + 1;
        $timer = new Timer(function () {
            usleep(100000);
        });
        $timer->setDelay(0)->start();

        $this->tick(0.0, true);

        $record = $this->findSlowCallRecord('timer');
        $this->assertNotNull($record);
        $this->assertSame('Closure@' . __FILE__ . ':' . $line, $record['context']['callback']);
    }

    public function testFastTimerCallbackIsNotReported(): void
    {
        $timer = new Timer(function () {
        });
        $timer->setDelay(0)->start();

        $this->tick(0.0, true);

        $this->assertNull($this->findSlowCallRecord('timer'));
    }

    public function testDisabledProfilingReportsNothing(): void
    {
        Monocycle::setMaxCallDuration(0);
        $timer = new Timer(function () {
            usleep(100000);
        });
        $timer->setDelay(0)->start();

        $this->tick(0.0, true);

        $this->assertNull($this->findSlowCallRecord('timer'));
    }

    public function testSlowUdpHandlerIsReported(): void
    {
        $receiver = new UdpSocket('udp://127.0.0.1:0');
        $sender = new UdpSocket('udp://127.0.0.1:0');

        $received = false;
        $receiver->onRecvFrom = function () use (&$received) {
            usleep(100000);
            $received = true;
        };

        $sender->sendTo('payload', $receiver->getLocalAddress());

        $this->pumpUntil(function () use (&$received) {
            return $received;
        }, 2.0);

        $record = $this->findSlowCallRecord('udp onRecvFrom');
        $this->assertNotNull($record);
        $this->assertSame(UdpSocket::class, $record['context']['class']);
        $this->assertSame($receiver->getId(), $record['context']['id']);
    }

    private function findSlowCallRecord(string $what): ?array
    {
        foreach ($this->handler->getRecords() as $record) {
            if (strpos($record['message'], "Slow call: $what took") === 0) {
                return is_array($record) ? $record : $record->toArray();
            }
        }

        return null;
    }


}

<?php

require_once __DIR__ . '/../TimerMock.php';

use Uhf\Monocycle\Monocycle;
use Uhf\Monocycle\Socket\Connection;
use Uhf\Monocycle\Socket\Server;
use Uhf\Monocycle\Socket\Socket;
use function Uhf\Monocycle\Timer\setMockTime;

/**
 * Connect/read/write timeouts of Socket\Connection. Sockets are real, the
 * clock is not: Timer's time() is mocked and timers only run in advance(),
 * so each timeout is checked one second before and at its deadline.
 */
class ConnectionTimeoutTest extends BaseTest
{
    private const MOCK_TIME = 1700000000;

    private ?Server $server = null;

    /** @var Connection[] connections accepted by $server */
    private array $accepted = [];

    /** @var Connection[] client connections opened by the test */
    private array $clients = [];

    protected function setUp(): void
    {
        setMockTime(self::MOCK_TIME);
        $this->server = new Server('tcp://127.0.0.1:0');
        $this->server->onConnection = function (Server $server, Connection $client) {
            $this->accepted[] = $client;
        };
    }

    protected function tearDown(): void
    {
        foreach (array_merge($this->clients, $this->accepted) as $conn) {
            $conn->close(false);
        }
        $this->clients = [];
        $this->accepted = [];
        $this->server->close();
        $this->server = null;
        setMockTime(null);

        $this->resetMonocycle('sockets', 'timers', 'readStreams', 'readListeners', 'writeStreams', 'writeListeners');
    }

    /**
     * Sets the mocked clock to $seconds after the start of the test and runs
     * the timers due by then (no other loop iteration runs timers here).
     */
    private function advance(int $seconds): void
    {
        $now = self::MOCK_TIME + $seconds;
        setMockTime($now);
        $this->tick(0.0, true, $now);
    }

    /**
     * A client with its lifecycle closures recorded in the returned object;
     * $configure runs before connect().
     */
    private function client(?callable $configure = null): array
    {
        $client = new Connection();
        $events = (object)['errors' => [], 'connected' => false, 'disconnected' => false, 'received' => ''];
        $client->onConnectionError = function (Connection $conn, string $message) use ($events) {
            $events->errors[] = $message;
        };
        $client->onConnect = function () use ($events) {
            $events->connected = true;
        };
        $client->onDisconnect = function () use ($events) {
            $events->disconnected = true;
        };
        $client->onData = function (Connection $conn, string $data) use ($events) {
            $events->received .= $data;
        };
        if ($configure) {
            $configure($client);
        }
        $this->clients[] = $client;

        return [$client, $events];
    }

    /** Connects a client and waits until both sides are connected. */
    private function connected(?callable $configure = null): array
    {
        [$client, $events] = $this->client($configure);
        $client->connect('tcp://' . $this->server->getLocalAddress());
        $this->pumpUntil(function () use ($events) {
            return $events->connected && count($this->accepted) === 1;
        });

        return [$client, $events];
    }

    /**
     * Starts a connect that never completes: TCP connects, but the server
     * never answers the TLS ClientHello.
     */
    private function hangingConnect(?callable $configure = null): array
    {
        [$client, $events] = $this->client($configure);
        $client->connect('tcp://' . $this->server->getLocalAddress(), STREAM_CRYPTO_METHOD_TLS_CLIENT, ['verify_peer' => false]);
        for ($i = 0; $i < 5; $i++) {
            $this->tick(0.05);
        }
        $this->assertSame(Socket::STATE_CONNECTING, $client->getState());

        return [$client, $events];
    }

    /**
     * Connects a client and fills its write buffer: the server side stops
     * reading, so once the kernel buffers are full nothing more drains.
     */
    private function stuckWriter(?callable $configure = null): array
    {
        [$client, $events] = $this->connected($configure);
        Monocycle::removeReadStream($this->accepted[0]->getResource());

        $client->setWriteBufferSize(64 * 1024 * 1024);
        $client->write(str_repeat('x', 32 * 1024 * 1024));
        for ($i = 0; $i < 20; $i++) {
            $this->tick(0.05);
        }
        $this->assertFalse($client->isWriteBufferEmpty(), 'Write buffer drained, the kernel took 32MB');

        return [$client, $events];
    }

    // connect

    public function testConnectTimeoutFiresAtDeadline(): void
    {
        [$client, $events] = $this->hangingConnect(function (Connection $c) {
            $c->setConnectTimeout(5);
        });

        $this->advance(4);
        $this->assertSame([], $events->errors);

        $this->advance(5);
        $this->assertSame(['Connect timeout'], $events->errors);
        $this->assertFalse($events->disconnected);
        $this->assertSame(Socket::STATE_CLOSED, $client->getState());
        $this->assertSame(0, $this->countMonocycle('timers'));
    }

    public function testConnectTimerIsStoppedOnConnect(): void
    {
        [$client, $events] = $this->connected(function (Connection $c) {
            $c->setConnectTimeout(5);
        });
        $this->assertSame(0, $this->countMonocycle('timers'));

        $this->advance(60);
        $this->assertSame([], $events->errors);
        $this->assertSame(Socket::STATE_CONNECTED, $client->getState());
    }

    public function testZeroConnectTimeoutStopsRunningTimer(): void
    {
        [$client, $events] = $this->hangingConnect(function (Connection $c) {
            $c->setConnectTimeout(5);
        });
        $this->assertSame(1, $this->countMonocycle('timers'));

        $this->assertSame($client, $client->setConnectTimeout(0));
        $this->assertSame(0, $this->countMonocycle('timers'));

        $this->advance(60);
        $this->assertSame([], $events->errors);
        $this->assertSame(Socket::STATE_CONNECTING, $client->getState());
    }

    public function testZeroConnectTimeoutBeforeConnectDropsTimer(): void
    {
        [$client, $events] = $this->hangingConnect(function (Connection $c) {
            $c->setConnectTimeout(5)->setConnectTimeout(0);
        });
        $this->assertSame(0, $this->countMonocycle('timers'));

        $this->advance(60);
        $this->assertSame([], $events->errors);
        $this->assertSame(Socket::STATE_CONNECTING, $client->getState());
    }

    public function testConnectTimeoutSetWhileConnectingStartsTimer(): void
    {
        [$client, $events] = $this->hangingConnect();
        $this->advance(2);
        $client->setConnectTimeout(5);

        $this->advance(6);
        $this->assertSame([], $events->errors);

        $this->advance(7);
        $this->assertSame(['Connect timeout'], $events->errors);
    }

    // read

    public function testReadTimeoutDisconnectsIdleConnection(): void
    {
        [$client, $events] = $this->connected(function (Connection $c) {
            $c->setReadTimeout(5);
        });

        $this->advance(4);
        $this->assertFalse($events->disconnected);

        $this->advance(5);
        $this->assertTrue($events->disconnected);
        $this->assertSame([], $events->errors);
        $this->assertSame(Socket::STATE_CLOSED, $client->getState());
        $this->assertSame(0, $this->countMonocycle('timers'));
    }

    public function testIncomingDataResetsReadTimeout(): void
    {
        [$client, $events] = $this->connected(function (Connection $c) {
            $c->setReadTimeout(5);
        });

        $this->advance(4);
        $this->accepted[0]->write('ping');
        $this->pumpUntil(function () use ($events) {
            return $events->received === 'ping';
        });

        $this->advance(8);
        $this->assertFalse($events->disconnected, 'deadline moved to 4 + 5');

        $this->advance(9);
        $this->assertTrue($events->disconnected);
    }

    public function testOutgoingDataDoesNotResetReadTimeout(): void
    {
        [$client, $events] = $this->connected(function (Connection $c) {
            $c->setReadTimeout(5);
        });

        $this->advance(4);
        $client->write('ping');
        $this->pumpUntil(function () use ($client) {
            return $client->isWriteBufferEmpty();
        });

        $this->advance(5);
        $this->assertTrue($events->disconnected);
    }

    public function testZeroReadTimeoutStopsRunningTimer(): void
    {
        [$client, $events] = $this->connected(function (Connection $c) {
            $c->setReadTimeout(5);
        });
        $this->assertSame(1, $this->countMonocycle('timers'));

        $this->assertSame($client, $client->setReadTimeout(0));
        $this->assertSame(0, $this->countMonocycle('timers'));

        $this->advance(60);
        $this->assertFalse($events->disconnected);

        // reading without a timer still works
        $this->accepted[0]->write('ping');
        $this->pumpUntil(function () use ($events) {
            return $events->received === 'ping';
        });
        $this->assertSame(Socket::STATE_CONNECTED, $client->getState());
    }

    public function testReadTimeoutSetWhileConnectedStartsTimer(): void
    {
        [$client, $events] = $this->connected();
        $this->advance(2);
        $client->setReadTimeout(5);

        $this->advance(6);
        $this->assertFalse($events->disconnected);

        $this->advance(7);
        $this->assertTrue($events->disconnected);
    }

    public function testReadTimeoutReenabledAfterZero(): void
    {
        [$client, $events] = $this->connected(function (Connection $c) {
            $c->setReadTimeout(5);
        });
        $client->setReadTimeout(0);
        $this->advance(2);
        $client->setReadTimeout(5);
        $this->assertSame(1, $this->countMonocycle('timers'));

        $this->advance(6);
        $this->assertFalse($events->disconnected);

        $this->advance(7);
        $this->assertTrue($events->disconnected);
    }

    public function testChangingRunningReadTimeoutMovesDeadline(): void
    {
        [$client, $events] = $this->connected(function (Connection $c) {
            $c->setReadTimeout(5);
        });
        $this->advance(2);
        $client->setReadTimeout(10);
        $this->assertSame(1, $this->countMonocycle('timers'), 'the running timer is reused');

        $this->advance(11);
        $this->assertFalse($events->disconnected);

        $this->advance(12);
        $this->assertTrue($events->disconnected);
    }

    // write

    public function testWriteTimeoutDisconnectsWhenBufferCannotDrain(): void
    {
        [$client, $events] = $this->stuckWriter(function (Connection $c) {
            $c->setWriteTimeout(5);
        });

        $this->advance(4);
        $this->assertFalse($events->disconnected);

        $this->advance(5);
        $this->assertTrue($events->disconnected);
        $this->assertSame(Socket::STATE_CLOSED, $client->getState());
        $this->assertSame(0, $this->countMonocycle('timers'));
    }

    public function testWriteProgressResetsWriteTimeout(): void
    {
        [$client, $events] = $this->stuckWriter(function (Connection $c) {
            $c->setWriteTimeout(5);
        });

        // the server takes some data, so the client can flush a bit more and stalls again
        $this->advance(4);
        $peer = $this->accepted[0]->getResource();
        // fread() on a socket returns at most 8KB per call
        $taken = 0;
        while ($taken < 2 * 1024 * 1024 && ($chunk = fread($peer, 1024 * 1024)) !== '' && $chunk !== false) {
            $taken += strlen($chunk);
        }
        $this->assertGreaterThan(0, $taken);
        for ($i = 0; $i < 10; $i++) {
            $this->tick(0.05);
        }
        $this->assertFalse($client->isWriteBufferEmpty());

        $this->advance(8);
        $this->assertFalse($events->disconnected, 'deadline moved to 4 + 5');

        $this->advance(9);
        $this->assertTrue($events->disconnected);
    }

    public function testWriteTimerStopsOnDrain(): void
    {
        [$client, $events] = $this->connected(function (Connection $c) {
            $c->setWriteTimeout(5);
        });
        $this->assertSame(0, $this->countMonocycle('timers'), 'runs only while data is buffered');

        $client->write('ping');
        $this->assertSame(1, $this->countMonocycle('timers'));
        $this->pumpUntil(function () use ($client) {
            return $client->isWriteBufferEmpty();
        });
        $this->assertSame(0, $this->countMonocycle('timers'));

        $this->advance(60);
        $this->assertFalse($events->disconnected);
    }

    public function testZeroWriteTimeoutStopsRunningTimer(): void
    {
        [$client, $events] = $this->stuckWriter(function (Connection $c) {
            $c->setWriteTimeout(5);
        });
        $this->assertSame(1, $this->countMonocycle('timers'));

        $this->assertSame($client, $client->setWriteTimeout(0));
        $this->assertSame(0, $this->countMonocycle('timers'));

        $this->advance(60);
        $this->assertFalse($events->disconnected);
        $this->assertSame(Socket::STATE_CONNECTED, $client->getState());
    }

    public function testWriteTimeoutSetWhileBufferedStartsTimer(): void
    {
        [$client, $events] = $this->stuckWriter();
        $this->advance(2);
        $client->setWriteTimeout(5);

        $this->advance(6);
        $this->assertFalse($events->disconnected);

        $this->advance(7);
        $this->assertTrue($events->disconnected);
    }

    public function testTimeoutsSetOutsideTheirPhaseDoNotStart(): void
    {
        // write timeout on a connected client with nothing buffered: starts with the next write()
        [$client, $events] = $this->connected(function (Connection $c) {
            $c->setReadTimeout(0);
        });
        $client->setWriteTimeout(5)->setConnectTimeout(5);
        $this->assertSame(0, $this->countMonocycle('timers'));

        // read/write timeouts while connecting: started by onConnect()
        [$hanging] = $this->hangingConnect();
        $hanging->setReadTimeout(5)->setWriteTimeout(5);
        $this->assertSame(0, $this->countMonocycle('timers'));
    }

    public function testZeroTimeoutsWithoutTimersAreNoops(): void
    {
        $client = new Connection();
        $this->clients[] = $client;

        $this->assertSame($client, $client->setConnectTimeout(0)->setReadTimeout(0)->setWriteTimeout(0));
        $this->assertSame(0, $this->countMonocycle('timers'));
    }
}

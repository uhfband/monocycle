<?php

use Uhf\Monocycle\Monocycle;
use Uhf\Monocycle\Socket\Connection;
use Uhf\Monocycle\Socket\Server;
use Uhf\Monocycle\Socket\Socket;
use Uhf\Monocycle\Socket\SocketException;
use Uhf\Monocycle\Stream\StreamException;

class UnixSocketTest extends BaseTest
{
    /** @var string[] */
    private array $socketPaths = [];

    protected function tearDown(): void
    {
        $this->resetMonocycle('sockets');

        foreach ($this->socketPaths as $path) {
            @unlink($path);
        }
        $this->socketPaths = [];
    }

    public function testFullEventLoopAcceptsAndExchangesDataOverUnixSocket(): void
    {
        $path = $this->makeSocketPath();
        $server = new Server('unix://' . $path, Connection::class);

        $accepted = null;
        $server->onConnection = function (Server $server, Connection $client) use (&$accepted) {
            $accepted = $client;
        };

        $received = null;
        $client = new Connection();
        $client->onConnect = function (Connection $client) {
            $client->write('ping from client');
        };
        $client->onData = function (Connection $client, string $data) use (&$received) {
            $received = $data;
        };
        $client->connect('unix://' . $path);

        $this->pumpUntil(function () use (&$accepted) {
            return $accepted !== null;
        });

        $accepted->onData = function (Connection $client, string $data) use ($accepted) {
            $client->write('pong: ' . $data);
        };

        $this->pumpUntil(function () use (&$received) {
            return $received !== null;
        });

        $this->assertSame('pong: ping from client', $received);
        $this->assertTrue($client->isUnixSocket());
        $this->assertTrue($accepted->isUnixSocket());
        $this->assertSame($path, $accepted->getRemoteHost());
        $this->assertSame(0, $accepted->getRemotePort());
    }

    public function testClientIsUnixSocketWhenPortOmitted(): void
    {
        $client = new Connection();
        $client->connect('unix:///tmp/does-not-matter.sock');

        $this->assertTrue($client->isUnixSocket());
        $this->assertSame('/tmp/does-not-matter.sock', $client->getRemoteHost());
        $this->assertSame(0, $client->getRemotePort());
    }

    public function testClientIsNotUnixSocketWhenPortGiven(): void
    {
        $client = new Connection();
        $client->connect('tcp://127.0.0.1:12345');

        $this->assertFalse($client->isUnixSocket());
        $this->assertSame(12345, $client->getRemotePort());
    }

    public function testClientHasNoRemoteAddressBeforeConnect(): void
    {
        $client = new Connection();

        $this->assertFalse($client->isUnixSocket());
        $this->assertNull($client->getRemoteHost());
        $this->assertNull($client->getRemotePort());
    }

    public function testServerCleansUpStaleSocketFileOnRestart(): void
    {
        $path = $this->makeSocketPath();

        $first = new Server('unix://' . $path, Connection::class);
        // Simulate an unclean shutdown: the process exits and the socket
        // file is left behind on disk with nothing listening on it anymore.
        $first->close();

        $this->assertSame('socket', @filetype($path));

        $second = new Server('unix://' . $path, Connection::class);
        $this->assertSame(Socket::STATE_LISTENING, $second->getState());

        $accepted = null;
        $second->onConnection = function (Server $server, Connection $client) use (&$accepted) {
            $accepted = $client;
        };

        $client = new Connection();
        $client->connect('unix://' . $path);

        $this->pumpUntil(function () use (&$accepted) {
            return $accepted !== null;
        });

        $this->assertInstanceOf(Connection::class, $accepted);
    }

    public function testServerDoesNotDeleteRegularFileAtUnixPath(): void
    {
        $path = $this->makeSocketPath();
        file_put_contents($path, 'not a socket');

        try {
            $server = new Server('unix://' . $path, Connection::class);
            $this->fail('Expected SocketException was not thrown');
        } catch (SocketException $e) {
            // expected
        }

        $this->assertFileExists($path);
        $this->assertSame('not a socket', file_get_contents($path));
    }

    public function testRejectedWriteLeavesNoWriteListener(): void
    {
        $path = $this->makeSocketPath();
        $server = new Server('unix://' . $path);
        $client = new Connection();
        $client->connect('unix://' . $path);
        $this->pumpUntil(function () use ($client) {
            return $client->getState() === Socket::STATE_CONNECTED;
        });
        $client->setWriteBufferSize(10);

        try {
            $client->write(str_repeat('x', 20));
            $this->fail('Expected StreamException');
        } catch (StreamException $e) {
            $this->assertSame('Write buffer exceed limit', $e->getMessage());
        }

        $writeListeners = new ReflectionProperty(Monocycle::class, 'writeListeners');
        $writeListeners->setAccessible(true);
        $this->assertArrayNotHasKey($client->getId(), $writeListeners->getValue());
        $this->assertTrue($client->isWriteBufferEmpty());
    }

    public function testWriteTimeoutRunsOnlyWhileDataIsBuffered(): void
    {
        $path = $this->makeSocketPath();
        $server = new Server('unix://' . $path);

        $writer = (new Connection())->setWriteTimeout(5);
        $writer->onConnect = function (Connection $conn) {
            $conn->write('hello'); // used to throw: onConnect had already started the timer
        };
        $idle = (new Connection())->setWriteTimeout(5);
        $writer->connect('unix://' . $path);
        $idle->connect('unix://' . $path);

        $this->pumpUntil(function () use ($writer, $idle) {
            return $writer->getState() === Socket::STATE_CONNECTED
                && $idle->getState() === Socket::STATE_CONNECTED;
        });
        $this->assertFalse($this->writeTimeoutTimer($idle)->isRunning(), 'idle connection must not time out');

        $this->pumpUntil(function () use ($writer) {
            return $writer->isWriteBufferEmpty();
        });
        $this->assertFalse($this->writeTimeoutTimer($writer)->isRunning());

        $writer->write('again');
        $this->assertTrue($this->writeTimeoutTimer($writer)->isRunning());

        $writer->close(false);
        $idle->close(false);
    }

    public function testOnCloseClosureFiresForConnection(): void
    {
        $path = $this->makeSocketPath();
        $server = new Server('unix://' . $path);

        $closed = 0;
        $client = new Connection();
        $client->onClose = function (Connection $conn) use (&$closed) {
            $closed++;
        };
        $client->connect('unix://' . $path);
        $this->pumpUntil(function () use ($client) {
            return $client->getState() === Socket::STATE_CONNECTED;
        });

        $client->close();

        $this->assertSame(1, $closed);
    }

    public function testPeerCloseDuringGracefulCloseClosesConnection(): void
    {
        $path = $this->makeSocketPath();
        // raw peer outside the loop: accepts but never reads
        $peerServer = stream_socket_server('unix://' . $path);

        $events = [];
        $client = new Connection();
        $client->onDisconnect = function (Connection $conn) use (&$events) {
            $events[] = 'disconnect';
        };
        $client->onClose = function (Connection $conn) use (&$events) {
            $events[] = 'close';
        };
        $client->connect('unix://' . $path);
        $this->pumpUntil(function () use ($client) {
            return $client->getState() === Socket::STATE_CONNECTED;
        });
        $peer = stream_socket_accept($peerServer, 1);

        // more than the unix socket buffer holds, so the graceful close has to wait
        $client->write(str_repeat('x', 900000));
        $client->close();
        $this->pumpUntil(function () use ($client) {
            return $client->getState() === Socket::STATE_CONNECTED && !$client->isWriteBufferEmpty();
        });

        fclose($peer);
        fclose($peerServer);

        // with a graceful close in onDisconnect() it stayed DISCONNECTED and open, spinning select()
        $this->pumpUntil(function () use ($client) {
            return $client->getState() === Socket::STATE_CLOSED;
        });
        $this->assertFalse($client->isOpen());
        $this->assertSame(['disconnect', 'close'], $events);
    }

    public function testMqttClientSetsReadTimeout(): void
    {
        $client = new \Uhf\Monocycle\Proto\Mqtt\Client\Client('mqtt://127.0.0.1:1883', 'test', 30);

        $timer = new ReflectionProperty(Connection::class, 'readTimeoutTimer');
        $timer->setAccessible(true);
        $this->assertNotNull($timer->getValue($client));
    }

    private function writeTimeoutTimer(Connection $conn): \Uhf\Monocycle\Timer\Timer
    {
        $timer = new ReflectionProperty(Connection::class, 'writeTimeoutTimer');
        $timer->setAccessible(true);

        return $timer->getValue($conn);
    }

    private function makeSocketPath(): string
    {
        $path = sys_get_temp_dir() . '/monocycle_test_' . uniqid('', true) . '.sock';
        $this->socketPaths[] = $path;

        return $path;
    }


}

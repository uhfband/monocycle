<?php

use Uhf\Monocycle\Socket\Connection;
use Uhf\Monocycle\Socket\Server;
use Uhf\Monocycle\Socket\Socket;

class TcpSocketTest extends BaseTest
{
    private ?Server $server = null;

    /** @var Connection[] connections accepted by $server */
    private array $accepted = [];

    protected function setUp(): void
    {
        $this->server = new Server('tcp://127.0.0.1:0');
        $this->server->onConnection = function (Server $server, Connection $client) {
            $this->accepted[] = $client;
        };
    }

    protected function tearDown(): void
    {
        foreach ($this->accepted as $conn) {
            $conn->close(false);
        }
        $this->accepted = [];
        $this->server->close();
        $this->server = null;

        $this->resetMonocycle('sockets', 'timers', 'readStreams', 'readListeners', 'writeStreams', 'writeListeners');
    }

    public function testClientAndServerExchangeData(): void
    {
        $serverReceived = '';
        $serverConnected = false;
        $this->server->onConnection = function (Server $server, Connection $conn) use (&$serverReceived, &$serverConnected) {
            $this->accepted[] = $conn;
            $conn->onConnect = function () use (&$serverConnected) {
                $serverConnected = true;
            };
            $conn->onData = function (Connection $conn, string $data) use (&$serverReceived) {
                $serverReceived .= $data;
                $conn->write('echo: ' . $data);
            };
        };

        $clientConnected = false;
        $clientReceived = '';
        $client = new Connection();
        $client->onConnect = function (Connection $conn) use (&$clientConnected) {
            $clientConnected = true;
            $conn->write('hello');
        };
        $client->onData = function (Connection $conn, string $data) use (&$clientReceived) {
            $clientReceived .= $data;
        };
        $client->connect('tcp://' . $this->server->getLocalAddress());

        $this->assertSame(Socket::STATE_CONNECTING, $client->getState());

        $this->pumpUntil(function () use (&$clientReceived) {
            return $clientReceived === 'echo: hello';
        });

        $this->assertTrue($clientConnected);
        $this->assertTrue($serverConnected);
        $this->assertSame('hello', $serverReceived);

        $this->assertCount(1, $this->accepted);
        $accepted = $this->accepted[0];
        $this->assertSame(Socket::STATE_CONNECTED, $client->getState());
        $this->assertSame(Socket::STATE_CONNECTED, $accepted->getState());
        $this->assertFalse($client->isUnixSocket());
        $this->assertSame('127.0.0.1', $client->getRemoteHost());
        $this->assertSame((int)parse_url('tcp://' . $this->server->getLocalAddress(), PHP_URL_PORT), $client->getRemotePort());
        $this->assertSame($client->getLocalAddress(), $accepted->getRemoteAddress());
        $this->assertSame($accepted->getLocalAddress(), $client->getRemoteAddress());

        // second round trip, now initiated by the server side
        $clientReceived = '';
        $accepted->write('from server');
        $this->pumpUntil(function () use (&$clientReceived) {
            return $clientReceived === 'from server';
        });

        $client->close(false);
    }

    public function testDataWrittenBeforeConnectIsSentOnConnect(): void
    {
        $serverReceived = '';
        $this->server->onConnection = function (Server $server, Connection $conn) use (&$serverReceived) {
            $this->accepted[] = $conn;
            $conn->onData = function (Connection $conn, string $data) use (&$serverReceived) {
                $serverReceived .= $data;
            };
        };

        $client = new Connection();
        $client->connect('tcp://' . $this->server->getLocalAddress());
        $client->write('queued while connecting');

        $this->pumpUntil(function () use (&$serverReceived) {
            return $serverReceived === 'queued while connecting';
        });

        $client->close(false);
    }

    public function testLargePayloadArrivesInFullAndDrains(): void
    {
        // bigger than the kernel socket buffers, so it takes several writes and reads
        $payload = random_bytes(900 * 1024);

        $serverReceived = '';
        $this->server->onConnection = function (Server $server, Connection $conn) use (&$serverReceived) {
            $this->accepted[] = $conn;
            $conn->onData = function (Connection $conn, string $data) use (&$serverReceived) {
                $serverReceived .= $data;
            };
        };

        $drains = 0;
        $client = new Connection();
        $client->onConnect = function (Connection $conn) use ($payload) {
            $conn->write($payload);
        };
        $client->onDrain = function () use (&$drains) {
            $drains++;
        };
        $client->connect('tcp://' . $this->server->getLocalAddress());

        $this->pumpUntil(function () use (&$serverReceived, $payload) {
            return strlen($serverReceived) >= strlen($payload);
        });

        $this->assertSame(strlen($payload), strlen($serverReceived));
        $this->assertTrue($payload === $serverReceived, 'payload was corrupted in transit');
        $this->assertTrue($client->isWriteBufferEmpty());
        $this->assertSame(1, $drains);

        $client->close(false);
    }

    public function testGracefulCloseFlushesDataThenPeerSeesDisconnect(): void
    {
        $events = [];
        $this->server->onConnection = function (Server $server, Connection $conn) use (&$events) {
            $this->accepted[] = $conn;
            $conn->onData = function (Connection $conn, string $data) use (&$events) {
                $events[] = 'data:' . $data;
            };
            $conn->onDisconnect = function () use (&$events) {
                $events[] = 'disconnect';
            };
        };

        $clientDisconnected = false;
        $clientClosed = false;
        $client = new Connection();
        $client->onConnect = function (Connection $conn) {
            $conn->write('bye');
            $conn->close();
        };
        $client->onDisconnect = function () use (&$clientDisconnected) {
            $clientDisconnected = true;
        };
        $client->onClose = function () use (&$clientClosed) {
            $clientClosed = true;
        };
        $client->connect('tcp://' . $this->server->getLocalAddress());

        $this->pumpUntil(function () use (&$events) {
            return in_array('disconnect', $events, true);
        });

        $this->assertSame(['data:bye', 'disconnect'], $events);
        $this->assertSame(Socket::STATE_CLOSED, $this->accepted[0]->getState());
        $this->assertTrue($clientClosed);
        $this->assertFalse($clientDisconnected, 'a locally closed connection gets no onDisconnect');
        $this->assertSame(Socket::STATE_CLOSED, $client->getState());
    }

    public function testServerSideCloseDisconnectsClient(): void
    {
        $this->server->onConnection = function (Server $server, Connection $conn) {
            $this->accepted[] = $conn;
            $conn->onConnect = function (Connection $conn) {
                $conn->write('closing');
                $conn->close();
            };
        };

        $received = '';
        $disconnected = false;
        $client = new Connection();
        $client->onData = function (Connection $conn, string $data) use (&$received) {
            $received .= $data;
        };
        $client->onDisconnect = function () use (&$disconnected) {
            $disconnected = true;
        };
        $client->connect('tcp://' . $this->server->getLocalAddress());

        $this->pumpUntil(function () use (&$disconnected) {
            return $disconnected;
        });

        $this->assertSame('closing', $received);
        $this->assertSame(Socket::STATE_CLOSED, $client->getState());
    }

    public function testCloseInsideOnDisconnectFiresOnCloseOnce(): void
    {
        $this->server->onConnection = function (Server $server, Connection $conn) {
            $this->accepted[] = $conn;
            $conn->onConnect = function (Connection $conn) {
                $conn->close();
            };
        };

        $closes = 0;
        $client = new Connection();
        // onDisconnect() closes the connection again right after this closure
        $client->onDisconnect = function (Connection $conn) {
            $conn->close();
        };
        $client->onClose = function () use (&$closes) {
            $closes++;
        };
        $client->connect('tcp://' . $this->server->getLocalAddress());

        $this->pumpUntil(function () use (&$closes) {
            return $closes > 0;
        });
        $client->close();

        $this->assertSame(1, $closes);
        $this->assertSame(Socket::STATE_CLOSED, $client->getState());
    }

    public function testConnectionRefusedFiresConnectionError(): void
    {
        // grab a free port, then free it again so nothing listens there
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($probe, false);
        fclose($probe);

        $client = new Connection();
        $events = $this->recordConnectionEvents($client);
        $client->connect('tcp://' . $address);

        $this->pumpUntil(function () use ($events) {
            return $events->errors !== [];
        });

        $this->assertConnectionFailedWith('Connection refused', $client, $events);
    }

    public function testInvalidAddressFailsInsideConnect(): void
    {
        $client = new Connection();
        $events = $this->recordConnectionEvents($client);
        $client->connect('nosuchscheme://127.0.0.1:1');

        // no loop iteration needed: stream_socket_client() fails synchronously
        $this->assertCount(1, $events->errors);
        $this->assertNotSame('', $events->errors[0]);
        $this->assertFalse($events->connected);
        $this->assertFalse($events->disconnected);
        $this->assertSame(Socket::STATE_CLOSED, $client->getState());
        $this->assertSame(1, $this->countMonocycle('sockets'), 'only the server stays registered');
    }

    public function testConnectTimeoutFiresConnectionError(): void
    {
        // TCP connects, but the server never answers the TLS ClientHello,
        // so the connection stays in CONNECTING until the timer fires
        $client = (new Connection())->setConnectTimeout(1);
        $events = $this->recordConnectionEvents($client);
        $client->connect('tcp://' . $this->server->getLocalAddress(), STREAM_CRYPTO_METHOD_TLS_CLIENT, ['verify_peer' => false]);

        $this->pumpUntil(function () use ($events) {
            return $events->errors !== [];
        }, 5.0, true);

        $this->assertConnectionFailedWith('Connect timeout', $client, $events);
        $this->assertSame(0, $this->countMonocycle('timers'), 'connect timer is stopped and dropped');
    }

    public function testTlsHandshakeFailureFiresConnectionError(): void
    {
        $this->server->onConnection = function (Server $server, Connection $conn) {
            $this->accepted[] = $conn;
            $conn->onConnect = function (Connection $conn) {
                $conn->write("HTTP/1.1 400 Bad Request\r\n\r\nthis is not TLS");
            };
        };

        $client = new Connection();
        $events = $this->recordConnectionEvents($client);
        $client->connect('tcp://' . $this->server->getLocalAddress(), STREAM_CRYPTO_METHOD_TLS_CLIENT, ['verify_peer' => false]);

        $this->pumpUntil(function () use ($events) {
            return $events->errors !== [];
        });

        $this->assertConnectionFailedWith('TLS handshake failed', $client, $events);
    }

    /**
     * Hooks the connection's lifecycle closures and records what fired.
     */
    private function recordConnectionEvents(Connection $client): stdClass
    {
        $events = (object)['errors' => [], 'connected' => false, 'disconnected' => false, 'closed' => false];
        $client->onConnectionError = function (Connection $conn, string $message) use ($events) {
            $events->errors[] = $message;
        };
        $client->onConnect = function () use ($events) {
            $events->connected = true;
        };
        $client->onDisconnect = function () use ($events) {
            $events->disconnected = true;
        };
        $client->onClose = function () use ($events) {
            $events->closed = true;
        };

        return $events;
    }

    private function assertConnectionFailedWith(string $message, Connection $client, stdClass $events): void
    {
        // a couple more ticks, to be sure nothing fires twice or late
        $this->tick(0.1, true);
        $this->tick(0.1, true);

        $this->assertSame([$message], $events->errors);
        $this->assertFalse($events->connected, 'onConnect must not fire');
        $this->assertFalse($events->disconnected, 'onDisconnect is only for established connections');
        $this->assertTrue($events->closed);
        $this->assertSame(Socket::STATE_CLOSED, $client->getState());
        $this->assertNull($client->getLocalAddress(), 'stream is closed');
        $this->assertSame(1, $this->countMonocycle('sockets'), 'only the server stays registered');
    }
}

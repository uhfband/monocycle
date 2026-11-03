<?php

use Uhf\Monocycle\Socket\Connection;
use Uhf\Monocycle\Socket\Server;

/**
 * A rejected connection is accepted and closed at once by the server, so the
 * test sees it from the client side: it connects, then gets onDisconnect.
 */
class ServerConnectionLimitTest extends BaseTest
{
    private ?Server $server = null;

    /** @var Connection[] connections accepted by $server */
    private array $accepted = [];

    /** @var Connection[] client connections opened by the test */
    private array $clients = [];

    /** @var ArrayObject[] per client: whether it connected and was disconnected */
    private array $states = [];

    protected function setUp(): void
    {
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
        $this->states = [];
        $this->server->close();
        $this->server = null;

        $this->resetMonocycle('sockets', 'timers', 'readStreams', 'readListeners', 'writeStreams', 'writeListeners');
    }

    private function connect(): Connection
    {
        $state = new ArrayObject(['connected' => false, 'disconnected' => false]);

        $client = new Connection();
        $client->onConnect = function () use ($state) {
            $state['connected'] = true;
        };
        $client->onDisconnect = function () use ($state) {
            $state['disconnected'] = true;
        };
        $client->onConnectionError = function (Connection $conn, string $message) {
            $this->fail("Connection error: $message");
        };
        $client->connect('tcp://' . $this->server->getLocalAddress());
        $this->clients[] = $client;
        $this->states[] = $state;

        return $client;
    }

    /**
     * Number of clients the server closed on them, i.e. rejected
     */
    private function countDisconnected(): int
    {
        return count(array_filter($this->states, function (ArrayObject $s) {
            return $s['disconnected'];
        }));
    }

    public function testUnlimitedByDefault(): void
    {
        $this->assertSame(0, $this->server->getMaxConnections());

        for ($i = 0; $i < 5; $i++) {
            $this->connect();
        }

        $this->pumpUntil(function () {
            return count($this->accepted) === 5;
        });
        $this->assertSame(5, $this->server->getConnectionCount());

        // no client gets closed: tick a little longer to give a wrong close time to arrive
        $this->tick(0.1);
        $this->assertSame(0, $this->countDisconnected());
    }

    public function testConnectionsOverLimitAreRejected(): void
    {
        $log = $this->captureLog();
        $this->assertSame($this->server, $this->server->setMaxConnections(2));

        for ($i = 0; $i < 3; $i++) {
            $this->connect();
        }

        $this->pumpUntil(function () {
            return count($this->accepted) === 2 && $this->countDisconnected() === 1;
        });
        $this->assertSame(2, $this->server->getConnectionCount());

        // every client connected (the kernel completes the handshake before accept),
        // the two accepted ones are still up
        foreach ($this->states as $state) {
            $this->assertTrue($state['connected']);
        }
        foreach ($this->accepted as $conn) {
            $this->assertTrue($conn->isOpen());
        }

        // a few more ticks: nothing else is accepted or closed
        $this->tick(0.1);
        $this->assertCount(2, $this->accepted);
        $this->assertSame(1, $this->countDisconnected());
        $this->assertTrue($log->hasWarningThatContains('Connection limit reached (2)'));
    }

    public function testClosedConnectionFreesASlot(): void
    {
        $log = $this->captureLog();
        $this->server->setMaxConnections(1);

        $this->connect();
        $this->pumpUntil(function () {
            return count($this->accepted) === 1;
        });

        $this->connect();
        $this->pumpUntil(function () {
            return $this->states[1]['disconnected'];
        });
        $this->assertCount(1, $this->accepted);
        $this->assertTrue($log->hasWarningThatContains('Connection limit reached (1)'));

        // the peer going away closes the accepted connection and frees the slot
        $this->clients[0]->close(false);
        $this->pumpUntil(function () {
            return !$this->accepted[0]->isOpen();
        });
        $this->assertSame(0, $this->server->getConnectionCount());

        $this->connect();
        $this->pumpUntil(function () {
            return count($this->accepted) === 2;
        });
        $this->assertSame(1, $this->server->getConnectionCount());
        $this->tick(0.1);
        $this->assertFalse($this->states[2]['disconnected']);
    }

    public function testNegativeLimitIsInvalid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->server->setMaxConnections(-1);
    }
}

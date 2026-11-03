<?php

use Uhf\Monocycle\Database\Protocol;
use Uhf\Monocycle\Database\Server;
use Uhf\Monocycle\Database\SQLite\Driver;
use Uhf\Monocycle\Monocycle;
use Uhf\Monocycle\Socket\Connection;

/**
 * Runs Database\Server with the SQLite driver in-process on a unix socket
 * and talks to it over a raw Socket\Connection with Protocol packets.
 */
class DatabaseServerTest extends BaseTest
{
    private string $dir;
    private string $socketPath;
    private Server $server;
    private Connection $client;
    /** the server's side of $client */
    private ?Connection $accepted = null;
    private Protocol $protocol;

    /** @var array<int, array> responses by query_id */
    private array $responses = [];
    private int $nextQueryId = 1;

    protected function setUp(): void
    {
        if (!extension_loaded('sqlite3')) {
            $this->markTestSkipped('sqlite3 extension is not installed');
        }

        $this->dir = sys_get_temp_dir() . '/monocycle_dbserver_' . getmypid() . '_' . uniqid();
        mkdir($this->dir);
        $this->socketPath = $this->dir . '/db.sock';

        $initScript = $this->dir . '/init.sql';
        file_put_contents($initScript, "
            CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, age INTEGER);
        ");

        $this->server = new Server('unix://' . $this->socketPath, new Driver($this->dir . '/test.db', $initScript));
        $this->server->onConnection = function ($server, Connection $client) {
            $this->accepted = $client;
        };

        $this->protocol = new Protocol();
        $this->client = new Connection();
        $this->client->onData = function (Connection $client, string $data) {
            $this->protocol->feed($data);
            while (($packet = $this->protocol->nextPacket()) !== null) {
                $this->responses[$packet[Protocol::FIELD_QUERY_ID]] = $packet;
            }
        };
        $this->client->connect('unix://' . $this->socketPath);

        $this->pumpUntil(function () {
            return $this->client->getState() === Connection::STATE_CONNECTED && $this->accepted !== null;
        });
    }

    protected function tearDown(): void
    {
        if (isset($this->server)) {
            $this->client->close(false);
            $this->server->close();
        }

        $this->resetMonocycle('sockets', 'timers', 'readStreams', 'readListeners', 'writeStreams', 'writeListeners');

        if (isset($this->dir)) {
            foreach (glob($this->dir . '/*') as $file) {
                unlink($file);
            }
            rmdir($this->dir);
        }
    }

    /**
     * Sends one request and returns its decoded response
     */
    private function query(string $sql): array
    {
        $queryId = $this->nextQueryId++;
        $this->client->write(Protocol::request($queryId, $sql));

        $this->pumpUntil(function () use ($queryId) {
            return isset($this->responses[$queryId]);
        });

        return $this->responses[$queryId];
    }

    private function insertUsers(): void
    {
        foreach ([['Alice', 30], ['Bob', 25], ['Carol', 41]] as [$name, $age]) {
            $response = $this->query("INSERT INTO users (name, age) VALUES ('$name', $age)");
            $this->assertArrayNotHasKey(Protocol::FIELD_ERROR, $response);
        }
    }

    public function testInsertRow(): void
    {
        $response = $this->query("INSERT INTO users (name, age) VALUES ('Alice', 30)");

        $this->assertSame([
            Protocol::FIELD_QUERY_ID => 1,
            Protocol::FIELD_RESULT => [],
            Protocol::FIELD_AFFECTED_ROWS => 1,
            Protocol::FIELD_LAST_INSERT_ROW_ID => 1,
        ], $response);

        $response = $this->query("INSERT INTO users (name, age) VALUES ('Bob', 25)");
        $this->assertSame(1, $response[Protocol::FIELD_AFFECTED_ROWS]);
        $this->assertSame(2, $response[Protocol::FIELD_LAST_INSERT_ROW_ID]);

        $response = $this->query('SELECT COUNT(*) AS cnt FROM users');
        $this->assertSame([['cnt' => 2]], $response[Protocol::FIELD_RESULT]);
    }

    public function testSelectOneRow(): void
    {
        $this->insertUsers();

        $response = $this->query("SELECT id, name, age FROM users WHERE name = 'Bob'");

        $this->assertSame([['id' => 2, 'name' => 'Bob', 'age' => 25]], $response[Protocol::FIELD_RESULT]);
        $this->assertArrayNotHasKey(Protocol::FIELD_AFFECTED_ROWS, $response);
        $this->assertArrayNotHasKey(Protocol::FIELD_LAST_INSERT_ROW_ID, $response);
    }

    public function testSelectManyRows(): void
    {
        $this->insertUsers();

        $response = $this->query('SELECT id, name, age FROM users ORDER BY id');

        $this->assertSame([
            ['id' => 1, 'name' => 'Alice', 'age' => 30],
            ['id' => 2, 'name' => 'Bob', 'age' => 25],
            ['id' => 3, 'name' => 'Carol', 'age' => 41],
        ], $response[Protocol::FIELD_RESULT]);
    }

    public function testSelectNoRows(): void
    {
        $response = $this->query('SELECT * FROM users');

        $this->assertSame([], $response[Protocol::FIELD_RESULT]);
    }

    public function testDeleteRow(): void
    {
        $this->insertUsers();

        $response = $this->query("DELETE FROM users WHERE name = 'Bob'");
        $this->assertSame(1, $response[Protocol::FIELD_AFFECTED_ROWS]);
        $this->assertSame([], $response[Protocol::FIELD_RESULT]);
        $this->assertArrayNotHasKey(Protocol::FIELD_LAST_INSERT_ROW_ID, $response);

        $response = $this->query('SELECT name FROM users ORDER BY id');
        $this->assertSame([['name' => 'Alice'], ['name' => 'Carol']], $response[Protocol::FIELD_RESULT]);

        $response = $this->query("DELETE FROM users WHERE name = 'Nobody'");
        $this->assertSame(0, $response[Protocol::FIELD_AFFECTED_ROWS]);
    }

    public function testErrorQuery(): void
    {
        $response = $this->query('SELECT * FROM missing_table');
        $this->assertSame(1, $response[Protocol::FIELD_QUERY_ID]);
        $this->assertArrayNotHasKey(Protocol::FIELD_RESULT, $response);
        $this->assertStringContainsString('no such table: missing_table', $response[Protocol::FIELD_ERROR]);

        $response = $this->query('INSERT INTO users (age) VALUES (1)');
        $this->assertStringContainsString('NOT NULL', $response[Protocol::FIELD_ERROR]);

        $response = $this->query('   ');
        $this->assertSame("Field 'sql' must be a non-empty string", $response[Protocol::FIELD_ERROR]);

        // the connection keeps working after errors
        $response = $this->query('SELECT COUNT(*) AS cnt FROM users');
        $this->assertSame([['cnt' => 0]], $response[Protocol::FIELD_RESULT]);
    }

    public function testCloseRemovesSocketFile(): void
    {
        $this->assertSame('socket', filetype($this->socketPath));

        $this->server->close();

        $this->assertFileDoesNotExist($this->socketPath);
    }

    public function testResultLargerThanDefaultWriteBuffer(): void
    {
        // ~1.4MB of JSON: over the 1MiB stream default, which used to kill the worker
        $response = $this->query('SELECT hex(randomblob(700000)) AS blob');

        $this->assertArrayNotHasKey(Protocol::FIELD_ERROR, $response);
        $this->assertSame(1400000, strlen($response[Protocol::FIELD_RESULT][0]['blob']));
    }

    public function testResultOverPacketLimitFailsOnlyThatQuery(): void
    {
        $response = $this->query('SELECT hex(randomblob(' . (Protocol::MAX_PACKET_SIZE / 2 + 1000) . ')) AS blob');

        $this->assertStringStartsWith('Result too large', $response[Protocol::FIELD_ERROR] ?? '');
        $this->assertArrayNotHasKey(Protocol::FIELD_RESULT, $response);

        $response = $this->query('SELECT 1 AS one');
        $this->assertSame([['one' => 1]], $response[Protocol::FIELD_RESULT]);
    }

    public function testResultThatDoesNotFitInWriteBufferFailsOnlyThatQuery(): void
    {
        // room for one ~1.2KB result, not two; the requests arrive together,
        // so all three responses are queued before anything is flushed
        $log = $this->captureLog();
        $this->accepted->setWriteBufferSize(2000);
        $sql = 'SELECT hex(randomblob(600)) AS blob';
        $this->client->write(Protocol::request(1, $sql) . Protocol::request(2, $sql) . Protocol::request(3, $sql));
        $this->nextQueryId = 4;

        $this->pumpUntil(function () {
            return count($this->responses) === 3;
        });

        $this->assertSame(1200, strlen($this->responses[1][Protocol::FIELD_RESULT][0]['blob']));
        foreach ([2, 3] as $id) {
            $this->assertSame('Result does not fit in the write buffer', $this->responses[$id][Protocol::FIELD_ERROR] ?? null);
        }

        $this->assertCount(2, array_filter($log->getRecords(), function ($record) {
            return $record['message'] === 'Database response does not fit in the write buffer';
        }));

        // the buffer has drained, so the next one fits again
        $response = $this->query($sql);
        $this->assertSame(1200, strlen($response[Protocol::FIELD_RESULT][0]['blob']));
    }

    public function testClientIsDroppedWhenNotEvenAnErrorFits(): void
    {
        $disconnected = false;
        $this->client->onDisconnect = function () use (&$disconnected) {
            $disconnected = true;
        };
        $log = $this->captureLog();
        $this->accepted->setWriteBufferSize(10);

        $this->client->write(Protocol::request(1, 'SELECT 1 AS one'));
        $this->pumpUntil(function () use (&$disconnected) {
            return $disconnected;
        });

        $this->assertSame([], $this->responses);
        $this->assertTrue($log->hasWarningThatContains('does not fit in the write buffer'));
        $this->assertFileExists($this->socketPath, 'the server itself keeps running');
    }
}

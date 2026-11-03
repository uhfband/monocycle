<?php

namespace Uhf\Monocycle\Database;

use Uhf\Monocycle\Monocycle;
use Uhf\Monocycle\Process\Process;
use Uhf\Monocycle\Process\ProcessException;
use Uhf\Monocycle\Socket\Connection;
use Uhf\Monocycle\Timer\Timer;

/**
 * Asynchronous client of a database worker process (bin/db_worker_*).
 * Use the engine-specific subclasses' open() to start a worker and get a client for it.
 *
 * Queries are sent over the worker's unix socket using the Protocol packet
 * format and answered out of band: the callback passed to query() is called
 * from the event loop once the response arrives, the query times out, or the
 * connection/worker goes away.
 *
 * Callback signature:
 *   function (?array $rows, ?string $error, int $returnCode)
 * $rows is null when $error is set; $returnCode is one of the RETURN_CODE_* constants.
 */
abstract class Database
{
    public const RETURN_CODE_SUCCESS = 0;
    public const RETURN_CODE_ERROR = 1;
    public const RETURN_CODE_DATABASE_CLOSED = 2;
    public const RETURN_CODE_TIMEOUT = 3;

    private int $id = 1;

    /** @var array<int, array{0: string, 1: callable, 2: int}> pending queries: [sql, callback, deadline] */
    private array $queries = [];

    /** @var array<int, true> ids of pending queries already written to the worker */
    private array $sent = [];

    private int $timeout = 10;
    private int $workerPid;
    private string $socketPath;

    private ?Connection $client = null;
    private Protocol $protocol;
    private Timer $timer;
    private bool $connected = false;
    private bool $closed = false;

    public function __construct(int $pid, string $socketPath, $timeout = 10)
    {
        $this->workerPid = $pid;
        $this->socketPath = $socketPath;
        $this->timeout = $timeout;
        $this->protocol = new Protocol();

        $process = Monocycle::getProcess($pid);
        if ($process) {
            $onExit = $process->onExit;
            $process->onExit = function (Process $process, int $code) use ($onExit) {
                if (is_callable($onExit)) {
                    call_user_func($onExit, $process, $code);
                }
                $this->onWorkerTerminate($code);
            };
        }

        // the worker needs some time to start listening, so connecting is
        // retried on every tick; the same tick expires timed out queries
        $this->timer = new Timer(function () {
            $this->expireQueries();
            if (!$this->closed && $this->client === null) {
                $this->connect();
            }
        });
        $this->timer->setInterval(1)->start();

        $this->connect();
    }

    /**
     * Spawns a bin/<worker> process listening on a temp unix socket and returns a client for it
     *
     * @param string[] $args worker arguments following the socket path, empty ones are dropped
     * @param array|null $env worker environment, null to inherit the current one
     * @return static
     * @throws ProcessException
     */
    protected static function spawn(string $worker, array $args, int $timeout, ?array $env = null): self
    {
        // tempnam() only reserves a unique name; the regular file it creates
        // would make the worker's bind() fail, so it's removed right away
        $socketName = tempnam(sys_get_temp_dir(), 'db_worker_');
        @unlink($socketName);

        $process = new Process(
            array_merge(
                [PHP_BINARY, __DIR__ . '/../../bin/' . $worker, $socketName],
                array_filter($args)
            ),
            null,
            null,
            [],
            $env
        );
        $process->start();

        return new static($process->getPid(), $socketName, $timeout);
    }

    /**
     * @param string $query
     * @param callable $callback function (?array $rows, ?string $error, int $returnCode)
     * @param int|null $timeout seconds, defaults to the database timeout
     * @return int query id
     */
    public function query(string $query, $callback, ?int $timeout = null): int
    {
        $id = $this->id++;

        if ($this->closed) {
            call_user_func($callback, null, 'Database is closed', self::RETURN_CODE_DATABASE_CLOSED);
            return $id;
        }

        $this->queries[$id] = [$query, $callback, time() + ($timeout ?? $this->timeout)];

        if ($this->connected) {
            $this->send($id);
        }

        return $id;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function close()
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->timer->stop();

        if ($this->client !== null) {
            $client = $this->client;
            $this->client = null;
            $this->connected = false;
            $client->close();
        }

        $this->failAll('Database is closed', self::RETURN_CODE_DATABASE_CLOSED);

        if (!empty($this->workerPid)) {
            $process = Monocycle::getProcess($this->workerPid);
            if ($process) {
                $process->terminate();
            }
        }
    }

    private function connect(): void
    {
        // the worker binds the socket asynchronously, don't bother until it's there
        if (@filetype($this->socketPath) !== 'socket') {
            return;
        }

        $client = new Connection();
        $this->client = $client;

        $client->onConnect = function () use ($client) {
            if ($client !== $this->client) {
                return;
            }
            $this->connected = true;
            foreach (array_keys($this->queries) as $id) {
                $this->send($id);
            }
        };
        $client->onConnectionError = function (Connection $c, string $message) use ($client) {
            Monocycle::getLogger()->debug("Database worker connect error: $message");
            if ($client === $this->client) {
                // retried by the timer
                $this->client = null;
            }
        };
        $client->onDisconnect = function () use ($client) {
            if ($client === $this->client) {
                $this->onConnectionLost('Connection to database worker lost');
            }
        };
        $client->onData = function (Connection $c, string $data) use ($client) {
            if ($client === $this->client) {
                $this->onData($data);
            }
        };

        $client->connect('unix://' . $this->socketPath);
    }

    private function send(int $id): void
    {
        if (isset($this->sent[$id]) || !isset($this->queries[$id])) {
            return;
        }
        $this->sent[$id] = true;
        $this->client->write(Protocol::request($id, $this->queries[$id][0]));
    }

    protected function onData(string $data): void
    {
        $this->protocol->feed($data);

        while (!$this->closed) {
            try {
                $packet = $this->protocol->nextPacket();
            } catch (ProtocolException $e) {
                // either the framing is broken or a payload is garbage; in both
                // cases responses can no longer be matched reliably
                Monocycle::getLogger()->warning('Database protocol error: ' . $e->getMessage());
                $client = $this->client;
                $this->onConnectionLost('Database protocol error: ' . $e->getMessage());
                $client->close();
                return;
            }

            if ($packet === null) {
                return;
            }

            $id = $packet[Protocol::FIELD_QUERY_ID] ?? null;
            if (!is_int($id) || !isset($this->queries[$id])) {
                // response to an already timed out query, or a request the worker couldn't parse
                if (isset($packet[Protocol::FIELD_ERROR]) && !is_int($id)) {
                    Monocycle::getLogger()->warning('Database worker error: ' . $packet[Protocol::FIELD_ERROR]);
                }
                continue;
            }

            if (isset($packet[Protocol::FIELD_ERROR])) {
                $this->complete($id, null, (string)$packet[Protocol::FIELD_ERROR]);
            } else {
                $this->complete($id, (array)($packet[Protocol::FIELD_RESULT] ?? []), null);
            }
        }
    }

    /**
     * Queries already written to a lost connection may or may not have been
     * executed, so they fail; the ones not sent yet wait for a reconnect
     */
    protected function onConnectionLost(string $error): void
    {
        $this->client = null;
        $this->connected = false;
        $this->protocol = new Protocol();

        foreach (array_keys($this->sent) as $id) {
            $this->complete($id, null, $error);
        }
    }

    protected function onWorkerTerminate(int $code): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->timer->stop();

        if ($this->client !== null) {
            $client = $this->client;
            $this->client = null;
            $this->connected = false;
            $client->close();
        }

        $this->failAll("Database worker exited with code $code", self::RETURN_CODE_DATABASE_CLOSED);
    }

    private function expireQueries(): void
    {
        $now = time();
        foreach ($this->queries as $id => [, , $deadline]) {
            if ($deadline <= $now) {
                $this->complete($id, null, 'Query timed out', self::RETURN_CODE_TIMEOUT);
            }
        }
    }

    private function failAll(string $error, int $returnCode = self::RETURN_CODE_ERROR): void
    {
        foreach (array_keys($this->queries) as $id) {
            $this->complete($id, null, $error, $returnCode);
        }
    }

    private function complete(int $id, ?array $rows, ?string $error, ?int $returnCode = null): void
    {
        if (!isset($this->queries[$id])) {
            return;
        }
        $callback = $this->queries[$id][1];
        unset($this->queries[$id], $this->sent[$id]);

        if ($returnCode === null) {
            $returnCode = $error === null ? self::RETURN_CODE_SUCCESS : self::RETURN_CODE_ERROR;
        }

        call_user_func($callback, $rows, $error, $returnCode);
    }
}

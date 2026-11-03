<?php

namespace Uhf\Monocycle\Socket;

use Uhf\Monocycle\Monocycle;
use WeakReference;

class Server extends Socket
{
    /** @var callable */
    public $onConnection;

    private string $address;
    private string $connectionClass;

    /** 0 = unlimited */
    private int $maxConnections = 0;

    /**
     * Open accepted connections, by id. Weak references, so the server does not
     * keep a connection object alive; closed or freed entries are pruned on use.
     *
     * @var WeakReference[]
     */
    private array $connections = [];

    /**
     * @param string $address
     * @param string $connectionClass
     */
    public function __construct(string $address, string $connectionClass = Connection::class)
    {
        $this->address = $address;
        $this->connectionClass = $connectionClass;

        // parse_url() spuriously returns false for the "scheme:///path"
        // triple-slash form used by unix domain socket addresses (same
        // quirk documented in UdpSocket for udp://host:0), so the scheme
        // is checked directly instead.
        if (strncmp($this->address, 'unix://', 7) === 0) {
            $path = substr($this->address, 7);
            // A stale socket file left behind by a previous, uncleanly
            // stopped process would otherwise make the bind below fail
            // with "Address already in use".
            if ($path !== '' && @filetype($path) === 'socket') {
                @unlink($path);
            }
        }

        $flags = (parse_url($this->address, PHP_URL_SCHEME) === 'udp') ?
            STREAM_SERVER_BIND : STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
        $socket = @stream_socket_server($this->address, $errNo, $errStr, $flags);

        if (!$socket) {
            throw new SocketException($errStr, $errNo);
        }
        parent::__construct($socket, self::STATE_LISTENING);

        Monocycle::addSocket($this);
        Monocycle::addReadStream($socket, function() {
            $this->onReadable();
        });
    }

    public function getAddress(): string
    {
        return $this->address;
    }

    /**
     * Limits the number of simultaneously open accepted connections; 0 = unlimited.
     * Connections accepted beyond the limit are closed at once, without creating a
     * connection object, and a warning is logged. Lowering the limit does not close
     * connections that are already open.
     */
    public function setMaxConnections(int $maxConnections): self
    {
        if ($maxConnections < 0) {
            throw new \InvalidArgumentException('maxConnections must be >= 0');
        }
        $this->maxConnections = $maxConnections;

        return $this;
    }

    public function getMaxConnections(): int
    {
        return $this->maxConnections;
    }

    /**
     * Number of accepted connections that are still open (a connection closing
     * gracefully counts until its write buffer has drained and it is closed).
     */
    public function getConnectionCount(): int
    {
        foreach ($this->connections as $id => $ref) {
            $conn = $ref->get();
            if ($conn === null || !$conn->isOpen()) {
                unset($this->connections[$id]);
            }
        }

        return count($this->connections);
    }

    protected function onConnection(Connection $client)
    {
        if (is_callable($this->onConnection)) {
            call_user_func($this->onConnection, $this, $client);
        }
    }

    protected function onReadable()
    {
        try {
            $socket = $this->accept();
        } catch (SocketException $e) {
            Monocycle::getLogger()->warning('SocketException: ' . $e->getMessage());
            return;
        }

        if ($this->maxConnections > 0 && $this->getConnectionCount() >= $this->maxConnections) {
            Monocycle::getLogger()->warning(
                "Connection limit reached ({$this->maxConnections}) on {$this->address}, rejecting connection"
            );
            fclose($socket);
            return;
        }

        $client = new $this->connectionClass($socket);
        $this->connections[$client->getId()] = WeakReference::create($client);
        $this->onConnection($client);
        if ($client->isOpen()) {
            $client->setState(self::STATE_CONNECTED);
        }
    }
}
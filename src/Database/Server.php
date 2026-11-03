<?php

namespace Uhf\Monocycle\Database;

use Uhf\Monocycle\Socket\Connection;
use Uhf\Monocycle\Monocycle;
use Uhf\Monocycle\Socket\Server as SocketServer;
use Uhf\Monocycle\Stream\StreamException;

class Server extends SocketServer
{
    /**
     * Write buffer limit of client connections: results are built in memory
     * anyway, so allow a few maximum-size packets queued behind each other.
     */
    public const WRITE_BUFFER_SIZE = 4 * (Protocol::HEADER_SIZE + Protocol::MAX_PACKET_SIZE);

    private Driver $driver;

    public function __construct(string $address, Driver $driver, string $connectionClass = Connection::class)
    {
        parent::__construct($address, $connectionClass);
        $this->driver = $driver;
    }

    protected function onConnection(Connection $client)
    {
        // before the $onConnection closure, which may still change it
        $client->setWriteBufferSize(self::WRITE_BUFFER_SIZE);
        parent::onConnection($client);

        $protocol = new Protocol();

        $client->onData = function (Connection $client, string $data) use ($protocol) {
            $protocol->feed($data);

            while (true) {
                try {
                    $payload = $protocol->nextPayload();
                } catch (ProtocolException $e) {
                    // framing is broken, the rest of the stream can't be parsed
                    Monocycle::getLogger()->warning('Protocol error: ' . $e->getMessage());
                    $client->setState(Connection::STATE_DISCONNECTED, $e->getMessage());
                    return;
                }

                if ($payload === null) {
                    return;
                }

                try {
                    $request = Protocol::decode($payload);
                } catch (ProtocolException $e) {
                    if (!$this->respond($client, null, Protocol::errorResponse(null, $e->getMessage()))) {
                        return;
                    }
                    continue;
                }

                $queryId = $request[Protocol::FIELD_QUERY_ID] ?? null;
                if (!$this->respond($client, $queryId, $this->driver->executeRequest($request))) {
                    return;
                }
            }
        };
    }

    /**
     * Queues a response packet. One the client can't accept (over the packet
     * size limit) or that doesn't fit in the write buffer is replaced by an
     * error response, so only that query fails.
     *
     * @param int|string|null $queryId
     * @return bool false if not even the error fitted and the client was dropped
     */
    private function respond(Connection $client, $queryId, string $packet): bool
    {
        $length = strlen($packet) - Protocol::HEADER_SIZE;
        if ($length > Protocol::MAX_PACKET_SIZE) {
            $packet = Protocol::errorResponse(
                $queryId,
                sprintf('Result too large: %d bytes, the limit is %d', $length, Protocol::MAX_PACKET_SIZE)
            );
        }

        try {
            $client->write($packet);

            return true;
        } catch (StreamException $e) {
            Monocycle::getLogger()->warning('Database response does not fit in the write buffer', ['bytes' => strlen($packet)]);
        }

        try {
            $client->write(Protocol::errorResponse($queryId, 'Result does not fit in the write buffer'));

            return true;
        } catch (StreamException $e) {
            $client->setState(Connection::STATE_DISCONNECTED, 'Write buffer full');

            return false;
        }
    }

    protected function onClose()
    {
        $this->driver->close();

        $address = $this->getAddress();
        if (strncmp($address, 'unix://', 7) === 0) {
            $path = substr($address, 7);
            if ($path !== '' && @filetype($path) === 'socket') {
                @unlink($path);
            }
        }

        parent::onClose();
    }
}

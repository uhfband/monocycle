<?php

namespace Uhf\Monocycle\Socket;

use Uhf\Monocycle\Monocycle;
use Uhf\Monocycle\Stream\Stream;

abstract class Socket extends Stream
{
    public const STATE_CONNECTING    = 1;
    public const STATE_CONNECTED     = 2;
    public const STATE_DISCONNECTED  = 3;
    public const STATE_CLOSED        = 4;
    public const STATE_LISTENING     = 5;

    protected int $state = 0;

    /**
     * @param ?int $length
     * @return string
     * @throws SocketException
     */
    public function read(?int $length = null): string
    {
        $res = '';

        while (true) {
            $chunk = @fread($this->getResource(), $length ?? $this->readChunkLength);

            if ($chunk === false) {
                throw new SocketException(error_get_last()['message'] ?? 'Socket::read() failure');
            }

            if ($chunk === '') {
                break;
            }

            $res .= $chunk;
        }

        if ($res === '' && feof($this->getResource())) {
            throw new SocketException('Connection closed');
        }

        return $res;
    }

    /**
     * @return resource
     * @throws SocketException
     */
    public function accept()
    {
        $res = @stream_socket_accept($this->getResource());
        if ($res === false) {
            throw new SocketException(error_get_last()['message'] ?? 'Socket::accept() failure');
        }

        return $res;
    }

    public function close(): void
    {
        $this->setState(self::STATE_CLOSED);
        Monocycle::removeSocket($this);
        parent::close();
    }

    /**
     * @return string|null "host:port" (or socket path) of the local end, null if unknown
     */
    public function getLocalAddress(): ?string
    {
        return $this->getName(false);
    }

    /**
     * @return string|null "host:port" of the peer, null if not connected
     * or unavailable (e.g. listening sockets, unix domain sockets)
     */
    public function getRemoteAddress(): ?string
    {
        return $this->getName(true);
    }

    public function getState(): int
    {
        return $this->state;
    }

    public function setState(int $newState, $message=''): void
    {
        Monocycle::getLogger()
            ->debug("Socket change state, oldState={$this->state} newState=$newState msg=$message");

        $oldState = $this->state;
        $this->state = $newState;
    }

    public function getOption(int $level, int $option)
    {
        return socket_get_option(socket_import_stream($this->getResource()), $level, $option);
    }

    public function setOption(int $level, int $option, $value): bool
    {
        return socket_set_option(socket_import_stream($this->getResource()), $level, $option, $value);
    }

    private function getName(bool $remote): ?string
    {
        if (!is_resource($this->getResource())) {
            return null;
        }

        $name = @stream_socket_get_name($this->getResource(), $remote);

        return ($name !== false && $name !== '') ? $name : null;
    }
}

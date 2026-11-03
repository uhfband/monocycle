<?php

namespace Uhf\Monocycle\Socket;

use Uhf\Monocycle\Monocycle;

class UdpSocket extends Socket
{
    private string $localAddress;

    /** @var callable (string $data, string $from) */
    public $onRecvFrom;

    /**
     * @param string|null $localAddress
     * @throws SocketException
     */
    public function __construct(?string $localAddress = null)
    {
        $localAddress = $localAddress ?? 'udp://0.0.0.0:0';

        // parse_url() spuriously returns false for a URL with an explicit
        // port of 0 (e.g. 'udp://127.0.0.1:0', the common "pick an
        // ephemeral port" idiom), so the scheme is checked directly instead.
        if (strncmp($localAddress, 'udp://', 6) !== 0) {
            throw new SocketException('Invalid local address');
        }
        $this->localAddress = $localAddress;

        $socket = @stream_socket_server(
            $this->localAddress,
            $errNo,
            $errStr,
            STREAM_SERVER_BIND
        );

        if ($socket === false) {
            throw new SocketException($errStr, $errNo);
        }
        parent::__construct($socket, self::STATE_LISTENING);

        Monocycle::addSocket($this);
        Monocycle::addReadStream($this->getResource(), function() {
            $this->onReadable();
        });
    }

    /**
     * @param int $length
     * @param $from
     * @return string
     * @throws SocketException
     */
    public function recvFrom(&$from, int $length = 65536): string
    {
        $data = @stream_socket_recvfrom($this->getResource(), $length, 0, $from);

        if ($data === false) {
            throw new SocketException(error_get_last()['message'] ?? 'UdpSocket::recvFrom() error');
        }

        return $data;
    }

    /**
     * @param string $data
     * @param string $address
     * @return int
     * @throws SocketException
     */
    public function sendTo(string $data, string $address): int
    {
        $sent = @stream_socket_sendto($this->getResource(), $data, 0, $address);

        if ($sent === -1 || $sent === false) {
            throw new SocketException(error_get_last()['message'] ?? 'UdpSocket::sendTo() error');
        }

        return $sent;
    }

    /**
     * @param string $data
     * @param string $from
     * @return void
     */
    protected function onRecvFrom(string $data, string $from)
    {
        if (is_callable($this->onRecvFrom)) {
            call_user_func($this->onRecvFrom, $this, $data, $from);
        }
    }

    protected function onReadable()
    {
        $profiler = Monocycle::startProfiler();
        try {
            $from = '';
            $data = $this->recvFrom($from);
            $this->onRecvFrom($data, $from);
        } catch (SocketException $e) {
            Monocycle::getLogger()->warning('UdpSocket read error: ' . $e->getMessage());
            $this->close();
        } finally {
            $profiler->stop('udp onRecvFrom', ['class' => get_class($this), 'id' => $this->getId()]);
        }
    }
}

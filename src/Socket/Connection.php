<?php

namespace Uhf\Monocycle\Socket;

use Uhf\Monocycle\Monocycle;
use Uhf\Monocycle\Stream\StreamException;
use Uhf\Monocycle\Timer\Timer;
use Uhf\Monocycle\Timer\TimerException;

class Connection extends Socket
{
    /** @var callable */
    public $onConnect = null;
    /** @var callable */
    public $onDisconnect = null;
    /** @var callable(string $error) */
    public $onConnectionError = null;

    /** @var string|null set by connect(), null until then */
    protected ?string $remoteAddress = null;

    private ?Timer $connectTimeoutTimer = null;
    private ?Timer $readTimeoutTimer = null;
    private ?Timer $writeTimeoutTimer = null;

    private ?int $cryptoMethod;
    private ?array $sslOptions;

    public function __construct($socket = null)
    {
        parent::__construct($socket);

        if ($socket) {
            // For TCP, the peer name is "host:port". For a unix domain socket
            // the accepted connection has no meaningful peer address -
            // stream_socket_get_name(..., true) returns false - so fall back to
            // the local (listening) socket's path for identification
            $peerName = @stream_socket_get_name($socket, true);
            if ($peerName !== false) {
                $this->remoteAddress = $peerName;
            } else {
                $localName = @stream_socket_get_name($socket, false);
                $this->remoteAddress = 'unix://' . $localName;
            }

            Monocycle::addSocket($this);
        }
    }

    /**
     * Seconds to wait for connect() to complete, 0 = no limit (the default).
     * Takes effect at once when called while connecting.
     */
    public function setConnectTimeout(int $timeout): self
    {
        $this->connectTimeoutTimer = $this->setTimeoutTimer(
            $this->connectTimeoutTimer,
            $timeout,
            'Connect timeout',
            $this->state === self::STATE_CONNECTING && $this->getResource() !== null
        );

        return $this;
    }

    /**
     * Seconds without incoming data before the connection is dropped, 0 = no
     * limit (the default). Takes effect at once when called while connected.
     */
    public function setReadTimeout(int $timeout): self
    {
        $this->readTimeoutTimer = $this->setTimeoutTimer(
            $this->readTimeoutTimer,
            $timeout,
            'Read timeout',
            $this->state === self::STATE_CONNECTED
        );

        return $this;
    }

    /**
     * Seconds buffered data may go unsent before the connection is dropped,
     * 0 = no limit (the default). Takes effect at once when called while
     * data is waiting to be sent.
     */
    public function setWriteTimeout(int $timeout): self
    {
        $this->writeTimeoutTimer = $this->setTimeoutTimer(
            $this->writeTimeoutTimer,
            $timeout,
            'Write timeout',
            $this->state === self::STATE_CONNECTED && !$this->isWriteBufferEmpty()
        );

        return $this;
    }

    /**
     * @param string $remoteAddress
     * @param int|null $cryptoMethod @link https://www.php.net/manual/en/function.stream-socket-enable-crypto.php
     * @param array|null $sslOptions @link https://www.php.net/manual/en/context.ssl.php
     * @return void|null
     * @throws TimerException
     */
    public function connect(string $remoteAddress, ?int $cryptoMethod = null, ?array $sslOptions = null)
    {
        $this->remoteAddress = $remoteAddress;
        $this->cryptoMethod = $cryptoMethod;
        $this->sslOptions = $sslOptions;

        $this->setState(self::STATE_CONNECTING);

        $context = stream_context_create(
            !empty($this->sslOptions) ? ['ssl' => $this->sslOptions] : []
        );

        $socket = @stream_socket_client(
            $this->remoteAddress,
            $errNo,
            $errStr,
            null,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
            $context
        );

        if ($socket === false) {
            $this->onConnectionError($errStr);
            return null;
        }
        $this->setResource($socket);

        if (!empty($this->connectTimeoutTimer)) {
            $this->connectTimeoutTimer->start();
        }

        Monocycle::addSocket($this);
        Monocycle::addWriteStream($socket, function() {
            $this->onWritable();
        });
    }

    /**
     * @param string $data
     * @return int
     */
    public function write(string $data): int
    {
        $len = strlen($data);
        if (!$len) {
            return 0;
        }

        $wasEmpty = $this->isWriteBufferEmpty();
        // buffer first: writeAsync() throws over the limit, and must not leave a listener behind
        $this->writeAsync($data);
        if ($this->state === self::STATE_CONNECTED && $wasEmpty) {
            Monocycle::addWriteStream($this->getResource(), function () {
                $this->onWritable();
            });
            if (!empty($this->writeTimeoutTimer)) {
                $this->writeTimeoutTimer->start();
            }
        }

        return $len;
    }

    public function close(bool $graceful = true): void
    {
        if ($this->getState() === self::STATE_CLOSED) {
            return;
        }

        if ($graceful && !$this->isWriteBufferEmpty()) {
            $this->closing = true;

            return;
        }

        parent::close();
    }

    public function isUnixSocket(): bool
    {
        // parse_url() returns false for "unix:///path", so the scheme is checked directly
        return $this->remoteAddress !== null && strncmp($this->remoteAddress, 'unix://', 7) === 0;
    }

    /**
     * @return string|null host, or the socket file path for a unix domain socket, null before connect()
     */
    public function getRemoteHost(): ?string
    {
        if ($this->remoteAddress === null) {
            return null;
        }
        if ($this->isUnixSocket()) {
            return substr($this->remoteAddress, 7);
        }

        return parse_url($this->remoteAddress, PHP_URL_HOST);
    }

    /**
     * @return int|null port, 0 for a unix domain socket, null before connect()
     */
    public function getRemotePort(): ?int
    {
        if ($this->remoteAddress === null) {
            return null;
        }
        if ($this->isUnixSocket()) {
            return 0;
        }

        return parse_url($this->remoteAddress, PHP_URL_PORT);
    }

    public function setState(int $newState, $message=''): void
    {
        $oldState = $this->state;
        parent::setState($newState, $message);

        if ($oldState !== self::STATE_CONNECTED && $newState === self::STATE_CONNECTED) {
            $this->onConnect();
        } elseif ($oldState === self::STATE_CONNECTED && $newState === self::STATE_DISCONNECTED) {
            $this->onDisconnect();
        } elseif ($oldState === self::STATE_CONNECTING && $newState === self::STATE_DISCONNECTED) {
            $this->onConnectionError($message);
        }
    }

    protected function onDrain()
    {
        parent::onDrain();
        if (!empty($this->writeTimeoutTimer)) {
            $this->writeTimeoutTimer->stop();
        }
    }

    /** Fires on success connection
     * @return void
     */
    protected function onConnect()
    {
        if (!empty($this->connectTimeoutTimer)) {
            $this->connectTimeoutTimer->stop();
            $this->connectTimeoutTimer = null;
        }
        if (!empty($this->readTimeoutTimer)) {
            $this->readTimeoutTimer->start();
        }

        Monocycle::addReadStream($this->getResource(), function() {
            $this->onReadable();
        });
        if (!$this->isWriteBufferEmpty()) {
            Monocycle::addWriteStream($this->getResource(), function () {
                $this->onWritable();
            });
            if (!empty($this->writeTimeoutTimer)) {
                $this->writeTimeoutTimer->start();
            }
        } else {
            Monocycle::removeWriteStream($this->getResource());
        }

        if (is_callable($this->onConnect)) {
            call_user_func($this->onConnect, $this);
        }
    }

    protected function onConnectionError(string $message)
    {
        if (is_callable($this->onConnectionError)) {
            call_user_func($this->onConnectionError, $this, $message);
        }
        $this->close(false);
    }

    /**
     * Fires when remote side closed the connection, or timeout
     * @return void
     */
    protected function onDisconnect()
    {
        if (is_callable($this->onDisconnect)) {
            call_user_func($this->onDisconnect, $this);
        }
        $this->close(false);
    }

    protected function onReadable()
    {
        switch ($this->getState()) {
            case Socket::STATE_CONNECTED:
                //$stream->readToBuffer();
                try {
                    $data = $this->read();
                    if (!empty($this->readTimeoutTimer)) {
                        $this->readTimeoutTimer->reset();
                    }
                    if ($data === '') {
                        break; // TLS-internal record (e.g. session ticket) or partial record, no app data yet
                    }
                    $this->onData($data);
                } catch (SocketException $e) {
                    $this->setState(Socket::STATE_DISCONNECTED, $e->getMessage());
                }
                break;
        }
    }

    protected function onWritable()
    {
        switch ($this->getState()) {
            case Socket::STATE_CONNECTING:
                if ($this->isSecured()) {
                    $this->doTlsHandshake();
                } else {
                    // A failed async connect becomes writable without a peer name.
                    if (is_null($this->getRemoteAddress())) {
                        $this->setState(Socket::STATE_DISCONNECTED, 'Connection refused');
                        //$this->onConnectionError('Connection failed');
                        break;
                    }
                    $this->setState(Socket::STATE_CONNECTED);
                }
                break;

            case Socket::STATE_CONNECTED:
                try {
                    $this->writeBuffer();
                    if (!empty($this->writeTimeoutTimer)) {
                        $this->writeTimeoutTimer->reset();
                    }
                } catch (StreamException $e) {
                    $this->setState(self::STATE_DISCONNECTED, $e->getMessage());
                }
                break;
        }
    }

    protected function onClose(): void
    {
        if (!empty($this->connectTimeoutTimer)) {
            $this->connectTimeoutTimer->stop();
            $this->connectTimeoutTimer = null;
        }

        if (!empty($this->readTimeoutTimer)) {
            $this->readTimeoutTimer->stop();
            $this->readTimeoutTimer = null;
        }

        if (!empty($this->writeTimeoutTimer)) {
            $this->writeTimeoutTimer->stop();
            $this->writeTimeoutTimer = null;
        }

        parent::onClose();
    }

    /**
     * Perform one step of the nonblocking TLS handshake.
     *
     * stream_socket_enable_crypto() returns:
     *   true  – handshake complete, transition to STATE_CONNECTED
     *   false – handshake failed, transition to STATE_DISCONNECTED
     *   0     – handshake in progress (nonblocking), will be retried on next select cycle
     */
    private function doTlsHandshake(): void
    {
        $profiler = Monocycle::startProfiler();
        $result = @stream_socket_enable_crypto($this->getResource(), true, $this->cryptoMethod);
        $profiler->stop('doTlsHandshake', ['class' => get_class($this), 'remote_address' => $this->remoteAddress]);

        Monocycle::getLogger()->debug(
            'doTlsHandshake result = ' . json_encode($result),
            [
                'file' => __FILE__,
                'line' => __LINE__
            ]
        );

        // result === 0: still in progress – Daemon will call again on next readable/writable event
        if ($result === true) {
            $this->setState(self::STATE_CONNECTED);
        } elseif ($result === false) {
            $this->setState(self::STATE_DISCONNECTED, 'TLS handshake failed');
        }
    }

    private function isSecured(): bool
    {
        return !empty($this->cryptoMethod);
    }

    /**
     * Creates, updates or (for $timeout 0) stops and drops a timeout timer.
     * $active: the phase the timer guards is under way, so it must run now
     * rather than wait for connect()/onConnect()/write() to start it.
     */
    private function setTimeoutTimer(?Timer $timer, int $timeout, string $message, bool $active): ?Timer
    {
        if (empty($timeout)) {
            if (!empty($timer)) {
                $timer->stop();
            }

            return null;
        }

        if (empty($timer)) {
            $timer = new Timer(function () use ($message) {
                $this->setState(self::STATE_DISCONNECTED, $message);
            });
        }
        // on a running timer this also moves the deadline to now + $timeout
        $timer->setDelay($timeout);
        if ($active && !$timer->isRunning()) {
            $timer->start();
        }

        return $timer;
    }
}

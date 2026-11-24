<?php

namespace Uhf\Monocycle\Proto\Http\Server;

use Uhf\Monocycle\Socket\Server as SocketServer;
use Uhf\Monocycle\Socket\SocketException;

class Server extends SocketServer
{
    private int $clientReadTimeout = 60;
    private int $maxRequestLength = 4096;

    private Router $router;

    /**
     * @param string $address
     * @param string $wwwRoot
     * @param string $clientCls
     * @throws SocketException
     */
    public function __construct(string $address, string $wwwRoot, string $clientCls = ServerClient::class)
    {
        parent::__construct($address, $clientCls);
        $this->router = new Router($wwwRoot);
    }

    protected function onConnection($client)
    {
        assert($client instanceof ServerClient);
        $client->setRouter($this->router);
        $client->setReadTimeout($this->clientReadTimeout);
        $client->setMaxRequestLength($this->maxRequestLength);
        parent::onConnection($client);
    }

    /**
     * Read timeout (seconds) of connections accepted from now on, default
     * 60, 0 = none. It is applied before the $onConnection closure runs, so
     * the closure can still override it per connection.
     */
    public function setClientReadTimeout(int $seconds): self
    {
        if ($seconds < 0) {
            throw new \InvalidArgumentException('Client read timeout must not be negative');
        }
        $this->clientReadTimeout = $seconds;

        return $this;
    }

    /**
     * @param int $maxRequestLength
     * @return self
     */
    public function setMaxRequestLength(int $maxRequestLength): self
    {
        $this->maxRequestLength = $maxRequestLength;

        return $this;
    }

    /**
     * @param string $path
     * @param array $methods
     * @param callable $handler
     * @return void
     */
    public function addRoute(string $path, array $methods, $handler)
    {
        $this->router->addRoute($path, $methods, $handler);
    }
}

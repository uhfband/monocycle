<?php

namespace Uhf\Monocycle\Proto\WebSocket\Server;

use Uhf\Monocycle\Proto\Http\Server\Server as HttpServer;

class Server extends HttpServer
{
    private array $messageHandlers = [];

    public function __construct(string $address, string $wwwRoot, string $clientCls = ServerClient::class)
    {
        parent::__construct($address, $wwwRoot, $clientCls);
    }

    public function addMessageHandler($handler)
    {
        $this->messageHandlers[] = $handler;
    }

    protected function onConnection($client)
    {
        parent::onConnection($client);
        assert($client instanceof ServerClient);
        foreach ($this->messageHandlers as $messageHandler) {
            $client->addMessageHandler($messageHandler);
        }
    }
}

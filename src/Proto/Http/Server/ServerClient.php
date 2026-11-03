<?php

namespace Uhf\Monocycle\Proto\Http\Server;

use Symfony\Component\HttpFoundation\Request;
use Uhf\Monocycle\Monocycle;
use Uhf\Monocycle\Socket\Connection;

class ServerClient extends Connection
{
    protected string $readBuffer = '';
    protected bool $keepAlive = false;

    private ?RequestParser $parser;

	private $accepted;
    private $response;
    private int $maxRequestLength = 4096;
    private Router $router;

    public function setRouter(Router $router)
    {
        $this->router = $router;
    }

    public function close(bool $graceful = true): void
    {
        Monocycle::getLogger()
            ->info(
                sprintf(
                    "[%s] close connection from %s:%d",
                    __CLASS__,
                    $this->getRemoteHost(),
                    $this->getRemotePort()
                )
            );
        parent::close($graceful);
    }

    protected function onConnect()
	{
        parent::onConnect();
        Monocycle::getLogger()
            ->info(
                sprintf(
                    "[%s] accepted connection from %s:%d",
                    __CLASS__,
                    $this->getRemoteHost(),
                    $this->getRemotePort()
                )
            );

		$this->accepted = time();
		$this->last_action = $this->accepted;

        $this->parser = new RequestParser(function($request) {
            $this->onHttpRequest($request);
        });
	}

    protected function onDisconnect()
    {
        Monocycle::getLogger()
            ->info(
                sprintf(
                    "[%s] %s:%d disconnected",
                    __CLASS__,
                    $this->getRemoteHost(),
                    $this->getRemotePort()
                )
            );
        parent::onDisconnect();
    }

    protected function onData(string $data)
    {
        parent::onData($data);
        $this->readBuffer .= $data;
        
        if (strlen($this->readBuffer) > $this->maxRequestLength) {
            $response = new Response(400, 'Bad Request');
            $response->setContent('Bad request');
            $this->readBuffer  = '';
            $this->write((string)$response);

            return;
        }

        $this->parser->parse($this->readBuffer);
	}

    protected function onDrain()
    {
        parent::onDrain();

        if (!$this->isWriteBufferEmpty()) {
            return;
        }

        if (!empty($this->response) && !$this->response->eof()) {
            $data = (string)$this->response;
            //$data = $this->response->getContent();
            $this->write($data);
            //$this->write_buffer .= $data;
            unset($data);

            return;
        }

        if (!$this->keepAlive) {
            $this->close();
        }
    }

    protected function onHttpRequest(Request $request)
    {
        if (strtolower((string)$request->headers->get('Connection')) === 'keep-alive') {
            $this->keepAlive = true;
        }

        // connection info for handlers such as PhpCgi (Request::create() fills in placeholders)
        $request->server->set('REMOTE_ADDR', $this->getRemoteHost());
        $request->server->set('REMOTE_PORT', $this->getRemotePort());
        $local = $this->getLocalAddress();

        if (!$this->isUnixSocket() && $local && ($pos = strrpos($local, ':')) !== false) {
            $request->server->set('SERVER_ADDR', substr($local, 0, $pos));
            $request->server->set('SERVER_PORT', (int)substr($local, $pos + 1));
        }

        /////////////////////////////
        $response = $this->router->route($request, function($response) use ($request) {
            if (is_string($response)) {
                $content = $response;
                $response = new Response(200, 'OK');
                $response->setContent($content);
            }
            $this->onResponse($request, $response);
        });

        if (!is_null($response)) {
            $this->onResponse($request, $response);
        }
    }

    protected function onResponse($request, $response)
    {
        $this->response = $response;
        //$this->write($this->response->getContent());

        Monocycle::getLogger()
            ->info(
                sprintf(
                    "[%s] \"%s %s %s\" %d %d",
                    __CLASS__,
                    $request->getMethod(),
                    $request->getRequestUri(),
                    $request->server->get('SERVER_PROTOCOL', 'HTTP/1.0'),
                    $this->response->getStatusCode(),
                    $this->response->getContentSize()
                )
            );

        $this->write((string)$this->response);
    }

    protected function onClose(): void
    {
        $this->parser = null;
        $this->readBuffer = '';
        parent::onClose();
    }
}

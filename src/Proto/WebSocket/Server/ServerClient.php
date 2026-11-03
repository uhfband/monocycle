<?php

namespace Uhf\Monocycle\Proto\WebSocket\Server;

use Symfony\Component\HttpFoundation\Request;
use Uhf\Monocycle\Monocycle;
use Uhf\Monocycle\Proto\Http\Server\Response;
use Uhf\Monocycle\Proto\Http\Server\ServerClient as HttpServerClient;
use Uhf\Monocycle\Timer\Timer;

class ServerClient extends HttpServerClient
{
    public const OPCODE_CONTINUATION = 0;
    public const OPCODE_TEXT = 1;
    public const OPCODE_BINARY = 2;
    public const OPCODE_CLOSE = 8;
    public const OPCODE_PING = 9;
    public const OPCODE_PONG = 10;

    public const CLOSE_NORMAL = 1000;

    private const MAGIC = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    private bool $upgraded = false;
    private int $pingInterval = 30;
    private ?Timer $pingTimer = null;

    private ?Parser $parser = null;
    /** @var callable[]  */
    private array $messageHandlers = [];

    public function addMessageHandler($handler): void
    {
        $this->messageHandlers[] = $handler;
    }

    protected function onData(string $data): void
    {
        if (!$this->upgraded) {
            parent::onData($data);
            if (!$this->upgraded) {
                return;
            }
        } else {
            $this->readBuffer .= $data;
        }

        if (!strlen($this->readBuffer)) {
            return;
        }

        try {
            $this->parser->parse($this->readBuffer);
        } catch (ProtocolException $e) {
            $this->closeWebSocket($e->getCode(), $e->getMessage());
        }
    }

    protected function onHttpRequest(Request $request)
    {
        if (
            strtolower((string)$request->headers->get('Upgrade')) === 'websocket'
        ) {
            Monocycle::getLogger()->info('Upgrade WebSocket Client');
            $websocketKey = $request->headers->get('sec-websocket-key');

            $response = new Response(101, 'Switching Protocols');
            $response->setHeader('Upgrade', 'websocket');
            $response->setHeader('Connection', 'Upgrade');
            $response->setHeader('Sec-WebSocket-Accept', base64_encode(sha1($websocketKey . self::MAGIC, true)));
            $this->write((string)$response);

            $this->upgraded = true;
            $this->keepAlive = true;
            // frames sent right behind the handshake; taking them also ends the HTTP parse loop
            //$this->webSocketBuffer = $this->takeReadBuffer();
            $this->parser = new Parser(function ($opcode, $message) {
                //if (!$this->closeSent) {
                    $this->onWebsocketMessage($opcode, $message);
                //}
            }, Monocycle::getLogger());

            $this->pingTimer = new Timer([$this, 'ping']);
            $this->pingTimer->setInterval($this->pingInterval)->start();

        } else {
            parent::onHttpRequest($request);
        }
    }

    protected function onWebsocketMessage($opcode, $message)
    {
        if (!empty($this->pingTimer)) {
            $this->pingTimer->reset();
        }

        foreach ($this->messageHandlers as $handler) {
            call_user_func($handler, $this, $opcode, $message);
        }

        switch ($opcode) {
            case self::OPCODE_PING:
                $this->sendWebsocketMessage(self::OPCODE_PONG, $message);
                break;
            case self::OPCODE_PONG:
                break;
            case self::OPCODE_CLOSE:
                $code = strlen($message) >= 2 ? unpack('n', $message)[1] : null;
                Monocycle::getLogger()->debug(sprintf("Websocket client closed, reason code: %s", $code ?? 'none'));
                // echo the close frame, then close once it has been sent
                $this->closeWebSocket($code);
                break;
            default:
                break;
        }
    }

    public function closeWebSocket(?int $code = self::CLOSE_NORMAL, string $reason = ''): void
    {
        $this->sendWebsocketMessage(self::OPCODE_CLOSE, $code === null ? '' : pack('n', $code) . $reason);
        $this->close();
    }

    public function ping()
    {
        $this->sendWebsocketMessage(self::OPCODE_PING);
    }

    public function sendWebsocketMessage(int $opcode, string $payload='')
    {
        $payloadLen = strlen($payload);
        $fin = 1;
        $masked = 0;

        if ($payloadLen < 126) {
            $header = pack('CC', ($fin <<7) | $opcode, ($masked <<7) | $payloadLen);
        } elseif ($payloadLen < 65536) {
            $header = pack('CCn', ($fin <<7) | $opcode, ($masked <<7) | 126, $payloadLen);
        } else {
            $header = pack('CCJ', ($fin <<7) | $opcode, ($masked <<7) | 127, $payloadLen);
        }
        $this->write($header . $payload);
    }

    protected function onClose(): void
    {
        if (!empty($this->pingTimer)) {
            $this->pingTimer->stop();
            $this->pingTimer = null;
        }
        $this->parser = null;
        parent::onClose();
    }
}

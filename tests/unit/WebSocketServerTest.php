<?php

use Uhf\Monocycle\Proto\WebSocket\Server\Server as WebSocketServer;
use Uhf\Monocycle\Proto\WebSocket\Server\ServerClient;
use Uhf\Monocycle\Socket\Connection;

/**
 * Runs the WebSocket server in-process on an ephemeral port and talks to it
 * over a raw Socket\Connection, building client frames by hand.
 */
class WebSocketServerTest extends BaseTest
{
    private const KEY = 'dGhlIHNhbXBsZSBub25jZQ==';
    private const ACCEPT = 's3pPLMBiTxaQ9kYGzzhZRbK+xOo=';

    private ?WebSocketServer $server = null;

    /** @var ServerClient[] connections accepted by $server */
    private array $accepted = [];

    /** @var Connection[] client connections opened by the test */
    private array $clients = [];

    protected function setUp(): void
    {
        $this->server = new WebSocketServer('tcp://127.0.0.1:0', __DIR__ . '/fixtures/public');
        $this->server->onConnection = function ($server, Connection $client) {
            $this->accepted[] = $client;
        };
    }

    protected function tearDown(): void
    {
        foreach (array_merge($this->clients, $this->accepted) as $conn) {
            $conn->close(false);
        }
        $this->clients = [];
        $this->accepted = [];
        $this->server->close();
        $this->server = null;

        $this->resetMonocycle('sockets', 'timers', 'readStreams', 'readListeners', 'writeStreams', 'writeListeners');
    }

    private function addEchoHandler(): void
    {
        $this->server->addMessageHandler(function (ServerClient $client, int $opcode, string $message) {
            if ($opcode === ServerClient::OPCODE_TEXT || $opcode === ServerClient::OPCODE_BINARY) {
                $client->sendWebsocketMessage($opcode, $message);
            }
        });
    }

    private function connect(?ArrayObject &$state = null): Connection
    {
        $state = new ArrayObject(['received' => '', 'disconnected' => false]);

        $client = new Connection();
        $client->onData = function (Connection $conn, string $data) use ($state) {
            $state['received'] .= $data;
        };
        $client->onDisconnect = function () use ($state) {
            $state['disconnected'] = true;
        };
        $client->onConnectionError = function (Connection $conn, string $message) {
            $this->fail("Connection error: $message");
        };
        $client->connect('tcp://' . $this->server->getLocalAddress());
        $this->clients[] = $client;

        return $client;
    }

    private function handshake(): string
    {
        return "GET /chat HTTP/1.1\r\nHost: 127.0.0.1\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . "Sec-WebSocket-Key: " . self::KEY . "\r\nSec-WebSocket-Version: 13\r\n\r\n";
    }

    /**
     * Connects and completes the handshake; $state['received'] then holds only
     * what came after the 101 response.
     */
    private function open(?ArrayObject &$state = null, string $afterHandshake = ''): Connection
    {
        $client = $this->connect($state);
        $client->write($this->handshake() . $afterHandshake);

        $this->pumpUntil(function () use ($state) {
            return strpos($state['received'], "\r\n\r\n") !== false;
        });
        [$head, $rest] = explode("\r\n\r\n", $state['received'], 2);
        $this->assertStringStartsWith('HTTP/1.1 101', $head);
        $this->assertStringContainsString('Sec-WebSocket-Accept: ' . self::ACCEPT, $head);
        $state['received'] = $rest;

        return $client;
    }

    /** A client frame; masked unless $mask is null. */
    private function frame(int $opcode, string $payload, ?string $mask = "\x37\xfa\x21\x3d", bool $fin = true): string
    {
        $len = strlen($payload);
        $b1 = ($fin ? 0x80 : 0) | $opcode;
        $maskBit = $mask === null ? 0 : 0x80;

        if ($len < 126) {
            $header = pack('CC', $b1, $maskBit | $len);
        } elseif ($len < 65536) {
            $header = pack('CCn', $b1, $maskBit | 126, $len);
        } else {
            $header = pack('CCJ', $b1, $maskBit | 127, $len);
        }
        if ($mask === null) {
            return $header . $payload;
        }

        return $header . $mask . ($payload ^ substr(str_repeat($mask, intdiv($len, 4) + 1), 0, $len));
    }

    /**
     * Splits complete (unmasked) server frames off the front of $buffer as
     * [opcode, payload] pairs.
     */
    private function parseFrames(string &$buffer): array
    {
        $frames = [];
        while (strlen($buffer) >= 2) {
            $h = unpack('Cb1/Cb2', $buffer);
            $this->assertSame(0, $h['b2'] & 0x80, 'Server frames must not be masked');
            $len = $h['b2'] & 0x7F;
            $hdrLen = 2;
            if ($len === 126) {
                $hdrLen = 4;
                if (strlen($buffer) < $hdrLen) {
                    break;
                }
                $len = unpack('n', $buffer, 2)[1];
            } elseif ($len === 127) {
                $hdrLen = 10;
                if (strlen($buffer) < $hdrLen) {
                    break;
                }
                $len = unpack('J', $buffer, 2)[1];
            }
            if (strlen($buffer) < $hdrLen + $len) {
                break;
            }
            $frames[] = [$h['b1'] & 0x0F, (string)substr($buffer, $hdrLen, $len)];
            $buffer = (string)substr($buffer, $hdrLen + $len);
        }

        return $frames;
    }

    /** Pumps until $count server frames have arrived and returns them. */
    private function receiveFrames(ArrayObject $state, int $count): array
    {
        $frames = [];
        $this->pumpUntil(function () use ($state, $count, &$frames) {
            $buffer = $state['received'];
            $frames = array_merge($frames, $this->parseFrames($buffer));
            $state['received'] = $buffer;

            return count($frames) >= $count;
        });
        $this->assertCount($count, $frames);

        return $frames;
    }

    public function testEcho(): void
    {
        $this->addEchoHandler();
        $client = $this->open($state);

        $client->write($this->frame(ServerClient::OPCODE_TEXT, 'hello'));

        $this->assertSame([[ServerClient::OPCODE_TEXT, 'hello']], $this->receiveFrames($state, 1));
    }

    public function testFrameSplitAcrossReads(): void
    {
        $this->addEchoHandler();
        $client = $this->open($state);

        // 2-byte header + 2 of the 4 mask bytes: looked complete before the mask was counted
        $frame = $this->frame(ServerClient::OPCODE_TEXT, 'hi');
        foreach ([substr($frame, 0, 4), substr($frame, 4, 3), substr($frame, 7)] as $part) {
            $client->write($part);
            $this->pumpUntil(function () use ($client) {
                return $client->isWriteBufferEmpty();
            });
            for ($i = 0; $i < 3; $i++) {
                $this->tick(0.05);
            }
        }

        $this->assertSame([[ServerClient::OPCODE_TEXT, 'hi']], $this->receiveFrames($state, 1));
        $this->assertSame('', $state['received']);
    }

    public function testFramesInSameWriteAsHandshake(): void
    {
        $this->addEchoHandler();
        $this->open($state, $this->frame(ServerClient::OPCODE_TEXT, 'one') . $this->frame(ServerClient::OPCODE_TEXT, 'two'));

        $this->assertSame(
            [[ServerClient::OPCODE_TEXT, 'one'], [ServerClient::OPCODE_TEXT, 'two']],
            $this->receiveFrames($state, 2)
        );
    }

    /**
     * @dataProvider payloadLengthProvider
     */
    public function testPayloadLengthEncodings(int $length): void
    {
        $this->addEchoHandler();
        $client = $this->open($state);
        $payload = substr(str_repeat('0123456789abcdef', intdiv($length, 16) + 1), 0, $length);

        $client->write($this->frame(ServerClient::OPCODE_BINARY, $payload));

        $frames = $this->receiveFrames($state, 1);
        $this->assertSame(ServerClient::OPCODE_BINARY, $frames[0][0]);
        $this->assertSame($payload, $frames[0][1]);
    }

    public function payloadLengthProvider(): array
    {
        return [
            '7-bit max' => [125],
            '16-bit min' => [126],
            '16-bit max' => [65535],
            '64-bit' => [70000],
        ];
    }

    public function testPingWithoutMessageHandlers(): void
    {
        $client = $this->open($state);

        $client->write($this->frame(ServerClient::OPCODE_PING, 'abc'));

        $this->assertSame([[ServerClient::OPCODE_PONG, 'abc']], $this->receiveFrames($state, 1));
    }

    public function testFramesResetReadTimeout(): void
    {
        $this->server->setClientReadTimeout(2);
        $client = $this->open($state);

        // a ping every 0.5s for longer than the timeout keeps the connection open
        $deadline = microtime(true) + 3.5;
        $pings = 0;
        while (microtime(true) < $deadline) {
            $client->write($this->frame(ServerClient::OPCODE_PING, (string)$pings++));
            // tick() returns as soon as a stream is ready, so pace by the clock
            $next = microtime(true) + 0.5;
            while (($left = $next - microtime(true)) > 0) {
                $this->tick($left, true);
            }
        }
        $this->assertFalse($state['disconnected'], 'Read timeout fired although frames kept arriving');
        $this->assertCount($pings, $this->receiveFrames($state, $pings));

        // once the client goes quiet, the timeout closes it
        $this->pumpUntil(function () use ($state) {
            return $state['disconnected'];
        }, 4.0, true);
    }

    public function testCloseFrameIsEchoedAndClosesOnce(): void
    {
        $client = $this->open($state);
        $closed = 0;
        $this->accepted[0]->onClose = function () use (&$closed) {
            $closed++;
        };

        $client->write($this->frame(ServerClient::OPCODE_CLOSE, pack('n', 1001) . 'bye'));
        $this->pumpUntil(function () use ($state) {
            return $state['disconnected'];
        });

        $buffer = $state['received'];
        $this->assertSame([[ServerClient::OPCODE_CLOSE, pack('n', 1001)]], $this->parseFrames($buffer));
        $this->assertSame('', $buffer);
        $this->assertSame(1, $closed);
    }

    /**
     * @dataProvider protocolErrorProvider
     */
    public function testProtocolErrorClosesConnection(string $frame, int $code): void
    {
        $log = $this->captureLog();
        $this->addEchoHandler();
        $client = $this->open($state);

        // the echo after the bad frame must not be answered
        $client->write($frame . $this->frame(ServerClient::OPCODE_TEXT, 'after'));
        $this->pumpUntil(function () use ($state) {
            return $state['disconnected'];
        });

        $buffer = $state['received'];
        $frames = $this->parseFrames($buffer);
        $this->assertCount(1, $frames);
        $this->assertSame(ServerClient::OPCODE_CLOSE, $frames[0][0]);
        $this->assertSame($code, unpack('n', $frames[0][1])[1]);
        $this->assertSame('', $buffer);
        $this->assertTrue($log->hasWarningThatContains('WebSocket protocol error'));
    }

    public function protocolErrorProvider(): array
    {
        return [
            'unmasked' => [$this->frame(ServerClient::OPCODE_TEXT, 'x', null), 1002],
            'fragmented' => [$this->frame(ServerClient::OPCODE_TEXT, 'x', "\1\2\3\4", false), 1002],
            'unknown opcode' => [$this->frame(3, 'x'), 1002],
            'long control frame' => [$this->frame(ServerClient::OPCODE_PING, str_repeat('x', 126)), 1002],
            'length with top bit set' => ["\x82\xff" . str_repeat("\xff", 8) . "\1\2\3\4", 1009],
        ];
    }
}

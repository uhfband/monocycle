<?php

declare(strict_types=1);

namespace Uhf\Monocycle\Proto\WebSocket\Client;

use Uhf\Monocycle\Socket\Connection;

/**
 * Minimal asynchronous WebSocket client (RFC 6455)
 * ws:// and wss://, text messages, fragmentation, ping/pong and close.
 *
 * Callbacks: $onOpen(), $onMessage(string $text), $onClose(?string $error) —
 * $onClose fires exactly once, also after a failed connect or handshake.
 */
final class Client
{
    private const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    public const OP_CONTINUATION = 0x0;
    public const OP_TEXT = 0x1;
    public const OP_BINARY = 0x2;
    public const OP_CLOSE = 0x8;
    public const OP_PING = 0x9;
    public const OP_PONG = 0xA;

    /** @var callable|null */
    public $onOpen = null;
    /** @var callable|null */
    public $onMessage = null;
    /** @var callable|null */
    public $onClose = null;

    private string $url;
    private int $maxMessageBytes;

    private ?Connection $conn = null;
    private bool $open = false;
    private bool $finished = false;
    private string $buffer = '';
    private string $key = '';
    private ?string $message = null;

    public function __construct(string $url, int $maxMessageBytes = 64 * 1024 * 1024)
    {
        $this->url = $url;
        $this->maxMessageBytes = $maxMessageBytes;
    }

    public function connect(int $connectTimeout = 10): void
    {
        $parts = parse_url($this->url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['ws', 'wss'], true) || empty($parts['host'])) {
            $this->finish("Invalid WebSocket URL: {$this->url}");

            return;
        }
        $secure = $scheme === 'wss';
        $host = $parts['host'];
        $port = (int)($parts['port'] ?? ($secure ? 443 : 80));
        $path = ($parts['path'] ?? '') ?: '/';
        if (isset($parts['query'])) {
            $path .= '?' . $parts['query'];
        }
        $this->key = base64_encode(random_bytes(16));
        $hostHeader = $host . (isset($parts['port']) ? ':' . $parts['port'] : '');

        $conn = new Connection();
        $this->conn = $conn;
        $conn->setConnectTimeout($connectTimeout);
        $conn->onConnect = function () use ($conn, $path, $hostHeader) {
            $conn->write("GET $path HTTP/1.1\r\nHost: $hostHeader\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
                . "Sec-WebSocket-Key: {$this->key}\r\nSec-WebSocket-Version: 13\r\n\r\n");
        };
        $conn->onData = function ($c, string $data) {
            $this->onData($data);
        };
        $conn->onConnectionError = function ($c, string $message) {
            $this->finish("WebSocket connection failed: $message");
        };
        $conn->onDisconnect = function () {
            $this->finish($this->open ? 'WebSocket connection lost' : 'WebSocket handshake failed: connection closed');
        };
        $conn->connect(
            "tcp://$host:$port",
            $secure ? STREAM_CRYPTO_METHOD_TLS_CLIENT : null,
            $secure ? ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host] : null
        );
    }

    public function send(string $text): void
    {
        if ($this->open && $this->conn !== null) {
            $this->conn->write(self::encodeFrame(self::OP_TEXT, $text));
        }
    }

    /** Sends a close frame and closes the connection; $onClose fires with a null error. */
    public function close(): void
    {
        if ($this->conn !== null && $this->open) {
            $this->conn->write(self::encodeFrame(self::OP_CLOSE, pack('n', 1000)));
        }
        $this->finish(null);
    }

    /**
     * One frame; client frames are masked (RFC 6455 5.3).
     */
    public static function encodeFrame(int $opcode, string $payload, bool $mask = true): string
    {
        $length = strlen($payload);
        $frame = chr(0x80 | $opcode);
        $maskBit = $mask ? 0x80 : 0;
        if ($length < 126) {
            $frame .= chr($maskBit | $length);
        } elseif ($length < 65536) {
            $frame .= chr($maskBit | 126) . pack('n', $length);
        } else {
            $frame .= chr($maskBit | 127) . pack('J', $length);
        }
        if (!$mask) {
            return $frame . $payload;
        }
        $key = random_bytes(4);

        return $frame . $key . ($payload ^ str_repeat($key, intdiv($length + 3, 4)));
    }

    /**
     * Splits complete frames off the front of $buffer.
     *
     * @return array<array{0: bool, 1: int, 2: string}> [fin, opcode, payload] per frame
     */
    public static function decodeFrames(string &$buffer, int $maxFrameBytes): array
    {
        $frames = [];
        while (strlen($buffer) >= 2) {
            $b0 = ord($buffer[0]);
            $b1 = ord($buffer[1]);
            $length = $b1 & 0x7F;
            $offset = 2;
            if ($length === 126) {
                if (strlen($buffer) < 4) {
                    break;
                }
                $length = unpack('n', substr($buffer, 2, 2))[1];
                $offset = 4;
            } elseif ($length === 127) {
                if (strlen($buffer) < 10) {
                    break;
                }
                $length = unpack('J', substr($buffer, 2, 8))[1];
                $offset = 10;
            }
            if ($length < 0 || $length > $maxFrameBytes) {
                throw new \RuntimeException("WebSocket frame too large ($length bytes)");
            }
            $masked = ($b1 & 0x80) !== 0;
            $key = '';
            if ($masked) {
                if (strlen($buffer) < $offset + 4) {
                    break;
                }
                $key = substr($buffer, $offset, 4);
                $offset += 4;
            }
            if (strlen($buffer) < $offset + $length) {
                break;
            }
            $payload = (string)substr($buffer, $offset, $length);
            if ($masked && $length > 0) {
                $payload ^= str_repeat($key, intdiv($length + 3, 4));
            }
            $buffer = (string)substr($buffer, $offset + $length);
            $frames[] = [($b0 & 0x80) !== 0, $b0 & 0x0F, $payload];
        }

        return $frames;
    }

    private function onData(string $data): void
    {
        if ($this->finished) {
            return;
        }
        $this->buffer .= $data;
        if (!$this->open) {
            $end = strpos($this->buffer, "\r\n\r\n");
            if ($end === false) {
                if (strlen($this->buffer) > 65536) {
                    $this->finish('WebSocket handshake failed: response too large');
                }

                return;
            }
            $head = substr($this->buffer, 0, $end);
            $this->buffer = (string)substr($this->buffer, $end + 4);
            $error = $this->checkHandshake($head);
            if ($error !== null) {
                $this->finish("WebSocket handshake failed: $error");

                return;
            }
            $this->open = true;
            $this->onOpen && ($this->onOpen)();
        }
        try {
            $frames = self::decodeFrames($this->buffer, $this->maxMessageBytes);
        } catch (\RuntimeException $e) {
            $this->finish($e->getMessage());

            return;
        }
        foreach ($frames as [$fin, $opcode, $payload]) {
            if ($this->finished) {
                return;
            }
            $this->onFrame($fin, $opcode, $payload);
        }
    }

    private function onFrame(bool $fin, int $opcode, string $payload): void
    {
        switch ($opcode) {
            case self::OP_TEXT:
            case self::OP_BINARY:
                $this->message = $payload;
                break;
            case self::OP_CONTINUATION:
                if ($this->message === null) {
                    $this->finish('WebSocket protocol error: unexpected continuation frame');

                    return;
                }
                $this->message .= $payload;
                break;
            case self::OP_PING:
                if (!empty($this->conn)) {
                    $this->conn->write(self::encodeFrame(self::OP_PONG, $payload));
                }

                return;
            case self::OP_PONG:
                return;
            case self::OP_CLOSE:
                if (!empty($this->conn)) {
                    $this->conn->write(self::encodeFrame(self::OP_CLOSE, substr($payload, 0, 2)));
                }
                $this->finish($this->finished ? null : 'WebSocket closed by the server');

                return;
            default:
                $this->finish("WebSocket protocol error: opcode $opcode");

                return;
        }
        if (strlen((string)$this->message) > $this->maxMessageBytes) {
            $this->finish('WebSocket message too large');

            return;
        }
        if ($fin) {
            $message = (string)$this->message;
            $this->message = null;
            $this->onMessage && ($this->onMessage)($message);
        }
    }

    private function checkHandshake(string $head): ?string
    {
        $lines = explode("\r\n", $head);
        if (!preg_match('#^HTTP/1\.[01] 101\b#', (string)array_shift($lines))) {
            return 'unexpected response "' . substr($head, 0, strcspn($head, "\r\n")) . '"';
        }
        $headers = [];
        foreach ($lines as $line) {
            [$name, $value] = array_map('trim', explode(':', $line, 2)) + [1 => ''];
            $headers[strtolower($name)] = $value;
        }
        $expected = base64_encode(sha1($this->key . self::GUID, true));

        return ($headers['sec-websocket-accept'] ?? '') === $expected ? null : 'bad Sec-WebSocket-Accept';
    }

    private function finish(?string $error): void
    {
        if ($this->finished) {
            return;
        }
        $this->finished = true;
        $this->open = false;
        $conn = $this->conn;
        $this->conn = null;
        if (!empty($conn)) {
            $conn->close();
        }
        $this->onClose && ($this->onClose)($error);
    }
}

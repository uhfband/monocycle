<?php

namespace Uhf\Monocycle\Proto\WebSocket\Server;

use Psr\Log\LoggerInterface;

/**
 * Splits complete client frames off the front of a buffer (RFC 6455).
 * Incomplete frames stay in the buffer until more data arrives.
 */
class Parser
{
    /** @var callable */
    private $callback;
    private LoggerInterface $logger;
    private array $loggerContext;

    public function __construct($callback, LoggerInterface $logger, array $loggerContext = [])
    {
        $this->callback = $callback;
        $this->logger = $logger;
        $this->loggerContext = $loggerContext;
    }

    /**
     * Calls the callback with ($opcode, $payload) for every complete frame in
     * $data and removes it from there.
     *
     * @throws ProtocolException on a frame the server can't accept; the caller
     *         should close the connection with the exception's code.
     */
    public function parse(string &$data): void
    {
        while (1) {
            $hdrLen = 2;
            if (strlen($data) < $hdrLen) {
                return;
            }
            $h = unpack('Cb1/Cb2', $data);
            $fin = $h['b1'] >> 7;
            $rsv = ($h['b1'] >> 4) & 0x7;
            $opcode = $h['b1'] & 0xF;
            $masked = $h['b2'] >> 7;
            $payloadLen = $h['b2'] & 0x7F;

            if ($rsv) {
                $this->fail('reserved bits set without a negotiated extension');
            }
            if (!$masked) {
                $this->fail('client must mask all frames sent to the server');
            }
            if ($opcode >= 8) {
                if ($opcode > ServerClient::OPCODE_PONG) {
                    $this->fail(sprintf('unknown opcode %d', $opcode));
                }
                if (!$fin || $payloadLen > 125) {
                    $this->fail('control frames must not be fragmented or longer than 125 bytes');
                }
            } elseif ($opcode > ServerClient::OPCODE_BINARY) {
                $this->fail(sprintf('unknown opcode %d', $opcode));
            } elseif (!$fin || $opcode === ServerClient::OPCODE_CONTINUATION) {
                $this->fail('fragmented messages are not supported');
            }

            switch ($payloadLen) {
                case 126:
                    $hdrLen += 2;
                    if (strlen($data) < $hdrLen) {
                        return;
                    }
                    $payloadLen = unpack('n', $data, 2)[1];
                    break;
                case 127:
                    $hdrLen += 8;
                    if (strlen($data) < $hdrLen) {
                        return;
                    }
                    $payloadLen = unpack('J', $data, 2)[1];
                    if ($payloadLen < 0) {
                        // most significant bit set: invalid, and beyond PHP_INT_MAX anyway
                        $this->fail('payload length out of range', ProtocolException::CLOSE_MESSAGE_TOO_BIG);
                    }
                    break;
            }

            $mask = substr($data, $hdrLen, 4);
            $hdrLen += 4;
            if (strlen($data) < $hdrLen + $payloadLen) {
                return;
            }

            $payload = (string)substr($data, $hdrLen, $payloadLen);
            if ($payloadLen > 0) {
                $payload ^= substr(str_repeat($mask, intdiv($payloadLen, 4) + 1), 0, $payloadLen);
            }

            $data = (string)substr($data, $hdrLen + $payloadLen);

            call_user_func($this->callback, $opcode, $payload);
        }
    }

    /**
     * @throws ProtocolException
     */
    private function fail(string $message, int $code = ProtocolException::CLOSE_PROTOCOL_ERROR): void
    {
        $this->logger->warning('WebSocket protocol error: ' . $message, $this->loggerContext);
        throw new ProtocolException($message, $code);
    }
}

<?php

namespace Uhf\Monocycle\Proto\Mqtt;

/**
 * MQTT 3.1.1 packet framing shared by the client and the server
 */
final class Packet
{
    public const CONNECT     = 1;
    public const CONNACK     = 2;
    public const PUBLISH     = 3;
    public const PUBACK      = 4;
    public const PUBREC      = 5;
    public const PUBREL      = 6;
    public const PUBCOMP     = 7;
    public const SUBSCRIBE   = 8;
    public const SUBACK      = 9;
    public const UNSUBSCRIBE = 10;
    public const UNSUBACK    = 11;
    public const PINGREQ     = 12;
    public const PINGRESP    = 13;
    public const DISCONNECT  = 14;

    /** largest remaining length the 4-byte variable length encoding can carry */
    public const MAX_REMAINING_LENGTH = 268435455;

    public static function encode(int $type, string $varHeader = '', string $payload = '', int $flags = 0): string
    {
        return chr($type << 4 | $flags)
            . self::encodeLength(strlen($varHeader) + strlen($payload))
            . $varHeader
            . $payload;
    }

    public static function encodeLength(int $length): string
    {
        if ($length < 0 || $length > self::MAX_REMAINING_LENGTH) {
            throw new \InvalidArgumentException("MQTT packet too large: $length bytes");
        }
        $encoded = '';
        do {
            $digit = $length % 128;
            $length = intdiv($length, 128);
            if ($length > 0) {
                $digit |= 0x80;
            }
            $encoded .= chr($digit);
        } while ($length > 0);

        return $encoded;
    }

    public static function encodeString(string $value): string
    {
        $length = strlen($value);
        if ($length > 0xFFFF) {
            throw new \InvalidArgumentException("MQTT string too long: $length bytes");
        }

        return chr($length >> 8) . chr($length & 0xFF) . $value;
    }

    public static function packetId(int $id): string
    {
        return chr($id >> 8 & 0xFF) . chr($id & 0xFF);
    }

    public static function readUint16(string $body, int &$offset): int
    {
        if (strlen($body) < $offset + 2) {
            throw new ProtocolException('Truncated MQTT packet');
        }
        $value = (ord($body[$offset]) << 8) | ord($body[$offset + 1]);
        $offset += 2;

        return $value;
    }

    public static function readString(string $body, int &$offset): string
    {
        $length = self::readUint16($body, $offset);
        if (strlen($body) < $offset + $length) {
            throw new ProtocolException('Truncated MQTT packet');
        }
        $value = (string)substr($body, $offset, $length);
        $offset += $length;

        return $value;
    }

    /**
     * Removes one complete packet from the front of $buffer
     *
     * @return array{0:int,1:string}|null [first byte (type and flags), body after the fixed header],
     *                                    null while the packet is incomplete
     * @throws ProtocolException on a malformed remaining length or a packet over $maxSize bytes
     */
    public static function extract(string &$buffer, int $maxSize = self::MAX_REMAINING_LENGTH): ?array
    {
        $available = strlen($buffer);
        if ($available < 2) {
            return null;
        }

        $length = 0;
        $multiplier = 1;
        $i = 1;
        while (true) {
            if ($i > 4) {
                throw new ProtocolException('Malformed MQTT remaining length');
            }
            if (!isset($buffer[$i])) {
                return null;
            }
            $byte = ord($buffer[$i]);
            $length += ($byte & 0x7F) * $multiplier;
            $multiplier *= 128;
            $i++;
            if (($byte & 0x80) === 0) {
                break;
            }
        }

        $total = $i + $length;
        if ($total > $maxSize) {
            throw new ProtocolException("MQTT packet of $total bytes exceeds the limit of $maxSize");
        }
        if ($available < $total) {
            return null;
        }

        $byte1 = ord($buffer[0]);
        $body = (string)substr($buffer, $i, $length);
        $buffer = (string)substr($buffer, $total);

        return [$byte1, $body];
    }
}

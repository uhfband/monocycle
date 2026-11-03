<?php

namespace Uhf\Monocycle\Database;

use JsonException;

/**
 * Database worker wire protocol.
 *
 * Every packet is a 32-bit unsigned big-endian payload length followed by
 * a JSON object payload of that length.
 *
 * Request payload:  {"query_id": <id>, "sql": "<query>"}
 * Response payload: {"query_id": <id>, "result": [<rows>], "affected_rows": <n>, "last_insert_row_id": <n>}
 *               or  {"query_id": <id>, "error": "<description>"}
 * affected_rows and last_insert_row_id are present only when available.
 */
class Protocol
{
    public const HEADER_SIZE = 4;
    public const MAX_PACKET_SIZE = 16 * 1024 * 1024;

    public const FIELD_QUERY_ID = 'query_id';
    public const FIELD_SQL = 'sql';
    public const FIELD_RESULT = 'result';
    public const FIELD_ERROR = 'error';
    public const FIELD_AFFECTED_ROWS = 'affected_rows';
    public const FIELD_LAST_INSERT_ROW_ID = 'last_insert_row_id';

    private const JSON_ENCODE_FLAGS = JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRESERVE_ZERO_FRACTION
        | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_THROW_ON_ERROR;

    private string $buffer = '';
    private int $maxPacketSize;

    public function __construct(int $maxPacketSize = self::MAX_PACKET_SIZE)
    {
        $this->maxPacketSize = $maxPacketSize;
    }

    /**
     * Append received bytes to the internal buffer
     */
    public function feed(string $data): void
    {
        $this->buffer .= $data;
    }

    /**
     * Extract the next complete payload from the buffer
     *
     * @return string|null raw payload, or null if no complete packet is buffered yet
     * @throws ProtocolException when the declared length exceeds the limit;
     *                           the stream can't be resynchronized after that
     */
    public function nextPayload(): ?string
    {
        if (strlen($this->buffer) < self::HEADER_SIZE) {
            return null;
        }

        $length = unpack('N', $this->buffer)[1];
        if ($length > $this->maxPacketSize) {
            throw new ProtocolException(
                "Packet length $length exceeds limit {$this->maxPacketSize}"
            );
        }

        if (strlen($this->buffer) < self::HEADER_SIZE + $length) {
            return null;
        }

        $payload = (string)substr($this->buffer, self::HEADER_SIZE, $length);
        $this->buffer = (string)substr($this->buffer, self::HEADER_SIZE + $length);

        return $payload;
    }

    /**
     * Extract and decode the next complete packet from the buffer
     *
     * @return array|null decoded payload, or null if no complete packet is buffered yet
     * @throws ProtocolException
     */
    public function nextPacket(): ?array
    {
        $payload = $this->nextPayload();

        return $payload === null ? null : self::decode($payload);
    }

    /**
     * @throws ProtocolException
     */
    public static function encode(array $payload): string
    {
        try {
            $json = json_encode($payload, self::JSON_ENCODE_FLAGS);
        } catch (JsonException $e) {
            throw new ProtocolException('Unable to encode payload: ' . $e->getMessage(), 0, $e);
        }

        return pack('N', strlen($json)) . $json;
    }

    /**
     * @throws ProtocolException
     */
    public static function decode(string $payload): array
    {
        try {
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ProtocolException('Invalid JSON payload: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($data)) {
            throw new ProtocolException('Payload must be a JSON object');
        }

        return $data;
    }

    /**
     * @param int|string|null $queryId
     */
    public static function request($queryId, string $sql): string
    {
        return self::encode([
            self::FIELD_QUERY_ID => $queryId,
            self::FIELD_SQL => $sql,
        ]);
    }

    /**
     * @param int|string|null $queryId
     * @throws ProtocolException
     */
    public static function response(
        $queryId,
        array $result,
        ?int $affectedRows = null,
        ?int $lastInsertRowId = null
    ): string
    {
        $payload = [
            self::FIELD_QUERY_ID => $queryId,
            self::FIELD_RESULT => $result,
        ];
        if ($affectedRows !== null) {
            $payload[self::FIELD_AFFECTED_ROWS] = $affectedRows;
        }
        if ($lastInsertRowId !== null) {
            $payload[self::FIELD_LAST_INSERT_ROW_ID] = $lastInsertRowId;
        }

        return self::encode($payload);
    }

    /**
     * @param int|string|null $queryId
     */
    public static function errorResponse($queryId, string $error): string
    {
        return self::encode([
            self::FIELD_QUERY_ID => $queryId,
            self::FIELD_ERROR => $error,
        ]);
    }
}

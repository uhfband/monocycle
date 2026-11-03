<?php

namespace Uhf\Monocycle\Proto\Mqtt\Client;

use Uhf\Monocycle\Monocycle;
use Uhf\Monocycle\Socket\Connection as BaseSocketClient;
use Uhf\Monocycle\Timer\Timer;

class Client extends BaseSocketClient
{
    public const PACKET_TYPE_CONNECT    = 1;
    public const PACKET_TYPE_CONNACK    = 2;

    public const PACKET_TYPE_PUBLISH    = 3;
    public const PACKET_TYPE_PUBACK     = 4;
    public const PACKET_TYPE_PUBREC     = 5;

    public const PACKET_TYPE_SUBSCRIBE  = 8;
    public const PACKET_TYPE_SUBACK     = 9;
    public const PACKET_TYPE_PINGREQ    = 12;
    public const PACKET_TYPE_PINGRESP   = 13;
    public const PACKET_TYPE_DISCONNECT = 14;

    public const CMD_CONNECT      = 0x10; // self::PACKET_TYPE_CONNECT << 4
    public const CMD_PUBLISH_BASE = 0x30; // self::PACKET_TYPE_PUBLISH << 4
    public const CMD_SUBSCRIBE    = 0x82; // self::PACKET_TYPE_SUBSCRIBE << 4 | 0x02
    public const CMD_PINGREQ      = 0xC0; // self::PACKET_TYPE_PINGREQ << 4
    public const CMD_DISCONNECT   = 0xE0; // self::PACKET_TYPE_DISCONNECT << 4

    /** @var callable */
    public $onMessage;

    private string $address;
    private ?int $cryptoMethod;
    private ?array $sslOptions;

    private string $clientId;
    private int $keepAlive;
    private bool $cleanSession;
    private int $packetId = 0;

    private string $readBuffer = '';
    private int $lastActivity;

    private ?Timer $keepAliveTimer;

    public function __construct(
        string $url,
        string $clientId,
        int $keepAlive = 30,
        bool $cleanSession = true,
        ?int $timeout = null,
        ?string $localCert = null,
        ?string $localKey = null,
        ?string $cafile = null
    ) {
        $cryptoMethod = null;
        $sslOptions = null;

        $parsedUrl = parse_url($url);
        switch ($parsedUrl['scheme']) {
            case 'tlsv1.0':
                $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT;
                break;
            case 'tlsv1.1':
                $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT;
                break;
            case 'tlsv1.2':
                $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                break;
        }
        if ($cryptoMethod) {
            $sslOptions = [
                'verify_peer'       => true,
                'verify_peer_name'  => false,
                'allow_self_signed' => false,

                'local_cert'  => $localCert,
                'local_pk'    => $localKey,

                'cafile' => $cafile,
            ];
        }

        $this->address = sprintf('tcp://%s:%s', $parsedUrl['host'], $parsedUrl['port']);
        $this->cryptoMethod = $cryptoMethod;
        $this->sslOptions = $sslOptions;

        // connect timeout as in Connection; with pings every keepAlive-2 seconds the broker
        // answers well within the read timeout, so silence means the connection is dead
        $this->setConnectTimeout(10)
            ->setReadTimeout($timeout ?? ($keepAlive + 10));

        $this->clientId = $clientId;
        $this->keepAlive = $keepAlive;
        $this->cleanSession = $cleanSession;
        $this->lastActivity = time();
    }

    /**
     * The address and TLS settings default to the ones derived from the broker URL
     */
    public function connect(?string $remoteAddress = null, ?int $cryptoMethod = null, ?array $sslOptions = null)
    {
        return parent::connect(
            $remoteAddress ?? $this->address,
            $cryptoMethod ?? $this->cryptoMethod,
            $sslOptions ?? $this->sslOptions
        );
    }

    protected function onConnect()
    {
        parent::onConnect();
        Monocycle::getLogger()
            ->notice(
                sprintf(
                    "[%s] connected to %s:%d",
                __CLASS__,
                    $this->getRemoteHost(),
                    $this->getRemotePort()
                )
            );
        
        $this->sendConnectPacket();
    }

    protected function onConnectionError($message)
    {
        Monocycle::getLogger()->error(
            sprintf(
                "[%s] connect error: %s",
                __CLASS__,
                $message
            )
        );
        parent::onConnectionError($message);
    }

    protected function onDisconnect()
    {
        if (!empty($this->keepAliveTimer)) {
            $this->keepAliveTimer->stop();
        }
        Monocycle::getLogger()->warning(
            sprintf("[%s] %s:%d disconnected",
                __CLASS__,
                $this->getRemoteHost(),
                $this->getRemotePort()
            )
        );
        parent::onDisconnect();
    }

    /**
     * Subscribe to one or more topics.
     *
     * @param string[] $topics
     * @param int      $qos
     */
    public function subscribe(array $topics, int $qos = 0): void
    {
        $payload = '';
        foreach ($topics as $topic) {
            $payload .= $this->encodeString($topic) . chr($qos);
        }

        $packetId = $this->nextPacketId();
        $variableHeader = chr($packetId >> 8) . chr($packetId & 0xFF);

        $this->sendPacket(self::PACKET_TYPE_SUBSCRIBE, $variableHeader, $payload);
    }

    public function publish(string $topic, string $payload, int $qos = 0, bool $retain = false): void
    {
        if ($qos < 0 || $qos > 2) {
            throw new \InvalidArgumentException('QoS must be 0, 1 or 2');
        }

        $flags = ($qos << 1) | ($retain ? 0x01 : 0x00);
        $variableHeader = $this->encodeString($topic);
        
        if ($qos > 0) {
            $packetId = $this->nextPacketId();
            $variableHeader .= chr($packetId >> 8) . chr($packetId & 0xFF);
        }

        $this->sendPacket(self::PACKET_TYPE_PUBLISH, $variableHeader, $payload, $flags);
    }

    public function ping(): void
    {
        $this->sendPacket(self::PACKET_TYPE_PINGREQ);
    }

    public function close(bool $graceful = true): void
    {
        if ($this->isClosed()) {
            return;
        }
        $this->sendPacket(self::PACKET_TYPE_DISCONNECT);
        if (!empty($this->keepAliveTimer)) {
            $this->keepAliveTimer->stop();
        }
        parent::close($graceful);
    }

    protected function onData(string $data)
    {
        parent::onData($data);
        $this->readBuffer .= $data;
        $this->lastActivity = time();

        Monocycle::getLogger()
            ->debug('<<< ' . bin2hex($this->readBuffer));

        while (true) {
            if (strlen($this->readBuffer) < 2) {
                return;
            }

            $data = $this->readBuffer;
            $byte1 = ord($data[0]);

            [$remainingLength, $lengthBytes] = $this->decodeLength(substr($data, 1));
            if ($remainingLength === null) {
                // Not enough data to determine remaining length yet.
                return;
            }

            $totalLength = 1 + $lengthBytes + $remainingLength;
            if (strlen($data) < $totalLength) {
                // Wait for the rest of the packet.
                return;
            }

            $packet = substr($data, 0, $totalLength);
            $this->readBuffer = substr($data, $totalLength);

            $this->handlePacket($packet, $byte1);
        }
    }

    protected function onDrain()
    {
        parent::onDrain();
        $this->lastActivity = time();
    }

    protected function onMessage(string $topic, string $payload)
    {
        if (is_callable($this->onMessage)) {
            call_user_func($this->onMessage, $this, $topic, $payload);
        }
    }

    protected function onMqttConnect(): void
    {
        Monocycle::getLogger()->notice("MQTT connected", ['class' => __CLASS__, 'method' => __METHOD__]);
        $this->keepAliveTimer = new Timer(
            function () {
                $this->ping();
            }
        );
        $this->keepAliveTimer->setInterval($this->keepAlive - 2)->start();
    }

    protected function onClose(): void
    {
        $this->keepAliveTimer = null;
        parent::onClose();
    }

    private function sendPacket($type, $varHeader = null, $payload = null, int $flags = 0): void
    {
        $fixedHeader = chr($type << 4 | $flags);
        $packet = $fixedHeader . chr(strlen($varHeader) + strlen($payload)) . $varHeader . $payload;
        Monocycle::getLogger()->debug(">>> " . bin2hex($packet));
        // no keepAliveTimer restart here: pings must keep going while publishing,
        // their PINGRESP is the only inbound traffic a publish-only client gets
        $this->write($packet);
    }

    private function sendConnectPacket(): void
    {
        $protocolName  = $this->encodeString('MQTT');
        $protocolLevel = chr(4); // MQTT 3.1.1

        $connectFlags = 0;
        if ($this->cleanSession) {
            $connectFlags |= 0x02;
        }

        $variableHeader =
            $protocolName .
            $protocolLevel .
            chr($connectFlags) .
            chr($this->keepAlive >> 8) .
            chr($this->keepAlive & 0xFF);

        $payload = $this->encodeString($this->clientId);

        $this->sendPacket(self::PACKET_TYPE_CONNECT, $variableHeader, $payload);
    }

    private function handlePacket(string $packet, int $byte1): void
    {
        $type = ($byte1 & 0xF0) >> 4;

        switch ($type) {
            case self::PACKET_TYPE_CONNACK: // CONNACK
                $this->handleConnAck($packet);
                break;

            case self::PACKET_TYPE_PUBLISH: // PUBLISH
                $this->handlePublish($packet, $byte1);
                break;

            case self::PACKET_TYPE_SUBACK: // SUBACK
                // For now we just ignore the contents.
                break;

            case self::PACKET_TYPE_PINGRESP: // PINGRESP
                // nothing to do
                break;

            default:
                // ignore other packet types
                break;
        }
    }

    private function handleConnAck(string $packet): void
    {
        $offset = 1;
        [$remainingLength, $lengthBytes] = $this->decodeLength(substr($packet, $offset));
        if ($remainingLength === null) {
            return;
        }
        $offset += $lengthBytes;

        if ($remainingLength < 2 || strlen($packet) < $offset + 2) {
            return;
        }

        $sessionPresent = (ord($packet[$offset]) & 0x01) !== 0;
        $code = ord($packet[$offset + 1]);

        if ($code === 0) {
            $this->onMqttConnect();
        } else {
            error_log(sprintf("[%s] MQTT CONNACK error code %d", __CLASS__, $code));
            $this->close();
        }
    }

    private function handlePublish(string $packet, int $byte1): void
    {
        $flags = $byte1 & 0x0F;
        $qos = ($flags & 0x06) >> 1;

        $offset = 1;
        [$remainingLength, $lengthBytes] = $this->decodeLength(substr($packet, $offset));
        if ($remainingLength === null) {
            return;
        }
        $offset += $lengthBytes;

        if ($remainingLength <= 0) {
            return;
        }

        if (strlen($packet) < $offset + 2) {
            return;
        }

        $topicLength = (ord($packet[$offset]) << 8) + ord($packet[$offset + 1]);
        $offset += 2;

        if (strlen($packet) < $offset + $topicLength) {
            return;
        }

        $topic = substr($packet, $offset, $topicLength);
        $offset += $topicLength;
        $variableHeader = '';

        switch ($qos) {
            case 0:
                //nothing
                break;
            case 1:
                $packetId = (ord($packet[$offset]) << 8) + ord($packet[$offset + 1]);
                $variableHeader .= chr($packetId >> 8) . chr($packetId & 0xFF);
                $this->sendPacket(self::PACKET_TYPE_PUBACK, $variableHeader);
                break;
            case 2:
                $packetId = (ord($packet[$offset]) << 8) + ord($packet[$offset + 1]);
                $variableHeader .= chr($packetId >> 8) . chr($packetId & 0xFF);
                $this->sendPacket(self::PACKET_TYPE_PUBREC, $variableHeader);
                break;

            default:
                Monocycle::getLogger()
                    ->error("QOS value error: $qos", ['file' => __FILE__, 'line' => __LINE__]);
                return;
        }

        $payload = substr($packet, $offset);
        
        $this->onMessage($topic, $payload);
    }

    private function encodeString(string $value): string
    {
        $length = strlen($value);

        return chr($length >> 8) . chr($length & 0xFF) . $value;
    }

    private function encodeLength(int $length): string
    {
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

    /**
     * @return array{0:int|null,1:int}
     */
    private function decodeLength(string $data): array
    {
        $multiplier = 1;
        $value = 0;
        $i = 0;

        while (true) {
            if (!isset($data[$i])) {
                return [null, 0];
            }
            $encodedByte = ord($data[$i]);
            $value += ($encodedByte & 0x7F) * $multiplier;
            $multiplier *= 128;
            $i++;

            if (($encodedByte & 0x80) === 0) {
                break;
            }

            if ($i > 4) {
                // Malformed Remaining Length
                return [null, 0];
            }
        }

        return [$value, $i];
    }

    private function nextPacketId(): int
    {
        $this->packetId++;
        if ($this->packetId > 0xFFFF) {
            $this->packetId = 1;
        }

        return $this->packetId;
    }
}

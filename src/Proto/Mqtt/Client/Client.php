<?php

namespace Uhf\Monocycle\Proto\Mqtt\Client;

use Uhf\Monocycle\Monocycle;
use Uhf\Monocycle\Proto\Mqtt\Packet;
use Uhf\Monocycle\Proto\Mqtt\ProtocolException;
use Uhf\Monocycle\Socket\Connection as BaseSocketClient;
use Uhf\Monocycle\Timer\Timer;

class Client extends BaseSocketClient
{
    public const PACKET_TYPE_CONNECT    = 1;
    public const PACKET_TYPE_CONNACK    = 2;

    public const PACKET_TYPE_PUBLISH    = 3;
    public const PACKET_TYPE_PUBACK     = 4;
    public const PACKET_TYPE_PUBREC     = 5;
    public const PACKET_TYPE_PUBREL     = 6;
    public const PACKET_TYPE_PUBCOMP    = 7;

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
    public $onMqttConnect;

    /** @var callable */
    public $onMessage;

    private string $address;
    private ?int $cryptoMethod;
    private ?array $sslOptions;

    private string $clientId;
    private int $keepAlive;
    private bool $cleanSession;
    private ?string $username;
    private ?string $password;
    /** @var array{topic:string,message:string,qos:int,retain:bool}|null */
    private ?array $will = null;
    private int $packetId = 0;
    private bool $mqttConnected = false;
    /** @var string[] packets written before CONNACK, sent once the session is up */
    private array $pendingPackets = [];

    private string $readBuffer = '';
    private int $lastActivity;

    private ?Timer $keepAliveTimer = null;

    public function __construct(
        string $url,
        string $clientId,
        int $keepAlive = 30,
        bool $cleanSession = true,
        ?int $timeout = null
    ) {
        $cryptoMethod = null;
        $sslOptions = null;

        $parsedUrl = parse_url($url);
        switch ($parsedUrl['scheme']) {
            case 'tls':
                $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;
                break;
            case 'tlsv1.0':
                $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT;
                break;
            case 'tlsv1.1':
                $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT;
                break;
            case 'tlsv1.2':
                $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                break;
            case 'tlsv1.3':
                $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
                break;
        }
        if ($cryptoMethod) {
            $sslOptions = [
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                'allow_self_signed' => false,

                'local_cert'  => null,
                'local_pk'    => null,

                'cafile' => null,
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
        // credentials from the URL (mqtt://user:pass@host:port); setUsername()/setPassword() override them
        $this->username = isset($parsedUrl['user']) ? rawurldecode($parsedUrl['user']) : null;
        $this->password = isset($parsedUrl['pass']) ? rawurldecode($parsedUrl['pass']) : null;
        $this->checkCredentials();
        $this->lastActivity = time();
    }

    public function setUsername(string $username): self
    {
        $this->username = $username;

        return $this;
    }

    public function setPassword(string $password): self
    {
        $this->password = $password;

        return $this;
    }

    /**
     * True once the broker has accepted CONNECT (CONNACK), until the connection closes
     */
    public function isMqttConnected(): bool
    {
        return $this->mqttConnected;
    }

    /**
     * Last Will: published by the broker when this client goes away without sending DISCONNECT
     */
    public function setWill(string $topic, string $message, int $qos = 0, bool $retain = false): self
    {
        if ($qos < 0 || $qos > 2) {
            throw new \InvalidArgumentException('QoS must be 0, 1 or 2');
        }
        $this->will = ['topic' => $topic, 'message' => $message, 'qos' => $qos, 'retain' => $retain];

        return $this;
    }

    public function setTlsVerifyPeer(bool $verifyPeer): self
    {
        $this->sslOptions['verify_peer'] = $verifyPeer;

        return $this;
    }

    public function setTlsVerifyPeerName(bool $verifyPeerName): self
    {
        $this->sslOptions['verify_peer_name'] = $verifyPeerName;

        return $this;
    }

    public function setTlsSelfSignedAllowed(bool $selfSigned): self
    {
        $this->sslOptions['allow_self_signed'] = $selfSigned;

        return $this;
    }

    public function setTlsCertificateAuthorityFile(string $cafile): self
    {
        $this->sslOptions['cafile'] = $cafile;

        return $this;
    }

    public function setTlsClientCertificateFile(string $clientCert): self
    {
        $this->sslOptions['local_cert'] = $clientCert;

        return $this;
    }

    public function setTlsClientCertificateKeyFile(string $clientCertKey): self
    {
        $this->sslOptions['local_pk'] = $clientCertKey;

        return $this;
    }

    /**
     * The address and TLS settings default to the ones derived from the broker URL
     */
    public function connect(?string $remoteAddress = null, ?int $cryptoMethod = null, ?array $sslOptions = null)
    {
        $this->checkCredentials();

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

        // MQTT 3.1.1 requires the reserved flags 0b0010 on SUBSCRIBE
        $this->sendPacket(self::PACKET_TYPE_SUBSCRIBE, $variableHeader, $payload, 0x02);
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
        if ($this->getState() === self::STATE_CLOSED) {
            return;
        }
        // DISCONNECT only makes sense on an established MQTT session that can still be written to
        if ($graceful && $this->mqttConnected && $this->getState() === self::STATE_CONNECTED) {
            $this->sendPacket(self::PACKET_TYPE_DISCONNECT);
        }
        $this->mqttConnected = false;
        $this->pendingPackets = [];
        $this->stopKeepAliveTimer();
        parent::close($graceful);
    }

    protected function onData(string $data)
    {
        parent::onData($data);
        $this->readBuffer .= $data;
        $this->lastActivity = time();

        Monocycle::getLogger()
            ->debug('<<< ' . bin2hex($this->readBuffer));

        try {
            while (($packet = Packet::extract($this->readBuffer)) !== null) {
                $this->handlePacket($packet[0], $packet[1]);
            }
        } catch (ProtocolException $e) {
            Monocycle::getLogger()->error(sprintf('[%s] %s', __CLASS__, $e->getMessage()));
            $this->close(false);
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
        $this->mqttConnected = true;
        if (is_callable($this->onMqttConnect)) {
            call_user_func($this->onMqttConnect, $this);
        }
        foreach ($this->pendingPackets as $packet) {
            $this->write($packet);
        }
        $this->pendingPackets = [];
        $this->stopKeepAliveTimer();
        $this->keepAliveTimer = new Timer(
            function () {
                $this->ping();
            }
        );
        $this->keepAliveTimer->setInterval($this->keepAlive - 2)->start();
    }

    protected function onClose(): void
    {
        $this->mqttConnected = false;
        $this->pendingPackets = [];
        $this->stopKeepAliveTimer();
        parent::onClose();
    }

    /**
     * MQTT 3.1.1: a password requires a username
     */
    private function checkCredentials(): void
    {
        if ($this->password !== null && $this->username === null) {
            throw new \InvalidArgumentException('MQTT password requires a username');
        }
    }

    private function stopKeepAliveTimer(): void
    {
        if ($this->keepAliveTimer !== null) {
            $this->keepAliveTimer->stop();
            $this->keepAliveTimer = null;
        }
    }

    private function sendPacket(int $type, string $varHeader = '', string $payload = '', int $flags = 0): void
    {
        $packet = Packet::encode($type, $varHeader, $payload, $flags);
        Monocycle::getLogger()->debug(">>> " . bin2hex($packet));
        // no keepAliveTimer restart here: pings must keep going while publishing,
        // their PINGRESP is the only inbound traffic a publish-only client gets
        if (!$this->mqttConnected && $type !== self::PACKET_TYPE_CONNECT) {
            // CONNECT must be the first packet, so hold back whatever is written while connecting
            $this->pendingPackets[] = $packet;

            return;
        }
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
        if ($this->username !== null) {
            $connectFlags |= 0x80;
        }
        if ($this->password !== null) {
            $connectFlags |= 0x40;
        }
        if ($this->will !== null) {
            $connectFlags |= 0x04 | ($this->will['qos'] << 3) | ($this->will['retain'] ? 0x20 : 0);
        }

        $variableHeader =
            $protocolName .
            $protocolLevel .
            chr($connectFlags) .
            chr($this->keepAlive >> 8) .
            chr($this->keepAlive & 0xFF);

        // payload order is fixed: client id, (will topic/message), username, password
        $payload = $this->encodeString($this->clientId);
        if ($this->will !== null) {
            $payload .= $this->encodeString($this->will['topic']) . $this->encodeString($this->will['message']);
        }
        if ($this->username !== null) {
            $payload .= $this->encodeString($this->username);
        }
        if ($this->password !== null) {
            $payload .= $this->encodeString($this->password);
        }

        $this->sendPacket(self::PACKET_TYPE_CONNECT, $variableHeader, $payload);
    }

    /**
     * @param int    $byte1 packet type and flags
     * @param string $body  the packet after the fixed header
     * @throws ProtocolException
     */
    private function handlePacket(int $byte1, string $body): void
    {
        switch ($byte1 >> 4) {
            case Packet::CONNACK:
                $this->handleConnAck($body);
                break;

            case Packet::PUBLISH:
                $this->handlePublish($byte1, $body);
                break;

            case Packet::PUBREC:
                // QoS 2 publish, step 2: the broker only delivers the message once it gets PUBREL
                // (MQTT 3.1.1 requires the reserved flags 0b0010 on PUBREL)
                $this->sendPacket(Packet::PUBREL, substr($body, 0, 2), '', 0x02);
                break;

            case Packet::PUBREL:
                // QoS 2 delivery, step 3: complete the exchange started by our PUBREC
                $this->sendPacket(Packet::PUBCOMP, substr($body, 0, 2));
                break;

            default:
                // SUBACK, UNSUBACK, PUBACK, PUBCOMP, PINGRESP: nothing to do
                break;
        }
    }

    private function handleConnAck(string $body): void
    {
        if (strlen($body) < 2) {
            throw new ProtocolException('Truncated CONNACK');
        }
        $code = ord($body[1]);

        if ($code === 0) {
            $this->onMqttConnect();
        } else {
            error_log(sprintf("[%s] MQTT CONNACK error code %d", __CLASS__, $code));
            $this->close();
        }
    }

    private function handlePublish(int $byte1, string $body): void
    {
        $qos = ($byte1 & 0x06) >> 1;
        $offset = 0;
        $topic = Packet::readString($body, $offset);

        switch ($qos) {
            case 0:
                break;
            case 1:
            case 2:
                // the packet id follows the topic and is not part of the payload
                $packetId = substr($body, $offset, 2);
                Packet::readUint16($body, $offset);
                $this->sendPacket($qos === 1 ? Packet::PUBACK : Packet::PUBREC, $packetId);
                break;
            default:
                throw new ProtocolException("Invalid QoS $qos");
        }

        $this->onMessage($topic, (string)substr($body, $offset));
    }

    private function encodeString(string $value): string
    {
        return Packet::encodeString($value);
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

<?php

namespace Uhf\Monocycle\Proto\Mqtt\Server;

use Uhf\Monocycle\Monocycle;
use Uhf\Monocycle\Proto\Mqtt\Packet;
use Uhf\Monocycle\Proto\Mqtt\ProtocolException;
use Uhf\Monocycle\Proto\Mqtt\Topic;
use Uhf\Monocycle\Socket\Connection;
use Uhf\Monocycle\Stream\StreamException;

/**
 * One MQTT client connection accepted by Mqtt\Server\Server
 */
class ServerClient extends Connection
{
    private ?Server $server = null;
    private int $maxPacketSize = Packet::MAX_REMAINING_LENGTH;
    private string $readBuffer = '';

    private bool $mqttConnected = false;
    private ?string $clientId = null;
    private ?string $username = null;
    private int $keepAlive = 0;
    /** @var array{topic:string,message:string,qos:int,retain:bool}|null */
    private ?array $will = null;

    /** @var array<string, int> granted QoS by topic filter */
    private array $subscriptions = [];
    /** @var array<int, true> ids of QoS 1 messages sent and not acknowledged yet */
    private array $inflight = [];
    /** @var array<int, true> ids of QoS 2 messages received and not released yet */
    private array $inboundQos2 = [];
    private int $packetId = 0;

    /**
     * @internal called by Server for each accepted connection
     */
    public function attach(Server $server, int $maxPacketSize): void
    {
        $this->server = $server;
        $this->maxPacketSize = $maxPacketSize;
    }

    public function getClientId(): ?string
    {
        return $this->clientId;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function getKeepAlive(): int
    {
        return $this->keepAlive;
    }

    public function isMqttConnected(): bool
    {
        return $this->mqttConnected;
    }

    /**
     * @return array<string, int> granted QoS by topic filter
     */
    public function getSubscriptions(): array
    {
        return $this->subscriptions;
    }

    /**
     * Highest QoS among this client's subscriptions matching $topic, null if none matches
     */
    public function getMaxQos(string $topic): ?int
    {
        $max = null;
        foreach ($this->subscriptions as $filter => $qos) {
            if (($max === null || $qos > $max) && Topic::matches((string)$filter, $topic)) {
                $max = $qos;
            }
        }

        return $max;
    }

    /**
     * Sends a PUBLISH to this client; a full write buffer (slow subscriber) drops the connection
     */
    public function deliver(string $topic, string $payload, int $qos, bool $retain): void
    {
        if (!$this->mqttConnected || $this->closing || $this->getState() !== self::STATE_CONNECTED) {
            return;
        }
        $qos = min($qos, 1);
        $varHeader = Packet::encodeString($topic);
        if ($qos > 0) {
            $packetId = $this->nextPacketId();
            if ($packetId === null) {
                $this->log('warning', 'too many unacknowledged messages, disconnecting');
                $this->close(false);

                return;
            }
            $this->inflight[$packetId] = true;
            $varHeader .= Packet::packetId($packetId);
        }

        try {
            $this->write(Packet::encode(Packet::PUBLISH, $varHeader, $payload, ($qos << 1) | ($retain ? 1 : 0)));
        } catch (StreamException $e) {
            $this->log('warning', 'write buffer full, disconnecting: ' . $e->getMessage());
            $this->close(false);
        }
    }

    protected function onData(string $data)
    {
        parent::onData($data);
        if ($this->closing) {
            // refused CONNECT or DISCONNECT, waiting for the write buffer to drain
            return;
        }
        $this->readBuffer .= $data;

        try {
            while (!$this->closing && $this->getState() !== self::STATE_CLOSED
                && ($packet = Packet::extract($this->readBuffer, $this->maxPacketSize)) !== null
            ) {
                $this->handlePacket($packet[0], $packet[1]);
            }
        } catch (ProtocolException $e) {
            $this->log('warning', 'protocol error: ' . $e->getMessage());
            $this->close(false);
        }
    }

    protected function onClose(): void
    {
        $will = $this->will;
        $this->will = null;
        $wasConnected = $this->mqttConnected;
        $this->mqttConnected = false;
        $this->readBuffer = '';

        if ($this->server !== null && $wasConnected) {
            $this->server->unregister($this);
            $this->log('notice', 'disconnected');
            if ($will !== null) {
                $this->server->route($this, $will['topic'], $will['message'], $will['qos'], $will['retain']);
            }
        }
        parent::onClose();
    }

    /**
     * @throws ProtocolException
     */
    private function handlePacket(int $byte1, string $body): void
    {
        $type = $byte1 >> 4;
        $flags = $byte1 & 0x0F;

        if (!$this->mqttConnected) {
            if ($type !== Packet::CONNECT) {
                throw new ProtocolException('First packet must be CONNECT');
            }
            $this->handleConnect($body);

            return;
        }

        switch ($type) {
            case Packet::PUBLISH:
                $this->handlePublish($byte1, $body);
                break;

            case Packet::PUBACK:
                $offset = 0;
                unset($this->inflight[Packet::readUint16($body, $offset)]);
                break;

            case Packet::PUBREL:
                $this->expectFlags($flags, 0x02, 'PUBREL');
                $offset = 0;
                $packetId = Packet::readUint16($body, $offset);
                unset($this->inboundQos2[$packetId]);
                $this->send(Packet::PUBCOMP, Packet::packetId($packetId));
                break;

            case Packet::SUBSCRIBE:
                $this->expectFlags($flags, 0x02, 'SUBSCRIBE');
                $this->handleSubscribe($body);
                break;

            case Packet::UNSUBSCRIBE:
                $this->expectFlags($flags, 0x02, 'UNSUBSCRIBE');
                $this->handleUnsubscribe($body);
                break;

            case Packet::PINGREQ:
                $this->send(Packet::PINGRESP);
                break;

            case Packet::DISCONNECT:
                // a clean disconnect discards the will
                $this->will = null;
                $this->close();
                break;

            case Packet::CONNECT:
                throw new ProtocolException('Second CONNECT');

            default:
                // PUBREC/PUBCOMP can't happen as the broker never sends QoS 2; the rest are server-to-client
                throw new ProtocolException("Unexpected packet type $type");
        }
    }

    private function handleConnect(string $body): void
    {
        $offset = 0;
        $protocolName = Packet::readString($body, $offset);
        if (strlen($body) < $offset + 2) {
            throw new ProtocolException('Truncated CONNECT');
        }
        $level = ord($body[$offset++]);
        $connectFlags = ord($body[$offset++]);
        $keepAlive = Packet::readUint16($body, $offset);

        if ($protocolName !== 'MQTT' || $level !== 4) {
            $this->refuse(Server::CONNACK_UNACCEPTABLE_PROTOCOL, "unsupported protocol $protocolName level $level");

            return;
        }
        if ($connectFlags & 0x01) {
            throw new ProtocolException('Reserved CONNECT flag set');
        }
        $cleanSession = ($connectFlags & 0x02) !== 0;
        $clientId = Packet::readString($body, $offset);

        $will = null;
        $willQos = ($connectFlags >> 3) & 0x03;
        $willRetain = ($connectFlags & 0x20) !== 0;
        if ($connectFlags & 0x04) {
            $willTopic = Packet::readString($body, $offset);
            $willMessage = Packet::readString($body, $offset);
            if ($willQos > 2 || !Topic::isValidName($willTopic)) {
                throw new ProtocolException('Invalid will');
            }
            $will = ['topic' => $willTopic, 'message' => $willMessage, 'qos' => $willQos, 'retain' => $willRetain];
        } elseif ($willQos !== 0 || $willRetain) {
            throw new ProtocolException('Will QoS/retain set without a will');
        }

        $username = ($connectFlags & 0x80) ? Packet::readString($body, $offset) : null;
        if ($connectFlags & 0x40) {
            if ($username === null) {
                throw new ProtocolException('Password without username');
            }
            $password = Packet::readString($body, $offset);
        } else {
            $password = null;
        }

        if ($clientId === '') {
            if (!$cleanSession) {
                $this->refuse(Server::CONNACK_IDENTIFIER_REJECTED, 'empty client id without clean session');

                return;
            }
            $clientId = 'monocycle-' . bin2hex(random_bytes(8));
        }

        $code = $this->server->authenticate($this, $clientId, $username, $password);
        if ($code !== Server::CONNACK_ACCEPTED) {
            $this->refuse($code, "client $clientId refused");

            return;
        }

        $this->clientId = $clientId;
        $this->username = $username;
        $this->keepAlive = $keepAlive;
        $this->will = $will;
        $this->mqttConnected = true;
        // MQTT 3.1.1 3.1.2.10: drop a client silent for one and a half keep-alive periods
        $this->setReadTimeout($keepAlive > 0 ? (int)ceil($keepAlive * 1.5) : 0);
        $this->server->register($this);

        // session present is always 0, sessions are not persisted
        $this->send(Packet::CONNACK, "\x00" . chr(Server::CONNACK_ACCEPTED));
        $this->log('notice', 'connected');
    }

    private function handlePublish(int $byte1, string $body): void
    {
        $qos = ($byte1 >> 1) & 0x03;
        $retain = ($byte1 & 0x01) !== 0;
        if ($qos === 3) {
            throw new ProtocolException('Invalid QoS 3');
        }

        $offset = 0;
        $topic = Packet::readString($body, $offset);
        if (!Topic::isValidName($topic)) {
            throw new ProtocolException("Invalid topic name in PUBLISH");
        }
        $packetId = $qos > 0 ? Packet::readUint16($body, $offset) : null;
        $payload = (string)substr($body, $offset);

        if ($qos === 1) {
            $this->send(Packet::PUBACK, Packet::packetId($packetId));
        } elseif ($qos === 2) {
            $this->send(Packet::PUBREC, Packet::packetId($packetId));
            if (isset($this->inboundQos2[$packetId])) {
                // a resend before PUBREL: already delivered
                return;
            }
            $this->inboundQos2[$packetId] = true;
        }

        $this->server->route($this, $topic, $payload, $qos, $retain);
    }

    private function handleSubscribe(string $body): void
    {
        $offset = 0;
        $packetId = Packet::readUint16($body, $offset);
        $granted = '';
        $added = [];
        while ($offset < strlen($body)) {
            $filter = Packet::readString($body, $offset);
            if (!isset($body[$offset])) {
                throw new ProtocolException('Truncated SUBSCRIBE');
            }
            $requested = ord($body[$offset++]);
            if ($requested > 2) {
                throw new ProtocolException('Invalid requested QoS');
            }
            if (!Topic::isValidFilter($filter)) {
                $granted .= "\x80";
                continue;
            }
            $qos = min($requested, 1);
            $this->subscriptions[$filter] = $qos;
            $added[$filter] = $qos;
            $granted .= chr($qos);
        }
        if ($granted === '') {
            throw new ProtocolException('SUBSCRIBE without topic filters');
        }

        $this->send(Packet::SUBACK, Packet::packetId($packetId), $granted);

        foreach ($added as $filter => $qos) {
            foreach ($this->server->getRetained((string)$filter) as $topic => [$payload, $retainedQos]) {
                $this->deliver((string)$topic, $payload, min($qos, $retainedQos), true);
            }
        }
    }

    private function handleUnsubscribe(string $body): void
    {
        $offset = 0;
        $packetId = Packet::readUint16($body, $offset);
        if ($offset >= strlen($body)) {
            throw new ProtocolException('UNSUBSCRIBE without topic filters');
        }
        while ($offset < strlen($body)) {
            unset($this->subscriptions[Packet::readString($body, $offset)]);
        }
        $this->send(Packet::UNSUBACK, Packet::packetId($packetId));
    }

    /**
     * Answers CONNECT with an error code and closes once it is sent
     */
    private function refuse(int $code, string $reason): void
    {
        $this->log('warning', "CONNECT refused ($code): $reason");
        $this->send(Packet::CONNACK, "\x00" . chr($code));
        $this->close();
    }

    private function expectFlags(int $flags, int $expected, string $packet): void
    {
        if ($flags !== $expected) {
            throw new ProtocolException("Invalid $packet flags");
        }
    }

    private function send(int $type, string $varHeader = '', string $payload = ''): void
    {
        try {
            $this->write(Packet::encode($type, $varHeader, $payload));
        } catch (StreamException $e) {
            $this->log('warning', 'write buffer full, disconnecting: ' . $e->getMessage());
            $this->close(false);
        }
    }

    /**
     * Next packet id not waiting for a PUBACK, null when all 65535 are in flight
     */
    private function nextPacketId(): ?int
    {
        for ($i = 0; $i < 0xFFFF; $i++) {
            $this->packetId = $this->packetId % 0xFFFF + 1;
            if (!isset($this->inflight[$this->packetId])) {
                return $this->packetId;
            }
        }

        return null;
    }

    private function log(string $level, string $message): void
    {
        Monocycle::getLogger()->$level(sprintf(
            '[%s] %s (%s): %s',
            __CLASS__,
            $this->clientId ?? '-',
            $this->remoteAddress ?? '?',
            $message
        ));
    }
}

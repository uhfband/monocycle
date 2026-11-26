<?php

namespace Uhf\Monocycle\Proto\Mqtt\Server;

use Uhf\Monocycle\Proto\Mqtt\Topic;
use Uhf\Monocycle\Socket\Connection;
use Uhf\Monocycle\Socket\Server as SocketServer;

/**
 * In-memory MQTT 3.1.1 broker.
 *
 * Delivers at QoS 0 and 1 (a QoS 2 subscription is granted QoS 1; a QoS 2 publish is still
 * completed with PUBREC/PUBREL/PUBCOMP). Supports retained messages and Last Will. Sessions
 * are not persisted: cleanSession=0 is accepted but treated as a clean session.
 */
class Server extends SocketServer
{
    public const CONNACK_ACCEPTED = 0x00;
    public const CONNACK_UNACCEPTABLE_PROTOCOL = 0x01;
    public const CONNACK_IDENTIFIER_REJECTED = 0x02;
    public const CONNACK_SERVER_UNAVAILABLE = 0x03;
    public const CONNACK_BAD_CREDENTIALS = 0x04;
    public const CONNACK_NOT_AUTHORIZED = 0x05;

    /**
     * function (Server $server, ServerClient $client, string $clientId, ?string $username, ?string $password): bool|int
     * true (or CONNACK_ACCEPTED) accepts the connection, false refuses it with CONNACK_NOT_AUTHORIZED,
     * any other int is sent as the CONNACK return code. Without it every client is accepted.
     *
     * @var callable|null
     */
    public $onAuthenticate = null;

    /**
     * function (Server $server, ?ServerClient $from, string $topic, string $payload, int $qos, bool $retain)
     * Called for every message routed by the broker; $from is null for Server::publish()
     *
     * @var callable|null
     */
    public $onMessage = null;

    /** @var ServerClient[] connected sessions by client id */
    private array $clients = [];
    /** @var array<string, array{0:string,1:int}> retained [payload, qos] by topic */
    private array $retained = [];

    private int $maxPacketSize = 1048576;
    private int $connectTimeout = 10;

    public function __construct(string $address, string $clientClass = ServerClient::class)
    {
        parent::__construct($address, $clientClass);
    }

    /**
     * Largest packet (fixed header included) a client may send; a larger one drops the connection
     */
    public function setMaxPacketSize(int $bytes): self
    {
        if ($bytes < 2) {
            throw new \InvalidArgumentException('maxPacketSize must be >= 2');
        }
        $this->maxPacketSize = $bytes;

        return $this;
    }

    /**
     * Seconds a new connection may take to send CONNECT, 0 = no limit
     */
    public function setConnectTimeout(int $seconds): self
    {
        $this->connectTimeout = $seconds;

        return $this;
    }

    /**
     * @return ServerClient[] connected sessions by client id
     */
    public function getClients(): array
    {
        return $this->clients;
    }

    public function getClient(string $clientId): ?ServerClient
    {
        return $this->clients[$clientId] ?? null;
    }

    /**
     * Publishes a message from the broker itself
     */
    public function publish(string $topic, string $payload, int $qos = 0, bool $retain = false): void
    {
        if (!Topic::isValidName($topic)) {
            throw new \InvalidArgumentException("Invalid topic name: $topic");
        }
        if ($qos < 0 || $qos > 2) {
            throw new \InvalidArgumentException('QoS must be 0, 1 or 2');
        }
        $this->route(null, $topic, $payload, $qos, $retain);
    }

    /**
     * @internal used by ServerClient
     * @return int CONNACK return code
     */
    public function authenticate(ServerClient $client, string $clientId, ?string $username, ?string $password): int
    {
        if (!is_callable($this->onAuthenticate)) {
            return self::CONNACK_ACCEPTED;
        }
        $result = call_user_func($this->onAuthenticate, $this, $client, $clientId, $username, $password);
        if (is_int($result)) {
            return $result;
        }

        return $result ? self::CONNACK_ACCEPTED : self::CONNACK_NOT_AUTHORIZED;
    }

    /**
     * @internal used by ServerClient once CONNECT is accepted; an existing session with the same
     * client id is taken over (its connection is closed, which publishes its will)
     */
    public function register(ServerClient $client): void
    {
        $clientId = $client->getClientId();
        $old = $this->clients[$clientId] ?? null;
        if ($old !== null && $old !== $client) {
            $old->close(false);
        }
        $this->clients[$clientId] = $client;
    }

    /**
     * @internal used by ServerClient when its connection closes
     */
    public function unregister(ServerClient $client): void
    {
        $clientId = $client->getClientId();
        if ($clientId !== null && ($this->clients[$clientId] ?? null) === $client) {
            unset($this->clients[$clientId]);
        }
    }

    /**
     * @internal used by ServerClient for PUBLISH and wills
     */
    public function route(?ServerClient $from, string $topic, string $payload, int $qos, bool $retain): void
    {
        if ($retain) {
            if ($payload === '') {
                unset($this->retained[$topic]);
            } else {
                $this->retained[$topic] = [$payload, min($qos, 1)];
            }
        }

        if (is_callable($this->onMessage)) {
            call_user_func($this->onMessage, $this, $from, $topic, $payload, $qos, $retain);
        }

        // a copy: a delivery may close a slow subscriber, which unregisters it
        foreach ($this->clients as $client) {
            $subQos = $client->getMaxQos($topic);
            if ($subQos !== null) {
                // retain is only set on messages sent because of a new subscription
                $client->deliver($topic, $payload, min($qos, $subQos), false);
            }
        }
    }

    /**
     * @internal used by ServerClient on SUBSCRIBE
     * @return array<string, array{0:string,1:int}> retained [payload, qos] by topic, for topics matching $filter
     */
    public function getRetained(string $filter): array
    {
        $result = [];
        foreach ($this->retained as $topic => $message) {
            if (Topic::matches($filter, (string)$topic)) {
                $result[(string)$topic] = $message;
            }
        }

        return $result;
    }

    protected function onConnection(Connection $client)
    {
        if ($client instanceof ServerClient) {
            $client->attach($this, $this->maxPacketSize);
            // until CONNECT arrives; replaced by the keep-alive timeout afterwards
            $client->setReadTimeout($this->connectTimeout);
        }
        parent::onConnection($client);
    }
}

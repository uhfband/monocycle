<?php

use Uhf\Monocycle\Proto\Mqtt\Client\Client;
use Uhf\Monocycle\Proto\Mqtt\Packet;
use Uhf\Monocycle\Proto\Mqtt\Server\Server;
use Uhf\Monocycle\Proto\Mqtt\Server\ServerClient;
use Uhf\Monocycle\Socket\Connection;
use Uhf\Monocycle\Socket\Socket;

/**
 * Runs the broker in-process on an ephemeral port; talks to it with the MQTT Client,
 * or with a raw Connection for byte-level checks and malformed input
 */
class MqttServerTest extends BaseTest
{
    private Server $server;
    private string $url;

    protected function setUp(): void
    {
        $this->server = new Server('tcp://127.0.0.1:0');
        $this->url = 'mqtt://' . $this->server->getLocalAddress();
    }

    protected function tearDown(): void
    {
        $this->resetMonocycle('sockets', 'timers', 'readStreams', 'readListeners', 'writeStreams', 'writeListeners');
    }

    public function testPublishSubscribeQos0(): void
    {
        $sub = $this->client('sub');
        $messages = $this->collect($sub);
        $this->subscribe($sub, ['a/b']);

        $this->client('pub')->publish('a/b', 'hello');
        $this->pumpUntil(function () use ($messages) {
            return count($messages) === 1;
        });
        $this->assertSame([['a/b', 'hello']], $messages->getArrayCopy());
    }

    public function testQos1PublishIsAckedAndDeliveredWithPayloadIntact(): void
    {
        $sub = $this->raw();
        $this->rawConnect($sub, 'sub');
        $sub->conn->write(Packet::encode(Packet::SUBSCRIBE, "\x00\x01", Packet::encodeString('t') . "\x01", 0x02));
        $this->expectBytes($sub, "\x90\x03\x00\x01\x01");

        $pub = $this->raw();
        $this->rawConnect($pub, 'pub');
        $pub->conn->write(Packet::encode(Packet::PUBLISH, Packet::encodeString('t') . "\x00\x07", 'abc', 0x02));
        $this->expectBytes($pub, "\x40\x02\x00\x07", 'PUBACK');

        // QoS 1 to the subscriber, with the broker's own packet id
        $this->expectBytes($sub, "\x32\x08\x00\x01t\x00\x01abc");
        $sub->conn->write("\x40\x02\x00\x01");
        $this->pumpUntil(function () {
            return $this->inflight($this->server->getClient('sub')) === [];
        });
    }

    public function testQos2PublishHandshakeDeliversOnce(): void
    {
        $sub = $this->client('sub');
        $messages = $this->collect($sub);
        $this->subscribe($sub, ['t'], 2);
        $this->assertSame(['t' => 1], $this->server->getClient('sub')->getSubscriptions(), 'QoS 2 granted as 1');

        $pub = $this->raw();
        $this->rawConnect($pub, 'pub');
        $publish = Packet::encode(Packet::PUBLISH, Packet::encodeString('t') . "\x00\x05", 'x', 0x04);
        $pub->conn->write($publish);
        $this->expectBytes($pub, "\x50\x02\x00\x05", 'PUBREC');
        // a resend before PUBREL is acknowledged again but not delivered again
        $pub->conn->write(Packet::encode(Packet::PUBLISH, Packet::encodeString('t') . "\x00\x05", 'x', 0x0C));
        $this->expectBytes($pub, "\x50\x02\x00\x05", 'PUBREC');
        $pub->conn->write("\x62\x02\x00\x05");
        $this->expectBytes($pub, "\x70\x02\x00\x05", 'PUBCOMP');

        $this->pumpUntil(function () use ($messages) {
            return count($messages) === 1;
        });
        $this->pump(0.1);
        $this->assertSame([['t', 'x']], $messages->getArrayCopy());

        // our own client completes QoS 2 end to end
        $this->client('pub2')->publish('t', 'y', 2);
        $this->pumpUntil(function () use ($messages) {
            return count($messages) === 2;
        });
    }

    public function testSubAckGrantsAndRejects(): void
    {
        $raw = $this->raw();
        $this->rawConnect($raw, 'c');
        $raw->conn->write(Packet::encode(
            Packet::SUBSCRIBE,
            "\x12\x34",
            Packet::encodeString('a') . "\x02" . Packet::encodeString('a/#/b') . "\x00" . Packet::encodeString('b') . "\x00",
            0x02
        ));
        $this->expectBytes($raw, "\x90\x05\x12\x34\x01\x80\x00");
    }

    public function testWildcardsDeliverOnceAtHighestQos(): void
    {
        $sub = $this->raw();
        $this->rawConnect($sub, 'sub');
        $sub->conn->write(Packet::encode(
            Packet::SUBSCRIBE,
            "\x00\x01",
            Packet::encodeString('a/+') . "\x00" . Packet::encodeString('a/#') . "\x01" . Packet::encodeString('b') . "\x00",
            0x02
        ));
        $this->expectBytes($sub, "\x90\x05\x00\x01\x00\x01\x00");

        $this->server->publish('a/b', 'm', 1);
        $this->expectBytes($sub, "\x32\x08\x00\x03a/b\x00\x01m");
        $this->server->publish('c', 'nobody');
        $this->pump(0.1);
        $this->assertSame('', $sub->data);
    }

    public function testUnsubscribe(): void
    {
        $raw = $this->raw();
        $this->rawConnect($raw, 'c');
        $raw->conn->write(Packet::encode(Packet::SUBSCRIBE, "\x00\x01", Packet::encodeString('t') . "\x00", 0x02));
        $this->expectBytes($raw, "\x90\x03\x00\x01\x00");
        $raw->conn->write(Packet::encode(Packet::UNSUBSCRIBE, "\x00\x02", Packet::encodeString('t'), 0x02));
        $this->expectBytes($raw, "\xB0\x02\x00\x02");

        $this->server->publish('t', 'x');
        $this->pump(0.1);
        $this->assertSame('', $raw->data);
    }

    public function testRetainedMessages(): void
    {
        $pub = $this->client('pub');
        $pub->publish('r/1', 'kept', 1, true);
        $pub->publish('r/2', 'gone', 0, true);
        $pub->publish('r/2', '', 0, true); // clears
        $this->pumpUntil(function () {
            return count($this->server->getRetained('#')) === 1;
        });

        $raw = $this->raw();
        $this->rawConnect($raw, 'late');
        $raw->conn->write(Packet::encode(Packet::SUBSCRIBE, "\x00\x01", Packet::encodeString('r/#') . "\x00", 0x02));
        $this->expectBytes($raw, "\x90\x03\x00\x01\x00");
        // retain flag set on a message sent because of a new subscription, at the granted QoS 0
        $this->expectBytes($raw, "\x31\x09\x00\x03r/1kept");
        $this->pump(0.1);
        $this->assertSame('', $raw->data);
    }

    public function testWillOnAbruptCloseOnly(): void
    {
        $sub = $this->client('sub');
        $messages = $this->collect($sub);
        $this->subscribe($sub, ['will/#']);

        $this->client('clean')->setWill('will/clean', 'bye')->close();
        $abrupt = (new Client($this->url, 'abrupt'))->setWill('will/abrupt', 'gone');
        $abrupt->connect();
        $this->pumpUntil(function () use ($abrupt) {
            return $abrupt->isMqttConnected();
        });
        $abrupt->close(false);

        $this->pumpUntil(function () use ($messages) {
            return count($messages) === 1;
        });
        $this->pump(0.1);
        $this->assertSame([['will/abrupt', 'gone']], $messages->getArrayCopy());
    }

    public function testKeepAliveExpiryClosesAndPublishesWill(): void
    {
        $sub = $this->client('sub');
        $messages = $this->collect($sub);
        $this->subscribe($sub, ['w']);

        // keep-alive 1s => dropped after 2s without traffic
        $raw = $this->raw();
        $this->rawConnect($raw, 'silent', 1, ['w', 'timeout']);
        $this->pumpUntil(function () use ($raw) {
            return $raw->conn->getState() === Socket::STATE_CLOSED;
        }, 5.0, true);
        $this->pumpUntil(function () use ($messages) {
            return count($messages) === 1;
        });
        $this->assertSame([['w', 'timeout']], $messages->getArrayCopy());
    }

    public function testConnectTimeout(): void
    {
        $this->server->setConnectTimeout(1);
        $raw = $this->raw();
        $this->pumpUntil(function () use ($raw) {
            return $raw->conn->getState() === Socket::STATE_CLOSED;
        }, 5.0, true);
        $this->assertSame('', $raw->data);
    }

    public function testSessionTakeover(): void
    {
        $first = $this->client('same');
        $second = $this->client('same');
        $this->pumpUntil(function () use ($first) {
            return $first->getState() === Socket::STATE_CLOSED;
        });
        $this->assertSame(Socket::STATE_CONNECTED, $second->getState());
        $this->assertCount(1, $this->server->getClients());
    }

    public function testAuthentication(): void
    {
        $this->server->onAuthenticate = function (Server $server, ServerClient $client, string $id, ?string $user, ?string $pass) {
            return $user === 'u' && $pass === 'p';
        };

        $good = (new Client($this->url, 'good'))->setUsername('u')->setPassword('p');
        $good->connect();
        $this->pumpUntil(function () use ($good) {
            return $good->isMqttConnected();
        });
        $this->assertSame('u', $this->server->getClient('good')->getUsername());

        $raw = $this->raw();
        $raw->conn->write($this->connectPacket('bad', 30, null, 'u', 'wrong'));
        $this->expectBytes($raw, "\x20\x02\x00\x05");
        $this->pumpUntil(function () use ($raw) {
            return $raw->conn->getState() === Socket::STATE_CLOSED;
        });
        $this->assertNull($this->server->getClient('bad'));
    }

    public function testEmptyClientId(): void
    {
        $raw = $this->raw();
        $raw->conn->write($this->connectPacket(''));
        $this->expectBytes($raw, "\x20\x02\x00\x00");
        $this->assertCount(1, $this->server->getClients(), 'an id is assigned');

        $raw = $this->raw();
        $raw->conn->write($this->connectPacket('', 30, null, null, null, false));
        $this->expectBytes($raw, "\x20\x02\x00\x02", 'identifier rejected without clean session');
    }

    /**
     * @dataProvider protocolViolationProvider
     */
    public function testProtocolViolationsClose(string $afterConnect, ?string $instead = null): void
    {
        $raw = $this->raw();
        if ($instead === null) {
            $this->rawConnect($raw, 'c');
            $raw->conn->write($afterConnect);
        } else {
            $raw->conn->write($instead);
        }
        $this->pumpUntil(function () use ($raw) {
            return $raw->conn->getState() === Socket::STATE_CLOSED;
        });
    }

    public function protocolViolationProvider(): array
    {
        return [
            'first packet not CONNECT' => ['', "\xC0\x00"],
            'second CONNECT' => ["\x10\x00"],
            'wildcard in PUBLISH topic' => [Packet::encode(Packet::PUBLISH, Packet::encodeString('a/+'), 'x')],
            'QoS 3' => [Packet::encode(Packet::PUBLISH, Packet::encodeString('a'), 'x', 0x06)],
            'SUBSCRIBE without reserved flags' => [Packet::encode(Packet::SUBSCRIBE, "\x00\x01", Packet::encodeString('a') . "\x00")],
            'malformed length' => ["\x30\xFF\xFF\xFF\xFF\x01"],
            'truncated SUBSCRIBE' => [Packet::encode(Packet::SUBSCRIBE, "\x00\x01", "\x00\x05ab", 0x02)],
        ];
    }

    public function testUnsupportedProtocolLevel(): void
    {
        $raw = $this->raw();
        $raw->conn->write(Packet::encode(Packet::CONNECT, Packet::encodeString('MQIsdp') . "\x03\x02\x00\x1E", Packet::encodeString('old')));
        $this->expectBytes($raw, "\x20\x02\x00\x01");
        $this->pumpUntil(function () use ($raw) {
            return $raw->conn->getState() === Socket::STATE_CLOSED;
        });
    }

    public function testOversizedPacketCloses(): void
    {
        $this->server->setMaxPacketSize(100);
        $client = $this->client('big');
        $client->publish('t', str_repeat('x', 200));
        $this->pumpUntil(function () use ($client) {
            return $client->getState() === Socket::STATE_CLOSED;
        });
        $this->assertSame([], $this->server->getClients());
    }

    public function testPingAndServerPublishAndOnMessage(): void
    {
        $seen = [];
        $this->server->onMessage = function (Server $server, ?ServerClient $from, string $topic, string $payload, int $qos, bool $retain) use (&$seen) {
            $seen[] = [$from ? $from->getClientId() : null, $topic, $payload, $qos, $retain];
        };

        $raw = $this->raw();
        $this->rawConnect($raw, 'c');
        $raw->conn->write("\xC0\x00");
        $this->expectBytes($raw, "\xD0\x00", 'PINGRESP');

        $sub = $this->client('sub');
        $messages = $this->collect($sub);
        $this->subscribe($sub, ['s']);
        $this->server->publish('s', 'from broker');
        $sub->publish('s', 'from client', 1, true);
        $this->pumpUntil(function () use ($messages) {
            return count($messages) === 2;
        });
        $this->assertSame([['s', 'from broker'], ['s', 'from client']], $messages->getArrayCopy());
        $this->assertSame([[null, 's', 'from broker', 0, false], ['sub', 's', 'from client', 1, true]], $seen);
    }

    // --- helpers ---

    private function client(string $clientId): Client
    {
        $client = new Client($this->url, $clientId);
        $client->connect();
        $this->pumpUntil(function () use ($client) {
            return $client->isMqttConnected();
        });

        return $client;
    }

    private function collect(Client $client): ArrayObject
    {
        $messages = new ArrayObject();
        $client->onMessage = function (Client $client, string $topic, string $payload) use ($messages) {
            $messages[] = [$topic, $payload];
        };

        return $messages;
    }

    /**
     * SUBACK is not reported by the client, so wait for the broker to record the subscription
     */
    private function subscribe(Client $client, array $filters, int $qos = 0): void
    {
        $client->subscribe($filters, $qos);
        $this->pumpUntil(function () use ($filters) {
            foreach ($this->server->getClients() as $serverClient) {
                if (array_keys($serverClient->getSubscriptions()) === $filters) {
                    return true;
                }
            }

            return false;
        });
    }

    /**
     * A raw TCP connection to the broker; ->data collects everything it receives
     */
    private function raw(): stdClass
    {
        $raw = new stdClass();
        $raw->data = '';
        $raw->conn = new Connection();
        $raw->conn->onData = function (Connection $conn, string $data) use ($raw) {
            $raw->data .= $data;
        };
        $raw->conn->connect('tcp://' . $this->server->getLocalAddress());

        return $raw;
    }

    private function rawConnect(stdClass $raw, string $clientId, int $keepAlive = 30, ?array $will = null): void
    {
        $raw->conn->write($this->connectPacket($clientId, $keepAlive, $will));
        $this->expectBytes($raw, "\x20\x02\x00\x00", 'CONNACK');
    }

    private function connectPacket(
        string $clientId,
        int $keepAlive = 30,
        ?array $will = null,
        ?string $username = null,
        ?string $password = null,
        bool $clean = true
    ): string {
        $flags = ($clean ? 0x02 : 0) | ($will ? 0x04 : 0) | ($username !== null ? 0x80 : 0) | ($password !== null ? 0x40 : 0);
        $payload = Packet::encodeString($clientId);
        if ($will) {
            $payload .= Packet::encodeString($will[0]) . Packet::encodeString($will[1]);
        }
        if ($username !== null) {
            $payload .= Packet::encodeString($username);
        }
        if ($password !== null) {
            $payload .= Packet::encodeString($password);
        }

        return Packet::encode(
            Packet::CONNECT,
            Packet::encodeString('MQTT') . "\x04" . chr($flags) . chr($keepAlive >> 8) . chr($keepAlive & 0xFF),
            $payload
        );
    }

    private function expectBytes(stdClass $raw, string $expected, string $message = ''): void
    {
        $this->pumpUntil(function () use ($raw, $expected) {
            return strlen($raw->data) >= strlen($expected);
        });
        $this->assertSame(bin2hex($expected), bin2hex(substr($raw->data, 0, strlen($expected))), $message);
        $raw->data = (string)substr($raw->data, strlen($expected));
    }

    private function inflight(ServerClient $client): array
    {
        $property = new ReflectionProperty(ServerClient::class, 'inflight');
        $property->setAccessible(true);

        return $property->getValue($client);
    }

    private function pump(float $seconds): void
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            $this->tick(0.02);
        }
    }
}

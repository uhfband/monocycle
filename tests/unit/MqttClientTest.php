<?php

use Uhf\Monocycle\Proto\Mqtt\Client\Client;
use Uhf\Monocycle\Socket\Connection;
use Uhf\Monocycle\Socket\Server;
use Uhf\Monocycle\Socket\Socket;

/**
 * Talks to a fake broker: a plain Socket\Server on an ephemeral TCP port that records what the client sends
 */
class MqttClientTest extends BaseTest
{
    private Server $broker;
    private ?Connection $brokerConn = null;
    private string $received = '';

    protected function setUp(): void
    {
        $this->broker = new Server('tcp://127.0.0.1:0', Connection::class);
        $this->broker->onConnection = function (Server $server, Connection $conn) {
            $this->brokerConn = $conn;
            $conn->onData = function (Connection $conn, string $data) {
                $this->received .= $data;
            };
        };
    }

    protected function tearDown(): void
    {
        $this->resetMonocycle('sockets', 'timers', 'readStreams', 'readListeners', 'writeStreams', 'writeListeners');
    }

    public function testConnectPacketCarriesCredentials(): void
    {
        $client = (new Client('mqtt://127.0.0.1:1', 'cid'))->setUsername('user')->setPassword('secret');
        $client->connect('tcp://' . $this->broker->getLocalAddress());

        $packet = $this->readPacket();
        $this->assertSame(0x10, ord($packet[0]));
        // variable header: "\0\4MQTT" + level + flags + keep-alive, after 1 type byte + 1 length byte
        $flags = ord($packet[2 + 6 + 1]);
        $this->assertSame(0xC2, $flags, 'username + password + clean session');
        $this->assertStringEndsWith("\0\3cid\0\4user\0\6secret", $packet);
    }

    public function testCredentialsFromUrl(): void
    {
        $client = new Client('mqtt://us%40er:p%3Ass@127.0.0.1:1', 'cid');
        $client->connect('tcp://' . $this->broker->getLocalAddress());

        $this->assertStringEndsWith("\0\3cid\0\5us@er\0\4p:ss", $this->readPacket());
    }

    public function testSettersOverrideUrlCredentials(): void
    {
        $client = (new Client('mqtt://a:b@127.0.0.1:1', 'cid'))->setUsername('user')->setPassword('secret');
        $client->connect('tcp://' . $this->broker->getLocalAddress());

        $this->assertStringEndsWith("\0\3cid\0\4user\0\6secret", $this->readPacket());
    }

    public function testPasswordWithoutUsernameIsRejected(): void
    {
        $client = (new Client('mqtt://127.0.0.1:1', 'cid'))->setPassword('secret');
        $this->expectException(InvalidArgumentException::class);
        $client->connect('tcp://' . $this->broker->getLocalAddress());
    }

    public function testLongPublishUsesMultiByteLength(): void
    {
        $client = $this->connectedClient();
        $this->received = '';

        $payload = str_repeat('x', 300);
        $client->publish('t', $payload);

        $packet = $this->readPacket();
        $this->assertSame(0x30, ord($packet[0]));
        // remaining length 2 + 1 + 300 = 303 => 0xAF 0x02
        $this->assertSame("\xAF\x02", substr($packet, 1, 2));
        $this->assertSame("\0\1t" . $payload, substr($packet, 3));
    }

    public function testSubscribeHasReservedFlags(): void
    {
        $client = $this->connectedClient();
        $this->received = '';

        $client->subscribe(['a/b']);
        $this->assertSame(0x82, ord($this->readPacket()[0]));
    }

    public function testPacketsWrittenBeforeConnAckFollowConnect(): void
    {
        $client = new Client('mqtt://127.0.0.1:1', 'cid');
        $client->connect('tcp://' . $this->broker->getLocalAddress());
        $client->subscribe(['a/b']);

        $this->assertSame(0x10, ord($this->readPacket()[0]), 'CONNECT goes first');
        $this->pump(0.1);
        $this->assertSame('', $this->received, 'nothing else before CONNACK');

        $this->brokerConn->write("\x20\x02\x00\x00");
        $this->assertSame(0x82, ord($this->readPacket()[0]));
    }

    public function testQos1PayloadExcludesPacketIdAndIsAcked(): void
    {
        $messages = [];
        $client = $this->connectedClient();
        $client->onMessage = function (Client $client, string $topic, string $payload) use (&$messages) {
            $messages[] = [$topic, $payload];
        };
        $this->received = '';

        $this->brokerConn->write("\x32\x08\x00\x01t\x12\x34abc"); // PUBLISH QoS 1, id 0x1234
        $this->assertSame("\x40\x02\x12\x34", $this->readPacket(), 'PUBACK');
        $this->assertSame([['t', 'abc']], $messages);
    }

    public function testQos2Handshake(): void
    {
        $messages = [];
        $client = $this->connectedClient();
        $client->onMessage = function (Client $client, string $topic, string $payload) use (&$messages) {
            $messages[] = $payload;
        };
        $this->received = '';

        // inbound: PUBLISH QoS 2 -> PUBREC, PUBREL -> PUBCOMP
        $this->brokerConn->write("\x34\x08\x00\x01t\x00\x07abc");
        $this->assertSame("\x50\x02\x00\x07", $this->readPacket(), 'PUBREC');
        $this->assertSame(['abc'], $messages);
        $this->brokerConn->write("\x62\x02\x00\x07");
        $this->assertSame("\x70\x02\x00\x07", $this->readPacket(), 'PUBCOMP');

        // outbound: PUBLISH QoS 2, PUBREC -> PUBREL
        $client->publish('t', 'x', 2);
        $this->assertSame("\x34\x06\x00\x01t\x00\x01x", $this->readPacket());
        $this->brokerConn->write("\x50\x02\x00\x01");
        $this->assertSame("\x62\x02\x00\x01", $this->readPacket(), 'PUBREL');
    }

    public function testRejectedConnAckClosesWithoutError(): void
    {
        $client = (new Client('mqtt://127.0.0.1:1', 'cid'))->setUsername('user')->setPassword('wrong');
        $client->connect('tcp://' . $this->broker->getLocalAddress());
        $this->readPacket();

        $this->brokerConn->write("\x20\x02\x00\x05"); // CONNACK: not authorized
        $this->pumpUntil(function () use ($client) {
            return $client->getState() === Socket::STATE_CLOSED;
        });
        $this->assertSame(0, $this->countMonocycle('timers'));
    }

    public function testCloseSendsDisconnectAndStopsKeepAlive(): void
    {
        $client = $this->connectedClient();
        $this->assertSame(1, $this->countMonocycle('timers'), 'keep-alive timer');
        $this->received = '';

        $client->close();
        $this->assertSame("\xE0\x00", $this->readPacket());
        $this->pumpUntil(function () use ($client) {
            return $client->getState() === Socket::STATE_CLOSED;
        });
        $this->assertSame(0, $this->countMonocycle('timers'));
    }

    public function testBrokerDisconnectStopsKeepAlive(): void
    {
        $client = $this->connectedClient();
        $this->brokerConn->close(false);

        $this->pumpUntil(function () use ($client) {
            return $client->getState() === Socket::STATE_CLOSED;
        });
        $this->assertSame(0, $this->countMonocycle('timers'));
    }

    private function connectedClient(): Client
    {
        // no read/connect timeout so only the keep-alive timer is registered
        $client = new Client('mqtt://127.0.0.1:1', 'cid');
        $client->setConnectTimeout(0)->setReadTimeout(0);
        $client->connect('tcp://' . $this->broker->getLocalAddress());
        $this->readPacket();

        $this->brokerConn->write("\x20\x02\x00\x00"); // CONNACK accepted
        $this->pumpUntil(function () {
            return $this->countMonocycle('timers') === 1;
        });

        return $client;
    }

    private function pump(float $seconds): void
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            $this->tick(0.02);
        }
    }

    /**
     * Waits for one complete MQTT packet from the client and removes it from the received buffer
     */
    private function readPacket(): string
    {
        $length = null;
        $this->pumpUntil(function () use (&$length) {
            if (strlen($this->received) < 2) {
                return false;
            }
            $value = 0;
            $multiplier = 1;
            for ($i = 1; isset($this->received[$i]); $i++) {
                $byte = ord($this->received[$i]);
                $value += ($byte & 0x7F) * $multiplier;
                $multiplier *= 128;
                if (($byte & 0x80) === 0) {
                    $length = 1 + $i + $value;

                    return strlen($this->received) >= $length;
                }
            }

            return false;
        });
        $packet = substr($this->received, 0, $length);
        $this->received = (string)substr($this->received, $length);

        return $packet;
    }
}

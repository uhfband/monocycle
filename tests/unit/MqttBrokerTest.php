<?php

use Uhf\Monocycle\Proto\Mqtt\Client\Client;
use Uhf\Monocycle\Socket\Socket;

/**
 * Talks to a real MQTT 3.1.1 broker; skipped unless MQTT_BROKER_URL is set, e.g.
 *   docker run -d --rm -p 127.0.0.1:1883:1883 eclipse-mosquitto:2 mosquitto -c /mosquitto-no-auth.conf
 *   MQTT_BROKER_URL=mqtt://127.0.0.1:1883 ./vendor/bin/phpunit tests/unit/MqttBrokerTest.php
 */
class MqttBrokerTest extends BaseTest
{
    private string $url;
    /** @var Client[] */
    private array $clients = [];

    protected function setUp(): void
    {
        $url = getenv('MQTT_BROKER_URL');
        if (!$url) {
            $this->markTestSkipped('MQTT_BROKER_URL is not set');
        }
        $this->url = $url;
    }

    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            $client->close(false);
        }
        $this->resetMonocycle('sockets', 'timers', 'readStreams', 'readListeners', 'writeStreams', 'writeListeners');
    }

    public function testPublishSubscribeQos0(): void
    {
        $messages = [];
        $topic = $this->topic();
        $sub = $this->connectedClient('sub');
        $sub->onMessage = function (Client $client, string $t, string $payload) use (&$messages) {
            $messages[] = [$t, $payload];
        };
        $this->subscribe($sub, [$topic]);

        $pub = $this->connectedClient('pub');
        $pub->publish($topic, 'hello');

        $this->pumpUntil(function () use (&$messages) {
            return count($messages) === 1;
        });
        $this->assertSame([[$topic, 'hello']], $messages);
    }

    public function testQos1DeliveryIsAcknowledged(): void
    {
        $messages = [];
        $topic = $this->topic();
        $sub = $this->connectedClient('sub');
        $sub->onMessage = function (Client $client, string $t, string $payload) use (&$messages) {
            $messages[] = $payload;
        };
        $this->subscribe($sub, [$topic], 1);

        $pub = $this->connectedClient('pub');
        $pub->publish($topic, 'one', 1);
        $pub->publish($topic, 'two', 1);

        $this->pumpUntil(function () use (&$messages) {
            return count($messages) === 2;
        });
        // the packet id is not part of the payload
        $this->assertSame(['one', 'two'], $messages);
        // a missing PUBACK makes the broker resend; give it time to and check nothing more arrives
        $this->pump(0.5);
        $this->assertCount(2, $messages);
        $this->assertSame(Socket::STATE_CONNECTED, $sub->getState());
    }

    public function testQos2Delivery(): void
    {
        $messages = [];
        $topic = $this->topic();
        $sub = $this->connectedClient('sub');
        $sub->onMessage = function (Client $client, string $t, string $payload) use (&$messages) {
            $messages[] = $payload;
        };
        $this->subscribe($sub, [$topic], 2);

        $pub = $this->connectedClient('pub');
        $pub->publish($topic, 'exactly-once', 2);

        $this->pumpUntil(function () use (&$messages) {
            return count($messages) === 1;
        });
        $this->pump(0.5);
        $this->assertSame(['exactly-once'], $messages);
        $this->assertSame(Socket::STATE_CONNECTED, $sub->getState());
        $this->assertSame(Socket::STATE_CONNECTED, $pub->getState());
    }

    public function testLargePayloadAndWildcard(): void
    {
        $received = null;
        $topic = $this->topic();
        $sub = $this->connectedClient('sub');
        $sub->onMessage = function (Client $client, string $t, string $payload) use (&$received) {
            $received = $payload;
        };
        $this->subscribe($sub, [$topic . '/#']);

        $payload = random_bytes(300 * 1024);
        $this->connectedClient('pub')->publish($topic . '/big', $payload);

        $this->pumpUntil(function () use (&$received) {
            return $received !== null;
        });
        $this->assertSame($payload, $received);
    }

    public function testRetainedMessage(): void
    {
        $topic = $this->topic();
        $pub = $this->connectedClient('pub');
        $pub->publish($topic, 'kept', 0, true);
        $this->pump(0.3);

        $received = null;
        $sub = $this->connectedClient('sub');
        $sub->onMessage = function (Client $client, string $t, string $payload) use (&$received) {
            $received = $payload;
        };
        $sub->subscribe([$topic]);
        $this->pumpUntil(function () use (&$received) {
            return $received !== null;
        });
        $this->assertSame('kept', $received);

        $pub->publish($topic, '', 0, true); // clear the retained message
        $this->pump(0.3);
    }

    public function testSubscribeBeforeConnAck(): void
    {
        $received = null;
        $topic = $this->topic();
        $sub = new Client($this->url, 'monocycle-test-early-' . bin2hex(random_bytes(4)));
        $this->clients[] = $sub;
        $sub->onMessage = function (Client $client, string $t, string $payload) use (&$received) {
            $received = $payload;
        };
        $sub->connect();
        $sub->subscribe([$topic]);
        $this->pump(0.5);
        $this->assertSame(Socket::STATE_CONNECTED, $sub->getState());

        $this->connectedClient('pub')->publish($topic, 'early');
        $this->pumpUntil(function () use (&$received) {
            return $received !== null;
        });
        $this->assertSame('early', $received);
    }

    public function testPingsKeepIdleConnectionAlive(): void
    {
        // pings every 2s; their PINGRESPs reset the 4s read timeout
        $client = $this->connectedClient('idle', 4);
        $client->setReadTimeout(4);
        $this->pump(7.0, true);
        $this->assertSame(Socket::STATE_CONNECTED, $client->getState());
    }

    public function testCleanDisconnect(): void
    {
        $client = $this->connectedClient('bye');
        $client->close();
        $this->pumpUntil(function () use ($client) {
            return $client->getState() === Socket::STATE_CLOSED;
        });
        $this->assertSame(0, $this->countMonocycle('timers'));
    }

    private function connectedClient(string $name, int $keepAlive = 30): Client
    {
        $client = new Client($this->url, 'monocycle-test-' . $name . '-' . bin2hex(random_bytes(4)), $keepAlive);
        $this->clients[] = $client;
        $client->connect();

        $this->pumpUntil(function () use ($client) {
            return $client->isMqttConnected();
        });

        return $client;
    }

    /**
     * SUBACK is not reported, so give the broker a moment to register the subscription
     */
    private function subscribe(Client $client, array $topics, int $qos = 0): void
    {
        $client->subscribe($topics, $qos);
        $this->pump(0.3);
    }

    private function pump(float $seconds, bool $runTimers = false): void
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            $this->tick(0.05, $runTimers);
        }
    }

    private function topic(): string
    {
        return 'monocycle/test/' . bin2hex(random_bytes(6));
    }
}

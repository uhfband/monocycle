<?php

use Symfony\Component\HttpFoundation\Request;
use Uhf\Monocycle\Proto\Http\Server\Server as HttpServer;
use Uhf\Monocycle\Socket\Connection;
use Uhf\Monocycle\Socket\Socket;

/**
 * Runs the HTTP server in-process on an ephemeral port and talks to it over a
 * raw Socket\Connection, so the exact bytes on the wire are asserted.
 */
class HttpServerTest extends BaseTest
{
    private const TEST_CONTENT = "1234567890abcdefghijklmnopqrstuvwxyz";

    private ?HttpServer $server = null;

    /** @var Connection[] connections accepted by $server */
    private array $accepted = [];

    /** @var Connection[] client connections opened by the test */
    private array $clients = [];

    protected function setUp(): void
    {
        $this->server = new HttpServer('tcp://127.0.0.1:0', __DIR__ . '/fixtures/public');
        $this->server->onConnection = function ($server, Connection $client) {
            $this->accepted[] = $client;
        };
    }

    protected function tearDown(): void
    {
        foreach (array_merge($this->clients, $this->accepted) as $conn) {
            $conn->close(false);
        }
        $this->clients = [];
        $this->accepted = [];
        $this->server->close();
        $this->server = null;

        $this->resetMonocycle('sockets', 'timers', 'readStreams', 'readListeners', 'writeStreams', 'writeListeners');
    }

    /**
     * Opens a client connection; $state collects everything it receives and
     * whether the server closed it.
     */
    private function connect(?ArrayObject &$state = null): Connection
    {
        $state = new ArrayObject(['received' => '', 'connected' => false, 'disconnected' => false]);

        $client = new Connection();
        $client->onConnect = function () use ($state) {
            $state['connected'] = true;
        };
        $client->onData = function (Connection $conn, string $data) use ($state) {
            $state['received'] .= $data;
        };
        $client->onDisconnect = function () use ($state) {
            $state['disconnected'] = true;
        };
        $client->onConnectionError = function (Connection $conn, string $message) {
            $this->fail("Connection error: $message");
        };
        $client->connect('tcp://' . $this->server->getLocalAddress());
        $this->clients[] = $client;

        return $client;
    }

    /**
     * Splits complete responses (framed by Content-Length) off the front of
     * $buffer. Each is ['status', 'reason', 'headers' (lowercased names), 'body'].
     */
    private function parseResponses(string &$buffer): array
    {
        $responses = [];

        while (($headerEnd = strpos($buffer, "\r\n\r\n")) !== false) {
            $lines = explode("\r\n", substr($buffer, 0, $headerEnd));
            $statusLine = array_shift($lines);
            $this->assertMatchesRegularExpression('~^HTTP/1\.[01] \d{3} ~', $statusLine);
            [, $status, $reason] = explode(' ', $statusLine, 3);

            $headers = [];
            foreach ($lines as $line) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower($name)] = trim($value);
            }

            $this->assertArrayHasKey('content-length', $headers, 'Response without Content-Length');
            $length = (int)$headers['content-length'];
            if (strlen($buffer) < $headerEnd + 4 + $length) {
                break;
            }

            $responses[] = [
                'status' => (int)$status,
                'reason' => $reason,
                'headers' => $headers,
                'body' => substr($buffer, $headerEnd + 4, $length),
            ];
            $buffer = (string)substr($buffer, $headerEnd + 4 + $length);
        }

        return $responses;
    }

    /**
     * Sends one request on a fresh connection and waits until the server has
     * answered and closed the connection (no keep-alive). Fails if the server
     * sends anything beyond the one response.
     */
    private function request(string $method, string $path, array $headers = [], string $body = ''): array
    {
        $client = $this->connect($state);
        $client->write($this->buildRequest($method, $path, $headers, $body));

        $this->pumpUntil(function () use ($state) {
            return $state['disconnected'];
        });

        $buffer = $state['received'];
        $responses = $this->parseResponses($buffer);
        $this->assertCount(1, $responses);
        $this->assertSame('', $buffer, 'Unexpected bytes after the response');

        return $responses[0];
    }

    private function buildRequest(string $method, string $path, array $headers = [], string $body = ''): string
    {
        $lines = ["$method $path HTTP/1.1", 'Host: 127.0.0.1'];
        foreach ($headers as $name => $value) {
            $lines[] = "$name: $value";
        }
        if ($body !== '') {
            $lines[] = 'Content-Length: ' . strlen($body);
        }

        return implode("\r\n", $lines) . "\r\n\r\n" . $body;
    }

    public function test200(): void
    {
        $res = $this->request('GET', '/');

        $this->assertSame(200, $res['status']);
        $this->assertSame('OK', $res['reason']);
        $this->assertSame('Hello world!', $res['body']);
        $this->assertSame('text/html', explode(';', $res['headers']['content-type'] ?? '')[0]);
        $this->assertSame('bytes', $res['headers']['accept-ranges'] ?? null);
    }

    public function testStaticFile(): void
    {
        $res = $this->request('GET', '/test.txt');

        $this->assertSame(200, $res['status']);
        $this->assertSame(self::TEST_CONTENT, $res['body']);
    }

    public function test404(): void
    {
        $res = $this->request('GET', '/not-found');

        $this->assertSame(404, $res['status']);
        $this->assertSame('Not Found', $res['body']);
    }

    public function testParentDirectoryIsStripped(): void
    {
        $res = $this->request('GET', '/../http/public/test.txt');

        $this->assertSame(404, $res['status']);
    }

    public function test206PartialContent(): void
    {
        $res = $this->request('GET', '/test.txt', ['Range' => 'bytes=5-14']);

        $this->assertSame(206, $res['status']);
        $this->assertSame(substr(self::TEST_CONTENT, 5, 10), $res['body']);
        $this->assertSame('bytes 5-14/36', $res['headers']['content-range'] ?? null);
    }

    public function testInvalidRange(): void
    {
        $res = $this->request('GET', '/test.txt', ['Range' => 'bytes=999999-1000000']);

        $this->assertSame(416, $res['status']);
    }

    public function testRouteCallback(): void
    {
        $this->server->addRoute('/hello', ['GET'], function (Request $request) {
            return 'hi ' . $request->query->get('name');
        });

        $res = $this->request('GET', '/hello?name=bob');

        $this->assertSame(200, $res['status']);
        $this->assertSame('hi bob', $res['body']);
    }

    public function testPostBody(): void
    {
        $this->server->addRoute('/echo', ['POST'], function (Request $request) {
            return strrev($request->getContent());
        });

        $res = $this->request('POST', '/echo', ['Content-Type' => 'text/plain'], 'abcdef');

        $this->assertSame(200, $res['status']);
        $this->assertSame('fedcba', $res['body']);
    }

    public function testPostBodyInTwoParts(): void
    {
        $routeCalls = 0;
        $this->server->addRoute('/echo', ['POST'], function (Request $request) use (&$routeCalls) {
            $routeCalls++;

            return strrev($request->getContent());
        });

        $serverReceived = '';
        $this->server->onConnection = function ($server, Connection $client) use (&$serverReceived) {
            $this->accepted[] = $client;
            $client->onData = function (Connection $conn, string $data) use (&$serverReceived) {
                $serverReceived .= $data;
            };
        };

        $request = $this->buildRequest('POST', '/echo', ['Content-Type' => 'text/plain'], 'abcdef');
        $firstPart = substr($request, 0, -3); // headers and 'abc'

        $client = $this->connect($state);
        $client->write($firstPart);

        // the server has the headers and half the body, but must wait for the rest
        $this->pumpUntil(function () use (&$serverReceived, $firstPart) {
            return $serverReceived === $firstPart;
        });
        $this->tick(0.1);
        $this->assertSame(0, $routeCalls);
        $this->assertSame('', $state['received']);

        $client->write('def');

        $this->pumpUntil(function () use ($state) {
            return $state['disconnected'];
        });
        $buffer = $state['received'];
        $responses = $this->parseResponses($buffer);
        $this->assertCount(1, $responses);
        $this->assertSame('', $buffer, 'Unexpected bytes after the response');
        $this->assertSame(200, $responses[0]['status']);
        $this->assertSame('fedcba', $responses[0]['body']);
        $this->assertSame(1, $routeCalls);
    }

    public function testAsyncRouteCallback(): void
    {
        $respond = null;
        $this->server->addRoute('/later', ['GET'], function (Request $request, callable $callback) use (&$respond) {
            $respond = $callback;

            return null; // answered later through $callback
        });

        $client = $this->connect($state);
        $client->write($this->buildRequest('GET', '/later'));

        $this->pumpUntil(function () use (&$respond) {
            return $respond !== null;
        });
        $this->tick(0.1);
        $this->assertSame('', $state['received']);

        $respond('done');

        $this->pumpUntil(function () use ($state) {
            return $state['disconnected'];
        });
        $buffer = $state['received'];
        $responses = $this->parseResponses($buffer);
        $this->assertCount(1, $responses);
        $this->assertSame(200, $responses[0]['status']);
        $this->assertSame('done', $responses[0]['body']);
    }

    /**
     * Connects and returns how many timers are running once the server has
     * accepted: the client sets no timeouts, so these are the accepted
     * connection's read timer.
     */
    private function timersAfterAccept(): int
    {
        $this->connect();
        $this->pumpUntil(function () {
            return count($this->accepted) === 1;
        });

        return $this->countMonocycle('timers');
    }

    public function testAcceptedConnectionsGetReadTimeout(): void
    {
        $this->assertSame(1, $this->timersAfterAccept());
    }

    public function testZeroClientReadTimeoutDisablesIt(): void
    {
        $this->assertSame($this->server, $this->server->setClientReadTimeout(0));

        $this->assertSame(0, $this->timersAfterAccept());
    }

    public function testOnConnectionClosureCanOverrideClientReadTimeout(): void
    {
        $this->server->onConnection = function ($server, Connection $client) {
            $client->setReadTimeout(0);
            $this->accepted[] = $client;
        };

        $this->assertSame(0, $this->timersAfterAccept());
    }

    public function testNegativeClientReadTimeoutIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->server->setClientReadTimeout(-1);
    }

    public function testKeepAlive(): void
    {
        $client = $this->connect($state);
        $buffer = '';

        foreach (['/', '/test.txt'] as $path) {
            $client->write($this->buildRequest('GET', $path, ['Connection' => 'keep-alive']));

            // pumpUntil() evaluates the condition again after the loop, so it must be idempotent
            $responses = [];
            $this->pumpUntil(function () use ($state, &$buffer, &$responses) {
                $buffer .= $state['received'];
                $state['received'] = '';
                array_push($responses, ...$this->parseResponses($buffer));

                return $responses || $state['disconnected'];
            });

            $this->assertCount(1, $responses);
            $this->assertSame(200, $responses[0]['status']);
        }

        $this->assertSame(self::TEST_CONTENT, $responses[0]['body']);

        // the server keeps the connection open after both responses
        $this->tick(0.2);
        $this->assertFalse($state['disconnected']);
        $this->assertSame(Socket::STATE_CONNECTED, $client->getState());
        $this->assertCount(1, $this->accepted);
        $this->assertTrue($this->accepted[0]->isOpen());

        // ... until the client goes away
        $client->close();
        $this->pumpUntil(function () {
            return !$this->accepted[0]->isOpen();
        });
    }

    public function testRequestTooLarge(): void
    {
        $client = $this->connect($state);
        // no header terminator, so the request never completes
        $client->write("GET / HTTP/1.1\r\nX-Padding: " . str_repeat('a', 5000));

        $this->pumpUntil(function () use ($state) {
            return $state['disconnected'];
        });

        $buffer = $state['received'];
        $responses = $this->parseResponses($buffer);
        $this->assertCount(1, $responses);
        $this->assertSame(400, $responses[0]['status']);
    }
}

<?php

use Symfony\Component\HttpFoundation\Response;
use Uhf\Monocycle\Proto\Http\Client\Client;
use Uhf\Monocycle\Proto\Http\Client\DigestAuth;
use Uhf\Monocycle\Proto\Http\Client\RequestException;

class HttpClientTest extends BaseTest
{
    /** @var resource|null */
    private $fakeServer;

    protected function tearDown(): void
    {
        if (is_resource($this->fakeServer)) {
            fclose($this->fakeServer);
        }
        $this->fakeServer = null;

        $this->resetMonocycle('sockets');
    }

    public function testConstructorWithMissingHostThrows(): void
    {
        $this->expectException(RequestException::class);
        new Client('/no/host/here');
    }

    public function testGetRequestParsesStatusAndBody(): void
    {
        $port = $this->startFakeServer();

        $response = null;
        $client = new Client("http://127.0.0.1:$port/foo");
        $client->onHttpResponse = function (Client $c, Response $r) use (&$response) {
            $response = $r;
        };
        $client->connect();

        $this->exchange(
            "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 5\r\n\r\nhello",
            function () use (&$response) {
                return $response !== null;
            }
        );

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('hello', $response->getContent());
        $this->assertSame('text/plain', $response->headers->get('Content-Type'));
    }

    public function testTimeoutTimerIsReleasedAfterResponse(): void
    {
        $port = $this->startFakeServer();

        $response = null;
        $client = new Client("http://127.0.0.1:$port/", Client::METHOD_GET, ['timeout' => 30]);
        $client->onHttpResponse = function (Client $c, Response $r) use (&$response) {
            $response = $r;
        };
        $client->connect();

        $this->exchange(
            "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nok",
            function () use (&$response) {
                return $response !== null;
            }
        );

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(0, $this->countMonocycle('timers'));

        $ref = WeakReference::create($client);
        unset($client);
        $this->assertNull($ref->get(), 'client must not be kept alive by its timeout timer');
    }

    public function testTimeoutTimerIsReleasedAfterError(): void
    {
        $port = $this->startFakeServer();

        $error = null;
        $client = new Client("http://127.0.0.1:$port/", Client::METHOD_GET, ['timeout' => 30]);
        $client->onError = function (Client $c, Throwable $e) use (&$error) {
            $error = $e;
        };
        $client->connect();

        $this->exchange(
            "HTTP/1.1 200 OK\r\nContent-Length: 100\r\n\r\nshort",
            function () use (&$error) {
                return $error !== null;
            }
        );

        $this->assertInstanceOf(RequestException::class, $error);
        $this->assertSame(0, $this->countMonocycle('timers'));

        $ref = WeakReference::create($client);
        unset($client);
        $this->assertNull($ref->get(), 'failed client must be freed without the cycle collector');
    }

    public function test404Response(): void
    {
        $port = $this->startFakeServer();

        $response = null;
        $client = new Client("http://127.0.0.1:$port/missing");
        $client->onHttpResponse = function (Client $c, Response $r) use (&$response) {
            $response = $r;
        };
        $client->connect();

        $this->exchange(
            "HTTP/1.1 404 Not Found\r\nContent-Length: 9\r\n\r\nNot Found",
            function () use (&$response) {
                return $response !== null;
            }
        );

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Not Found', $response->getContent());
    }

    public function testRequestLineAndDefaultHeadersAreSent(): void
    {
        $port = $this->startFakeServer();

        $client = new Client("http://127.0.0.1:$port/foo?bar=1", Client::METHOD_GET);
        $client->connect();

        $request = $this->captureRequest();

        $this->assertStringStartsWith("GET /foo?bar=1 HTTP/1.1\r\n", $request);
        $this->assertStringContainsString("Host: 127.0.0.1:$port\r\n", $request);
        $this->assertStringContainsString("Connection: close\r\n", $request);
        $this->assertStringContainsString("User-Agent: monocycle-http-client\r\n", $request);
        $this->assertStringNotContainsString('Content-Length', $request);
    }

    public function testQueryOptionAddedWhenUrlHasNoQueryParams(): void
    {
        $port = $this->startFakeServer();

        $client = new Client("http://127.0.0.1:$port/foo", Client::METHOD_GET, [
            Client::REQUEST_OPTION_QUERY => ['bar' => '1', 'baz' => 'two words'],
        ]);
        $client->connect();

        $request = $this->captureRequest();

        $this->assertStringStartsWith(
            'GET /foo?' . http_build_query(['bar' => '1', 'baz' => 'two words']) . " HTTP/1.1\r\n",
            $request
        );
    }

    public function testQueryOptionAppendedWhenUrlAlreadyHasQueryParams(): void
    {
        $port = $this->startFakeServer();

        $client = new Client("http://127.0.0.1:$port/foo?existing=1", Client::METHOD_GET, [
            Client::REQUEST_OPTION_QUERY => ['bar' => '2'],
        ]);
        $client->connect();

        $request = $this->captureRequest();

        $this->assertStringStartsWith(
            'GET /foo?existing=1&' . http_build_query(['bar' => '2']) . " HTTP/1.1\r\n",
            $request
        );
    }

    public function testCustomHeaderOverridesDefault(): void
    {
        $port = $this->startFakeServer();

        $client = new Client("http://127.0.0.1:$port/", Client::METHOD_GET, [
            'headers' => ['User-Agent' => 'custom-agent'],
        ]);
        $client->connect();

        $request = $this->captureRequest();

        $this->assertStringContainsString("User-Agent: custom-agent\r\n", $request);
        $this->assertStringNotContainsString('monocycle-http-client', $request);
    }

    public function testJsonRequestBodyIsSent(): void
    {
        $port = $this->startFakeServer();

        $payload = ['a' => 1, 'b' => 'two'];
        $expectedBody = json_encode($payload);

        $client = new Client("http://127.0.0.1:$port/", Client::METHOD_POST, [
            Client::REQUEST_OPTION_JSON => $payload,
        ]);
        $client->connect();

        $request = $this->captureRequest(strlen($expectedBody));

        $this->assertStringStartsWith("POST / HTTP/1.1\r\n", $request);
        $this->assertStringContainsString("Content-Type: application/json\r\n", $request);
        $this->assertStringContainsString('Content-Length: ' . strlen($expectedBody) . "\r\n", $request);
        $this->assertStringEndsWith("\r\n\r\n" . $expectedBody, $request);
    }

    public function testFormParamsRequestBodyIsSent(): void
    {
        $port = $this->startFakeServer();

        $expectedBody = http_build_query(['a' => 1, 'b' => 'two words']);

        $client = new Client("http://127.0.0.1:$port/", Client::METHOD_POST, [
            Client::REQUEST_OPTION_FORM_PARAMS => ['a' => 1, 'b' => 'two words'],
        ]);
        $client->connect();

        $request = $this->captureRequest(strlen($expectedBody));

        $this->assertStringContainsString("Content-Type: application/x-www-form-urlencoded\r\n", $request);
        $this->assertStringEndsWith("\r\n\r\n" . $expectedBody, $request);
    }

    public function testChunkedResponseIsReassembled(): void
    {
        $port = $this->startFakeServer();

        $response = null;
        $client = new Client("http://127.0.0.1:$port/");
        $client->onHttpResponse = function (Client $c, Response $r) use (&$response) {
            $response = $r;
        };
        $client->connect();

        $body = "5\r\nhello\r\n6\r\n world\r\n0\r\n\r\n";
        $this->exchange(
            "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n" . $body,
            function () use (&$response) {
                return $response !== null;
            }
        );

        $this->assertSame('hello world', $response->getContent());
    }

    public function testCloseDelimitedBodyCompletesOnDisconnect(): void
    {
        $port = $this->startFakeServer();

        $response = null;
        $client = new Client("http://127.0.0.1:$port/");
        $client->onHttpResponse = function (Client $c, Response $r) use (&$response) {
            $response = $r;
        };
        $client->connect();

        // No Content-Length and no chunked framing: body ends when the
        // server closes the connection.
        $this->exchange(
            "HTTP/1.1 200 OK\r\n\r\nclose-delimited body",
            function () use (&$response) {
                return $response !== null;
            }
        );

        $this->assertSame('close-delimited body', $response->getContent());
    }

    public function testConnectionClosedBeforeResponseCompleteTriggersOnError(): void
    {
        $port = $this->startFakeServer();

        $error = null;
        $client = new Client("http://127.0.0.1:$port/");
        $client->onError = function (Client $c, Throwable $e) use (&$error) {
            $error = $e;
        };
        $client->connect();

        // Announce a body of 100 bytes but only send a few, then close.
        $this->exchange(
            "HTTP/1.1 200 OK\r\nContent-Length: 100\r\n\r\nshort",
            function () use (&$error) {
                return $error !== null;
            }
        );

        $this->assertInstanceOf(RequestException::class, $error);
        $this->assertStringContainsString('before response completed', $error->getMessage());
    }

    public function testMalformedStatusLineTriggersOnError(): void
    {
        $port = $this->startFakeServer();

        $error = null;
        $client = new Client("http://127.0.0.1:$port/");
        $client->onError = function (Client $c, Throwable $e) use (&$error) {
            $error = $e;
        };
        $client->connect();

        $this->exchange(
            "NOT A VALID STATUS LINE\r\n\r\nbody",
            function () use (&$error) {
                return $error !== null;
            }
        );

        $this->assertInstanceOf(RequestException::class, $error);
    }

    public function testConnectErrorTriggersOnError(): void
    {
        // Bind an ephemeral port, then release it so nothing is listening.
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($probe);
        $name = stream_socket_get_name($probe, false);
        fclose($probe);
        $port = (int)substr($name, strrpos($name, ':') + 1);

        $error = null;
        $client = new Client("http://127.0.0.1:$port/");
        $client->onError = function (Client $c, Throwable $e) use (&$error) {
            $error = $e;
        };
        $client->connect();

        $this->pumpUntil(function () use (&$error) {
            return $error !== null;
        }, 3.0);

        $this->assertInstanceOf(RequestException::class, $error);
        $this->assertStringContainsString('Connection failed', $error->getMessage());
    }

    public function testBasicAuthHeaderIsSent(): void
    {
        $port = $this->startFakeServer();

        $client = new Client("http://127.0.0.1:$port/", Client::METHOD_GET, [
            Client::REQUEST_OPTION_AUTH => ['user', 'pass'],
        ]);
        $client->connect();

        $request = $this->captureRequest();

        $this->assertStringContainsString('Authorization: Basic ' . base64_encode('user:pass') . "\r\n", $request);
    }

    public function testUnsupportedAuthTypeThrows(): void
    {
        $this->expectException(RequestException::class);
        new Client('http://127.0.0.1/', Client::METHOD_GET, [
            Client::REQUEST_OPTION_AUTH => ['user', 'pass', 'ntlm'],
        ]);
    }

    public function testDigestAuthAnswersChallengeOnNewConnection(): void
    {
        $port = $this->startFakeServer();

        $response = null;
        $client = new Client("http://127.0.0.1:$port/dir/index.html?x=1", Client::METHOD_GET, [
            Client::REQUEST_OPTION_AUTH => ['Mufasa', 'Circle Of Life', Client::AUTH_DIGEST],
        ]);
        $client->onHttpResponse = function (Client $c, Response $r) use (&$response) {
            $response = $r;
        };
        $client->connect();

        $requests = $this->serveSequence([
            "HTTP/1.1 401 Unauthorized\r\n"
            . "WWW-Authenticate: Digest realm=\"testrealm@host.com\", qop=\"auth\", nonce=\"abc123\", opaque=\"xyz\"\r\n"
            . "Content-Length: 0\r\n\r\n",
            "HTTP/1.1 200 OK\r\nContent-Length: 6\r\n\r\nsecret",
        ], function () use (&$response) {
            return $response !== null;
        });

        $this->assertCount(2, $requests);
        $this->assertStringNotContainsString('Authorization', $requests[0]);

        $this->assertMatchesRegularExpression('/^Authorization: Digest (.+)\r$/m', $requests[1]);
        preg_match('/^Authorization: (Digest .+)\r$/m', $requests[1], $m);
        $params = DigestAuth::parseChallenge($m[1]);

        $this->assertSame('Mufasa', $params['username']);
        $this->assertSame('/dir/index.html?x=1', $params['uri']);
        $this->assertSame('xyz', $params['opaque']);
        $ha1 = md5('Mufasa:testrealm@host.com:Circle Of Life');
        $ha2 = md5('GET:/dir/index.html?x=1');
        $this->assertSame(
            md5("$ha1:abc123:{$params['nc']}:{$params['cnonce']}:auth:$ha2"),
            $params['response']
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('secret', $response->getContent());

        // the retry client holds the original through its callbacks; both must go
        $ref = WeakReference::create($client);
        unset($client);
        $this->assertNull($ref->get(), 'digest-authenticated client must be freed without the cycle collector');
    }

    public function testDigestAuthDeliversSecond401WithoutLooping(): void
    {
        $port = $this->startFakeServer();

        $response = null;
        $client = new Client("http://127.0.0.1:$port/", Client::METHOD_GET, [
            Client::REQUEST_OPTION_AUTH => ['user', 'wrong', Client::AUTH_DIGEST],
        ]);
        $client->onHttpResponse = function (Client $c, Response $r) use (&$response) {
            $response = $r;
        };
        $client->connect();

        $challenge = "HTTP/1.1 401 Unauthorized\r\n"
            . "WWW-Authenticate: Digest realm=\"r\", nonce=\"n\"\r\n"
            . "Content-Length: 0\r\n\r\n";
        $requests = $this->serveSequence([$challenge, $challenge, $challenge], function () use (&$response) {
            return $response !== null;
        });

        $this->assertCount(2, $requests);
        $this->assertSame(401, $response->getStatusCode());
    }

    public function testDigestAuthWithoutChallengeDeliversResponse(): void
    {
        $port = $this->startFakeServer();

        $response = null;
        $client = new Client("http://127.0.0.1:$port/", Client::METHOD_GET, [
            Client::REQUEST_OPTION_AUTH => ['user', 'pass', Client::AUTH_DIGEST],
        ]);
        $client->onHttpResponse = function (Client $c, Response $r) use (&$response) {
            $response = $r;
        };
        $client->connect();

        $requests = $this->serveSequence(["HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nok"], function () use (&$response) {
            return $response !== null;
        });

        $this->assertCount(1, $requests);
        $this->assertSame('ok', $response->getContent());
    }

    private function startFakeServer(): int
    {
        $this->fakeServer = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($this->fakeServer, "Failed to start fake server: $errstr");

        $name = stream_socket_get_name($this->fakeServer, false);

        return (int)substr($name, strrpos($name, ':') + 1);
    }

    /**
     * Drives the Monocycle loop and the fake server's accept/read side at
     * once, capturing everything the client wrote until the request headers
     * plus at least $expectedBodyLength bytes of body have arrived.
     */
    private function captureRequest(int $expectedBodyLength = 0, float $timeout = 2.0): string
    {
        $conn = null;
        $request = '';
        $headerEnd = null;
        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            $this->tick();

            if ($conn === null) {
                $conn = $this->tryAccept();
            }

            if (is_resource($conn)) {
                $chunk = @fread($conn, 65536);
                if ($chunk !== false && $chunk !== '') {
                    $request .= $chunk;
                }
            }

            if ($headerEnd === null) {
                $pos = strpos($request, "\r\n\r\n");
                if ($pos !== false) {
                    $headerEnd = $pos + 4;
                }
            }

            if ($headerEnd !== null && strlen($request) - $headerEnd >= $expectedBodyLength) {
                break;
            }

            usleep(5000);
        }

        if (is_resource($conn)) {
            fclose($conn);
        }

        return $request;
    }

    /**
     * Drives the Monocycle loop and the fake server's accept side at once:
     * as soon as a request's headers have arrived, sends back
     * $responseToSend, then keeps driving the loop until $clientDone()
     * reports the client has settled (response parsed or error raised).
     */
    private function exchange(string $responseToSend, callable $clientDone, float $timeout = 2.0): void
    {
        $conn = null;
        $buffer = '';
        $responseSent = false;
        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            $this->tick();

            if ($conn === null && !$responseSent) {
                $conn = $this->tryAccept();
            }

            if (is_resource($conn) && !$responseSent) {
                $chunk = @fread($conn, 65536);
                if ($chunk !== false) {
                    $buffer .= $chunk;
                }
                if (strpos($buffer, "\r\n\r\n") !== false) {
                    fwrite($conn, $responseToSend);
                    fclose($conn);
                    $conn = null;
                    $responseSent = true;
                }
            }

            if ($clientDone()) {
                return;
            }

            usleep(5000);
        }

        if (is_resource($conn)) {
            fclose($conn);
        }
    }

    /**
     * Like exchange(), but answers successive connections with successive
     * entries of $responses. Returns the request received on each connection.
     *
     * @return string[]
     */
    private function serveSequence(array $responses, callable $clientDone, float $timeout = 2.0): array
    {
        $conn = null;
        $requests = [];
        $buffer = '';
        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            $this->tick();

            if ($conn === null && count($requests) < count($responses)) {
                $conn = $this->tryAccept();
                $buffer = '';
            }

            if (is_resource($conn)) {
                $chunk = @fread($conn, 65536);
                if ($chunk !== false) {
                    $buffer .= $chunk;
                }
                if (strpos($buffer, "\r\n\r\n") !== false) {
                    fwrite($conn, $responses[count($requests)]);
                    fclose($conn);
                    $conn = null;
                    $requests[] = $buffer;
                }
            }

            if ($clientDone()) {
                break;
            }

            usleep(5000);
        }

        if (is_resource($conn)) {
            fclose($conn);
        }

        return $requests;
    }

    /**
     * @return resource|null
     */
    private function tryAccept()
    {
        $conn = @stream_socket_accept($this->fakeServer, 0);
        if ($conn === false) {
            return null;
        }

        stream_set_blocking($conn, false);

        return $conn;
    }




}

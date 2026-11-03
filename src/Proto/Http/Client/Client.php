<?php

namespace Uhf\Monocycle\Proto\Http\Client;

use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;
use Uhf\Monocycle\Socket\Connection;
use Uhf\Monocycle\Timer\Timer;

class Client extends Connection
{
    public const METHOD_GET     = 'GET';
    public const METHOD_POST    = 'POST';
    public const METHOD_PUT     = 'PUT';
    public const METHOD_PATCH   = 'PATCH';
    public const METHOD_DELETE  = 'DELETE';
    public const METHOD_HEAD    = 'HEAD';
    public const METHOD_OPTIONS = 'OPTIONS';

    public const REQUEST_OPTION_HEADERS = 'headers';
    public const REQUEST_OPTION_QUERY = 'query';
    public const REQUEST_OPTION_BODY = 'body';
    public const REQUEST_OPTION_JSON = 'json';
    public const REQUEST_OPTION_FORM_PARAMS = 'form_params';
    /** [username, password] or [username, password, AUTH_BASIC|AUTH_DIGEST] */
    public const REQUEST_OPTION_AUTH = 'auth';

    public const AUTH_BASIC = 'basic';
    public const AUTH_DIGEST = 'digest';

    /** @var callable */
    public $onHttpResponse = null;

    private string $url;
    private string $address;
    private ?int $cryptoMethod;
    private ?array $sslOptions;
    private array $options;
    private string $method;
    private string $path;
    private array $requestHeaders;
    private string $requestBody;

    private ?ResponseParser $parser;
    private string $readBuffer = '';
    private bool $settled = false;
    private ?DigestAuth $digestAuth = null;
    
    private ?Timer $requestTimeoutTimer = null;

    /**
     * @param string $url
     * @param string $method
     * @param array $options
     * @throws RequestException
     */
    public function __construct(string $url, string $method = self::METHOD_GET, array $options = [])
    {
        parent::__construct(null);
        $parsed = parse_url($url);
        if ($parsed === false || empty($parsed['host'])) {
            throw new RequestException("Invalid URL: $url");
        }

        $secure = strtolower($parsed['scheme'] ?? 'http') === 'https';
        $port = $parsed['port'] ?? ($secure ? 443 : 80);

        $path = ($parsed['path'] ?? '') ?: '/';
        if (!empty($parsed['query'])) {
            $path .= '?' . $parsed['query'];
        }
        if (!empty($options[self::REQUEST_OPTION_QUERY])) {
            $qs = is_array($options[self::REQUEST_OPTION_QUERY]) ?
                http_build_query($options[self::REQUEST_OPTION_QUERY]) :
                $options[self::REQUEST_OPTION_QUERY];
            $path .= (strpos($path, '?') === false ? '?' : '&') . $qs;
        }
        $this->path = $path;

        $body = '';
        $contentType = null;
        if (isset($options[self::REQUEST_OPTION_JSON])) {
            $contentType = 'application/json';
            $body = json_encode($options[self::REQUEST_OPTION_JSON]);
        }
        if (isset($options[self::REQUEST_OPTION_FORM_PARAMS])) {
            $contentType = 'application/x-www-form-urlencoded';
            $body = http_build_query($options[self::REQUEST_OPTION_FORM_PARAMS]);
        }
        if (isset($options[self::REQUEST_OPTION_BODY])) {
            $body = $options[self::REQUEST_OPTION_BODY];
        }

        $this->requestBody = $body;

        $headers = $options[self::REQUEST_OPTION_HEADERS] ?? [];
        $headers += ['Host' => $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '')];
        $headers += ['Connection' => 'close'];
        $headers += ['User-Agent' => 'monocycle-http-client'];
        if (!empty($options[self::REQUEST_OPTION_AUTH]) && !isset($headers['Authorization'])) {
            [$username, $password] = $options[self::REQUEST_OPTION_AUTH];
            $authType = strtolower($options[self::REQUEST_OPTION_AUTH][2] ?? self::AUTH_BASIC);
            if ($authType === self::AUTH_BASIC) {
                $headers['Authorization'] = 'Basic ' . base64_encode("$username:$password");
            } elseif ($authType === self::AUTH_DIGEST) {
                // Sent unauthenticated first; the 401 challenge is answered in complete().
                $this->digestAuth = new DigestAuth($username, $password);
            } else {
                throw new RequestException("Unsupported auth type: $authType");
            }
        }
        if ($contentType !== null && !isset($headers['Content-Type'])) {
            $headers['Content-Type'] = $contentType;
        }
        if (strlen($this->requestBody)) {
            $headers['Content-Length'] = strlen($this->requestBody);
        }
        $this->requestHeaders = $headers;
        $this->method = strtoupper($method);
        $this->url = $url;
        $this->options = $options;

        $this->address = sprintf('tcp://%s:%s', $parsed['host'], $port);
        $this->cryptoMethod = $secure ? STREAM_CRYPTO_METHOD_ANY_CLIENT : null;
        $this->sslOptions = $secure ?
            ['verify_peer' => $options['verify'] ?? true, 'verify_peer_name' => $options['verify'] ?? true] :
            null;
        if (!empty($options['timeout'])) {
            $this->requestTimeoutTimer = (new Timer(function() {
                $this->fail(new RequestException("Request failed: timed out"));
            }))->setDelay($options['timeout']);
        }
    }

    /**
     * The address and TLS settings default to the ones derived from the request URL
     */
    public function connect(?string $remoteAddress = null, ?int $cryptoMethod = null, ?array $sslOptions = null)
    {
        if ($this->requestTimeoutTimer !== null) {
            $this->requestTimeoutTimer->start();
        }
        parent::connect(
            $remoteAddress ?? $this->address,
            $cryptoMethod ?? $this->cryptoMethod,
            $sslOptions ?? $this->sslOptions
        );
    }

    protected function onConnect()
    {
        parent::onConnect();
        $this->sendRequest($this->method, $this->path, $this->requestHeaders, $this->requestBody);
        $this->parser = new ResponseParser(function (HttpResponse $response) {
            $this->complete($response);
        });
    }

    protected function onConnectionError($message)
    {
        $this->fail(new RequestException("Connection failed: $message"));
        parent::onConnectionError($message);
    }

    protected function onData(string $data)
    {
        parent::onData($data);
        if (empty($this->parser)) {
            return;
        }
        $this->readBuffer .= $data;

        try {
            $this->parser->parse($this->readBuffer);
        } catch (Throwable $e) {
            $this->fail($e);
        }
    }

    protected function onDisconnect()
    {
        if ($this->settled) {
            return;
        }

        if (!$this->parser->finish($this->readBuffer)) {
            $this->fail(new RequestException('Connection closed before response completed'));
        }
        parent::onDisconnect();
    }

    protected function onHttpResponse(HttpResponse $response)
    {
        if (is_callable($this->onHttpResponse)) {
            call_user_func($this->onHttpResponse, $this, $response);
        }
    }

    protected function sendRequest($method = self::METHOD_GET, $path = '/', array $headers = [], string $body = '')
    {
        $req = sprintf("%s %s HTTP/1.1\r\n", $method, $path);

        if (strlen($body)) {
            $headers['Content-Length'] = strlen($body);
        }

        foreach ($headers as $name => $value) {
            $req .= "$name: $value\r\n";
        }
        $req .= "\r\n" . $body;

        $this->write($req);
    }

    protected function onClose(): void
    {
        $this->parser = null;
        $this->readBuffer = '';
        //$this->onHttpResponse = null;
        //$this->onError = null;
        parent::onClose();
    }

    private function complete(HttpResponse $response): void
    {
        if ($this->settled) {
            return;
        }
        $this->settled = true;
        $this->stopTimeoutTimer();

        if ($this->digestAuth !== null && $response->getStatusCode() === 401) {
            $challenge = DigestAuth::parseChallenge((string)$response->headers->get('WWW-Authenticate'));
            if ($challenge !== null) {
                $this->close();
                // user callbacks stay: the retry reports through them
                $this->retryWithDigest($challenge);
                return;
            }
        }

        try {
            $this->onHttpResponse($response);
        } finally {
            $this->close();
        }
    }

    /**
     * Connection: close means the challenge can't be answered on this
     * socket, so the authorized request goes out on a fresh client whose
     * outcome is reported through this one.
     */
    private function retryWithDigest(array $challenge): void
    {
        try {
            $options = $this->options;
            unset($options[self::REQUEST_OPTION_AUTH]);
            $options[self::REQUEST_OPTION_HEADERS]['Authorization'] = $this->digestAuth->authorize(
                $challenge,
                $this->method,
                $this->path,
                $this->requestBody
            );

            $retry = new self($this->url, $this->method, $options);
            $retry->onHttpResponse = function (Client $client, HttpResponse $response) {
                $this->onHttpResponse($response);
            };
            $retry->onError = function (Client $client, Throwable $e) {
                $this->onError($e);
            };
            $retry->connect();
        } catch (Throwable $e) {
            $this->onError($e);
        }
    }

    private function fail(Throwable $e): void
    {
        if ($this->settled) {
            return;
        }
        $this->settled = true;
        $this->stopTimeoutTimer();

        try {
            $this->onError($e);
        } finally {
            $this->close(false);
        }
    }

    /**
     * The timer's callback holds $this, so a timer left running keeps the
     * client (and its buffers) alive in Monocycle::$timers until it fires.
     */
    private function stopTimeoutTimer(): void
    {
        if ($this->requestTimeoutTimer !== null) {
            if ($this->requestTimeoutTimer->isRunning()) {
                $this->requestTimeoutTimer->stop();
            }
            $this->requestTimeoutTimer = null;
        }
    }
}

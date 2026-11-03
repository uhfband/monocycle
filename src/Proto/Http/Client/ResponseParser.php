<?php

namespace Uhf\Monocycle\Proto\Http\Client;

use Symfony\Component\HttpFoundation\Response;

class ResponseParser
{
    /** @var callable */
    private $callback;

    private bool $headersParsed = false;
    private int $statusCode = 200;
    private string $statusReason = '';
    private array $headers = [];
    private ?int $contentLength = null;
    private bool $chunked = false;

    public function __construct($callback)
    {
        $this->callback = $callback;
    }

    public function parse(string &$data)
    {
        while (1) {
            if (!$this->headersParsed) {
                if (!preg_match('/^(.+?)\r?\n\r?\n/s', $data, $parts)) {
                    return;
                }

                $headerLen = strlen($parts[0]);

                $lines = explode("\n", $parts[1]);
                $statusLine = trim(array_shift($lines));

                if (!preg_match('#^HTTP/\d\.\d\s+(\d{3})\s*(.*)$#', $statusLine, $sm)) {
                    throw new RequestException("Malformed status line: $statusLine");
                }

                $this->statusCode = (int)$sm[1];
                $this->statusReason = trim($sm[2]);
                $this->headers = [];

                foreach ($lines as $header) {
                    if (preg_match('/^([^:]+):\s*(.*)$/', trim($header), $m)) {
                        $this->headers[$m[1]] = $m[2];
                    }
                }

                $this->contentLength = null;
                $this->chunked = false;

                foreach ($this->headers as $name => $value) {
                    if (strtolower($name) === 'content-length') {
                        $this->contentLength = (int)$value;
                    } elseif (strtolower($name) === 'transfer-encoding' && stripos($value, 'chunked') !== false) {
                        $this->chunked = true;
                    }
                }

                $data = substr($data, $headerLen);
                $this->headersParsed = true;
            }

            if ($this->chunked) {
                $body = $this->extractChunked($data);
            } elseif ($this->contentLength !== null) {
                $body = $this->extractByLength($data, $this->contentLength);
            } else {
                // No Content-Length/chunked framing: body runs until the
                // connection closes, see finish().
                return;
            }

            if ($body === null) {
                return;
            }

            $this->dispatch($body);
        }
    }

    /**
     * Flushes a response whose body is delimited by connection close rather
     * than Content-Length/chunked framing. Call once the underlying socket
     * has disconnected.
     *
     * @return bool whether a response was flushed
     */
    public function finish(string &$data): bool
    {
        if (!$this->headersParsed || $this->contentLength !== null || $this->chunked) {
            return false;
        }

        $body = $data;
        $data = '';
        $this->dispatch($body);

        return true;
    }

    private function extractByLength(string &$data, int $length): ?string
    {
        if (strlen($data) < $length) {
            return null;
        }

        $body = substr($data, 0, $length);
        $data = substr($data, $length);

        return $body;
    }

    private function extractChunked(string &$data): ?string
    {
        $out = '';
        $offset = 0;

        while (true) {
            $pos = strpos($data, "\r\n", $offset);
            if ($pos === false) {
                return null;
            }

            $size = hexdec(trim(explode(';', substr($data, $offset, $pos - $offset))[0]));
            $chunkStart = $pos + 2;

            if ($size === 0) {
                $data = substr($data, $chunkStart);
                return $out;
            }

            if (strlen($data) < $chunkStart + $size + 2) {
                return null;
            }

            $out .= substr($data, $chunkStart, $size);
            $offset = $chunkStart + $size + 2;
        }
    }

    private function dispatch(string $body): void
    {
        $response = new Response($body, $this->statusCode, $this->headers);
        if ($this->statusReason !== '') {
            $response->setStatusCode($response->getStatusCode(), $this->statusReason);
        }

        $this->headersParsed = false;
        $this->headers = [];
        $this->contentLength = null;
        $this->chunked = false;

        call_user_func($this->callback, $response);
    }
}

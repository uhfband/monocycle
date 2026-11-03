<?php

namespace Uhf\Monocycle\Proto\Http\Server;

class Response
{

    private string $httpVersion = '1.1';

    private $statusCode;

    private $statusMessage;

    /** @var array<string, string|int|array> name => value, an array value is sent as one line per element */
    private array $headers = [];

    protected $content = '';

    private $contentStream;

    private $sendHeader;

    /** @var int|null bytes of a stream body still to send, null = until EOF */
    private ?int $remaining = null;

    public function __construct($code = 200, $message = 'OK')
    {
        $this->statusCode = $code;
        $this->statusMessage = $message;
        $this->sendHeader = true;
        $this->setContent('');
    }

    public function setContent($content) 
    {
        $this->content = $content;
        $this->setHeader('Content-Length', strlen($content));
    }

    public function setContentFile($file) 
    {
        $this->content = fopen($file, 'r');
        $this->setHeader('Content-Length', fstat($this->content)['size']);
    }

    public function setContentStream($stream)
    {
        $this->content = $stream;
    }

    public function setContentRange(int $start, int $end)
    {
        fseek($this->content, $start);
        $size = $this->getContentSize();
        $end = $end ?: ($size - 1);
        $this->setHeader('Content-Range', "bytes {$start}-{$end}/{$size}");
        $this->setHeader('Content-Length', $end - $start + 1);
        // stop at the end of the range instead of reading to EOF
        $this->remaining = $end - $start + 1;
    }

    public function getContentSize() 
    {
        return (is_string($this->content)) ? strlen($this->content) : fstat($this->content)['size'];
    }

    public function getHttpVersion(): string
    {
        return $this->httpVersion;
    }

    public function getStatusCode()
    {
        return $this->statusCode;
    }

    public function getStatusMessage()
    {
        return $this->statusMessage;
    }

    /**
     * @return array<string, string|int|array> headers as set, keyed by the name used to set them
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Looks a header up by name, ignoring case; null if it isn't set.
     *
     * @return string|int|array|null an array for a header set with addHeader() more than once
     */
    public function getHeader(string $name)
    {
        foreach ($this->headers as $header => $value) {
            if (strcasecmp($header, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    public function setHeader($header, $value)
    {
        $this->headers[$header] = $value;
    }

    public function addHeader($header, $value)
    {
        if (isset($this->headers[$header])) {
            $this->headers[$header] = array_merge((array)$this->headers[$header], [$value]);
        } else {
            $this->headers[$header] = $value;
        }
    }

    public function eof(): bool
    {
        return is_string($this->content) || $this->remaining === 0 || feof($this->content);
    }

    public function __toString(): string
    {
        return $this->getContent();
    }

    public function getContent(): string
    {
        $result = '';

        if ($this->sendHeader) {
            // an array value is sent as one header line per element (e.g. several Set-Cookie)
            $headers = join("\r\n", array_map(function($value, $key) {
                return join("\r\n", array_map(function($v) use ($key) {
                    return $key . ": " . $v;
                }, (array)$value));
            }, $this->headers, array_keys($this->headers)));

            $result .= sprintf("HTTP/%s %s %s\r\n%s\r\n\r\n", 
                $this->httpVersion, 
                $this->statusCode, 
                $this->statusMessage,
                $headers
            );
            $this->sendHeader = false;
        }

        if (is_string($this->content)) {
            $result .= $this->content;
        } elseif ($this->remaining === null) {
            $result .= fread($this->content, 4096);
        } elseif ($this->remaining > 0) {
            $chunk = (string)fread($this->content, min(4096, $this->remaining));
            $this->remaining -= strlen($chunk);
            $result .= $chunk;
        }

        return $result;
    }
}

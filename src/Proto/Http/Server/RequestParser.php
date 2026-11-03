<?php

namespace Uhf\Monocycle\Proto\Http\Server;

use Symfony\Component\HttpFoundation\Request;

class RequestParser
{
    /** @var callable */
    private $callback;

    public function __construct($callback)
    {
        $this->callback = $callback;
    }

    public function parse(string &$data)
    {
        while (1) {
            if (!preg_match('/^(.+?)\r?\n\r?\n/s', $data, $parts)) {
                return;
            }

            $headerLen = strlen($parts[0]);

            $headers = explode("\n", $parts[1]);
            list($method, $uri, $version) = explode(' ', trim(array_shift($headers)));

            $parsedHeaders = [];

            foreach($headers as $header) {
                if (preg_match('/^([^:]+):\s*(.*)$/', trim($header), $m)) {
                    $parsedHeaders[$m[1]] = $m[2];
                }
            }

            $length = 0;
            foreach ($parsedHeaders as $name => $value) {
                if (strtolower($name) === 'content-length') {
                    $length = (int)$value;
                    break;
                }
            }

            if ($length) {
                if (strlen($data) < ($headerLen + $length)) {
                    return;
                }
                $body = substr($data, $headerLen, $length);
            } else {
                $body = '';
            }

            $request = Request::create(
                $uri,
                $method,
                [],
                [],
                [],
                ['SERVER_PROTOCOL' => $version],
                $body
            );

            foreach ($parsedHeaders as $name => $value) {
                $request->headers->set($name, $value);
            }

            $data = substr($data, $headerLen + $length);

            call_user_func($this->callback, $request);
        }
    }
}

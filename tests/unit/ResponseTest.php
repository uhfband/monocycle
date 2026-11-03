<?php

use Uhf\Monocycle\Proto\Http\Server\Response;

class ResponseTest extends BaseTest
{
    public function testGetters(): void
    {
        $response = new Response(404, 'Not Found');
        $response->setHeader('Content-Type', 'text/plain');
        $response->setContent('nope');

        $this->assertSame('1.1', $response->getHttpVersion());
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Not Found', $response->getStatusMessage());
        $this->assertSame(['Content-Length' => 4, 'Content-Type' => 'text/plain'], $response->getHeaders());
    }

    public function testDefaults(): void
    {
        $response = new Response();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('OK', $response->getStatusMessage());
        $this->assertSame(['Content-Length' => 0], $response->getHeaders());
    }

    public function testGetHeaderIgnoresCase(): void
    {
        $response = new Response();
        $response->setHeader('Content-type', 'text/html');

        $this->assertSame('text/html', $response->getHeader('content-type'));
        $this->assertSame('text/html', $response->getHeader('CONTENT-TYPE'));
        $this->assertNull($response->getHeader('Location'));
    }

    public function testGetHeaderReturnsRepeatedHeaderAsArray(): void
    {
        $response = new Response();
        $response->addHeader('Set-Cookie', 'a=1');
        $response->addHeader('Set-Cookie', 'b=2');

        $this->assertSame(['a=1', 'b=2'], $response->getHeader('set-cookie'));
        $this->assertStringContainsString("Set-Cookie: a=1\r\nSet-Cookie: b=2\r\n", (string)$response);
    }

    public function testStatusLineUsesHttpVersion(): void
    {
        $this->assertStringStartsWith("HTTP/1.1 201 Created\r\n", (string)new Response(201, 'Created'));
    }
}

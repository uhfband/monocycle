<?php

use Symfony\Component\HttpFoundation\Request;
use Uhf\Monocycle\Proto\Http\Server\PhpCgi;
use Uhf\Monocycle\Proto\Http\Server\Response;
use Uhf\Monocycle\Proto\Http\Server\Router;

class PhpCgiTest extends BaseTest
{
    private const DOC_ROOT = __DIR__ . '/fixtures';
    private const SCRIPT = self::DOC_ROOT . '/cgi_script.php';

    protected function setUp(): void
    {
        if (!trim((string)shell_exec('command -v php-cgi'))) {
            $this->markTestSkipped('php-cgi is not installed');
        }
    }

    protected function tearDown(): void
    {
        $this->resetMonocycle('processes', 'timers');
    }

    private function runCgi(string $uri, string $method = 'GET', string $body = '', int $timeout = 30): Response
    {
        $request = Request::create($uri, $method, [], [], [], [], $body);
        if ($body !== '') {
            $request->headers->set('Content-Type', 'text/plain');
        }

        $response = null;
        $cgi = new PhpCgi(self::SCRIPT, self::DOC_ROOT, $request, function ($r) use (&$response) {
            $this->assertNull($response, 'callback must fire once');
            $response = $r;
        }, $timeout);
        $cgi->run();

        $this->pumpUntil(function () use (&$response) {
            return $response !== null;
        }, $timeout ? $timeout + 2.0 : 5.0, true);

        return $response;
    }

    private function body(Response $response): string
    {
        $content = $response->getContent();

        return substr($content, strpos($content, "\r\n\r\n") + 4);
    }

    public function testRunsScriptAndReturnsBody(): void
    {
        $response = $this->runCgi('/cgi_script.php');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('text/html', $response->getHeader('Content-type'));
        $this->assertSame(strlen('Hello from CGI'), $response->getHeader('Content-Length'));
        $this->assertSame('Hello from CGI', $this->body($response));
    }

    public function testPassesCgiEnvironment(): void
    {
        $request = Request::create('/cgi_script.php?env', 'GET');
        $request->headers->set('X-Custom', 'yes');
        $request->headers->set('Proxy', 'http://evil');
        $env = (new PhpCgi(self::SCRIPT, self::DOC_ROOT, $request, function () {}))->getEnv();

        $this->assertSame('GET', $env['REQUEST_METHOD']);
        $this->assertSame('env', $env['QUERY_STRING']);
        $this->assertSame('/cgi_script.php?env', $env['REQUEST_URI']);
        $this->assertSame('/cgi_script.php', $env['SCRIPT_NAME']);
        $this->assertSame(self::SCRIPT, $env['SCRIPT_FILENAME']);
        $this->assertSame('200', $env['REDIRECT_STATUS']);
        $this->assertSame('yes', $env['HTTP_X_CUSTOM']);
        $this->assertArrayNotHasKey('HTTP_PROXY', $env);

        $response = $this->runCgi('/cgi_script.php?env');
        $childEnv = json_decode($this->body($response), true);
        $this->assertSame('CGI/1.1', $childEnv['GATEWAY_INTERFACE']);
    }

    public function testPostBodyGoesToStdin(): void
    {
        $response = $this->runCgi('/cgi_script.php?body', 'POST', 'posted data');

        $this->assertSame('posted data', $this->body($response));
    }

    public function testStatusAndRepeatedHeaders(): void
    {
        $response = $this->runCgi('/cgi_script.php?status');

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('Created', $response->getStatusMessage());
        $this->assertStringContainsString("Set-Cookie: a=1\r\nSet-Cookie: b=2\r\n", $response->getContent());
    }

    public function testLocationWithoutStatusIsRedirect(): void
    {
        $response = $this->runCgi('/cgi_script.php?redirect');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/elsewhere', $response->getHeader('Location'));
    }

    public function testInvalidOutputIsBadGateway(): void
    {
        $log = $this->captureLog();
        // php-cgi always writes a header block, so feed the parser directly
        $response = null;
        $cgi = new PhpCgi(self::SCRIPT, self::DOC_ROOT, Request::create('/cgi_script.php'), function ($r) use (&$response) {
            $response = $r;
        });
        $reflection = new ReflectionClass($cgi);
        $output = $reflection->getProperty('output');
        $output->setAccessible(true);
        $output->setValue($cgi, 'no header block');
        $finish = $reflection->getMethod('finish');
        $finish->setAccessible(true);
        $finish->invoke($cgi);

        $this->assertSame(502, $response->getStatusCode());
        $this->assertTrue($log->hasErrorThatContains('php-cgi returned no valid CGI response'));
    }

    public function testStderrDoesNotBreakResponse(): void
    {
        $log = $this->captureLog();

        $this->assertSame('with stderr', $this->body($this->runCgi('/cgi_script.php?stderr')));
        $this->assertTrue($log->hasWarningThatContains('php-cgi stderr: oops'));
    }

    public function testTimeoutKillsScript(): void
    {
        $log = $this->captureLog();
        $start = microtime(true);
        $response = $this->runCgi('/cgi_script.php?sleep', 'GET', '', 1);

        $this->assertSame(504, $response->getStatusCode());
        $this->assertTrue($log->hasErrorThatContains('php-cgi timed out after 1s'));
        $this->assertLessThan(5, microtime(true) - $start);
    }

    public function testRouterAnswersPhpAsynchronously(): void
    {
        $router = new Router(self::DOC_ROOT);

        $response = null;
        $result = $router->route(Request::create('/cgi_script.php'), function ($r) use (&$response) {
            $response = $r;
        });
        $this->assertNull($result);

        $this->pumpUntil(function () use (&$response) {
            return $response !== null;
        }, 5.0, true);
        $this->assertSame('Hello from CGI', $this->body($response));
    }

    public function testServesHttpFixture(): void
    {
        $router = new Router(__DIR__ . '/fixtures/public');

        $response = null;
        $router->route(Request::create('/test.php'), function ($r) use (&$response) {
            $response = $r;
        });
        $this->pumpUntil(function () use (&$response) {
            return $response !== null;
        }, 5.0, true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Hello world!', $this->body($response));
    }

}

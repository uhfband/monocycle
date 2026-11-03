<?php

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Uhf\Monocycle\Proto\Http\Server\Router;

class RouterTest extends TestCase
{
    private function createRouter(array $paths): Router
    {
        $router = new Router(__DIR__ . '/fixtures/public');
        foreach ($paths as $path) {
            $router->addRoute($path, ['GET'], function () use ($path) {
                return $path;
            });
        }

        return $router;
    }

    private function route(Router $router, string $uri, string $method = 'GET'): string
    {
        return $router->route(Request::create($uri, $method), function () {})->getContent();
    }

    private function body(Router $router, string $uri): string
    {
        $content = $this->route($router, $uri);

        return substr($content, strpos($content, "\r\n\r\n") + 4);
    }

    public function testDocumentationExamples(): void
    {
        $router = $this->createRouter([
            '/images/',
            '~ \.jpg$',
            '^~/images/static/',
            '= /images/logo.jpg',
        ]);

        $this->assertSame('= /images/logo.jpg', $this->body($router, '/images/logo.jpg'));
        $this->assertSame('^~/images/static/', $this->body($router, '/images/static/photo.jpg'));
        $this->assertSame('~ \.jpg$', $this->body($router, '/images/photo.jpg'));
        $this->assertSame('/images/', $this->body($router, '/images/photo.png'));
    }

    public function testExactMatchDoesNotMatchPrefix(): void
    {
        $router = $this->createRouter(['=/api']);

        $this->assertSame('=/api', $this->body($router, '/api'));
        $this->assertSame('=/api', $this->body($router, '/api?x=1'));
        $this->assertSame('Not Found', $this->body($router, '/api/users'));
    }

    public function testLongestPrefixWins(): void
    {
        $router = $this->createRouter(['/api', '/api/v2/', '/']);

        $this->assertSame('/api/v2/', $this->body($router, '/api/v2/users'));
        $this->assertSame('/api', $this->body($router, '/api/v1/users'));
        $this->assertSame('/', $this->body($router, '/other'));
    }

    public function testShorterNoRegexPrefixDoesNotBlockRegex(): void
    {
        $router = $this->createRouter(['^~/images/', '/images/static/', '~ \.jpg$']);

        $this->assertSame('~ \.jpg$', $this->body($router, '/images/static/a.jpg'));
        $this->assertSame('^~/images/', $this->body($router, '/images/a.jpg'));
    }

    public function testRegexOrderAndCaseSensitivity(): void
    {
        $router = $this->createRouter(['~ \.JPG$', '~* \.jpg$', '~ \.jpg$']);

        $this->assertSame('~ \.JPG$', $this->body($router, '/a.JPG'));
        $this->assertSame('~* \.jpg$', $this->body($router, '/a.jpg'));
        $this->assertSame('~* \.jpg$', $this->body($router, '/a.Jpg'));
    }

    public function testRegexCaptures(): void
    {
        $router = new Router();
        $router->addRoute('~ ^/users/(?<id>\d+)$', ['GET'], function (Request $request) {
            return $request->attributes->get('matches')['id'];
        });

        $this->assertSame('42', $this->body($router, '/users/42'));
    }

    public function testMethodIsRespected(): void
    {
        $router = $this->createRouter(['/api']);

        $this->assertSame('Not Found', substr($this->route($router, '/api', 'POST'), -9));
    }

    public function testFallsBackToFilesystem(): void
    {
        $router = $this->createRouter(['=/api']);

        $this->assertStringStartsWith('1234567890', $this->body($router, '/test.txt'));
    }
}

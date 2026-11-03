<?php

namespace Uhf\Monocycle\Proto\Http\Server;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;
use Uhf\Monocycle\Monocycle;
use Uhf\Monocycle\Process\Process;
use Uhf\Monocycle\Timer\Timer;

/**
 * Runs a PHP script through php-cgi in a child Process without blocking the loop.
 *
 * The request body goes to the child's stdin, its stdout is collected until EOF and then
 * turned into a Response (CGI "Status"/"Location" headers are honoured), stderr is logged.
 * The Response is handed to the callback exactly once: on stdout EOF, on process exit, on
 * timeout (504, the child is killed) or when the child cannot be started (500).
 *
 * @link https://www.rfc-editor.org/rfc/rfc3875
 */
class PhpCgi
{
    private const PHP_CGI_CMD = ['php-cgi'];

    private array $cmd;
    private string $file;
    private string $scriptName;
    private string $documentRoot;
    private Request $request;
    /** @var callable */
    private $callback;
    private int $timeout;

    private ?Process $process = null;
    private ?Timer $timer = null;
    private string $output = '';
    private string $error = '';
    private bool $done = false;

    /**
     * @param string $file script to run (SCRIPT_FILENAME)
     * @param string $documentRoot
     * @param Request $request
     * @param callable $callback function (Response $response)
     * @param int $timeout seconds, 0 for none; second granularity like all timers
     */
    public function __construct(
        string $file,
        string $documentRoot,
        Request $request,
        callable $callback,
        int $timeout = 30
    ) {
        $this->cmd = self::PHP_CGI_CMD;
        $documentRoot = rtrim($documentRoot, '/');
        // the URL path, taken before realpath() so a symlinked script keeps its own name
        $this->scriptName = substr($file, strlen($documentRoot));
        // php-cgi (cgi.fix_pathinfo=1) answers "No input file specified." to a path with ".."
        $this->file = realpath($file) ?: $file;
        $this->documentRoot = realpath($documentRoot) ?: $documentRoot;
        $this->request = $request;
        $this->callback = $callback;
        $this->timeout = $timeout;
    }

    public function run(): void
    {
        $this->process = new Process(
            $this->cmd,
            [
                Process::STDIN => ['pipe', 'r'],
                Process::STDOUT => ['pipe', 'w'],
                Process::STDERR => ['pipe', 'w'],
            ],
            Monocycle::getLogger(),
            ['script' => $this->file],
            $this->getEnv()
        );

        $this->process->onStdout = function (Process $process, string $data) {
            $this->output .= $data;
        };
        $this->process->onStderr = function (Process $process, string $data) {
            $this->error .= $data;
        };
        // fallback: normally stdout EOF comes first, and onExit only on the next 1s tick
        $this->process->onExit = function (Process $process, int $code) {
            $this->finish($code ? sprintf('php-cgi exited with code %d', $code) : null);
        };

        try {
            $this->process->start();
        } catch (Throwable $e) {
            $this->process = null;
            $this->finish('php-cgi start failed: ' . $e->getMessage(), 500);
            return;
        }

        $stdout = $this->process->getPipe(Process::STDOUT);
        if ($stdout) {
            $stdout->onEnd = $stdout->onError = function () {
                $this->finish();
            };
        }

        $body = $this->request->getContent();
        if (strlen($body)) {
            // php-cgi reads exactly CONTENT_LENGTH bytes, so stdin does not need to be closed
            $this->process->write($body, Process::STDIN);
        }

        if ($this->timeout > 0) {
            $this->timer = new Timer(function () {
                if ($this->done) {
                    return;
                }
                $this->process->kill();
                $this->finish(sprintf('php-cgi timed out after %ds', $this->timeout), 504);
            });
            $this->timer->setDelay($this->timeout)->start();
        }
    }

    public function getEnv(): array
    {
        $request = $this->request;
        $uri = $request->getRequestUri();

        $env = [
            'GATEWAY_INTERFACE' => 'CGI/1.1',
            'SERVER_SOFTWARE' => 'monocycle',
            'SERVER_PROTOCOL' => $request->server->get('SERVER_PROTOCOL', 'HTTP/1.0'),
            'SERVER_NAME' => $request->getHost(),
            'SERVER_ADDR' => $request->server->get('SERVER_ADDR', ''),
            'SERVER_PORT' => (string)$request->server->get('SERVER_PORT', $request->getPort()),
            'REMOTE_ADDR' => (string)$request->server->get('REMOTE_ADDR', ''),
            'REMOTE_PORT' => (string)$request->server->get('REMOTE_PORT', ''),
            'REQUEST_METHOD' => $request->getMethod(),
            'REQUEST_URI' => $uri,
            'QUERY_STRING' => explode('?', $uri, 2)[1] ?? '',
            'DOCUMENT_ROOT' => $this->documentRoot,
            'SCRIPT_FILENAME' => $this->file,
            'SCRIPT_NAME' => $this->scriptName,
            // php-cgi refuses to run without it (cgi.force_redirect)
            'REDIRECT_STATUS' => '200',
            'PATH' => (string)getenv('PATH'),
        ];

        if (strlen($body = $request->getContent())) {
            $env['CONTENT_LENGTH'] = (string)strlen($body);
        }
        if ($contentType = $request->headers->get('Content-Type')) {
            $env['CONTENT_TYPE'] = $contentType;
        }

        foreach ($request->headers->all() as $name => $values) {
            // Content-* already passed above; Proxy would become HTTP_PROXY (httpoxy)
            if (in_array($name, ['content-length', 'content-type', 'proxy'], true)) {
                continue;
            }
            $env['HTTP_' . str_replace('-', '_', strtoupper($name))] = implode(', ', $values);
        }

        return $env;
    }

    private function finish(?string $error = null, ?int $errorStatus = null): void
    {
        if ($this->done) {
            return;
        }
        $this->done = true;

        if ($this->timer) {
            $this->timer->stop();
        }

        $logger = Monocycle::getLogger();
        $context = ['script' => $this->file];
        if (strlen(trim($this->error))) {
            $logger->warning('php-cgi stderr: ' . trim($this->error), $context);
        }

        if ($errorStatus) {
            $logger->error($error, $context);
            $response = $this->errorResponse($errorStatus);
        } else {
            $response = $this->parseOutput();
            if (!$response) {
                $logger->error($error ?? 'php-cgi returned no valid CGI response', $context);
                $response = $this->errorResponse(502);
            }
        }

        call_user_func($this->callback, $response);
    }

    /**
     * @return Response|null null if the output has no CGI header block
     */
    private function parseOutput(): ?Response
    {
        if (!preg_match('/\r?\n\r?\n/', $this->output, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $headerBlock = substr($this->output, 0, $m[0][1]);
        $body = substr($this->output, $m[0][1] + strlen($m[0][0]));

        $status = null;
        $headers = [];
        foreach (preg_split('/\r?\n/', $headerBlock) as $line) {
            if (!preg_match('/^([^:\s]+):\s*(.*)$/', $line, $h)) {
                return null;
            }
            [, $name, $value] = $h;
            $lower = strtolower($name);
            if ($lower === 'status') {
                $status = $value;
            } elseif ($lower !== 'content-length' && $lower !== 'transfer-encoding') {
                // the body is re-framed below
                $headers[] = [$name, $value];
            }
        }

        if ($status !== null && preg_match('/^(\d{3})\s*(.*)$/', $status, $s)) {
            $code = (int)$s[1];
            $message = $s[2] !== '' ? $s[2] : (SymfonyResponse::$statusTexts[$code] ?? '');
        } elseif ($this->hasHeader($headers, 'location')) {
            $code = 302;
            $message = 'Found';
        } else {
            $code = 200;
            $message = 'OK';
        }

        $response = new Response($code, $message);
        foreach ($headers as [$name, $value]) {
            $response->addHeader($name, $value);
        }
        $response->setContent($body);

        return $response;
    }

    private function hasHeader(array $headers, string $lowerName): bool
    {
        foreach ($headers as [$name]) {
            if (strtolower($name) === $lowerName) {
                return true;
            }
        }

        return false;
    }

    private function errorResponse(int $code): Response
    {
        $message = SymfonyResponse::$statusTexts[$code] ?? 'Error';
        $response = new Response($code, $message);
        $response->setContent($message);

        return $response;
    }
}

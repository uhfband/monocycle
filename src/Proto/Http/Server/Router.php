<?php

namespace Uhf\Monocycle\Proto\Http\Server;

use Symfony\Component\HttpFoundation\Request;
use Uhf\Monocycle\Monocycle;

class Router
{
    /** @link https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/MIME_types/Common_types */
    public const MIME_TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'mp4' => 'video/mp4',
        'js' => 'text/javascript',
        'css' => 'text/css',
        'htm' => 'text/html',
        'html' => 'text/html',
        'ico' => 'image/vnd.microsoft.icon',
        'mp3' => 'audio/mpeg',
        'mpeg' => 'video/mpeg',
        'webm' => 'video/webm',
    ];

    private array $routes = [];

    private string $wwwRoot;

    private array $indexFiles = ['index.html', 'index.htm', 'index.php'];

    public function __construct(string $wwwRoot = '/var/www/html')
    {
        $this->wwwRoot = $wwwRoot;
    }

    public const MATCH_EXACT = '=';
    public const MATCH_PREFIX_NO_REGEX = '^~';
    public const MATCH_REGEX = '~';
    public const MATCH_REGEX_CI = '~*';
    public const MATCH_PREFIX = '';

    /**
     * Registers a route using nginx location matching syntax.
     *
     *   "=/path"     exact match, stops searching immediately
     *   "^~/path"    prefix match, if it is the longest prefix regexes are not checked
     *   "~ pattern"  case-sensitive regex
     *   "~* pattern" case-insensitive regex
     *   "/path"      prefix match, can be overridden by a regex match
     *
     * Regex captures are available in $request->attributes->get('matches').
     *
     * @link https://docs.nginx.com/nginx-ingress-controller/configuration/path-matching/
     */
    public function addRoute(string $path, array $methods, $handler)
    {
        if (!preg_match('/^(=|\^~|~\*|~)?\s*(.+)$/s', $path, $m)) {
            Monocycle::getLogger()->error("invalid route path '$path'.");
            return;
        }
        [, $type, $pattern] = $m;

        if ($type === self::MATCH_REGEX || $type === self::MATCH_REGEX_CI) {
            $pattern = "\x01$pattern\x01" . ($type === self::MATCH_REGEX_CI ? 'i' : '');
            if (@preg_match($pattern, '') === false) {
                Monocycle::getLogger()->error("invalid regex in route path '$path'.");
                return;
            }
        }

        $key = "$type $pattern";
        foreach($methods as $method) {
            if (isset($this->routes[$method][$key])) {
                Monocycle::getLogger()->error("route with method $method and path $path already exists.");
                continue;
            }
            $this->routes[$method][$key] = [$type, $pattern, $handler];
        }
    }

    /**
     * Selects a route like nginx selects a location: exact match first, then the longest
     * prefix; if it is "^~" it wins, otherwise regexes are checked in the order they were
     * added and the first match wins; if none matches, the longest prefix is used.
     */
    private function match(string $method, string $path, ?array &$matches): ?callable
    {
        $matches = [];
        $longest = null;
        $longestLength = -1;

        foreach($this->routes[$method] ?? [] as [$type, $pattern, $handler]) {
            if ($type === self::MATCH_EXACT) {
                if ($pattern === $path) {
                    return $handler;
                }
            } elseif ($type === self::MATCH_PREFIX || $type === self::MATCH_PREFIX_NO_REGEX) {
                $length = strlen($pattern);
                if ($length > $longestLength && strncmp($path, $pattern, $length) === 0) {
                    $longest = [$type, $handler];
                    $longestLength = $length;
                }
            }
        }

        if ($longest && $longest[0] === self::MATCH_PREFIX_NO_REGEX) {
            return $longest[1];
        }

        foreach($this->routes[$method] ?? [] as [$type, $pattern, $handler]) {
            if (($type === self::MATCH_REGEX || $type === self::MATCH_REGEX_CI) && preg_match($pattern, $path, $matches)) {
                return $handler;
            }
        }

        return $longest[1] ?? null;
    }

    public function route(Request $request, callable $callback)
    {
        $method = $request->getMethod();
        $path = explode('?', $request->getRequestUri())[0];

        if ($handler = $this->match($method, $path, $matches)) {
            $request->attributes->set('matches', $matches);
            $content = call_user_func($handler, $request, $callback);
            if (is_null($content)) {
                return null;
            }

            $response = new Response(200, 'OK');
            $response->setContent($content);

            return $response;
        }

        $uri = str_replace('..', '', $request->getRequestUri()); //sanity

        $file = $this->wwwRoot . explode('?', $uri)[0];

        if (is_dir($file)) {
            foreach($this->indexFiles as $f) {
                if (file_exists($file . $f)) {
                    $file .= $f;
                    break;
                }
            }
        }

        if (is_dir($file) || !file_exists($file)) {
            //return new Response(404, 'Not Found');
            $response = new Response(404, 'Not Found');
            $response->setContent("Not Found");

            return $response;
        }

        if (substr($file, -4) === '.php') {
            // answered asynchronously through $callback
            (new PhpCgi($file, $this->wwwRoot, $request, $callback))->run();

            return null;
        }

        if (
            !empty($range = $request->headers->get('Range'))
            && preg_match('/bytes=(\d+)-(\d*)$/', $range, $m)
        ) {
            $start = (int)$m[1];
            $end = (int)$m[2];

            if ($start < 0 || $end >= filesize($file) || ($end && $start >= $end)) {
                $response = new Response(416, 'Range Not Satisfiable');
                $response->setContent("Range Not Satisfiable");

                return $response;
            }
            $response = new Response(206, 'Partial Content');
            $response->setContentFile($file);
            $response->setContentRange($start, $end);
        } else {
            $response = new Response(200, 'OK');
            $response->setContentFile($file);
        }

        //$response->setContent(file_get_contents($file));
        $response->setHeader('Accept-Ranges', 'bytes');
        $response->setHeader('Last-Modified', gmdate('D, d M Y H:i:s T', filemtime($file)));
        $response->setHeader('Date', gmdate('D, d M Y H:i:s T'));

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (isset(self::MIME_TYPES[$ext])) {
            $response->setHeader('Content-Type', self::MIME_TYPES[$ext]);
        }

        /*
         * if ($this->keep_alive) {
			$header .= "Connection: Keep-Alive\r\n";
			$header .= "Keep-Alive: timeout={$this->max_idle_time} max={$this->max_total_time}\r\n";
		} else {
			$this->keep_alive = false;
			$header .= "Connection: Close\r\n";
		}
         */

        return $response;
    }
}

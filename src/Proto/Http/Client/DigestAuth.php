<?php

namespace Uhf\Monocycle\Proto\Http\Client;

/**
 * HTTP Digest access authentication (RFC 7616, compatible with RFC 2617).
 */
class DigestAuth
{
    private const ALGORITHMS = [
        'MD5'          => 'md5',
        'MD5-SESS'     => 'md5',
        'SHA-256'      => 'sha256',
        'SHA-256-SESS' => 'sha256',
    ];

    private string $username;
    private string $password;

    public function __construct(string $username, string $password)
    {
        $this->username = $username;
        $this->password = $password;
    }

    /**
     * Extracts the Digest challenge params from a WWW-Authenticate header value.
     *
     * @return array|null lowercased param name => value, or null if there's no Digest challenge
     */
    public static function parseChallenge(string $header): ?array
    {
        if (!preg_match('/(?:^|[\s,])Digest\s+(.*)$/is', $header, $m)) {
            return null;
        }

        preg_match_all('/([\w-]+)\s*=\s*(?:"((?:[^"\\\\]|\\\\.)*)"|([^\s,]*))/', $m[1], $matches, PREG_SET_ORDER);

        $params = [];
        foreach ($matches as $match) {
            $name = strtolower($match[1]);
            if (isset($params[$name])) {
                // A following challenge (e.g. "Basic realm=...") starts here.
                break;
            }
            $params[$name] = isset($match[3]) && $match[3] !== '' ?
                $match[3] :
                preg_replace('/\\\\(.)/s', '$1', $match[2]);
        }

        if (!isset($params['nonce'])) {
            return null;
        }

        return $params;
    }

    /**
     * Builds the Authorization header value answering $challenge.
     *
     * @throws RequestException
     */
    public function authorize(array $challenge, string $method, string $uri, string $body = '', ?string $cnonce = null, int $nc = 1): string
    {
        $algorithm = strtoupper($challenge['algorithm'] ?? 'MD5');
        if (!isset(self::ALGORITHMS[$algorithm])) {
            throw new RequestException("Unsupported digest algorithm: $algorithm");
        }
        $hashAlgo = self::ALGORITHMS[$algorithm];
        $hash = function (string $data) use ($hashAlgo): string {
            return hash($hashAlgo, $data);
        };

        $realm = $challenge['realm'] ?? '';
        $nonce = $challenge['nonce'];
        $qop = self::selectQop($challenge['qop'] ?? null);
        $cnonce = $cnonce ?? bin2hex(random_bytes(16));
        $ncValue = sprintf('%08x', $nc);

        $ha1 = $hash("{$this->username}:$realm:{$this->password}");
        if (substr($algorithm, -5) === '-SESS') {
            $ha1 = $hash("$ha1:$nonce:$cnonce");
        }

        $ha2 = $qop === 'auth-int' ?
            $hash("$method:$uri:" . $hash($body)) :
            $hash("$method:$uri");

        $response = $qop !== null ?
            $hash("$ha1:$nonce:$ncValue:$cnonce:$qop:$ha2") :
            $hash("$ha1:$nonce:$ha2");

        $parts = [
            'username="' . addcslashes($this->username, '"\\') . '"',
            'realm="' . addcslashes($realm, '"\\') . '"',
            'nonce="' . addcslashes($nonce, '"\\') . '"',
            'uri="' . addcslashes($uri, '"\\') . '"',
            "response=\"$response\"",
        ];
        if (isset($challenge['algorithm'])) {
            $parts[] = "algorithm={$challenge['algorithm']}";
        }
        if (isset($challenge['opaque'])) {
            $parts[] = 'opaque="' . addcslashes($challenge['opaque'], '"\\') . '"';
        }
        if ($qop !== null) {
            $parts[] = "qop=$qop";
            $parts[] = "nc=$ncValue";
            $parts[] = "cnonce=\"$cnonce\"";
        }

        return 'Digest ' . implode(', ', $parts);
    }

    /**
     * Prefers "auth"; falls back to "auth-int" only when it's the sole option.
     *
     * @throws RequestException
     */
    private static function selectQop(?string $qop): ?string
    {
        if ($qop === null || trim($qop) === '') {
            return null;
        }

        $offered = array_map('trim', explode(',', strtolower($qop)));
        if (in_array('auth', $offered, true)) {
            return 'auth';
        }
        if (in_array('auth-int', $offered, true)) {
            return 'auth-int';
        }

        throw new RequestException("Unsupported digest qop: $qop");
    }
}

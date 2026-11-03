<?php

use PHPUnit\Framework\TestCase;
use Uhf\Monocycle\Proto\Http\Client\DigestAuth;
use Uhf\Monocycle\Proto\Http\Client\RequestException;

class DigestAuthTest extends TestCase
{
    public function testParseChallenge(): void
    {
        $challenge = DigestAuth::parseChallenge(
            'Digest realm="testrealm@host.com", qop="auth,auth-int", '
            . 'nonce="dcd98b7102dd2f0e8b11d0f600bfb0c093", opaque="5ccc069c403ebaf9f0171e9517f40e41", algorithm=MD5'
        );

        $this->assertSame([
            'realm' => 'testrealm@host.com',
            'qop' => 'auth,auth-int',
            'nonce' => 'dcd98b7102dd2f0e8b11d0f600bfb0c093',
            'opaque' => '5ccc069c403ebaf9f0171e9517f40e41',
            'algorithm' => 'MD5',
        ], $challenge);
    }

    public function testParseChallengeUnescapesQuotedPairs(): void
    {
        $challenge = DigestAuth::parseChallenge('Digest realm="a \"quoted\" realm", nonce="n"');

        $this->assertSame('a "quoted" realm', $challenge['realm']);
    }

    public function testParseChallengeSkipsOtherSchemes(): void
    {
        $challenge = DigestAuth::parseChallenge('Basic realm="basic", Digest realm="digest", nonce="abc"');

        $this->assertSame('digest', $challenge['realm']);
        $this->assertSame('abc', $challenge['nonce']);
    }

    public function testParseChallengeReturnsNullWithoutDigest(): void
    {
        $this->assertNull(DigestAuth::parseChallenge('Basic realm="x"'));
        $this->assertNull(DigestAuth::parseChallenge(''));
    }

    /** RFC 2617 section 3.5 example. */
    public function testAuthorizeMd5MatchesRfc2617(): void
    {
        $auth = new DigestAuth('Mufasa', 'Circle Of Life');
        $header = $auth->authorize([
            'realm' => 'testrealm@host.com',
            'qop' => 'auth,auth-int',
            'nonce' => 'dcd98b7102dd2f0e8b11d0f600bfb0c093',
            'opaque' => '5ccc069c403ebaf9f0171e9517f40e41',
        ], 'GET', '/dir/index.html', '', '0a4f113b');

        $this->assertSame(
            'Digest username="Mufasa", realm="testrealm@host.com", nonce="dcd98b7102dd2f0e8b11d0f600bfb0c093", '
            . 'uri="/dir/index.html", response="6629fae49393a05397450978507c4ef1", '
            . 'opaque="5ccc069c403ebaf9f0171e9517f40e41", qop=auth, nc=00000001, cnonce="0a4f113b"',
            $header
        );
    }

    /** RFC 7616 section 3.9.1 examples. */
    public function testAuthorizeMatchesRfc7616(): void
    {
        $auth = new DigestAuth('Mufasa', 'Circle of Life');
        $challenge = [
            'realm' => 'http-auth@example.org',
            'qop' => 'auth, auth-int',
            'nonce' => '7ypf/xlj9XXwfDPEoM4URrv/xwf94BcCAzFZH4GiTo0v',
            'opaque' => 'FQhe/qaU925kfnzjCev0ciny7QMkPqMAFRtzCUYo5tdS',
        ];
        $cnonce = 'f2/wE4q74E6zIJEtWaHKaf5wv/H5QzzpXusqGemxURZJ';

        $sha256 = $auth->authorize($challenge + ['algorithm' => 'SHA-256'], 'GET', '/dir/index.html', '', $cnonce);
        $this->assertStringContainsString(
            'response="753927fa0e85d155564e2e272a28d1802ca10daf4496794697cf8db5856cb6c1"',
            $sha256
        );
        $this->assertStringContainsString('algorithm=SHA-256', $sha256);

        $md5 = $auth->authorize($challenge + ['algorithm' => 'MD5'], 'GET', '/dir/index.html', '', $cnonce);
        $this->assertStringContainsString('response="8ca523f5e9506fed4657c9700eebdbec"', $md5);
    }

    public function testAuthorizeWithoutQop(): void
    {
        $auth = new DigestAuth('user', 'pass');
        $header = $auth->authorize(['realm' => 'r', 'nonce' => 'n'], 'GET', '/');

        $expected = md5(md5('user:r:pass') . ':n:' . md5('GET:/'));
        $this->assertStringContainsString("response=\"$expected\"", $header);
        $this->assertStringNotContainsString('qop=', $header);
        $this->assertStringNotContainsString('cnonce=', $header);
    }

    public function testAuthorizeAuthIntHashesBody(): void
    {
        $auth = new DigestAuth('user', 'pass');
        $header = $auth->authorize(['realm' => 'r', 'nonce' => 'n', 'qop' => 'auth-int'], 'POST', '/x', 'body', 'c');

        $ha2 = md5('POST:/x:' . md5('body'));
        $expected = md5(md5('user:r:pass') . ":n:00000001:c:auth-int:$ha2");
        $this->assertStringContainsString("response=\"$expected\"", $header);
        $this->assertStringContainsString('qop=auth-int', $header);
    }

    public function testAuthorizeSessAlgorithm(): void
    {
        $auth = new DigestAuth('user', 'pass');
        $header = $auth->authorize(['realm' => 'r', 'nonce' => 'n', 'qop' => 'auth', 'algorithm' => 'MD5-sess'], 'GET', '/', '', 'c');

        $ha1 = md5(md5('user:r:pass') . ':n:c');
        $expected = md5("$ha1:n:00000001:c:auth:" . md5('GET:/'));
        $this->assertStringContainsString("response=\"$expected\"", $header);
    }

    public function testUnsupportedAlgorithmThrows(): void
    {
        $this->expectException(RequestException::class);
        (new DigestAuth('u', 'p'))->authorize(['nonce' => 'n', 'algorithm' => 'SHA-512-256'], 'GET', '/');
    }
}

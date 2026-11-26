<?php

use PHPUnit\Framework\TestCase;
use Uhf\Monocycle\Proto\Mqtt\Packet;
use Uhf\Monocycle\Proto\Mqtt\ProtocolException;
use Uhf\Monocycle\Proto\Mqtt\Topic;

class MqttTopicTest extends TestCase
{
    /**
     * @dataProvider matchProvider
     */
    public function testMatches(string $filter, string $topic, bool $expected): void
    {
        $this->assertSame($expected, Topic::matches($filter, $topic), "$filter vs $topic");
    }

    public function matchProvider(): array
    {
        return [
            ['a/b', 'a/b', true],
            ['a/b', 'a/c', false],
            ['a/b', 'a/b/c', false],
            ['a/+', 'a/b', true],
            ['a/+', 'a/b/c', false],
            ['a/+', 'a', false],
            ['+/+', '/x', true],
            ['+', '/x', false],
            ['a/+/c', 'a/b/c', true],
            ['a/#', 'a', true],
            ['a/#', 'a/b/c', true],
            ['a/#', 'b/c', false],
            ['#', 'a/b', true],
            ['#', '$SYS/x', false],
            ['+/x', '$SYS/x', false],
            ['$SYS/#', '$SYS/x', true],
            ['a//b', 'a//b', true],
            ['a/+/b', 'a//b', true],
        ];
    }

    public function testValidation(): void
    {
        $this->assertTrue(Topic::isValidName('a/b'));
        $this->assertFalse(Topic::isValidName(''));
        $this->assertFalse(Topic::isValidName('a/+'));
        $this->assertFalse(Topic::isValidName('a/#'));
        $this->assertFalse(Topic::isValidName("a\0b"));

        foreach (['#', 'a/#', '+', 'a/+/b', '+/+', '/', 'a//b'] as $filter) {
            $this->assertTrue(Topic::isValidFilter($filter), $filter);
        }
        foreach (['', 'a/#/b', 'a#', 'a/b#', 'a+', 'a/+b', "a\0"] as $filter) {
            $this->assertFalse(Topic::isValidFilter($filter), $filter);
        }
    }

    public function testPacketRoundTrip(): void
    {
        $buffer = Packet::encode(Packet::PUBLISH, Packet::encodeString('t'), str_repeat('x', 200), 0x01) . "\xC0";
        [$byte1, $body] = Packet::extract($buffer);
        $this->assertSame(0x31, $byte1);
        $offset = 0;
        $this->assertSame('t', Packet::readString($body, $offset));
        $this->assertSame(str_repeat('x', 200), substr($body, $offset));
        $this->assertSame("\xC0", $buffer, 'rest left in the buffer');
        $this->assertNull(Packet::extract($buffer), 'incomplete');
    }

    public function testExtractRejectsOversizedAndMalformed(): void
    {
        $buffer = Packet::encode(Packet::PUBLISH, Packet::encodeString('t'), str_repeat('x', 200));
        try {
            Packet::extract($buffer, 100);
            $this->fail('oversized packet accepted');
        } catch (ProtocolException $e) {
            $this->assertStringContainsString('exceeds', $e->getMessage());
        }

        $buffer = "\x30\xFF\xFF\xFF\xFF\x01";
        $this->expectException(ProtocolException::class);
        Packet::extract($buffer);
    }
}

<?php

use Uhf\Monocycle\Monocycle;
use Uhf\Monocycle\Socket\Socket;
use Uhf\Monocycle\Socket\SocketException;
use Uhf\Monocycle\Socket\UdpSocket;

class UdpSocketTest extends BaseTest
{
    protected function tearDown(): void
    {
        $this->resetMonocycle('sockets');
    }

    public function testSendAndReceiveDatagram(): void
    {
        $receiver = new UdpSocket('udp://127.0.0.1:0');
        $sender = new UdpSocket('udp://127.0.0.1:0');

        $sender->sendTo('hello world', $receiver->getLocalAddress());

        $this->assertTrue($this->waitReadable($receiver));

        $data = $receiver->recvFrom($from);

        $this->assertSame('hello world', $data);
        $this->assertSame($sender->getLocalAddress(), $from);
    }

    public function testReadPreservesDatagramBoundaries(): void
    {
        $receiver = new UdpSocket('udp://127.0.0.1:0');
        $sender = new UdpSocket('udp://127.0.0.1:0');

        $sender->sendTo('first', $receiver->getLocalAddress());
        $sender->sendTo('second', $receiver->getLocalAddress());

        $this->assertTrue($this->waitReadable($receiver));
        $this->assertSame('first', $receiver->recvFrom($from1));

        $this->assertTrue($this->waitReadable($receiver));
        $this->assertSame('second', $receiver->recvFrom($from2));

        $this->assertSame($from1, $from2);
    }

    public function testDefaultLocalAddressBindsEphemeralPortOnAllInterfaces(): void
    {
        $socket = new UdpSocket();

        $this->assertNotNull($socket->getLocalAddress());
        $this->assertSame(Socket::STATE_LISTENING, $socket->getState());
    }

    public function testConstructorWithNonUdpSchemeThrows(): void
    {
        $this->expectException(SocketException::class);
        new UdpSocket('tcp://127.0.0.1:0');
    }

    public function testConstructorWithInvalidAddressThrows(): void
    {
        $this->expectException(SocketException::class);
        $this->expectExceptionMessage('Cannot assign requested address');
        $this->expectExceptionCode(0);

        new UdpSocket('udp://192.0.2.1:0');
    }

    public function testConstructorWithMalformedAddressThrows(): void
    {
        $this->expectException(SocketException::class);
        new UdpSocket('not a valid url ::');
    }

    public function testOnDataFromCallbackReceivesDataAndPeer(): void
    {
        $receiver = new UdpSocket('udp://127.0.0.1:0');
        $sender = new UdpSocket('udp://127.0.0.1:0');

        $received = null;
        $peer = null;
        $receiver->onRecvFrom = function (UdpSocket $socket, string $data, string $fromPeer) use (&$received, &$peer) {
            $received = $data;
            $peer = $fromPeer;
        };

        $sender->sendTo('via callback', $receiver->getLocalAddress());
        $this->assertTrue($this->waitReadable($receiver));

        $data = $receiver->recvFrom($from);
        $onRecvFrom = new ReflectionMethod($receiver, 'onRecvFrom');
        $onRecvFrom->setAccessible(true);
        $onRecvFrom->invoke($receiver, $data, $from);

        $this->assertSame('via callback', $received);
        $this->assertSame($sender->getLocalAddress(), $peer);
    }

    public function testConstructingRegistersWithMonocycle(): void
    {
        $before = $this->countMonocycle('sockets');

        $socket = new UdpSocket('udp://127.0.0.1:0');

        $this->assertSame($before + 1, $this->countMonocycle('sockets'));
        $this->assertSame($socket, Monocycle::getSocket($socket->getId()));
    }

    public function testSendToWithClosedStreamThrows(): void
    {
        $this->expectException(SocketException::class);
        $this->expectExceptionMessage('stream_socket_sendto(): supplied resource is not a valid stream resource');
        $this->expectExceptionCode(0);

        $receiver = new UdpSocket('udp://127.0.0.1:0');
        $sender = new UdpSocket('udp://127.0.0.1:0');
        fclose($sender->getResource());
        $sender->sendTo('012345678', $receiver->getLocalAddress());
    }

    public function testRecvFromWithClosedStreamThrows(): void
    {
        $this->expectException(SocketException::class);
        $this->expectExceptionMessage('stream_socket_recvfrom(): supplied resource is not a valid stream resource');
        $this->expectExceptionCode(0);

        $receiver = new UdpSocket('udp://127.0.0.1:0');
        fclose($receiver->getResource());
        $receiver->recvFrom($from);
    }

    public function testOnReadableWithClosedStreamThrows(): void
    {
        $log = $this->captureLog();
        $receiver = new UdpSocket('udp://127.0.0.1:0');
        fclose($receiver->getResource());
        $clientClosed = false;
        $receiver->onClose = function () use (&$clientClosed) {
            $clientClosed = true;
        };
        $onReadable = new ReflectionMethod($receiver, 'onReadable');
        $onReadable->setAccessible(true);
        $onReadable->invoke($receiver);

        $this->assertEquals(Socket::STATE_CLOSED, $receiver->getState());
        $this->assertEquals(true, $clientClosed);
        $this->assertTrue($log->hasWarningThatContains('UdpSocket read error'));
    }

    public function testFullEventLoopDispatchesRecvFrom(): void
    {
        $receiver = new UdpSocket('udp://127.0.0.1:0');
        $sender = new UdpSocket('udp://127.0.0.1:0');

        $received = null;
        $peer = null;
        $receiver->onRecvFrom = function (UdpSocket $socket, string $data, string $fromPeer) use (&$received, &$peer) {
            $received = $data;
            $peer = $fromPeer;
        };

        $sender->sendTo('loop payload', $receiver->getLocalAddress());

        $this->pumpUntil(function () use (&$received) {
            return $received !== null;
        }, 2.0);

        $this->assertSame('loop payload', $received);
        $this->assertSame($sender->getLocalAddress(), $peer);
    }

    private function waitReadable(UdpSocket $socket, float $timeout = 1.0): bool
    {
        $read = [$socket->getResource()];
        $write = $except = [];

        return stream_select($read, $write, $except, (int)$timeout) > 0;
    }


}

<?php

use Uhf\Monocycle\File\File;
use Uhf\Monocycle\Monocycle;
use Uhf\Monocycle\Stream\StreamException;

class FileTest extends BaseTest
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'monocycle_file_');
    }

    protected function tearDown(): void
    {
        $this->resetMonocycle('readStreams', 'readListeners', 'writeStreams', 'writeListeners');

        if (file_exists($this->path)) {
            unlink($this->path);
        }
    }

    public function testReadAsyncDeliversRequestedLengthInMaxReadLengthChunks(): void
    {
        file_put_contents($this->path, '0123456789');
        $chunks = [];
        $file = new File($this->path, 'r');
        $file->setReadChunkLength(3);
        $file->onData = static function (File $f, string $data) use (&$chunks) {
            $chunks[] = $data;
        };

        $file->readAsync(7);
        $this->pumpUntil(static function () use (&$chunks) {
            return implode('', $chunks) === '0123456';
        });

        $this->assertSame(['012', '345', '6'], $chunks);
    }

    public function testReadAsyncStopsListeningOnceRequestedLengthIsRead(): void
    {
        file_put_contents($this->path, '0123456789');
        $log = $this->captureLog();
        $data = '';
        $file = new File($this->path, 'r');
        $file->onData = static function (File $f, string $chunk) use (&$data) {
            $data .= $chunk;
        };

        $file->readAsync(4);
        $this->pumpUntil(static function () use (&$data) {
            return $data === '0123';
        });

        $this->assertFalse($this->hasReadListener($file));
        $this->assertFalse($log->hasWarningRecords());
        // the rest is still there for the next read
        $this->assertSame('456789', $file->readSync());
    }

    public function testReadAsyncFiresOnEndAtEofAndKeepsFileOpen(): void
    {
        file_put_contents($this->path, 'short');
        $log = $this->captureLog();
        $data = '';
        $ended = null;
        $file = new File($this->path, 'r');
        $file->onData = static function (File $f, string $chunk) use (&$data) {
            $data .= $chunk;
        };
        $file->onEnd = static function (File $f) use (&$ended) {
            $ended = $f;
        };

        $file->readAsync(100);
        $this->pumpUntil(static function () use (&$ended) {
            return $ended !== null;
        });

        $this->assertSame($file, $ended);
        $this->assertSame('short', $data);
        $this->assertFalse($this->hasReadListener($file));
        $this->assertTrue(is_resource($file->getResource()));
        $this->assertFalse($log->hasWarningRecords());
        $this->assertTrue($log->hasDebugThatContains('File closed by remote side'));
    }

    public function testReadAsyncCanResumeAfterEofOnceFileGrows(): void
    {
        file_put_contents($this->path, 'abc');
        $data = '';
        $ended = false;
        $file = new File($this->path, 'r');
        $file->onData = static function (File $f, string $chunk) use (&$data) {
            $data .= $chunk;
        };
        $file->onEnd = static function () use (&$ended) {
            $ended = true;
        };

        $file->readAsync(10);
        $this->pumpUntil(static function () use (&$ended) {
            return $ended;
        });
        file_put_contents($this->path, 'def', FILE_APPEND);
        $file->readAsync(3);
        $this->pumpUntil(static function () use (&$data) {
            return $data === 'abcdef';
        });

        $this->assertFalse($this->hasReadListener($file));
    }

    public function testReadAsyncFromOnEndKeepsTheNewRead(): void
    {
        file_put_contents($this->path, 'abc');
        $log = $this->captureLog();
        $data = '';
        $ends = 0;
        $path = $this->path;
        $file = new File($this->path, 'r');
        $file->onData = static function (File $f, string $chunk) use (&$data) {
            $data .= $chunk;
        };
        $file->onEnd = static function (File $f) use (&$ends, $path) {
            if (++$ends === 1) {
                file_put_contents($path, 'def', FILE_APPEND);
                $f->readAsync(3);
            }
        };

        $file->readAsync(10);
        $this->pumpUntil(static function () use (&$data) {
            return $data === 'abcdef';
        });

        $this->assertTrue(is_resource($file->getResource()));
        $this->assertFalse($this->hasReadListener($file));
        $this->assertFalse($log->hasWarningRecords());
    }

    public function testConstructorThrowsWhenFileCannotBeOpened(): void
    {
        $this->expectException(StreamException::class);

        @new File($this->path . '/missing/nested', 'r');
    }

    public function testWriteFlushesBufferedDataAndFiresOnDrain(): void
    {
        $drained = false;
        $file = new File($this->path, 'w');
        $file->onDrain = static function () use (&$drained) {
            $drained = true;
        };

        $file->write('hello ');
        $file->write('world');
        $this->assertTrue($this->hasWriteListener($file));

        $this->pumpUntil(static function () use (&$drained) {
            return $drained;
        });

        $this->assertTrue($file->isWriteBufferEmpty());
        $this->assertFalse($this->hasWriteListener($file));
        fflush($file->getResource());
        $this->assertSame('hello world', file_get_contents($this->path));
    }

    public function testWriteLargePayload(): void
    {
        $data = str_repeat('x', 512 * 1024);
        $drained = false;
        $file = new File($this->path, 'w');
        $file->onDrain = static function () use (&$drained) {
            $drained = true;
        };

        $file->write($data);
        $this->pumpUntil(static function () use (&$drained) {
            return $drained;
        });
        $file->close();

        $this->assertSame(strlen($data), filesize($this->path));
    }

    public function testEmptyWriteIsNoop(): void
    {
        $file = new File($this->path, 'w');

        $file->write('');

        $this->assertTrue($file->isWriteBufferEmpty());
        $this->assertFalse($this->hasWriteListener($file));
    }

    public function testWriteOverBufferLimitThrowsAndKeepsBuffer(): void
    {
        $file = new File($this->path, 'w');
        $file->setWriteBufferSize(10);
        $file->write('12345');

        try {
            $file->write('678901');
            $this->fail('Expected StreamException');
        } catch (StreamException $e) {
            $this->assertSame('Write buffer exceed limit', $e->getMessage());
        }

        $drained = false;
        $file->onDrain = static function () use (&$drained) {
            $drained = true;
        };
        $this->pumpUntil(static function () use (&$drained) {
            return $drained;
        });
        fflush($file->getResource());
        $this->assertSame('12345', file_get_contents($this->path));
    }

    public function testRejectedFirstWriteLeavesNoWriteListener(): void
    {
        $drained = false;
        $file = new File($this->path, 'w');
        $file->setWriteBufferSize(10);
        $file->onDrain = static function () use (&$drained) {
            $drained = true;
        };

        try {
            $file->write(str_repeat('x', 20));
        } catch (StreamException $e) {
        }

        // otherwise the next tick flushes an empty buffer and fires onDrain for nothing
        $this->assertFalse($this->hasWriteListener($file));
        $this->assertFalse($drained);
    }

    public function testSetMaxWriteBufferSizeAllowsLargerWrites(): void
    {
        $data = str_repeat('x', 2 * 1024 * 1024);
        $drained = false;
        $file = new File($this->path, 'w');
        $file->setWriteBufferSize(4 * 1024 * 1024);
        $file->onDrain = static function () use (&$drained) {
            $drained = true;
        };

        $file->write($data);
        $this->pumpUntil(static function () use (&$drained) {
            return $drained;
        });
        $file->close();

        $this->assertSame(strlen($data), filesize($this->path));
    }

    public function testCloseRemovesListenersAndClosesStream(): void
    {
        file_put_contents($this->path, 'data');
        $file = new File($this->path, 'r+');
        $stream = $file->getResource();
        $file->readAsync(4);
        $file->write('more');

        $file->close();

        $this->assertFalse(is_resource($stream));
        $this->assertFalse($this->hasReadListener($file));
        $this->assertFalse($this->hasWriteListener($file));
    }

    public function testSeekAndRewind(): void
    {
        file_put_contents($this->path, 'abcdef');
        $file = new File($this->path, 'r');

        $this->assertSame(0, $file->seek(3));
        $this->assertSame('def', $file->readSync());
        $this->assertSame(0, $file->seek(-2, SEEK_END));
        $this->assertSame('ef', $file->readSync());
        $this->assertSame(0, $file->rewind());
        $this->assertSame('abcdef', $file->readSync());
    }

    public function testStat(): void
    {
        file_put_contents($this->path, 'abcdef');
        $file = new File($this->path, 'r');

        $stat = $file->stat();

        $this->assertSame(6, $stat['size']);
        $this->assertSame(fileinode($this->path), $stat['ino']);
    }

    public function testLockIsExclusiveAndNonBlocking(): void
    {
        // flock() locks belong to the open file description, so two handles in one process conflict
        $first = new File($this->path, 'r');
        $second = new File($this->path, 'r');

        $this->assertTrue($first->lock());
        $this->assertFalse($second->lock());
        $this->assertFalse($second->lock(LOCK_SH));

        $this->assertTrue($first->lock(LOCK_UN));
        $this->assertTrue($second->lock());
    }

    public function testSharedLocksCoexist(): void
    {
        $first = new File($this->path, 'r');
        $second = new File($this->path, 'r');

        $this->assertTrue($first->lock(LOCK_SH));
        $this->assertTrue($second->lock(LOCK_SH));
        $this->assertFalse((new File($this->path, 'r'))->lock(LOCK_EX));
    }

    private function hasReadListener(File $file): bool
    {
        return $this->hasListener('readListeners', $file);
    }

    private function hasWriteListener(File $file): bool
    {
        return $this->hasListener('writeListeners', $file);
    }

    private function hasListener(string $registry, File $file): bool
    {
        $property = new ReflectionProperty(Monocycle::class, $registry);
        $property->setAccessible(true);

        return array_key_exists($file->getId(), $property->getValue());
    }

}

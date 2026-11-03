<?php

use Uhf\Monocycle\Monocycle;
use Uhf\Monocycle\Pipe\Pipe;
use Uhf\Monocycle\Process\Process;
use Uhf\Monocycle\Stream\StreamException;

class ProcessTest extends BaseTest
{
    // prints its whole stdin only once stdin hits EOF, so output proves both delivery and close
    private const CAT_ON_EOF = ['php', '-r', 'echo strlen($in = stream_get_contents(STDIN)), ":", $in;'];

    protected function tearDown(): void
    {
        $this->resetMonocycle('processes', 'timers');
    }

    public function testClosePipeFlushesBufferedWriteFirst(): void
    {
        $output = $this->runWithStdin("hello\n");

        $this->assertSame("6:hello\n", $output);
    }

    public function testClosePipeFlushesWriteLargerThanPipeBuffer(): void
    {
        // well past the usual 64KiB pipe capacity, so the buffer drains over several writes
        $data = str_repeat('x', 512 * 1024);

        $output = $this->runWithStdin($data);

        $this->assertSame(strlen($data) . ':' . $data, $output);
    }

    public function testClosePipeWithEmptyBufferClosesImmediately(): void
    {
        $process = new Process(self::CAT_ON_EOF);
        $process->start();

        $process->closePipe(Process::STDIN);

        $this->assertNull($process->getPipe(Process::STDIN));
    }

    public function testRejectedWriteLeavesNoWriteListener(): void
    {
        $process = new Process(self::CAT_ON_EOF);
        $process->start();
        $stdin = $process->getPipe(Process::STDIN);
        $stdin->setWriteBufferSize(10);

        try {
            $process->write(str_repeat('x', 20), Process::STDIN);
            $this->fail('Expected StreamException');
        } catch (StreamException $e) {
            $this->assertSame('Write buffer exceed limit', $e->getMessage());
        }

        $writeListeners = new ReflectionProperty(Monocycle::class, 'writeListeners');
        $writeListeners->setAccessible(true);
        $this->assertArrayNotHasKey($stdin->getId(), $writeListeners->getValue());
        $this->assertTrue($stdin->isWriteBufferEmpty());
    }

    public function testExitedChildWithUnsentStdinLeavesNoPipeBehind(): void
    {
        // the write to the exited child may fail with EPIPE first, which is logged
        $this->captureLog();
        $exitCode = null;
        // the child never reads stdin; 512KiB can't fit in the pipe, so some stays buffered
        $process = new Process(['php', '-r', 'exit(3);']);
        $process->onExit = static function (Process $p, int $code) use (&$exitCode) {
            $exitCode = $code;
        };
        $process->start();
        $process->write(str_repeat('x', 512 * 1024), Process::STDIN);

        // what the loop's per-second tick does
        $this->pumpUntil(static function () use ($process, &$exitCode) {
            if ($exitCode === null && !$process->isRunning()) {
                $process->close();
            }

            return $exitCode !== null;
        });

        $this->assertSame(3, $exitCode);
        $this->assertNull($process->getPipe(Process::STDIN));
        foreach (['readListeners', 'writeListeners'] as $name) {
            $listeners = new ReflectionProperty(Monocycle::class, $name);
            $listeners->setAccessible(true);
            $this->assertSame([], $listeners->getValue());
        }
    }

    public function testRemoteCloseFiresOnEndWithoutErrorOrWarning(): void
    {
        $log = $this->captureLog();
        $output = '';
        $ended = null;
        $error = null;
        $process = new Process(['php', '-r', 'echo "hi";']);
        $process->onStdout = static function (Process $p, string $chunk) use (&$output) {
            $output .= $chunk;
        };
        $process->start();
        $stdout = $process->getPipe(Process::STDOUT);
        $stdout->onEnd = static function (Pipe $pipe) use (&$ended) {
            $ended = $pipe;
        };
        $stdout->onError = static function (Pipe $pipe, Throwable $e) use (&$error) {
            $error = $e;
        };

        $this->pumpUntil(static function () use (&$ended) {
            return $ended !== null;
        });

        $this->assertSame($stdout, $ended);
        $this->assertSame('hi', $output);
        $this->assertNull($error);
        $this->assertNull($process->getPipe(Process::STDOUT));
        $this->assertFalse($log->hasWarningRecords());
        $this->assertTrue($log->hasDebugThatContains('Pipe closed by remote side'));
    }

    public function testPipeClosedByItsProcessFiresOnCloseOnce(): void
    {
        // EOF is read only after the child has exited, so the default onEnd closes the
        // process, which closes the pipe before Pipe::onReadable() closes it again
        $closes = 0;
        $exited = false;
        $process = new Process(['php', '-r', 'exit(0);']);
        $process->onExit = static function () use (&$exited) {
            $exited = true;
        };
        $process->start();
        $stdout = $process->getPipe(Process::STDOUT);
        $onClose = $stdout->onClose;
        $stdout->onClose = static function (Pipe $pipe) use ($onClose, &$closes) {
            $closes++;
            $onClose($pipe);
        };
        // wait without ticking, so EOF stays unread until the child is gone
        $deadline = microtime(true) + 5;
        while ($process->isRunning() && microtime(true) < $deadline) {
            usleep(10000);
        }
        $this->assertFalse($process->isRunning());

        // no timers: the process must be closed from the pipe's onEnd, not the reaper
        $this->pumpUntil(static function () use (&$exited) {
            return $exited;
        });
        // one more pass in case a second close were still pending
        $this->tick(0.1);

        $this->assertSame(1, $closes);
        $this->assertNull($process->getPipe(Process::STDOUT));
    }

    public function testPipesAreTrackedOnceForStats(): void
    {
        $this->resetMonocycle('objects');
        $process = new Process(self::CAT_ON_EOF);
        $process->start();

        // the process plus its stdin and stdout pipes
        $this->assertSame(3, $this->countMonocycle('objects'));
    }

    public function testWriteErrorFiresOnError(): void
    {
        $log = $this->captureLog();
        $error = null;
        $ended = false;
        // the child never reads stdin; 512KiB can't fit in the pipe, so writing eventually fails
        $process = new Process(['php', '-r', 'exit(0);']);
        $process->start();
        $stdin = $process->getPipe(Process::STDIN);
        $stdin->onError = static function (Pipe $pipe, Throwable $e) use (&$error) {
            $error = $e;
        };
        $stdin->onEnd = static function () use (&$ended) {
            $ended = true;
        };
        $process->write(str_repeat('x', 512 * 1024), Process::STDIN);

        $this->pumpUntil(static function () use (&$error) {
            return $error !== null;
        });

        $this->assertInstanceOf(StreamException::class, $error);
        $this->assertFalse($ended);
        $this->assertNull($process->getPipe(Process::STDIN));
        $this->assertTrue($log->hasWarningThatContains('Pipe write error'));
    }

    private function runWithStdin(string $data): string
    {
        $output = '';
        $done = false;
        $process = new Process(self::CAT_ON_EOF);
        $process->onStdout = static function (Process $p, string $chunk) use (&$output) {
            $output .= $chunk;
        };
        $process->start();
        $process->getPipe(Process::STDOUT)->onEnd = static function () use (&$done) {
            $done = true;
        };

        $process->write($data, Process::STDIN);
        $process->closePipe(Process::STDIN);

        $this->pumpUntil(static function () use (&$done) {
            return $done;
        });

        return $output;
    }

}

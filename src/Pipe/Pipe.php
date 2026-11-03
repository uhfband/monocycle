<?php

namespace Uhf\Monocycle\Pipe;

use Uhf\Monocycle\Monocycle;
use Uhf\Monocycle\Process\ProcessException;
use Uhf\Monocycle\Stream\Stream;
use Uhf\Monocycle\Stream\StreamException;

class Pipe extends Stream
{
    public const MODE_READ = 1;
    public const MODE_WRITE = 2;

    private int $mode;

    private array $loggerContext;

    public function __construct($resource, int $mode, array $loggerContext = [])
    {
        parent::__construct($resource);

        $this->mode = $mode;
        $this->loggerContext = $loggerContext;

        if ($this->mode === self::MODE_WRITE) {
            Monocycle::addReadStream($resource, function () {
                $this->onReadable();
            });
        }
    }

    /**
     * @return string data read, '' if there is nothing to read; '' with isEof() means the other end closed it
     * @throws ProcessException
     */
    public function read(?int $length = null): string
    {
        if (!is_resource($this->getResource())) {
            throw new ProcessException('stream is not resource');
        }

        $data = fread($this->getResource(), $length ?? $this->readChunkLength);

        if ($data === false) {
            throw new ProcessException(json_encode(error_get_last()));
        }

        return $data;
    }

    public function isEof(): bool
    {
        return !is_resource($this->getResource()) || feof($this->getResource());
    }

    public function write(string $data): void
    {
        if (!strlen($data)) {
            return;
        }
        $wasEmpty = $this->isWriteBufferEmpty();
        // buffer first: writeAsync() throws over the limit, and must not leave a listener behind
        $this->writeAsync($data);
        if ($wasEmpty) {
            Monocycle::addWriteStream($this->getResource(), function () {
                $this->onWritable();
            });
        }
    }

    public function close(bool $graceful = true): void
    {
        if ($graceful && !$this->isWriteBufferEmpty()) {
            $this->closing = true;

            return;
        }
        parent::close();
    }

    protected function onReadable()
    {
        try {
            $data = $this->read();
        } catch (ProcessException $e) {
            Monocycle::getLogger()->warning('Pipe read error: ' . $e->getMessage(), $this->loggerContext);
            $this->onError($e);
            $this->close(false);

            return;
        }

        if ($data === '') {
            if ($this->isEof()) {
                // the child closing its end (or exiting) is the normal way a pipe ends
                Monocycle::getLogger()->debug('Pipe closed by remote side', $this->loggerContext);
                $this->onEnd();
                $this->close(false);
            }
            // otherwise a wakeup with nothing to read yet, wait for the next one

            return;
        }

        $this->onData($data);
    }

    protected function onWritable()
    {
        try {
            $this->writeBuffer();
        } catch (StreamException $e) {
            Monocycle::getLogger()->warning('Pipe write error: ' . $e->getMessage(), $this->loggerContext);
            $this->onError($e);
            $this->close(false);
        }
    }
}

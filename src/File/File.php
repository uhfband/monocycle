<?php

namespace Uhf\Monocycle\File;

use Uhf\Monocycle\Monocycle;
use Uhf\Monocycle\Stream\Stream;
use Uhf\Monocycle\Stream\StreamException;

class File extends Stream
{
    private int $readLength = 0;
    private array $loggerContext = [];

    /**
     * @param string $filename
     * @param string $mode
     * @throws StreamException
     */
    public function __construct( string $filename, string $mode)
    {
        $this->loggerContext = ['filename' => $filename, 'mode' => $mode];
        $stream = fopen($filename, $mode);
        if ($stream === false) {
            throw new StreamException(json_encode(error_get_last()));
        }
        parent::__construct($stream);
    }

    public function stat(): array
    {
        return fstat($this->getResource()) ?: [];
    }

    public function tell(): int
    {
        return ftell($this->getResource());
    }

    /** Lock the file (non-blocking attempt); see flock(). */
    public function lock(int $operation = LOCK_EX): bool
    {
        return flock($this->getResource(), $operation | LOCK_NB);
    }

    function seek(int $offset, int $whence = SEEK_SET): int
    {
        return fseek($this->getResource(), $offset, $whence);
    }

    function rewind()
    {
        return $this->seek(0);
    }

    function readAsync(int $length)
    {
        if (!$length) {
            return;
        }
        if (!$this->readLength) {
            Monocycle::addReadStream($this->getResource(), function () {
                $this->onReadable();
            });
        }
        $this->readLength += $length;
    }

    public function write(string $data)
    {
        if ($data === '') {
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

    protected function onReadable()
    {
        try {
            $data = $this->readSync(min($this->readLength, $this->readChunkLength));
        } catch (StreamException $e) {
            Monocycle::getLogger()->warning('File read error: ' . $e->getMessage(), $this->loggerContext);
            $this->onError($e);
            $this->close();

            return;
        }

        if ($data === '') {
            //if ($this->isEof()) {
            if (feof($this->getResource())) {
                // the child closing its end (or exiting) is the normal way a pipe ends
                Monocycle::getLogger()->debug('File closed by remote side', $this->loggerContext);
                $this->readLength = 0;
                Monocycle::removeReadStream($this->getResource());
                $this->onEnd();
            }
            // otherwise a wakeup with nothing to read yet, wait for the next one

            return;
        }

        $this->readLength -= strlen($data);
        if ($this->readLength <= 0) {
            Monocycle::removeReadStream($this->getResource());
        }
        $this->onData($data);
    }

    protected function onWritable()
    {
        try {
            $this->writeBuffer();
        } catch (StreamException $e) {
            Monocycle::getLogger()->warning('File write error: ' . $e->getMessage(), $this->loggerContext);
            $this->onError($e);
            $this->close();
        }
    }
}
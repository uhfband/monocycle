<?php

namespace Uhf\Monocycle\Stream;

use Throwable;
use Uhf\Monocycle\Monocycle;

abstract class Stream
{
    /** @var callable (Stream $stream, string $data) */
    public $onData;
    /** @var callable (Stream $stream) */
    public $onDrain;
    /** @var callable (Stream $stream, Throwable $e) */
    public $onError;
    /** @var callable (Stream $stream) */
    public $onEnd;
    /** @var callable */
    public $onClose;

    protected bool $closing = false;
    protected int $readChunkLength = 128 * 1024;
    protected int $writeBufferSize = 1024 * 1024;

    /** @var ?resource */
    private $resource;
    private string $writeBuffer = '';

    public function __construct($resource = null, ?int $state = null)
    {
        $this->resource = $resource;
        if (!is_null($resource)) {
            $this->setNonBlocking();
        }

        if (!is_null($state)) {
            $this->setState($state);
        }
        Monocycle::addObject($this);
    }

    public function getId(): int
    {
        //TODO get_resource_id()
        return (int)$this->resource;
    }

    public function getResource()
    {
        return $this->resource;
    }

    protected function setResource($resource)
    {
        $this->resource = $resource;
        if (!is_null($resource)) {
            $this->setNonBlocking();
        }
    }

    /**
     * @param int|null $length
     * @return string
     * @throws StreamException
     */
    public function readSync(?int $length = null): string
    {
        $data = fread($this->resource, $length ?? $this->readChunkLength);

        if ($data === false) {
            throw new StreamException(json_encode(error_get_last()));
        }

        return $data;
    }

    /**
     * @param string $data
     * @return int
     * @throws StreamException
     */
    public function writeSync(string $data): int
    {
        if (!is_resource($this->resource)) {
            throw new StreamException('Stream is not open');
        }

        if (feof($this->resource)) {
            throw new StreamException('Stream is closed');
        }

        $res = @fwrite($this->resource, $data);
        if ($res === false) {
            throw new StreamException('Stream write error: ' . json_encode(error_get_last()));
        }

        return $res;
    }

    /**
     * @param string $data
     * @return void
     * @throws StreamException
     */
    public function writeAsync(string $data)
    {
        if (strlen($data) + strlen($this->writeBuffer) >= $this->writeBufferSize) {
            throw new StreamException('Write buffer exceed limit');
        }
        $this->writeBuffer .= $data;
    }

    public function isWriteBufferEmpty(): bool
    {
        return strlen($this->writeBuffer) === 0;
    }

    public function isOpen(): bool
    {
        return is_resource($this->resource);
    }

    public function close()
    {
        Monocycle::removeStream($this->resource);
        if (is_resource($this->resource)) {
            fclose($this->resource);
        }
        $this->onClose();
    }

    /**
     * @return void
     * @throws StreamException
     */
    public function setNonBlocking(): void
    {
        if (!stream_set_blocking($this->resource, false))
        {
            throw new StreamException('Unable to set non-blocking');
        }
    }

    /**
     * @param int $length
     * @return void
     */
    public function setReadChunkLength(int $length): void
    {
        $this->readChunkLength = $length;
    }

    /**
     * Limit of the write buffer (default 1MiB): a write that would make
     * the buffered data reach $length bytes throws StreamException.
     */
    public function setWriteBufferSize(int $length): void
    {
        $this->writeBufferSize = $length;
    }

    /**
     * @return int
     * @throws StreamException
     */
    protected function writeBuffer(): int
    {
        $written = $this->writeSync($this->writeBuffer);
        $this->writeBuffer = substr($this->writeBuffer, $written);
        if ($this->isWriteBufferEmpty()) {
            Monocycle::removeWriteStream($this->resource);
            $this->onDrain();
        }

        return $written;
    }

    protected function onData(string $data)
    {
        if (is_callable($this->onData)) {
            call_user_func($this->onData, $this, $data);
        }
    }

    protected function onDrain()
    {
        if ($this->closing) {
            $this->close();

            return;
        }

        if (is_callable($this->onDrain)) {
            call_user_func($this->onDrain, $this);
        }
    }

    protected function onError(Throwable $e)
    {
        if (is_callable($this->onError)) {
            call_user_func($this->onError, $this, $e);
        }
    }

    protected function onEnd()
    {
        Monocycle::removeReadStream($this->resource);
        if (is_callable($this->onEnd)) {
            call_user_func($this->onEnd, $this);
        }
    }

    protected function onClose()
    {
        if (is_callable($this->onClose)) {
            call_user_func($this->onClose, $this);
        }
    }
}
<?php

namespace Uhf\Monocycle\Process;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Uhf\Monocycle\Monocycle;
use Uhf\Monocycle\Pipe\Pipe;
use Uhf\Monocycle\Process\ProcessException as ProcessException;

class Process
{
    //0 is stdin, 1 is stdout, while 2 is stderr.
    public const STDIN = 0;
    public const STDOUT = 1;
    public const STDERR = 2;

    private const SIGINT = 2;
    private const SIGKILL = 9;
    private const SIGTERM = 15;

    /** @var callable (string $data, int $pipeNum) */
    public $onData;
    /** @var callable */
    public $onStdout;
    /** @var callable */
    public $onStderr;
    /** @var callable (int $code) */
    public $onExit = null;

    private array $cmd;
    private array $descriptorSpec = [];
    private ?array $env;

    /** @var resource */
    private $process;
    private array $streams =[];

    /** @var Pipe[]  */
    private array $pipes = [];
    private int $pid;
    private ?int $exitCode = null;

    private ?LoggerInterface $logger;
    private array $loggerContext;

    /**
     * @param array $cmd
     * @param array|null $descriptorSpec
     * @param LoggerInterface|null $logger
     * @param array $loggerContext
     * @param array|null $env child environment, null to inherit the current one
     */
    public function __construct(
        array $cmd,
        array $descriptorSpec = null,
        LoggerInterface $logger = null,
        array $loggerContext = [],
        ?array $env = null
    ) {
        $this->cmd = $cmd;
        $this->env = $env;

        $this->descriptorSpec = $descriptorSpec ??
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w']
            ];
        $this->logger = $logger;
        $this->loggerContext = $loggerContext;
        Monocycle::addObject($this);
    }

    public function getCmd(): array
    {
        return $this->cmd;
    }

    public function getDescriptorSpec(): array
    {
        return $this->descriptorSpec;
    }

    /**
     * @return int
     * @throws ProcessException
     */
    public function start(): int
    {
        $this->process = proc_open($this->cmd, $this->descriptorSpec, $this->streams, null, $this->env);

        if (!is_resource($this->process)) {
            throw new ProcessException('Create process error');
        }
        $this->pid = proc_get_status($this->process)['pid'];

        foreach ($this->descriptorSpec as $pipeNum => $desc) {
            if (!is_array($desc)) {
                throw new ProcessException('Pipe descriptor error');
            }

            [$type, $mode] = $desc;
            if ($type !== 'pipe') {
                continue;
            }
            $pipe = new Pipe(
                $this->streams[$pipeNum],
                ($mode === 'w') ? Pipe::MODE_WRITE : Pipe::MODE_READ,
                [
                    'pid' => $this->pid,
                    'pipe_num' => $pipeNum,
                ]
            );
            if (($mode === 'w')) {
                switch ($pipeNum) {
                    case self::STDOUT:
                        $pipe->onData = function (Pipe $pipe, string $data) {
                            $this->onStdout($data);
                        };
                        break;
                    case self::STDERR:
                        $pipe->onData = function (Pipe $pipe, string $data) {
                            $this->onStderr($data);
                        };
                        break;
                    default:
                        $pipe->onData = function (Pipe $pipe, string $data) use ($pipeNum) {
                            $this->onData($data, $pipeNum);
                        };
                        break;
                }
            }

            // the pipe closes after its onEnd/onError, so getPipe() still returns it inside them
            $pipe->onClose = function () use ($pipeNum) {
                unset($this->pipes[$pipeNum]);
                if (!$this->isRunning()) {
                    $this->close();
                }
            };

            $this->pipes[$pipeNum] = $pipe;
        }

        Monocycle::addProcess($this);

        if ($this->logger) {
            $this->logger->info(
                sprintf(
                    'Process started, cmd="%s", descriptorspec="%s"',
                    join(' ', $this->cmd),
                    json_encode($this->descriptorSpec)
                ),
                array_merge($this->loggerContext, [
                    'pid' => $this->pid,
                ])
            );
        }

        return $this->pid;
    }

    public function getPid(): int
    {
        return $this->pid;
    }

    /**
     * @param string $data
     * @param int $pipeNum
     * @return void
     * @throws ProcessException
     */
    public function write(string $data, int $pipeNum = 0)
    {
        if (empty($this->pipes[$pipeNum])) {
            throw new ProcessException("Pipe $pipeNum does not exists");
        }
        $pipe = $this->pipes[$pipeNum];
        if (empty($pipe)) {
            throw new ProcessException("Pipe $pipeNum not found");
        }

        $pipe->write($data);
    }

    /**
     * @param int $pipeNum
     * @return Pipe|null null if there is no such pipe or it is already closed
     */
    public function getPipe(int $pipeNum): ?Pipe
    {
        return $this->pipes[$pipeNum] ?? null;
    }

    /**
     * Closes the pipe once data already passed to write() has been flushed
     *
     * @param int $pipeNum
     * @param bool $graceful
     * @return void
     */
    public function closePipe(int $pipeNum, bool $graceful = true): void
    {
        $pipe = $this->getPipe($pipeNum);
        if ($pipe) {
            $pipe->close($graceful);
        }
    }

    /**
     * @return bool
     */
    public function isRunning(): bool
    {
        if (!is_resource($this->process)) {
            return false;
        }

        //$status = explode(' ', file_get_contents("/proc/{$this->pid}/stat"));
        //return $status[2] !== 'Z';

        $status = proc_get_status($this->process);
        //print_r($status);

        if (!$status['running'] && is_null($this->exitCode)) {
            // exitcode is only meaningful when the process exited on its own;
            // a signal-killed process (SIGTERM/SIGKILL/...) reports exitcode -1
            // and the real termination signal in termsig instead.
            $this->exitCode = $status['signaled'] ? (128 + $status['termsig']) : $status['exitcode'];
        }

        return $status['running'];
    }

    public function interrupt(): void
    {
        $this->terminate(self::SIGINT);
    }

    public function kill(): void
    {
        $this->terminate(self::SIGKILL);
    }

    public function terminate(int $signal = self::SIGTERM): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process, $signal);
        }
    }

    /**
     * @return int
     * @throws ProcessException
     */
    public function close(): int
    {
        if (!is_resource($this->process)) {
            throw new ProcessException('Process handler is not resource');
        }

        foreach ($this->pipes as $pipe) {
            $pipe->onClose = null;
            $pipe->close(false);
        }

        $res = proc_close($this->process);
        $exitCode = $this->exitCode ?? $res;
        
        $this->onExit($exitCode);

        return $exitCode;
    }

    protected function onData(string $data, int $pipeNum = 0): void
    {
        if (is_callable($this->onData)) {
            call_user_func($this->onData, $this, $data, $pipeNum);
        }
    }

    protected function onStdout(string $data): void
    {
        if (is_callable($this->onStdout)) {
            call_user_func($this->onStdout, $this, $data);
        }
    }

    protected function onStderr(string $data): void
    {
        if (is_callable($this->onStderr)) {
            call_user_func($this->onStderr, $this, $data);
        }
    }

    protected function onExit(int $code): void
    {
        Monocycle::deleteProcess($this->pid);
        if (is_callable($this->onExit)) {
            call_user_func($this->onExit, $this, $code);
        }
        if ($this->logger) {
            $this->logger->log(
                (!$code) ? LogLevel::INFO : LogLevel::WARNING,
                'Process exited with code ' . $code,
                array_merge($this->loggerContext, [
                    'pid' => $this->pid,
                ])
            );
        }
    }
}

<?php

namespace Uhf\Monocycle;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Uhf\Monocycle\Process\Process;
use Uhf\Monocycle\Socket\Socket;
use Uhf\Monocycle\Timer\Timer;
use Uhf\Monocycle\Timer\TimerException;
use WeakReference;
use gfaugere\Monolog\Formatter\ColoredLineFormatter;

class Monocycle
{
    private const IDLE_TIME = 1;

    private const STATE_RUNNING = 1;
    private const STATE_SHUTDOWN = 2;
    private const STATE_HALTED = 3;

    /** @var resource[] */
    private static array $readStreams = [];
    /** @var callable[] */
    private static array $readListeners = [];
    /** @var resource[] */
    private static array $writeStreams = [];
    /** @var callable[] */
    private static array $writeListeners = [];

    /** @var int ticks freq limit */
    private static int $freq = 0;
    /** @var float max time of execution for user code */
    private static float $maxCallDuration = 0.1;

    private static int $state;

    /** @var Socket[] */
    private static array $sockets = [];

    /** @var Timer[] */
    private static array $timers = [];

    /** @var Process[] */
    private static array $processes = [];

    /** @var WeakReference[]  */
    private static array $objects = [];

    private static int $started = 0;
    
    private static float $statFrom = 0;
    private static int $ticksCount = 0;
    private static float $ticksLimit = 0;

    private static Timer $statTimer;
    private static int $statInterval = 2;

    private static ?LoggerInterface $logger = null;
    /** the handler of the logger getLogger() builds, null until then or after setLogger() */
    private static ?StreamHandler $defaultLogHandler = null;
    private static int $logLevel = Logger::WARNING;

    /** @param resource $stream */
    public static function addReadStream($stream, callable $listener): void
    {
        $key = (int)$stream;
        self::$readStreams[$key] = $stream;
        self::$readListeners[$key] = $listener;
    }

    /** @param resource $stream */
    public static function addWriteStream($stream, callable $listener): void
    {
        $key = (int)$stream;
        self::$writeStreams[$key] = $stream;
        self::$writeListeners[$key] = $listener;
    }

    /** @param resource $stream */
    public static function removeReadStream($stream): void
    {
        $key = (int)$stream;
        unset(self::$readStreams[$key], self::$readListeners[$key]);
    }

    /** @param resource $stream */
    public static function removeWriteStream($stream): void
    {
        $key = (int)$stream;
        unset(self::$writeStreams[$key], self::$writeListeners[$key]);
    }

    /** @param resource $stream */
    public static function removeStream($stream): void
    {
        self::removeReadStream($stream);
        self::removeWriteStream($stream);
    }

    /**
     * @param Socket $socket
     * @return int
     */
    public static function addSocket(Socket $socket): int
    {
        $socketId = $socket->getId();
        self::$sockets[$socketId] = $socket;

        return $socketId;
    }

    /**
     * @param int $id
     * @return Socket|null
     */
    public static function getSocket(int $id): ?Socket
    {
        return self::$sockets[$id] ?? null;
    }

    /**
     * @param Socket $socket
     * @return void
     */
    public static function removeSocket(Socket $socket): void
    {
        self::removeStream($socket->getResource());
        unset(self::$sockets[$socket->getId()]);
    }

    /**
     * @param Process $process
     * @return int
     */
    public static function addProcess(Process $process): int
    {
        $pid = $process->getPid();
        self::$processes[$pid] = $process;

        return $pid;
    }

    /**
     * @param int $pid
     * @return Process|null
     */
    public static function getProcess(int $pid): ?Process
    {
        return self::$processes[$pid] ?? null;
    }

    /**
     * @param int $pid
     * @return void
     */
    public static function deleteProcess(int $pid): void
    {
        unset(self::$processes[$pid]);
    }

    /**
     * @param Timer $timer
     * @return void
     */
    public static function addTimer(Timer $timer): void
    {
        if (in_array($timer, self::$timers, true)) {
            self::getLogger()->warning("Timer already exists");
            return;
        }

        self::$timers[] = $timer;
    }

    /**
     * @param Timer $timer
     * @return void
     */
    public static function deleteTimer(Timer $timer): void
    {
        foreach (self::$timers as $idx => $t) {
            if ($timer === $t) {
                unset(self::$timers[$idx]);
                return;
            }
        }
    }

    public static function addObject(Object $object): void
    {
        self::$objects[] = WeakReference::create($object);
    }

    /**
     * Main loop
     *
     * @throws TimerException
     */
    public static function run(): void
    {
        self::$started = time();
        self::$state = self::STATE_RUNNING;

        self::$statFrom = microtime(true);
        self::$statTimer = new Timer(function() {
            $now = microtime(true);
            $period = $now - self::$statFrom;
            self::$ticksLimit = self::$ticksCount/$period;
            self::$statFrom = $now;
            self::$ticksCount = 0;
            self::$objects = array_filter(self::$objects, function ($reference) {
                return !is_null($reference->get());
            });
        });
        self::$statTimer->setInterval(self::$statInterval)
            ->start();
        
        $nextRun = 0.0;
        $nextTimersRun = 0.0;

        while (in_array(self::$state, [self::STATE_RUNNING, self::STATE_SHUTDOWN], true)) {

            $now = microtime(true);

            if (self::$freq) {
                $wait = $nextRun - $now;
                if ($wait > 0) {
                    usleep((int)($wait * 1000000));
                }
                $nextRun = max($now, $nextRun) + 1 / (float)self::$freq;
            }

            $runTimers = false;

            $now = microtime(true);
            if ($nextTimersRun <= $now) {
                $runTimers = true;
                $nextTimersRun = $now + 1;
            }

            self::tick(self::IDLE_TIME, $runTimers);

            if (self::$state == self::STATE_SHUTDOWN && empty(self::$processes)) {
                self::$state = self::STATE_HALTED;
            }
        }
    }

    public static function stop(bool $graceful = false, bool $sendSignal = false): void
    {
        self::$started = 0;
        self::$statTimer->stop();

        self::$timers = [];

        foreach(self::$sockets as $socket) {
            $socket->close();
        }

        if ($sendSignal) {
            foreach (self::$processes as $process) {
                $process->interrupt();
            }
        }

        self::$state = ($graceful) ? self::STATE_SHUTDOWN : self::STATE_HALTED;
    }

    public static function getLogger(): LoggerInterface
    {
        if (empty(self::$logger)) {
            self::$logger = new Logger('main');
            $stream = new StreamHandler(
                'php://stdout',
                self::$logLevel
            );
            $colorScheme = [
                Logger::DEBUG     => "\033[0;37m",
                Logger::INFO      => "\033[1;37m",
                Logger::NOTICE    => "\033[1;36m",
                Logger::WARNING   => "\033[0;33m",
                Logger::ERROR     => "\033[0;31m",
                Logger::CRITICAL  => "\033[1;31m",
                Logger::ALERT     => "\033[0;41m",
                Logger::EMERGENCY => "\033[1;41m"
            ];
            $formatter = new ColoredLineFormatter(
                "[%datetime%] %color_start%%channel%.%level_name%: %message% %color_end%%context% %extra%\n",
                'Y-m-d H:i:s',
                false,
                true,
                $colorScheme
            );
            $stream->setFormatter($formatter);
            self::$logger->pushHandler($stream);
            self::$defaultLogHandler = $stream;
        }

        return self::$logger;
    }

    public static function setLogger(LoggerInterface $logger): void
    {
        self::$logger = $logger;
        self::$defaultLogHandler = null;
    }

    /**
     * Sets the minimum level (a Monolog\Logger level constant) of the default
     * logger, including one getLogger() has already built. A logger passed to
     * setLogger() keeps its own levels; its handlers can use getLogLevel().
     */
    public static function setLogLevel(int $level): void
    {
        if (!in_array($level, Logger::getLevels(), true)) {
            throw new \InvalidArgumentException(sprintf('Unknown log level %d', $level));
        }

        self::$logLevel = $level;
        if (self::$defaultLogHandler !== null) {
            self::$defaultLogHandler->setLevel($level);
        }
    }

    public static function getLogLevel(): int
    {
        return self::$logLevel;
    }

    /**
     * Sets the maximum duration (in seconds) of a single loop callback
     * before a "too slow" warning is logged. Zero disables profiling.
     */
    public static function setMaxCallDuration(float $seconds): void
    {
        if ($seconds < 0) {
            throw new \InvalidArgumentException('Max call duration must not be negative');
        }

        self::$maxCallDuration = $seconds;
    }

    public static function getMaxCallDuration(): float
    {
        return self::$maxCallDuration;
    }

    /**
     * Sets how many loop iterations run per second at most (throttled with
     * usleep()). Zero disables throttling.
     */
    public static function setFreq(int $freq): void
    {
        if ($freq < 0) {
            throw new \InvalidArgumentException('Loop frequency must not be negative');
        }

        self::$freq = $freq;
    }

    public static function getFreq(): int
    {
        return self::$freq;
    }

    public static function startProfiler(): Profiler
    {
        return new Profiler(self::$maxCallDuration);
    }

    public static function getStat(): array
    {
        $processes = $sockets = [];

        foreach (self::$processes as $pid=>$process) {
            $pipes = [];
            foreach ($process->getDescriptorSpec() as $num => $descriptor) {
                $pipes[] = sprintf('%d:%s(%s)', $num, $descriptor[0], $descriptor[1]);
            }
            $processes[] = [
                'pid' => $pid,
                'cmd' => join(' ', $process->getCmd()),
                'pipes' => join(' ', $pipes),
            ];
        }

        self::$objects = array_filter(self::$objects, function (WeakReference $reference) {
            return !empty($reference->get());
        });

        foreach (self::$sockets as $socket) {
            $sockets[] = [
                'class' => get_class($socket),
                'local_addr' => $socket->getLocalAddress(),
                'remote_addr' => $socket->getRemoteAddress(),
                //'rcvbuf' => $socket->getOption(SOL_SOCKET, SO_RCVBUF),
            ];
        }

        return array_filter([
            'PID' => getmypid(),
            'uptime' => (self::$started) ? (time() - self::$started) : 0,
            'scheduler_freq' => self::$ticksLimit,
            'memory' => memory_get_usage(),
            'objects' => count(self::$objects),
            //'objects' => json_encode($refs),
            'timers' => count(self::$timers),
            'sockets' => $sockets,
            'processes' => $processes,
            //'cpu' => trim(shell_exec("ps -p $pid -o %cpu --no-headers")),
        ]);
    }

    private static function tick(float $timeout, bool $runTimers = false, ?float $now = null): void
    {
        $read = array_values(self::$readStreams);
        $write = array_values(self::$writeStreams);

        if (!empty($read) || !empty($write)) {
            $except = null;
            $cntRead = count($read);
            $cntWrite = count($write);

            $seconds = (int)$timeout;
            $microseconds = (int)(fmod($timeout, 1)*1000000);

            $res = @stream_select($read, $write, $except, $seconds, $microseconds);

            self::getLogger()
                ->debug(
                    sprintf(
                        "select(read=%d write=%d), result=(read=%d write=%d)\n",
                        $cntRead,
                        $cntWrite,
                        count($read),
                        count($write)
                    )
                );

            if ($res) {
                foreach($read as $stream) {
                    $id = (int)$stream;

                    if (is_callable(self::$readListeners[$id] ?? null)) {
                        call_user_func(self::$readListeners[$id]);
                    }
                }

                foreach($write as $stream) {
                    $id = (int)$stream;

                    if (is_callable(self::$writeListeners[$id] ?? null)) {
                        call_user_func(self::$writeListeners[$id]);
                    }
                }
            }
        }

        if ($runTimers) {
            $now = $now ?? microtime(true);
            foreach (self::$timers as $timer) {
                $nextRunTime = $timer->getNextRunTimestamp();
                if ($nextRunTime && $nextRunTime <= $now) {
                    try {
                        $timer->run();
                    } catch (TimerException $e) {
                        self::getLogger()->error('Timer callback error: ' . $e->getMessage());
                        self::deleteTimer($timer);
                    }
                }
            }

            foreach (self::$processes as $process) {
                if (!$process->isRunning()) {
                    $process->close();
                }
            }
        }

        self::$ticksCount++;
    }
}

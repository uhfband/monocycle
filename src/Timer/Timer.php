<?php

namespace Uhf\Monocycle\Timer;

use Cron\CronExpression;
use DateTimeInterface;
use Exception;
use Uhf\Monocycle\Monocycle;

class Timer
{
    private bool $running = false;
    private int $nextRunTimestamp = 0;
    private int $interval;
    private int $delay = 0;
    private CronExpression $cronExpression;

    /** @var callable */
    private $callback;

    /**
     * @param callable $callback
     */
    public function __construct(callable $callback)
    {
        $this->callback = $callback;
        Monocycle::addObject($this);
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function setDelay(int $delay): self
    {
        $this->delay = $delay;
        $this->nextRunTimestamp = time() + $delay;

        return $this;
    }

    public function setInterval(int $interval): self
    {
        $this->interval = $interval;
        $this->nextRunTimestamp = time() + $interval;

        return $this;
    }

    public function setDateTime(DateTimeInterface $dateTime): self
    {
        $this->nextRunTimestamp = $dateTime->getTimestamp();

        return $this;
    }

    public function setTimestamp(int $timestamp): self
    {
        $this->nextRunTimestamp = $timestamp;

        return $this;
    }

    public function setCronExpression(string $cronExpression): self
    {
        $this->cronExpression = new CronExpression($cronExpression);
        $this->nextRunTimestamp = $this->cronExpression->getNextRunDate()->getTimestamp();

        return $this;
    }

    public function start(): self
    {
        if ($this->running) {
            throw new TimerException('Timer is already running.');
        }
        if (empty($this->nextRunTimestamp)) {
            throw new TimerException('Timer is not set');
        }
        $this->running = true;

        $this->reset();

        Monocycle::addTimer($this);

        return $this;
    }

    public function reset(): self
    {
        if (!empty($this->delay)) {
            $this->nextRunTimestamp = time() + $this->delay;
        } elseif(!empty($this->interval)) {
            $this->nextRunTimestamp = time() + $this->interval;
        }

        return $this;
    }

    public function stop(): void
    {
        $this->running = false;
        Monocycle::deleteTimer($this);
    }

    public function getNextRunTimestamp(): int
    {
        return $this->nextRunTimestamp;
    }

    /**
     * @return void
     * @throws Exception
     */
    public function run(): void
    {
        $profiler = Monocycle::startProfiler();
        try {
            call_user_func($this->callback, $this);
        } finally {
            $profiler->stop('timer', ['callback' => $this->callback]);
            $this->scheduleNextRun();
        }
    }

    private function scheduleNextRun(): void
    {
        if (!empty($this->cronExpression)) {
            $this->nextRunTimestamp = $this->cronExpression->getNextRunDate()->getTimestamp();
        } elseif (!empty($this->interval)) {
            $this->nextRunTimestamp += $this->interval;
        } else {
            $this->stop();
        }
    }
}

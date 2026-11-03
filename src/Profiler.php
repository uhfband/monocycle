<?php

namespace Uhf\Monocycle;

class Profiler
{
    private float $start;
    private float $maxCallDuration;

    public function __construct(float $maxCallDuration)
    {
        $this->start = microtime(true);
        $this->maxCallDuration = $maxCallDuration;
    }

    public function stop(string $what, array $context = []): void
    {
        if (empty($this->maxCallDuration)) {
            return;
        }

        $duration = microtime(true) - $this->start;
        if ($duration <= $this->maxCallDuration) {
            return;
        }

        foreach ($context as $key => $value) {
            if (is_callable($value)) {
                $context[$key] = $this->describeCallable($value);
            }
            //TODO describe Socket, Process
        }

        Monocycle::getLogger()->warning(
            sprintf('Slow call: %s took %.3fs (limit %.3fs)', $what, $duration, $this->maxCallDuration),
            $context
        );
    }

    private function describeCallable($callback): string
    {
        if ($callback instanceof \Closure) {
            $reflection = new \ReflectionFunction($callback);
            return sprintf('Closure@%s:%d', $reflection->getFileName(), $reflection->getStartLine());
        }

        if (is_array($callback) && count($callback) === 2) {
            $class = is_object($callback[0]) ? get_class($callback[0]) : (string)$callback[0];
            return $class . '::' . $callback[1];
        }

        if (is_object($callback)) {
            return get_class($callback) . '::__invoke';
        }

        return is_string($callback) ? $callback : gettype($callback);
    }
}

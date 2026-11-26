<?php

namespace Uhf\Monocycle\Proto\Mqtt;

/**
 * MQTT 3.1.1 topic names and filters
 */
final class Topic
{
    /**
     * A topic name a message is published to: non-empty, no wildcards, no NUL
     */
    public static function isValidName(string $topic): bool
    {
        return $topic !== '' && strpbrk($topic, "+#\0") === false;
    }

    /**
     * A subscription filter: "#" only as the whole last level, "+" only as a whole level
     */
    public static function isValidFilter(string $filter): bool
    {
        if ($filter === '' || strpos($filter, "\0") !== false) {
            return false;
        }
        $levels = explode('/', $filter);
        $last = count($levels) - 1;
        foreach ($levels as $i => $level) {
            if (strpos($level, '#') !== false && ($level !== '#' || $i !== $last)) {
                return false;
            }
            if (strpos($level, '+') !== false && $level !== '+') {
                return false;
            }
        }

        return true;
    }

    public static function matches(string $filter, string $topic): bool
    {
        // topics starting with "$" (e.g. $SYS) are not matched by a leading wildcard
        if ($topic !== '' && $topic[0] === '$' && ($filter[0] ?? '') !== '$') {
            return false;
        }

        $filterLevels = explode('/', $filter);
        $topicLevels = explode('/', $topic);
        $topicCount = count($topicLevels);

        foreach ($filterLevels as $i => $level) {
            if ($level === '#') {
                // "a/#" also matches "a" itself
                return true;
            }
            if ($i >= $topicCount) {
                return false;
            }
            if ($level !== '+' && $level !== $topicLevels[$i]) {
                return false;
            }
        }

        return count($filterLevels) === $topicCount;
    }
}

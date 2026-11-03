<?php

/*
 * Overrides time() inside the Uhf\Monocycle\Timer namespace (unqualified
 * calls fall back to the global function only when no namespaced one
 * exists), so timer scheduling can be driven by tests. setMockTime(null)
 * restores the real clock. Shared by the tests that need it: a function can
 * only be declared once per process, so load it with require_once.
 */

namespace Uhf\Monocycle\Timer;

function setMockTime(?int $timestamp): void
{
    $GLOBALS['__monocycle_timer_mock_time'] = $timestamp;
}

function time(): int
{
    $timestamp = $GLOBALS['__monocycle_timer_mock_time'] ?? null;

    if ($timestamp !== null) {
        return $timestamp;
    }

    return \time();
}

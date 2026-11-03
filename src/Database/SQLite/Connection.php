<?php

namespace Uhf\Monocycle\Database\SQLite;

use Uhf\Monocycle\Database\Database as BaseDatabase;
use Uhf\Monocycle\Process\ProcessException;

/**
 * SQLite database served by a bin/db_worker_sqlite process
 */
class Connection extends BaseDatabase
{
    /**
     * Spawns a SQLite worker process and returns a client for it
     *
     * @param string $dbPath database file, created (and initialized from $initScript) if missing
     * @param string $initScript path to an SQL script run on a newly created database
     * @param int $timeout default query timeout, seconds
     * @throws ProcessException
     */
    public static function open(string $dbPath, string $initScript = '', int $timeout = 10): self
    {
        return self::spawn('db_worker_sqlite', [$dbPath, $initScript], $timeout);
    }
}

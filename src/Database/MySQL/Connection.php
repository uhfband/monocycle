<?php

namespace Uhf\Monocycle\Database\MySQL;

use Uhf\Monocycle\Database\Database as BaseDatabase;
use Uhf\Monocycle\Process\ProcessException;

/**
 * MySQL database served by a bin/db_worker_mysql process
 */
class Connection extends BaseDatabase
{
    private const CONNECTION_ENV = [
        'host' => 'MYSQL_HOST',
        'port' => 'MYSQL_PORT',
        'socket' => 'MYSQL_SOCKET',
        'user' => 'MYSQL_USER',
        'password' => 'MYSQL_PWD',
    ];

    /**
     * Spawns a MySQL worker process and returns a client for it
     *
     * @param string $dbName database name, created (and initialized from $initScript) if missing
     * @param array $connection server connection settings: host, port, socket, user, password;
     *                          handed to the worker via its environment, not its command line
     * @param string $initScript path to an SQL script run on a newly created database
     * @param int $timeout default query timeout, seconds
     * @throws ProcessException
     */
    public static function open(
        string $dbName,
        array $connection = [],
        string $initScript = '',
        int $timeout = 10
    ): self
    {
        $env = getenv();
        foreach (self::CONNECTION_ENV as $key => $var) {
            if (isset($connection[$key])) {
                $env[$var] = (string)$connection[$key];
            }
        }

        return self::spawn('db_worker_mysql', [$dbName, $initScript], $timeout, $env);
    }
}

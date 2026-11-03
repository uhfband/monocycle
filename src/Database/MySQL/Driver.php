<?php

namespace Uhf\Monocycle\Database\MySQL;

use mysqli;
use mysqli_result;
use mysqli_sql_exception;
use Throwable;
use Uhf\Monocycle\Database\DatabaseException;
use Uhf\Monocycle\Database\Driver as IDriver;
use Uhf\Monocycle\Database\Protocol;
use Uhf\Monocycle\Database\ProtocolException;
use Uhf\Monocycle\Monocycle;

/**
 * Executes requests on a MySQL server through mysqli.
 *
 * Connection settings are read from the environment (MYSQL_HOST, MYSQL_PORT,
 * MYSQL_SOCKET, MYSQL_USER, MYSQL_PWD). A lost server connection fails the
 * request that hit it and is re-established on the next one. Session state
 * (open transactions, user variables, temporary tables) does not survive a
 * reconnect. Call ping() periodically to keep an idle connection below the
 * server's wait_timeout.
 */
class Driver implements IDriver
{
    /**
     * Client error codes meaning the connection is unusable and must be reopened
     */
    private const CONNECTION_LOST_ERRORS = [
        2006, // CR_SERVER_GONE_ERROR
        2013, // CR_SERVER_LOST
        4031, // ER_CLIENT_INTERACTION_TIMEOUT (MySQL 8.0.24+)
    ];

    private string $dbName;
    private ?mysqli $db = null;

    /**
     * Creates the database (running the init script) when it doesn't exist
     * yet, then connects to it
     *
     * @throws DatabaseException
     */
    public function __construct(string $dbName, ?string $initSqlPath = null)
    {
        // errors are reported as mysqli_sql_exception instead of warnings/false
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        $this->dbName = $dbName;

        try {
            self::initDatabase($dbName, $initSqlPath);
            $this->get();
        } catch (mysqli_sql_exception $e) {
            throw new DatabaseException($e->getMessage(), $e->getCode(), $e);
        }
    }

    public function executeRequest(array $request): string
    {
        $queryId = $request[Protocol::FIELD_QUERY_ID] ?? null;
        $sql = $request[Protocol::FIELD_SQL] ?? null;

        if (!is_string($sql) || trim($sql) === '') {
            return Protocol::errorResponse($queryId, "Field '" . Protocol::FIELD_SQL . "' must be a non-empty string");
        }

        try {
            $db = $this->get();
            // real_query() runs a single statement only, like SQLite3::prepare()
            $db->real_query($sql);

            $rows = [];
            $affectedRows = null;
            $lastInsertRowId = null;

            $res = $db->store_result();
            if ($res instanceof mysqli_result) {
                $rows = $res->fetch_all(MYSQLI_ASSOC);
                $res->free();
            } else {
                $affectedRows = max(0, $db->affected_rows);
                if ($db->insert_id) {
                    $lastInsertRowId = (int)$db->insert_id;
                }
            }

            // stored procedures (CALL) return an extra status result, and any
            // result left unread would break the next query with "out of sync"
            while ($db->more_results() && $db->next_result()) {
                $extra = $db->store_result();
                if ($extra instanceof mysqli_result) {
                    $extra->free();
                }
            }
        } catch (mysqli_sql_exception $e) {
            $this->handleError($e);
            return Protocol::errorResponse($queryId, $e->getMessage());
        }

        try {
            return Protocol::response($queryId, $rows, $affectedRows, $lastInsertRowId);
        } catch (ProtocolException $e) {
            return Protocol::errorResponse($queryId, $e->getMessage());
        }
    }

    /**
     * Pings an open connection, dropping it if it's gone
     */
    public function ping(): void
    {
        if ($this->db === null) {
            return;
        }

        try {
            if (!$this->db->ping()) {
                Monocycle::getLogger()->warning('MySQL connection lost: ' . $this->db->error);
                $this->close();
            }
        } catch (mysqli_sql_exception $e) {
            if (!$this->handleError($e)) {
                Monocycle::getLogger()->warning('MySQL ping failed: ' . $e->getMessage());
            }
        }
    }

    public function close(): void
    {
        if ($this->db !== null) {
            try {
                $this->db->close();
            } catch (Throwable $e) {
                // the connection is already broken
            }
            $this->db = null;
        }
    }

    /**
     * @throws mysqli_sql_exception
     */
    private function get(): mysqli
    {
        if ($this->db === null) {
            $this->db = self::connect($this->dbName);
            Monocycle::getLogger()->info('Connected to MySQL server ' . $this->db->host_info);
        }

        return $this->db;
    }

    /**
     * Drops the connection if the exception says it's gone
     *
     * @return bool whether the connection was lost
     */
    private function handleError(mysqli_sql_exception $e): bool
    {
        if (!in_array($e->getCode(), self::CONNECTION_LOST_ERRORS, true)) {
            return false;
        }

        Monocycle::getLogger()->warning('MySQL connection lost: ' . $e->getMessage());
        $this->close();

        return true;
    }

    /**
     * Connects to the server, without selecting a database when $dbName is null
     *
     * @throws mysqli_sql_exception
     */
    private static function connect(?string $dbName): mysqli
    {
        $db = mysqli_init();
        // ints and floats come back as PHP ints/floats instead of strings,
        // matching what the SQLite driver returns (requires mysqlnd)
        if (defined('MYSQLI_OPT_INT_AND_FLOAT_NATIVE')) {
            $db->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, 1);
        }
        $db->real_connect(
            getenv('MYSQL_HOST') ?: 'localhost',
            getenv('MYSQL_USER') ?: null,
            getenv('MYSQL_PWD') ?: null,
            $dbName,
            (int)(getenv('MYSQL_PORT') ?: 3306),
            getenv('MYSQL_SOCKET') ?: null
        );
        $db->set_charset('utf8mb4');

        return $db;
    }

    /**
     * Creates the database and runs the init script when it doesn't exist yet
     *
     * @throws DatabaseException
     * @throws mysqli_sql_exception
     */
    private static function initDatabase(string $dbName, ?string $initSqlPath): void
    {
        $initSql = null;
        if ($initSqlPath !== null) {
            $initSql = @file_get_contents($initSqlPath);
            if ($initSql === false) {
                throw new DatabaseException("Unable to read init SQL script $initSqlPath");
            }
        }

        $db = self::connect(null);
        try {
            $stmt = $db->prepare('SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
            $stmt->bind_param('s', $dbName);
            $stmt->execute();
            $exists = $stmt->get_result()->num_rows > 0;
            $stmt->close();

            if ($exists) {
                return;
            }

            // no IF NOT EXISTS: if another worker created it in the meantime this
            // fails instead of running the init script twice
            $db->query('CREATE DATABASE ' . self::quoteIdentifier($dbName) . ' CHARACTER SET utf8mb4');
            Monocycle::getLogger()->info("Database $dbName created");

            if ($initSql !== null && trim($initSql) !== '') {
                try {
                    $db->select_db($dbName);
                    self::executeScript($db, $initSql);
                } catch (mysqli_sql_exception $e) {
                    // don't leave a half-initialized database behind, it would be
                    // treated as an existing one on the next start
                    try {
                        // the failed multi_query may have left results pending,
                        // so the drop goes over a fresh connection
                        $cleanup = self::connect(null);
                        $cleanup->query('DROP DATABASE ' . self::quoteIdentifier($dbName));
                        $cleanup->close();
                    } catch (mysqli_sql_exception $dropError) {
                        Monocycle::getLogger()->error(
                            "Unable to drop database $dbName: " . $dropError->getMessage()
                        );
                    }
                    throw new DatabaseException("Init SQL script failed: " . $e->getMessage(), 0, $e);
                }
                Monocycle::getLogger()->info("Database $dbName initialized from $initSqlPath");
            }
        } finally {
            $db->close();
        }
    }

    /**
     * Runs every statement of a multi-statement script
     *
     * @throws mysqli_sql_exception
     */
    private static function executeScript(mysqli $db, string $sql): void
    {
        // with strict reporting an error in any statement throws, either from
        // multi_query() for the first one or from next_result() for the rest
        $db->multi_query($sql);
        do {
            $res = $db->store_result();
            if ($res instanceof mysqli_result) {
                $res->free();
            }
        } while ($db->more_results() && $db->next_result());
    }

    private static function quoteIdentifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}

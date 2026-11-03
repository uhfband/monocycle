<?php

namespace Uhf\Monocycle\Database\SQLite;

use Exception;
use SQLite3;
use Uhf\Monocycle\Database\DatabaseException;
use Uhf\Monocycle\Database\Driver as IDriver;
use Uhf\Monocycle\Database\Protocol;
use Uhf\Monocycle\Database\ProtocolException;
use Uhf\Monocycle\Monocycle;

class Driver implements IDriver
{
    private SQLite3 $db;

    public function __construct(string $dbPath, ?string $initSqlPath = null)
    {
        $initSql = null;
        $exists = file_exists($dbPath);

        if (!$exists && $initSqlPath !== null) {
            $initSql = @file_get_contents($initSqlPath);
            if ($initSql === false) {
                throw new DatabaseException("Unable to read init SQL script $initSqlPath");
            }
        }

        $db = new SQLite3($dbPath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        $db->enableExceptions(true);
        $db->busyTimeout(5000);

        if (!$exists) {
            Monocycle::getLogger()->info("Database $dbPath created");
            if ($initSql !== null) {
                try {
                    $db->exec($initSql);
                } catch (Exception $e) {
                    // don't leave a half-initialized database behind, it would be
                    // treated as an existing one on the next start
                    $db->close();
                    @unlink($dbPath);
                    throw new DatabaseException("Init SQL script failed: " . $e->getMessage(), 0, $e);
                }
                Monocycle::getLogger()->info("Database $dbPath initialized from $initSqlPath");
            }
        }

        $this->db = $db;
    }

    public function executeRequest(array $request): string
    {
        $queryId = $request[Protocol::FIELD_QUERY_ID] ?? null;
        $sql = $request[Protocol::FIELD_SQL] ?? null;

        if (!is_string($sql) || trim($sql) === '') {
            return Protocol::errorResponse($queryId, "Field '" . Protocol::FIELD_SQL . "' must be a non-empty string");
        }

        try {
            $totalChangesBefore = $this->db->querySingle('SELECT total_changes()');
            $lastInsertRowIdBefore = $this->db->lastInsertRowID();

            $stmt = $this->db->prepare($sql);
            $res = $stmt->execute();

            $rows = [];
            $affectedRows = null;
            $lastInsertRowId = null;

            // fetchArray() on a statement without a result set re-executes it,
            // so rows are fetched only when there are result columns
            if ($res->numColumns() > 0) {
                while (($row = $res->fetchArray(SQLITE3_ASSOC)) !== false) {
                    $rows[] = $row;
                }
            } else {
                // changes() keeps the count of the last INSERT/UPDATE/DELETE, so
                // for statements that changed nothing (e.g. DDL) it would be stale
                $changed = $this->db->querySingle('SELECT total_changes()') !== $totalChangesBefore;
                $affectedRows = $changed ? $this->db->changes() : 0;

                if ($this->db->lastInsertRowID() !== $lastInsertRowIdBefore) {
                    $lastInsertRowId = $this->db->lastInsertRowID();
                }
            }
            $res->finalize();
            $stmt->close();
        } catch (Exception $e) {
            return Protocol::errorResponse($queryId, $e->getMessage());
        }

        try {
            return Protocol::response($queryId, $rows, $affectedRows, $lastInsertRowId);
        } catch (ProtocolException $e) {
            return Protocol::errorResponse($queryId, $e->getMessage());
        }
    }

    public function close(): void
    {
        $this->db->close();
    }
}

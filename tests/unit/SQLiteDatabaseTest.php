<?php

use Uhf\Monocycle\Database\Database as BaseDatabase;
use Uhf\Monocycle\Database\SQLite\Connection;

/**
 * Runs the real bin/db_worker_sqlite against a database in a temp directory.
 * One worker serves the whole class (starting one takes about a second);
 * tearDown() drops the tables a test created, so tests don't see each other's data.
 */
class SQLiteDatabaseTest extends BaseTest
{
    private static string $dir;
    private static string $dbPath;
    private static ?Connection $db = null;

    /** whether the database file existed before the worker was started */
    private static bool $existedBeforeOpen;

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('sqlite3')) {
            return; // setUp() skips every test
        }

        self::$dir = sys_get_temp_dir() . '/monocycle_sqlite_' . getmypid() . '_' . uniqid();
        mkdir(self::$dir);
        self::$dbPath = self::$dir . '/test.db';

        $initScript = self::$dir . '/init.sql';
        file_put_contents($initScript, "
            CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT);
            INSERT INTO settings VALUES ('version', '1');
        ");

        self::$existedBeforeOpen = file_exists(self::$dbPath);
        self::$db = Connection::open(self::$dbPath, $initScript);
        self::pumpUntil(function () {
            return self::$db->isConnected();
        }, 5.0, true);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            // terminate the worker and let it exit cleanly, so it removes its socket file
            self::$db->close();
            self::$db = null;
            self::pumpUntil(function () {
                return self::countMonocycle('processes') === 0;
            }, 5.0, true);
        }

        self::resetMonocycle('processes', 'sockets', 'timers', 'readStreams', 'readListeners', 'writeStreams', 'writeListeners');

        if (isset(self::$dir)) {
            foreach (glob(self::$dir . '/*') as $file) {
                unlink($file);
            }
            rmdir(self::$dir);
        }
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            $this->markTestSkipped('sqlite3 extension is not installed');
        }
    }

    protected function tearDown(): void
    {
        if (self::$db !== null && self::$db->isConnected()) {
            $this->rows('DROP TABLE IF EXISTS users');
        }
    }

    /**
     * Runs one query through the loop and returns [$rows, $error, $returnCode]
     */
    private function query(string $sql): array
    {
        $result = null;
        self::$db->query($sql, function (?array $rows, ?string $error, int $returnCode) use (&$result) {
            $result = [$rows, $error, $returnCode];
        });

        $this->pumpUntil(function () use (&$result) {
            return $result !== null;
        }, 5.0, true);

        return $result;
    }

    /**
     * Runs a query that must succeed and returns its rows
     */
    private function rows(string $sql): array
    {
        [$rows, $error, $returnCode] = $this->query($sql);
        $this->assertNull($error, "Query failed: $sql");
        $this->assertSame(BaseDatabase::RETURN_CODE_SUCCESS, $returnCode);

        return $rows;
    }

    private function createUsers(): void
    {
        $this->rows('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, age INTEGER)');
        $this->rows("INSERT INTO users (name, age) VALUES ('alice', 30), ('bob', 25), ('carol', 41)");
    }

    public function testNewDatabaseIsCreatedAndInitialized(): void
    {
        $this->assertFalse(self::$existedBeforeOpen);
        $this->assertFileExists(self::$dbPath);

        // the init script ran on the new database
        $this->assertSame(
            [['name' => 'version', 'value' => '1']],
            $this->rows('SELECT name, value FROM settings')
        );
    }

    public function testCreateTable(): void
    {
        $this->assertSame([], $this->rows('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, age INTEGER)'));

        $this->assertSame(
            [['name' => 'users']],
            $this->rows("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'users'")
        );
    }

    public function testInsertRows(): void
    {
        $this->rows('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, age INTEGER)');

        $this->assertSame([], $this->rows("INSERT INTO users (name, age) VALUES ('alice', 30)"));
        $this->assertSame([], $this->rows("INSERT INTO users (name, age) VALUES ('bob', 25), ('carol', 41)"));

        $this->assertSame([['n' => 3]], $this->rows('SELECT COUNT(*) AS n FROM users'));
    }

    public function testSelectOneRow(): void
    {
        $this->createUsers();

        $this->assertSame(
            [['id' => 2, 'name' => 'bob', 'age' => 25]],
            $this->rows('SELECT id, name, age FROM users WHERE id = 2')
        );
    }

    public function testSelectManyRows(): void
    {
        $this->createUsers();

        $this->assertSame(
            [
                ['id' => 1, 'name' => 'alice', 'age' => 30],
                ['id' => 2, 'name' => 'bob', 'age' => 25],
                ['id' => 3, 'name' => 'carol', 'age' => 41],
            ],
            $this->rows('SELECT id, name, age FROM users ORDER BY id')
        );
        $this->assertSame([], $this->rows('SELECT id FROM users WHERE age > 100'));
    }

    public function testDeleteRow(): void
    {
        $this->createUsers();

        $this->assertSame([], $this->rows('DELETE FROM users WHERE id = 2'));

        $this->assertSame(
            [['name' => 'alice'], ['name' => 'carol']],
            $this->rows('SELECT name FROM users ORDER BY id')
        );
    }

    public function testQueryError(): void
    {
        [$rows, $error, $returnCode] = $this->query('SELEKT 1');
        $this->assertNull($rows);
        $this->assertStringContainsString('syntax error', $error);
        $this->assertSame(BaseDatabase::RETURN_CODE_ERROR, $returnCode);

        [$rows, $error, $returnCode] = $this->query('SELECT * FROM missing_table');
        $this->assertNull($rows);
        $this->assertStringContainsString('no such table', $error);
        $this->assertSame(BaseDatabase::RETURN_CODE_ERROR, $returnCode);

        // the worker keeps serving after a failed query
        $this->assertSame([['x' => 1]], $this->rows('SELECT 1 AS x'));
    }
}

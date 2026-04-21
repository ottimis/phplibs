<?php

namespace ottimis\phplibs;

use mysqli;
use mysqli_result;
use ottimis\phplibs\Interfaces\DatabaseInterface;
use RuntimeException;

class dataBase implements DatabaseInterface
{
    private static array $instances = [];
    protected string $host = '';
    protected string $user = '';
    protected string $password = '';
    protected string $database = '';
    protected int $port = 3306;
    protected bool $persistent = false;
    protected mysqli|null $conn = NULL;
    protected mysqli_result|bool $result;


    public function __construct($dbname = "default")
    {
        if ($dbname === "") {
            $dbname = "default";
        }
        $this->host = ($dbname === "default" ? getenv('DB_HOST') : getenv('DB_HOST_' . $dbname));
        $this->user = ($dbname === "default" ? getenv('DB_USER') : getenv('DB_USER_' . $dbname));
        $this->password = ($dbname === "default" ? getenv('DB_PASSWORD') : getenv('DB_PASSWORD_' . $dbname));
        $this->database = ($dbname === "default" ? getenv('DB_NAME') : getenv('DB_NAME_' . $dbname));
        $portValue = $dbname === "default"
            ? (getenv('DB_PORT') !== false ? getenv('DB_PORT') : 3306)
            : (getenv('DB_PORT_' . $dbname) !== false ? getenv('DB_PORT_' . $dbname) : 3306);
        $this->port = (int) $portValue;

        $this->conn = mysqli_connect($this->host, $this->user, $this->password, $this->database, $this->port) or die("Could not connect " . mysqli_connect_error());
        if (getenv("SQL_MODE_LEGACY") === "true") {
            $this->query("SET sql_mode = '';");
        } elseif (getenv("SQL_MODE") !== false) {
            $this->query("SET sql_mode = '" . getenv("SQL_MODE") . "';");
        }
    }

    /**
     * Factory: returns MySQL or PostgreSQL adapter based on DB_DRIVER env var.
     *
     * @param string $dbname
     * @return DatabaseInterface
     */
    public static function getInstance(string $dbname = "default"): DatabaseInterface
    {
        if (empty(self::$instances[$dbname])) {
            $driver = $dbname === "default"
                ? (getenv('DB_DRIVER') ?: 'mysql')
                : (getenv('DB_DRIVER_' . $dbname) ?: (getenv('DB_DRIVER') ?: 'mysql'));

            if ($driver === 'pgsql') {
                self::$instances[$dbname] = new dataBasePgsql($dbname);
            } else {
                self::$instances[$dbname] = new self($dbname);
            }
        }

        return self::$instances[$dbname];
    }

    /**
     * Create new instance based on DB_DRIVER env var.
     *
     * @param string $dbname
     * @return DatabaseInterface
     */
    public static function createNew(string $dbname = "default"): DatabaseInterface
    {
        $driver = $dbname === "default"
            ? (getenv('DB_DRIVER') ?: 'mysql')
            : (getenv('DB_DRIVER_' . $dbname) ?: (getenv('DB_DRIVER') ?: 'mysql'));

        if ($driver === 'pgsql') {
            return new dataBasePgsql($dbname);
        }
        return new self($dbname);
    }


    /**
     * Close db connection and return mysqli_close() result
     *
     * @return bool
     */
    public function close(): bool
    {
        return (mysqli_close($this->conn));
    }

    /**
     * String description of the last error and return mysqli_error() result
     *
     * @return string
     */
    public function error(): string
    {
        return (mysqli_error($this->conn));
    }

    /**
     * Start a db transaction and return mysqli_begin_transaction() result
     *
     * @return void
     */
    public function startTransaction(): void
    {
        mysqli_begin_transaction($this->conn);
    }

    /**
     * Commits the current transaction and return mysqli_commit() result
     *
     * @return void
     */
    public function commitTransaction(): void
    {
        mysqli_commit($this->conn);
    }

    /**
     * Rolls back current transaction and return mysqli_rollback() result
     *
     * @return void
     */
    public function rollbackTransaction(): void
    {
        mysqli_rollback($this->conn);
    }

    /**
     * Performs a query on the database and return mysqli_query() result
     *
     * @param string $sql
     * @return mysqli_result|bool
     */
    public function query(string $sql): mysqli_result|bool
    {
        $this->result = mysqli_query($this->conn, $sql);
        return $this->result;
    }

    /**
     * Performs a multi query on the database and return mysqli_multi_query() result
     *
     * @param string $sql
     * @return object|bool
     */
    public function multi_query(string $sql): mysqli_result|bool
    {
        return mysqli_multi_query($this->conn, $sql);
    }

    /**
     * Gets the number of affected rows in a previous MySQL operation
     *
     * @return string|int
     */
    public function affectedRows(): int|string
    {
        return (mysqli_affected_rows($this->conn));
    }

    /**
     * Gets the number of rows in the result set.
     * Pass $result for safe nested queries, otherwise uses the last query result.
     *
     * @param mysqli_result|null $result
     * @return string|int
     */
    public function numrows(mixed $result = null): int|string
    {
        $r = $result ?? $this->result;
        return (mysqli_num_rows($r));
    }

    /**
     * Fetch the next row as an object.
     * Pass $result for safe nested queries, otherwise uses the last query result.
     *
     * @param mysqli_result|null $result
     * @return object|null|false
     */
    public function fetchobject(mixed $result = null): object|false|null
    {
        $r = $result ?? $this->result;
        return mysqli_fetch_object($r);
    }

    /**
     * Fetch the next row as an associative + numeric array.
     * Pass $result for safe nested queries, otherwise uses the last query result.
     *
     * @param mysqli_result|null $result
     * @return array|null|false
     */
    public function fetcharray(mixed $result = null): false|array|null
    {
        $r = $result ?? $this->result;
        return mysqli_fetch_array($r);
    }

    /**
     * Fetch the next row as an associative array.
     * Pass $result for safe nested queries, otherwise uses the last query result.
     *
     * @param mysqli_result|null $result
     * @return string[]|null|false
     */
    public function fetchassoc(mixed $result = null): array|false|null
    {
        $r = $result ?? $this->result;
        return mysqli_fetch_assoc($r);
    }

    /**
     * Frees the memory associated with a result.
     * Pass $result for safe nested queries, otherwise frees the last query result.
     *
     * @param mysqli_result|null $result
     * @return void
     */
    public function freeresult(mixed $result = null): void
    {
        $r = $result ?? $this->result;
        if (!empty($r) && $r instanceof mysqli_result) {
            $r->free();
        }
    }

    /**
     * Escapes special characters in a string for use in an SQL statement
     *
     * @param string $param
     * @return string
     */
    public function real_escape_string(string $param): string
    {
        return mysqli_real_escape_string($this->conn, $param);
    }

    /**
     * Returns the value generated for an AUTO_INCREMENT column by the last query
     *
     * @return int|string
     */
    public function insert_id(): int|string
    {
        return mysqli_insert_id($this->conn);
    }

    public function getDriver(): string
    {
        return 'mysql';
    }

    /**
     * Prevent the instance from being cloned
     */
    private function __clone() {}

    /**
     * Prevent from being unserialized
     * @throws RuntimeException
     */
    public function __wakeup()
    {
        throw new RuntimeException("Cannot unserialize a singleton.");
    }
}
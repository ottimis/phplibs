<?php

namespace ottimis\phplibs;

use mysqli;
use mysqli_result;
use RuntimeException;

class dataBase
{
    private static ?self $instances = null;
    protected string $host = '';
    protected string $user = '';
    protected string $password = '';
    protected string $database = '';
    protected string $port = '';
    protected bool $persistent = false;
    protected mysqli|null $conn = NULL;
    protected mysqli_result|bool $result;


    public function __construct($dbname = "")
    {
        $this->host = ($dbname === "" ? getenv('DB_HOST') : getenv('DB_HOST_' . $dbname));
        $this->user = ($dbname === "" ? getenv('DB_USER') : getenv('DB_USER_' . $dbname));
        $this->password = ($dbname === "" ? getenv('DB_PASSWORD') : getenv('DB_PASSWORD_' . $dbname));
        $this->database = ($dbname === "" ? getenv('DB_NAME') : getenv('DB_NAME_' . $dbname));
        $this->port = ($dbname === "" ? (getenv('DB_PORT') ? getenv('DB_PORT') : 3306) : getenv('DB_PORT_' . $dbname));

        $this->conn = mysqli_connect($this->host, $this->user, $this->password, $this->database, $this->port) or die("Could not connect " . mysqli_connect_error());
        if (getenv("SQL_MODE_LEGACY") === "true") {
            $this->query("SET sql_mode = '';");
        }
    }

    /**
     * Get the singleton instance of the class if it exists, otherwise create it
     *
     * @param string $dbname
     * @return self
     */
    public static function getInstance(string $dbname = ""): self
    {
        if (self::$instance === null) {
            self::$instance = new self($dbname);
        }

        return self::$instance;
    }

    /**
     * Create new instance of the class
     *
     * @param string $dbname
     * @return self
     */
    public static function createNew(string $dbname = ""): self
    {
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

    // se error_reporting attivato riporto errore

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
     * Start a db transaction  and return mysqli_begin_transaction() result
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

    // gruppo funzioni interrogazione

    /**
     * Performs a query on the database and return mysqli_query() result
     *
     * @param string $sql
     *
     * @return object|bool
     */
    public function query(string $sql): mysqli_result|bool
    {
        $this->result = mysqli_query($this->conn, $sql);
        return $this->result;
    }

    // gruppo funzioni interrogazione

    /**
     * Performs a multi query on the database and return mysqli_multi_query() result
     *
     * @param string $sql
     *
     * @return object|bool
     */
    public function multi_query(string $sql): mysqli_result|bool
    {
        return mysqli_multi_query($this->conn, $sql);
    }

    /**
     * Gets the number of affected rows in a previous MySQL operation
     * and return mysqli_affected_rows() result
     *
     * @return string|int
     */
    public function affectedRows(): int|string
    {
        return (mysqli_affected_rows($this->conn));
    }

    /**
     * Gets the number of rows in the result set and
     * return mysqli_num_rows() result
     *
     * @return string|int
     */
    public function numrows(): int|string
    {
        return (mysqli_num_rows($this->result));
    }

    /**
     * Fetch the next row of a result set as an object and
     * return mysqli_fetch_object() result
     *
     * @return object|null|false
     */
    public function fetchobject(): object|false|null
    {
        return mysqli_fetch_object($this->result);
    }

    /**
     * Fetch the next row of a result set as an associative,
     * a numeric array, or both and return mysqli_fetch_array() result
     *
     * @return array|null|false
     */
    public function fetcharray(): false|array|null
    {
        return mysqli_fetch_array($this->result);
    }

    /**
     * Fetch the next row of a result set as an associative array
     * and return mysqli_fetch_assoc() result
     *
     * @return string[]|null|false
     */
    public function fetchassoc(): array|false|null
    {
        return mysqli_fetch_assoc($this->result);
    }

    /**
     * Frees the memory associated with a result
     *
     * @return void
     */
    public function freeresult(): void
    {
        if (!empty($this->result)) {
            $this->result->free();
        }
    }

    /**
     * Escapes special characters in a string for use in an SQL statement,
     * taking into account the current charset of the connection
     * and return mysqli_real_escape_string() result
     *
     * @param string $param
     *
     * @return string
     */
    public function real_escape_string(string $param): string
    {
        return mysqli_real_escape_string($this->conn, $param);
    }

    /**
     * Returns the value generated for an AUTO_INCREMENT column by the last query
     * with mysqli_insert_id() function
     *
     * @return int|string
     */
    public function insert_id(): int|string
    {
        return mysqli_insert_id($this->conn);
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

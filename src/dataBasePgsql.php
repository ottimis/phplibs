<?php

namespace ottimis\phplibs;

use ottimis\phplibs\Interfaces\DatabaseInterface;
use PDO;
use PDOStatement;
use RuntimeException;

class dataBasePgsql implements DatabaseInterface
{
    private static array $instances = [];
    protected string $host = '';
    protected string $user = '';
    protected string $password = '';
    protected string $database = '';
    protected string $port = '';
    protected ?PDO $conn = null;
    protected PDOStatement|bool|null $result = null;
    protected ?int $lastInsertId = null;

    public function __construct(string $dbname = "default")
    {
        $this->host = ($dbname === "default" ? getenv('DB_HOST') : getenv('DB_HOST_' . $dbname));
        $this->user = ($dbname === "default" ? getenv('DB_USER') : getenv('DB_USER_' . $dbname));
        $this->password = ($dbname === "default" ? getenv('DB_PASSWORD') : getenv('DB_PASSWORD_' . $dbname));
        $this->database = ($dbname === "default" ? getenv('DB_NAME') : getenv('DB_NAME_' . $dbname));
        $this->port = ($dbname === "default" ? (getenv('DB_PORT') ?: '5432') : (getenv('DB_PORT_' . $dbname) ?: '5432'));

        try {
            $dsn = "pgsql:host={$this->host};port={$this->port};dbname={$this->database}";
            $this->conn = new PDO($dsn, $this->user, $this->password);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            throw new RuntimeException("Could not connect to PostgreSQL: " . $e->getMessage());
        }
    }

    public static function getInstance(string $dbname = "default"): self
    {
        if (empty(self::$instances[$dbname])) {
            self::$instances[$dbname] = new self($dbname);
        }
        return self::$instances[$dbname];
    }

    public static function createNew(string $dbname = "default"): self
    {
        return new self($dbname);
    }

    public function query(string $sql): PDOStatement|bool
    {
        $this->lastInsertId = null;
        $this->result = $this->conn->query($sql);

        // If the query has RETURNING, capture the id from the first row
        if ($this->result && stripos($sql, 'RETURNING') !== false) {
            $row = $this->result->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                // Get the first column value as the insert id
                $this->lastInsertId = (int)reset($row);
            }
        }

        return $this->result;
    }

    /**
     * Fetch the next row as an associative array.
     * Pass $result for safe nested queries, otherwise uses the last query result.
     *
     * @param PDOStatement|null $result
     * @return array|false|null
     */
    public function fetchassoc(mixed $result = null): array|false|null
    {
        $r = $result ?? $this->result;
        if (!$r) {
            return false;
        }
        return $r->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Fetch the next row as an associative + numeric array.
     * Pass $result for safe nested queries, otherwise uses the last query result.
     *
     * @param PDOStatement|null $result
     * @return array|false|null
     */
    public function fetcharray(mixed $result = null): false|array|null
    {
        $r = $result ?? $this->result;
        if (!$r) {
            return false;
        }
        return $r->fetch(PDO::FETCH_BOTH) ?: null;
    }

    /**
     * Fetch the next row as an object.
     * Pass $result for safe nested queries, otherwise uses the last query result.
     *
     * @param PDOStatement|null $result
     * @return object|false|null
     */
    public function fetchobject(mixed $result = null): object|false|null
    {
        $r = $result ?? $this->result;
        if (!$r) {
            return false;
        }
        return $r->fetch(PDO::FETCH_OBJ) ?: null;
    }

    /**
     * Gets the number of rows in the result set.
     * Pass $result for safe nested queries, otherwise uses the last query result.
     *
     * @param PDOStatement|null $result
     * @return int|string
     */
    public function numrows(mixed $result = null): int|string
    {
        $r = $result ?? $this->result;
        if (!$r) {
            return 0;
        }
        return $r->rowCount();
    }

    public function affectedRows(): int|string
    {
        if (!$this->result) {
            return 0;
        }
        return $this->result->rowCount();
    }

    public function insert_id(): int|string
    {
        if ($this->lastInsertId !== null) {
            return $this->lastInsertId;
        }
        return $this->conn->lastInsertId();
    }

    public function real_escape_string(string $param): string
    {
        // PDO::quote() adds surrounding quotes, strip them for compatibility
        $quoted = $this->conn->quote($param);
        // Remove the outer single quotes added by PDO::quote
        return substr($quoted, 1, -1);
    }

    public function error(): string|array
    {
        $info = $this->conn->errorInfo();
        if ($info[0] === '00000' || $info[0] === null) {
            return '';
        }
        return $info[2] ?? '';
    }

    public function startTransaction(): void
    {
        $this->conn->beginTransaction();
    }

    public function commitTransaction(): void
    {
        $this->conn->commit();
    }

    public function rollbackTransaction(): void
    {
        $this->conn->rollBack();
    }

    public function close(): bool
    {
        $this->conn = null;
        return true;
    }

    /**
     * Frees the memory associated with a result.
     * Pass $result for safe nested queries, otherwise frees the last query result.
     *
     * @param PDOStatement|null $result
     * @return void
     */
    public function freeresult(mixed $result = null): void
    {
        $r = $result ?? $this->result;
        if ($r instanceof PDOStatement) {
            $r->closeCursor();
        }
    }

    public function getDriver(): string
    {
        return 'pgsql';
    }

    public function getPdo(): PDO
    {
        return $this->conn;
    }

    private function __clone() {}

    public function __wakeup()
    {
        throw new RuntimeException("Cannot unserialize a singleton.");
    }
}
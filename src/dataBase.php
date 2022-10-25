<?php

namespace ottimis\phplibs;

class dataBase
{

  protected $host = '';
  protected $user = '';
  protected $password = '';
  protected $database = '';
  protected $port = '';
  protected $persistent = false;
  /**
   * @var \mysqli
   */
  protected $conn = NULL;
  /**
   * @var \mysqli_result
   */
  protected $result;
  protected $error_reporting = true;


  function __construct($dbname = "")
  {
    $this->host = ($dbname == "" ? getenv('DB_HOST') : getenv('DB_HOST_' . $dbname));
    $this->user = ($dbname == "" ? getenv('DB_USER') : getenv('DB_USER_' . $dbname));
    $this->password = ($dbname == "" ? getenv('DB_PASSWORD') : getenv('DB_PASSWORD_' . $dbname));
    $this->database = ($dbname == "" ? getenv('DB_NAME') : getenv('DB_NAME_' . $dbname));
    $this->port = ($dbname == "" ? (getenv('DB_PORT') ? getenv('DB_PORT') : 3306) : getenv('DB_PORT_' . $dbname));

    $this->conn = mysqli_connect($this->host, $this->user, $this->password, $this->database, $this->port) or die("Could not connect " . mysqli_connect_error($this->conn));
    if (getenv("SQL_MODE_LEGACY") == "true") {
      $this->query("SET sql_mode = '';");
    }
    return $this->result;
  }



  /**
   * Close db connection and return mysqli_close() result
   *
   * @return bool
   */
  function close()
  {
    return (mysqli_close($this->conn));
  }

  // se error_reporting attivato riporto errore
  /**
   * String description of the last error and return mysqli_error() result
   *
   * @return string
   */
  function error()
  {
    return (mysqli_error($this->conn));
  }

  /**
   * Start a db transaction  and return mysqli_begin_transaction() result
   *
   * @return bool
   */
  function startTransaction()
  {
    mysqli_begin_transaction($this->conn);
  }


  /**
   * Commits the current transaction and return mysqli_commit() result
   *
   * @return bool
   */
  function commitTransaction()
  {
    mysqli_commit($this->conn);
  }

  /**
   * Rolls back current transaction and return mysqli_rollback() result
   *
   * @return bool
   */
  function rollbackTransaction()
  {
    mysqli_rollback($this->conn);
  }

  // gruppo funzioni interrogazione
  /**
   * Performs a query on the database and return mysqli_query() result
   *
   * @param  string $sql
   *
   * @return object|bool
   */
  function query($sql)
  {
    $this->result = mysqli_query($this->conn, $sql);
    return ($this->result);
  }

  // gruppo funzioni interrogazione
  /**
   * Performs a multi query on the database and return mysqli_multi_query() result
   *
   * @param  string $sql
   *
   * @return object|bool
   */
  function multi_query($sql)
  {
    $this->result = mysqli_multi_query($this->conn, $sql);
    return ($this->result);
  }

  /**
   * Gets the number of affected rows in a previous MySQL operation
   * and return mysqli_affected_rows() result
   *
   * @return string|int
   */
  function affectedRows()
  {
    return (mysqli_affected_rows($this->conn));
  }

  /**
   * Gets the number of rows in the result set and
   * return mysqli_num_rows() result
   *
   * @return string|int
   */
  function numrows()
  {
    return (mysqli_num_rows($this->result));
  }

  /**
   * Fetch the next row of a result set as an object and
   * return mysqli_fetch_object() result
   *
   * @return object|null|false
   */
  function fetchobject()
  {
    return (mysqli_fetch_object($this->result));
  }

  /**
   * Fetch the next row of a result set as an associative,
   * a numeric array, or both and return mysqli_fetch_array() result
   *
   * @return array|null|false
   */
  function fetcharray()
  {
    return (mysqli_fetch_array($this->result));
  }

  /**
   * Fetch the next row of a result set as an associative array
   * and return mysqli_fetch_assoc() result
   *
   * @return string[]|null|false
   */
  function fetchassoc()
  {
    return (mysqli_fetch_assoc($this->result));
  }

  /**
   * Frees the memory associated with a result
   *
   * @return void
   */
  function freeresult()
  {
    if ($this->result)
      return $this->result->free();
  }

  /**
   * Escapes special characters in a string for use in an SQL statement,
   * taking into account the current charset of the connection
   * and return mysqli_real_escape_string() result
   *
   * @param  string $param
   *
   * @return string
   */
  function real_escape_string($param)
  {
      return (mysqli_real_escape_string($this->conn, $param ?? ''));
  }

  /**
   * Returns the value generated for an AUTO_INCREMENT column by the last query
   * with mysqli_insert_id() function
   *
   * @return int|string
   */
  function insert_id()
  {
    return (mysqli_insert_id($this->conn));
  }
}

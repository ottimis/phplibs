<?php

namespace ottimis\phplibs;

class PdoConnect
{
  protected $driver = '';
  protected $host = '';
  protected $user = '';
  protected $password = '';
  protected $database = '';
  protected $port = '';
  protected $conn = null;
  protected $result = null;
  protected $error_reporting = true;
  protected $transaction = false;
  protected $debug = false;

  public function __construct($error = false, $db = '')
  {
    $this->host = ($db !== '') ? getenv('DB_HOST_' . $db) : getenv('DB_HOST');
    $this->user = ($db !== '') ? getenv('DB_USER_' . $db) : getenv('DB_USER');
    $this->password = ($db !== '') ? getenv('DB_PASSWORD_' . $db) : getenv('DB_PASSWORD');
    $this->database = ($db !== '') ? getenv('DB_NAME_' . $db) : getenv('DB_NAME');
    $this->port = ($db !== '') ? getenv('DB_PORT_' . $db) : getenv('DB_PORT');

    $this->conn = new \PDO("sqlsrv:Server=$this->host,$this->port;Database=$this->database;TrustServerCertificate=true", "$this->user", "$this->password");
    $this->conn->setAttribute(\PDO::SQLSRV_ATTR_FETCHES_NUMERIC_TYPE, true);
    if ($error) {
      $this->conn->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }
    $this->debug = $error;
    return;
  }
  // se error_reporting attivato riporto errore        
  /**
   *  This function fetch extended error information associated with the 
   *  last operation on the database and return the PDO::errorInfo result
   *
   * @return array
   */
  public function error()
  {
    return $this->conn->errorInfo();
  }
  /**
   * This function Checks if inside a transaction and return the 
   * PDO::inTransaction result
   *
   * @return bool
   */
  public function inTransaction()
  {
    return $this->conn->inTransaction();
  }
  /**
   * This function initiates a transaction and return the 
   * PDO::beginTransaction result
   *
   * @return bool
   * @throws PDOException
   */
  public function startTransaction()
  {
    $this->transaction = $this->conn->beginTransaction();
    return $this->transaction;
  }
  /**
   * This function commits a transaction and return
   * PDO::commit result
   *
   * @return bool
   * @throws PDOException
   */
  public function commitTransaction()
  {
    $this->transaction = !$this->conn->commit();
    return $this->transaction;
  }
  /**
   * This function rolls back a transaction and return
   * PDO::rollBack result
   *
   * @return bool
   * @throws PDOException
   */
  public function rollBackTransaction()
  {
    $this->transaction = !$this->conn->rollBack();
    return $this->transaction;
  }
  // gruppo funzioni interrogazione
  /**
   * This function prepares and executes an SQL statement
   * and return PDO::query result
   * 
   * @param mixed $sql
   * 
   * @return PDOStatement|bool
   */
  public function query($sql)
  {
    $this->result = $this->conn->query($sql);
    return $this->result;
  }
  /**
   * This function fetches the next row from a result set 
   * 
   * @return mixed
   */
  public function fetchassoc()
  {
    return $this->result->fetch(\PDO::FETCH_ASSOC);
  }
  /**
   * This function  fetches the remaining rows from a result set  
   * 
   * @return array
   */
  public function fetchAll()
  {
    return $this->result->fetchAll(\PDO::FETCH_ASSOC);
  }
  /**
   * This function prepares a statement for execution 
   * and returns a statement object 
   * 
   * @param mixed $sql
   * 
   * @return PDOStatement|bool
   */
  public function prepare($sql)
  {
    $this->result = $this->conn->prepare($sql);
    return $this->result;
  }
  /**
   * This function binds a value to a parameter 
   * and return PDOStatement::bindValue result 
   * 
   * @param string|int $field
   * @param mixed $value
   * 
   * @return bool
   */
  public function bindValue($field, $value)
  {
    $this->result->bindValue($field, $value);
    return $this->result;
  }
  /**
   * This function binds a value to a parameter 
   * and return PDOStatement::bindValue result 
   * 
   * @param string|int $field
   * @param mixed $value
   * 
   * @return bool
   */
  public function bindParam($field, $value, $type = \PDO::PARAM_STR)
  {
    $this->result->bindParam($field, $value, $type);
    return $this->result;
  }
  /**
   * This function binds a value to a parameter 
   * and return PDOStatement::bindValue result 
   * 
   * @param array $ar
   * 
   * @return bool
   */
  public function execute($ar = null)
  {
    if ($ar)  {
      $this->result->execute($ar ?? null);
    } else {
      $this->result->execute();
    }
    return $this->result;
  }
  /**
   * This function dump an SQL prepared command 
   * and return PDOStatement::debugDumpParams result 
   * 
   * @return bool
   */
  public function debugDumpParams()
  {
    return $this->result->debugDumpParams();
  }
  /**
   * This function returns the number of rows affected 
   * by the last SQL statement 
   * 
   * @return int
   */
  public function affectedRows()
  {
    return $this->result->rowCount();
  }
  /**
   * This function returns the ID of the last inserted 
   * row or sequence value
   * 
   * @return string|false
   */
  public function insert_id()
  {
    return $this->conn->lastInsertId();
  }

  // START: Utils functions

  /**
   * This function returns all the results of an SQL query executed on a specific table. 
   *
   * @param  string $table
   * @param  array $paging [optional]
   * @param  array $fields [optional]
   * @param  mixed $where [optional]
   * 
   * @return array
   */
  public function getAll($table, $paging = [], $fields = ["*"], $where = null)
  {
    $arSql = array(
      "log" => $this->debug,
      "select" => $fields,
      "from" => $table
    );

    if (sizeof($paging) > 0) {
      $arSql['count'] = true;
    }

    if ($where) {
      $arSql['where'][] = $where;
    }

    $rec = $this->dbSelect($arSql, $paging);
    return $rec;
  }

  /**
   * This function returns a specific row from a database table
   *
   * @param  mixed $table
   * @param  mixed $id
   * @param  mixed $where [optional]
   * @param  mixed $field [optional]
   * @return void
   */
  public function get($table, $id, $where = null, $field = "id")
  {
    $arSql = array(
      "log" => $this->debug,
      "select" => ["*"],
      "from" => $table,
      "where" => [
        [
          "field" => $field,
          "value" => $id
        ]
      ]
    );

    if ($where) {
      $arSql['where'][] = $where;
    }

    $rec = $this->dbSelect($arSql);
    return sizeof($rec['data']) > 0 ? $rec['data'][0] : [];
  }

  /**
   * This function deletes a specific row from a database table
   *
   * @param  string $table
   * @param  string|int $id
   * 
   * @return array
   */
  public function delete($table, $id)
  {
    $arSql = array(
      "log" => $this->debug,
      "delete" => true,
      "from" => $table,
      "where" => [
        [
          "field" => "id",
          "value" => $id
        ]
      ]
    );

    $rec = $this->dbSelect($arSql);
    return $rec;
  }

  /**
   * This function returns the rows from a db table with 
   * a specified condition. Also is possible make JOIN 
   * operation.
   *
   * @param  mixed $table
   * @param  mixed $where
   * @param  mixed $fields [optional]
   * @param  mixed $join [optional]
   * @param  mixed $paging [optional]
   * @param  mixed $order [optional]
   * 
   * @return array
   */
  public function getRows($table, $where, $fields = ["*"], $join = null, $paging = null, $order = null)
  {
    $arSql = array(
      "log" => $this->debug,
      "select" => $fields,
      "from" => $table,
      "where" => isset($where['field']) ? array($where) : $where
    );

    if ($join) {
      $arSql['join'] = $join;
    }

    if ($order) {
      $arSql['order'] = $order;
    }

    if ($paging > 0) {
      $arSql['count'] = true;
      $rec = $this->dbSelect($arSql, $paging);
      return $rec;
    } else {
      $rec = $this->dbSelect($arSql);
      return $rec['data'];
    }
  }

  /**
   * This function create an SQL INSERT statement and is 
   * used to insert new records in a table.
   * 
   * @param  mixed $table
   * @param  mixed $ar
   * 
   * @return (string|int|false)[]|(string|int|array)[]
   */
  public function insertRow($table, $ar = array())
  {
    $res = $this->dbSql(true, $table, $ar);
    return $res;
  }

  /**
   * This function create an SQL UPDATE statement and is 
   * used to modify the existing records in a table.
   *
   * @param  mixed $table
   * @param  mixed $ar
   * @param  mixed $field 
   * @param  mixed $value
   * 
   * @return (string|int|false)[]|(string|int|array)[]
   */
  public function updateRow($table, $ar, $field = "", $value = "")
  {
    $res = $this->dbSql(false, $table, $ar, $field, $value);
    return $res;
  }

  /**
   * This function create an SQL DELETE statement and is 
   * used to delete existing records in a table.
   * @param  mixed $table
   * @param  mixed $where
   * @param  mixed $fields
   * @return array
   */
  public function deleteRow($table, $where, $fields = ["*"])
  {
    $arSql = array(
      "log" => $this->debug,
      "delete" => true,
      "from" => $table,
      "where" => $where
    );

    $rec = $this->dbSelect($arSql);
    return $rec;
  }

  /**
   * This function is used to increment the id of a db table by one
   *
   * @param  mixed $table
   * @return int
   */
  public function incrementId($table)
  {
    $sql = sprintf("UPDATE %s SET id = id + 1", $table);
    $this->query($sql);
    return (int)$this->getAll($table)['data'][0]['id'];
  }

  // END: Utils functions


  public function dbSql($bInsert, $table, $ar, $idfield = "", $idvalue = "", $noUpdate = false)
  {
    $values = '';
    $z = '';

    try {
      if ($bInsert) {
        $columns = implode(", ", array_keys($ar));
        $values = ":" . implode(", :", array_keys($ar));
        $sql = "INSERT INTO $table ($columns) VALUES ($values)";
      } else {
        $z = "";
        foreach ($ar as $k => $v) {
          $z .= ($z != "") ? ", " : "";
          $z .= $k . "=:" . $k;
        }
        $sql = sprintf("UPDATE %s SET %s WHERE %s=:%s", $table, $z, $idfield, $idfield);
        $ar[$idfield] = $idvalue;
      }

      $ret['sql'] = $sql;
      $this->prepare($sql);
      $this->execute($ar);
      $errors = $this->error();

      if (intval($errors[0]) === 0) {
        $ret['affectedRows'] = $this->affectedRows();
        $ret['id'] = $this->insert_id();
        $ret['success'] = 1;
      } else {
        // $log = new LoggerPdo();
        // $log->error('Errore inserimento: ' . $this->error() . " Query: " . $sql, "DBSQL");
        $ret['success'] = 0;
        $ret['err'] = $errors;
        // throw new \Exception('Errore inserimento: ' . $this->error() . " Query: " . $sql, "DBSQL");
      }
      return $ret;
    } catch (\Exception $e) {
      // $log = new LoggerPdo();
      // $log->error('Eccezione db: ' . $e->getMessage(), "DBSQL");
      $ret['success'] = 0;
      $ret['err'] = $e->getMessage();
      $ret['errCode'] = $e->getCode();
      // throw new \Exception('Eccezione db: ' . $e->getMessage() . " Query: " . $sql, "DBSQL");
      return $ret;
    }
  }



  /**
   * dbSelect
   *
   * @param  mixed $req SELECT, FROM, JOIN(Array), WHERE(Array), ORDER, LIMIT, OTHER
   *
   * Example: $ar = array(
                    "select" => ["uid", "status"],
                    "from" => "pso_utenti pu",
                    "join" => [
                        [
                            "pso_status ps",
                            " ps.id=pu.idstatus"
                        ]
                    ],
                    "where" => [
                        [
                            "field" => "email",
                            "operator" => "=",
                            "value" => "mattymatty95@gmail.com",
                            "operatorAfter" => "AND"
                        ]
                    ],
                    "order" => "uid",
                    "limit" => [0, 1]
                );

                print_r(dbSelect($ar));
   *
   * @return array|boolean
   */

  public function dbSelect($req, $paging = array())
  {
    $ar = array();
    $params = array();

    foreach ($req as $key => $value) {
      if (isset($req[$key])) {
        switch ($key) {
          case 'where':
            $index = 0;
            foreach ($value as $k => $v) {
              if (!isset($ar[$key])) {
                $ar[$key] = '';
              }
              if (isset($v['custom'])) {
                $ar[$key] .= $v['custom'];
                if (isset($v['operatorAfter']) || isset($value[$k + 1])) {
                  if (isset($value[$k + 1]) && isset($v['operatorAfter'])) {
                    $ar[$key] .= sprintf(" %s ", $v['operatorAfter']);
                  } elseif (isset($value[$k + 1]) && !isset($v['operatorAfter'])) {
                    $ar[$key] .= " AND ";
                  }
                }
                continue;
              }
              $subFieldPos = strrpos($v['field'], ".");
              if ($subFieldPos !== false) {
                $v['bindField'] = substr($v['field'], $subFieldPos + 1) . $index;
              } else {
                $v['bindField'] = $v['field'] . $index;
              }
              if (!isset($v['operator'])) {
                $ar[$key] .= sprintf("%s = :%s", $v['field'], $v['bindField']);
                $params[$v['bindField']] = $v['value'];
              } elseif ($v['operator'] === 'IN') {
                $inValues = array();
                foreach ($v['value'] as $kIN => $vIN) {
                  $inValues[] = ":in$kIN";
                  $params["in$kIN"] = $vIN;
                }
                $ar[$key] .= sprintf("%s IN(%s)", $v['field'], implode(',', $inValues));
              } else {
                if (isset($v['value'])) {
                  $ar[$key] .= sprintf("%s %s :%s", $v['field'], $v['operator'], $v['bindField']);
                  $params[$v['bindField']] = $v['value'];
                } else {
                  $ar[$key] .= sprintf("%s %s ", $v['field'], $v['operator']);
                }
              }
              if (isset($v['operatorAfter']) || isset($value[$k + 1])) {
                if (isset($value[$k + 1]) && isset($v['operatorAfter'])) {
                  $ar[$key] .= sprintf(" %s ", $v['operatorAfter']);
                } elseif (isset($value[$k + 1]) && !isset($v['operatorAfter'])) {
                  $ar[$key] .= " AND ";
                }
              }
              $index++;
            }
            break;
          case 'join':
            if (!isset($ar[$key])) {
              $ar[$key] = '';
            }
            foreach ($value as $v) {
              $ar[$key] .= sprintf("LEFT JOIN %s ON %s ", $v[0], $v[1]);
            }
            break;
          case 'limit':
            if (!isset($ar[$key])) {
              $ar[$key] = '';
            }
            $ar[$key] .= sprintf("OFFSET %d ROWS FETCH NEXT %d ROWS ONLY", $value[0], $value[1]);
            break;

          default:
            if (gettype($value) == 'array') {
              if (!isset($ar[$key])) {
                $ar[$key] = '';
              }
              foreach ($value as $v) {
                $ar[$key] .= $v .= ', ';
              }
              $ar[$key] = substr($ar[$key], 0, -2);
            } else {
              $ar[$key] = $value;
            }
            break;
        }
      } else {
        $ar[$key] = '';
      }
    }

    if (sizeof($paging) > 0) {
      $res = $this->buildPaging($ar, $paging, $params);
      $ar = $res['sql'];
      $params = $res['params'];
    }

    if (isset($req['select'])) {
      $sql = sprintf(
        "SELECT %s FROM %s %s %s %s %s %s",
        $ar['select'],
        $ar['from'],
        isset($ar['join']) ? $ar['join'] : '',
        isset($ar['where']) ? "WHERE " . $ar['where'] : '',
        isset($ar['order']) ? "ORDER BY " . $ar['order'] : '',
        isset($ar['limit']) ? $ar['limit'] : '',
        isset($ar['other']) ? $ar['other'] : ''
      );
    } elseif (isset($req['delete'])) {
      $sql = sprintf(
        "DELETE FROM %s WHERE %s %s",
        $ar['from'],
        isset($ar['where']) ? $ar['where'] : '',
        isset($ar['other']) ? $ar['other'] : ''
      );
    }

    if (isset($req['log']) && $req['log']) {
      if (defined("DEBUG")) {
        $debug = constant("DEBUG");
      } else {
        $debug = false;
      }
      $log = new LoggerPdo($debug, $debug);
      $log->log("Query: " . $sql, "DBSLC1");
      $log->log("Params: " . json_encode($params), "DBSLC2");
    }
    $this->prepare($sql);
    $this->execute($params);
    $errors = $this->error();
    if (intval($errors[0]) === 0) {
      if (isset($req['delete'])) {
        return true;
      }
      $ret = array();
      $ret['data'] = $this->fetchAll();
      if (isset($req['count'])) {
        $countSelect = sprintf("SELECT COUNT(*) AS tot FROM %s %s %s", $ar['from'], isset($ar['join']) ? $ar['join'] : '', isset($ar['where']) ? "WHERE " . $ar['where'] : '');
        $this->prepare($countSelect);
        $this->execute($params);
        $ret['total'] = intval($this->fetchassoc()['tot']);
        $ret['count'] = sizeof($ret['data']);
        $ret['rows'] = $ret['data'];
        unset($ret['data']);
      }
      return $ret;
    } else {
      if (defined("DEBUG")){
        $debug = constant("DEBUG");
      } else {
        $debug = false;
      }
      $log = new LoggerPdo($debug, $debug);
      $log->warning('Errore query: ' . $sql . "\r\n DB message: " . json_encode($errors), "DBSLC3");
      return $errors;
    }
  }

  private function buildPaging($ar, $paging, $params)
  {
    if (isset($paging['s']) && strlen($paging['s']) > 1 && isset($paging['searchField'])) {
      $searchWhere = array();
      foreach ($paging['searchField'] as $k => $v) {
        $searchWhere[] = "$v like :s$k";
        $params["s$k"] = "%" . $paging['s'] . "%";
      }
      $stringSearch = implode(' OR ', $searchWhere);
      if (isset($ar['where'])) {
        $ar['where'] .= " AND ($stringSearch)";
      } else {
        $ar['where'] = "($stringSearch)";
      }
    }
    if (isset($paging['srt']) && isset($paging['o'])) {
      $ar["order"] = $paging['srt'] . " " . $paging['o'];
    }
    if (isset($paging['p']) && isset($paging['c'])) {
      $count = $paging['c'] != "" ? ($paging['c']) : 20;
      $start = $paging['p'] != "" ? ($paging['p'] - 1) * $count : 0;
      $ar["limit"] = "OFFSET $start ROWS FETCH NEXT $count ROWS ONLY";
    }
    return array("sql" => $ar, "params" => $params);
  }
}

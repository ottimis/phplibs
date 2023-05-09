<?php

namespace ottimis\phplibs;

class Utils
{
  /**
   * @var dataBase
   */
  public $dataBase;
  /**
   * @var Logger | @var LoggerPdo
   */
  public $Log;
  protected $httpCodes = array(
    100 => 'Continue',
    101 => 'Switching Protocols',
    102 => 'Processing',
    103 => 'Checkpoint',
    200 => 'OK',
    201 => 'Created',
    202 => 'Accepted',
    203 => 'Non-Authoritative Information',
    204 => 'No Content',
    205 => 'Reset Content',
    206 => 'Partial Content',
    207 => 'Multi-Status',
    300 => 'Multiple Choices',
    301 => 'Moved Permanently',
    302 => 'Found',
    303 => 'See Other',
    304 => 'Not Modified',
    305 => 'Use Proxy',
    306 => 'Switch Proxy',
    307 => 'Temporary Redirect',
    400 => 'Bad Request',
    401 => 'Unauthorized',
    402 => 'Payment Required',
    403 => 'Forbidden',
    404 => 'Not Found',
    405 => 'Method Not Allowed',
    406 => 'Not Acceptable',
    407 => 'Proxy Authentication Required',
    408 => 'Request Timeout',
    409 => 'Conflict',
    410 => 'Gone',
    411 => 'Length Required',
    412 => 'Precondition Failed',
    413 => 'Request Entity Too Large',
    414 => 'Request-URI Too Long',
    415 => 'Unsupported Media Type',
    416 => 'Requested Range Not Satisfiable',
    417 => 'Expectation Failed',
    418 => 'I\'m a teapot',
    422 => 'Unprocessable Entity',
    423 => 'Locked',
    424 => 'Failed Dependency',
    425 => 'Unordered Collection',
    426 => 'Upgrade Required',
    449 => 'Retry With',
    450 => 'Blocked by Windows Parental Controls',
    500 => 'Internal Server Error',
    501 => 'Not Implemented',
    502 => 'Bad Gateway',
    503 => 'Service Unavailable',
    504 => 'Gateway Timeout',
    505 => 'HTTP Version Not Supported',
    506 => 'Variant Also Negotiates',
    507 => 'Insufficient Storage',
    509 => 'Bandwidth Limit Exceeded',
    510 => 'Not Extended'
  );

  public function __construct($dbname = "")
  {
    $this->dataBase = new dataBase($dbname);
    $this->Log = getenv('LOG_DB_TYPE') == 'mssql' ? new LoggerPdo() : new Logger();
  }


  public function dbSql($bInsert, $table, $ar, $idfield = "", $idvalue = "", $noUpdate = false)
  {
    $db = $this->dataBase;
    $values = '';
    $z = '';

    try {
      if ($bInsert) {
        $columns = implode(", ", array_keys($ar));
        foreach ($ar as $k) {
          $values .= $values != '' ? "," : "";
          if ($k !== "now()") {
            $values .= "'" . $db->real_escape_string($k) . "'";
          } else {
            $values .= "now()";
          }
        }
        foreach ($ar as $k => $v) {
          $z .= $z != '' ? "," : "";
          if ($v !== "now()") {
            $z .= $k . "='" . $db->real_escape_string($v) . "'";
          } else {
            $z .= $k . "=now()";
          }
        }
        $sql = "INSERT INTO $table ($columns) VALUES ($values)";
        if (!$noUpdate) {
          $sql .= " ON DUPLICATE KEY UPDATE $z";
        }
      } else {
        $z = "";
        foreach ($ar as $k => $v) {
          $z .= ($z != "") ? "," : "";
          if ($v !== "now()") {
            $z .= $k . "='" . $db->real_escape_string($v) . "'";
          } else {
            $z .= $k . "=now()";
          }
        }
        $sql = sprintf("UPDATE %s SET %s WHERE %s='%s'", $table, $z, $idfield, $idvalue);
      }

      $ret['sql'] = $sql;
      $r = $db->query($sql);

      if (!$r) {
        $this->Log->error('Errore inserimento: ' . $db->error() . " Query: " . $sql, "DBSQL");
        $ret['success'] = 0;
        $ret['error'] = $db->error();
      } else {
        $ret['affectedRows'] = $db->affectedRows();
        $ret['id'] = $db->insert_id();
        $ret['success'] = 1;
      }
      return $ret;
    } catch (\Exception $e) {
      $this->Log->error('Eccezione db: ' . $e->getMessage() . " Query: " . $sql, "DBSQL");
      $ret['success'] = 0;
      return $ret;
    }
  }


  private function buildWhere($req)
  {
    $db = $this->dataBase;
    $ar = array();
    foreach ($req as $key => $value) {
      if (isset($req[$key])) {
        switch ($key) {
          case 'where':
            foreach ($value as $k => $v) {
              if (!isset($ar[$key])) {
                $ar[$key] = '';
              }
              if (isset($v['custom'])) {
                $ar[$key] .= $v['custom'];
                if (isset($v['operatorAfter']) || isset($value[$k + 1])) {
                  if (isset($value[$k + 1]) && isset($v['operatorAfter'])) {
                    $ar[$key] .= sprintf(" %s ", $v['operatorAfter']);
                  } else if (isset($value[$k + 1]) && !isset($v['operatorAfter'])) {
                    $ar[$key] .= " AND ";
                  }
                }
                continue;
              }
              if (!isset($v['operator'])) {
                $ar[$key] .= sprintf("%s='%s'", $v['field'], $db->real_escape_string($v['value']));
              } elseif ($v['operator'] === 'IN') {
                $inValues = array();
                foreach ($v['value'] as $kIN => $vIN) {
                  $inValues[] = "'" . $db->real_escape_string($vIN) . "'";
                }
                $ar[$key] .= sprintf("%s IN(%s)", $v['field'], implode(',', $inValues));
              } else {
                $ar[$key] .= sprintf("%s %s %s '%s' %s", $v['before'] ?? "", $v['field'], $v['operator'], $db->real_escape_string($v['value']), $v['end'] ?? "");
              }
              if (isset($v['operatorAfter']) || isset($value[$k + 1])) {
                if (isset($value[$k + 1]) && isset($v['operatorAfter'])) {
                  $ar[$key] .= sprintf(" %s ", $v['operatorAfter']);
                } else if (isset($value[$k + 1]) && !isset($v['operatorAfter'])) {
                  $ar[$key] .= " AND ";
                }
              }
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
          case 'rightJoin':
            if (!isset($ar[$key])) {
              $ar[$key] = '';
            }
            foreach ($value as $v) {
              $ar[$key] .= sprintf("RIGHT JOIN %s ON %s ", $v[0], $v[1]);
            }
            break;
          case 'innerJoin':
            if (!isset($ar[$key])) {
              $ar[$key] = '';
            }
            foreach ($value as $v) {
              $ar[$key] .= sprintf("INNER JOIN %s ON %s ", $v[0], $v[1]);
            }
            break;
          case 'limit':
            if (!isset($ar[$key])) {
              $ar[$key] = '';
            }
            $ar[$key] .= sprintf("%d, %d", $value[0], $value[1]);
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
    return $ar;
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
   * @return Array
   */

  public function dbSelect($req, $paging = array())
  {
    $db = $this->dataBase;
    $ar = $this->buildWhere($req);

    if (sizeof($paging) > 0) {
      $res = $this->buildPaging($ar, $paging);
      $ar = $res['sql'];
    }

    if (isset($req['select'])) {
      $sql = sprintf(
        "SELECT %s %s FROM %s %s %s %s %s %s %s %s %s",
        isset($req['count']) ? "SQL_CALC_FOUND_ROWS " : '',
        $ar['select'],
        $ar['from'],
        isset($ar['join']) ? $ar['join'] : '',
        isset($ar['rightJoin']) ? $ar['rightJoin'] : '',
        isset($ar['innerJoin']) ? $ar['innerJoin'] : '',
        isset($ar['where']) ? "WHERE " . $ar['where'] : '',
        isset($ar['group']) ? "GROUP BY " . $ar['group'] : '',
        isset($ar['order']) ? "ORDER BY " . $ar['order'] : '',
        isset($ar['limit']) ? "LIMIT " . $ar['limit'] : '',
        isset($ar['other']) ? $ar['other'] : ''
      );
    } elseif (isset($req['delete'])) {
      $sql = sprintf(
        "DELETE %s FROM %s %s %s %s",
        gettype($req['delete']) == 'string' ? $req['delete'] : '',
        $ar['from'],
        isset($ar['join']) ? $ar['join'] : '',
        isset($ar['where']) ? "WHERE " . $ar['where'] : '',
        $ar['other']
      );
    }

    if (isset($req['log']) && $req['log']) {
      $this->Log->log("Query: " . $sql, "DBSLC1");
    }

    $res = $db->query($sql);
    if ($res) {
      if (isset($req['delete'])) {
        return array("success" => true);
      }
      $ret = array();
      while ($rec = $db->fetchassoc()) {
        if (isset($req['decode'])) {
          foreach ($req['decode'] as $value) {
            if (isset($rec[$value]) && $rec[$value] != '') {
              $rec[$value] = json_decode($rec[$value], true);
            }
          }
        }
        if (isset($req['map'])) {
          $rec = $req['map']($rec);
        }
        if ($rec) {
          $ret['data'][] = $rec;
        }
      }
      if (isset($req['count']) || sizeof($paging) > 0) {
        $db->query("SELECT FOUND_ROWS()");
        $ret['total'] = intval($db->fetcharray()[0]);
        $ret['count'] = sizeof($ret['data']);
        $ret['rows'] = $ret['data'];
        unset($ret['data']);
      }
      $db->freeresult();
      return $ret;
    } else {
      $this->Log->warning('Errore query: ' . $sql . "\r\n DB message: " . $db->error(), "DBSLC2");
      $db->freeresult();
      return false;
    }
  }

  private function buildPaging($ar, $paging)
  {
    if (isset($paging['s']) && strlen($paging['s']) > 1 && isset($paging['searchField'])) {
      $searchWhere = array();
      foreach ($paging['searchField'] as $k => $v) {
        $searchWhere[] = sprintf("$v like '%%%s%%'", $paging['s']);
      }
      $stringSearch = "(" . implode(' OR ', $searchWhere) . ")";
      if (isset($ar['where'])) {
        $ar['where'] .= " AND ($stringSearch)";
      } else {
        $ar['where'] = "WHERE ($stringSearch)";
      }
    }
    if (isset($paging['srt']) && isset($paging['o'])) {
      $ar["order"] = $paging['srt'] . " " . $paging['o'];
    }
    if (isset($paging['p']) && isset($paging['c'])) {
      $count = $paging['c'] != "" ? ($paging['c']) : 20;
      $start = $paging['p'] != "" ? ($paging['p'] - 1) * $count : 0;
      $ar["limit"] = "$start, $count";
    }
    return array("sql" => $ar);
  }

  public function _combo_list($req, $where = "", $log = false)
  {
    $table = $req['table'];
    $value = $req['value'];
    $text = $req['text'];
    $other_field = isset($req['other_field']) ? ",".$req['other_field'] : "";
    $order = $req['order'];
    $where = $req['where'];

    if ($where != "") {
      $where = " WHERE " . $where;
    }

    $sql = sprintf(
      "SELECT %s as id, %s as text %s
					FROM %s
					%s
					ORDER BY %s",
      $value,
      $text,
      $other_field,
      $table,
      $where,
      $order
    );

    if ($log) {
      $this->Log->log('Query: ' . $sql, "CL");
    }

    $db = $this->dataBase;
    $res = $db->query($sql);
    $ar = array();
    while ($rec = $db->fetchassoc()) {
      $ar[] = $rec;
    }
    return $ar;
  }
}

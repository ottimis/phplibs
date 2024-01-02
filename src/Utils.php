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

  public function __construct($dbname = "")
  {
    $this->dataBase = new dataBase($dbname);
    $this->Log = getenv('LOG_DB_TYPE') == 'mssql' ? new LoggerPdo() : new Logger();
  }


  public function dbSql($bInsert, $table, $ar, $idfield = "", $idvalue = "", $noUpdate = false, $preventEmptyStringOnNull = true)
  {
    $db = $this->dataBase;

    // Filter special keys like "now()" and null
    $ar = array_map(function ($value) use ($db) {
      $value = match ($value) {
        'now()' => "now()",
        null => "NULL",
        default => "'" . $db->real_escape_string($value) . "'",
      };
      return $value;
    }, $ar);

    // Merge $key + "=" + $value
    $mergedAr = array();
    foreach ($ar as $k => $v) {
      $mergedAr[] = "$k=$v";
    }
    $mergedValues = implode(", ", $mergedAr);

    try {
      if ($bInsert) {
        $columns = implode(", ", array_keys($ar));
        $values = implode(", ", $ar);
        $sql = "INSERT INTO $table ($columns) VALUES ($values)";
        if (!$noUpdate) {
          $sql .= " ON DUPLICATE KEY UPDATE $mergedValues";
        }
      } else {
        $sql = sprintf("UPDATE %s SET %s WHERE %s='%s'", $table, $mergedValues, $idfield, $idvalue);
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
   * @return array|boolean
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
      $ret = array(
        "data" => []
      );
      while ($rec = $db->fetchassoc()) {
        if (isset($req['decode'])) {
          foreach ($req['decode'] as $value) {
            if (!empty($rec[$value])) {
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
    if (!isset($req['table'])) {
      return false;
    }
    $table = $req['table'];
    $value = $req['value'] ?? "id";
    $text = $req['text'] ?? "text";
    $other_field = isset($req['other_field']) ? ",".$req['other_field'] : "";
    $order = $req['order'] ?? "text ASC";
    $where = $req['where'] ?? "";

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

  public function resizeAndSaveImage($imagePath, $newImagePath, $newWidth) {
      // Ottieni l'estensione del file originale
      $extension = pathinfo($imagePath, PATHINFO_EXTENSION);

      // Carica l'immagine
      if ($extension == 'jpg' || $extension == 'jpeg') {
          $image = imagecreatefromjpeg($imagePath);
      } elseif ($extension == 'png') {
          $image = imagecreatefrompng($imagePath);

          // Imposta il colore trasparente e abilita l'alpha blending
          imagealphablending($image, true);
          imagesavealpha($image, true);
      } else {
          die('Formato immagine non supportato. Utilizza un file JPG o PNG.');
      }

      // Ottieni le dimensioni attuali dell'immagine
      $width = imagesx($image);
      $height = imagesy($image);

      // Calcola l'altezza proporzionale
      $newHeight = ($height / $width) * $newWidth;

      // Crea una nuova immagine con le nuove dimensioni, considerando la trasparenza per PNG
      $newImage = imagecreatetruecolor($newWidth, $newHeight);

      if ($extension == 'png') {
          // Imposta il colore trasparente per il nuovo PNG
          $transparent = imagecolorallocatealpha($newImage, 0, 0, 0, 127);
          imagefill($newImage, 0, 0, $transparent);
          imagesavealpha($newImage, true);
      }

      // Ridimensiona l'immagine originale alle nuove dimensioni
      imagecopyresampled($newImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

      // Salva l'immagine ridimensionata nel formato corretto
      if ($extension == 'jpg' || $extension == 'jpeg') {
          imagejpeg($newImage, $newImagePath);
      } elseif ($extension == 'png') {
          imagepng($newImage, $newImagePath);
      }

      // Pulisci la memoria
      imagedestroy($image);
      imagedestroy($newImage);
  }
}

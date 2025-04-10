<?php

namespace ottimis\phplibs;

use Exception;
use JsonException;
use ottimis\phplibs\schemas\UPSERT_MODE;
use RuntimeException;

class Utils
{
    private static ?self $instance = null;
    public dataBase $dataBase;
    public LoggerPdo|Logger $Log;

    public function __construct($dbName = "", $singleton = true)
    {
        if ($singleton) {
            $this->dataBase = dataBase::getInstance($dbName);
        } else {
            $this->dataBase = dataBase::createNew($dbName);
        }
        $this->Log = getenv('LOG_DB_TYPE') === 'mssql' ? new LoggerPdo() : Logger::getInstance();
    }

    /**
     * Get the singleton instance of the class if it exists, otherwise create it
     *
     * @param string $dbName
     * @return self
     */
    public static function getInstance(string $dbName = ""): self
    {
        if (self::$instance === null) {
            self::$instance = new self($dbName);
        }

        return self::$instance;
    }

    /**
     * Create new instance of the class
     *
     * @param string $dbName
     * @return self
     */
    public static function createNew(string $dbName = ""): self
    {
        return new self($dbName, false);
    }

    public function startTransaction(): void
    {
        $this->dataBase->startTransaction();
    }

    public function commitTransaction(): void
    {
        $this->dataBase->commitTransaction();
    }

    public function rollbackTransaction(): void
    {
        $this->dataBase->rollbackTransaction();
    }

    /**
     * @deprecated Use upsert instead
     */
    public function dbSql($bInsert, $table, $ar, $idfield = "", $idvalue = "", $noUpdate = false): array
    {
        $db = $this->dataBase;

        // Filter special keys like "now()" and null
        $ar = array_map(function ($value) use ($db) {
            return match ($value) {
                'now()' => "now()",
                true => 1,
                false => 0,
                null => "NULL",
                default => "'" . $db->real_escape_string($value) . "'",
            };
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
        } catch (Exception $e) {
            $this->Log->error('Eccezione db: ' . $e->getMessage() . " Query: " . $sql, "DBSQL");
            $ret['success'] = 0;
            $ret['error'] = $e->getMessage();
            return $ret;
        }
    }

    public function upsert(UPSERT_MODE $mode, string $table, array $ar, array $fieldWhere = [], $noUpdate = false): array
    {
        $db = $this->dataBase;

        // Filter special keys like "now()" and null
        $ar = array_map(/**
         * @throws JsonException
         */ static function ($value) use ($db) {
            return match (true) { // Usare 'true' per gestire condizioni complesse
                $value === 'now()' => "now()",
                $value === true => 1,
                $value === false => 0,
                $value === null => "NULL",
                is_array($value), is_object($value) => "'" . $db->real_escape_string(json_encode($value, JSON_THROW_ON_ERROR)) . "'",
                default => "'" . $db->real_escape_string($value) . "'",
            };
        }, $ar);

        // Merge $key + "=" + $value
        $mergedAr = array();
        foreach ($ar as $k => $v) {
            $mergedAr[] = "$k=$v";
        }
        $mergedValues = implode(", ", $mergedAr);

        try {
            if ($mode === UPSERT_MODE::INSERT) {
                $columns = implode(", ", array_keys($ar));
                $values = implode(", ", $ar);
                $sql = "INSERT INTO $table ($columns) VALUES ($values)";
                if (!$noUpdate) {
                    $sql .= " ON DUPLICATE KEY UPDATE $mergedValues";
                }
            } else {
                $where = implode(" AND ", array_map(static function ($v, $k) use ($db) {
                    return "$k = '" . $db->real_escape_string($v) . "'";
                }, $fieldWhere, array_keys($fieldWhere)));
                $sql = sprintf("UPDATE %s SET %s WHERE %s", $table, $mergedValues, $where);
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
        } catch (Exception $e) {
            $this->Log->error('Eccezione db: ' . $e->getMessage() . " Query: " . $sql, "DBSQL");
            $ret['success'] = 0;
            $ret['error'] = $e->getMessage();
            return $ret;
        }
    }

    private function buildWhere($req): array
    {
        $db = $this->dataBase;
        $ar = array();
        foreach ($req as $key => $value) {
            if (isset($value)) {
                switch ($key) {
                    case 'where':
                        foreach ($value as $k => $v) {
                            if (!isset($ar[$key])) {
                                $ar[$key] = '';
                            }
                            if (isset($v['custom'])) {
                                $ar[$key] .= $v['custom'];
                                if (isset($v['operatorAfter']) || isset($value[$k + 1])) {
                                    if (isset($value[$k + 1], $v['operatorAfter'])) {
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
                            } elseif ($v['operator'] === 'BETWEEN')   {
                                $ar[$key] .= sprintf("%s BETWEEN '%s' AND '%s'", $v['field'], $db->real_escape_string($v['value'][0]), $db->real_escape_string($v['value'][1]));
                            } else {
                                $ar[$key] .= sprintf("%s %s %s '%s' %s", $v['before'] ?? "", $v['field'], $v['operator'], $db->real_escape_string($v['value']), $v['end'] ?? "");
                            }
                            if (isset($v['operatorAfter']) || isset($value[$k + 1])) {
                                if (isset($value[$k + 1], $v['operatorAfter'])) {
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
                        if (is_array($value)) {
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
     * @throws Exception
     */
    private function buildSql($req): array
    {
        $db = $this->dataBase;
        $ar = array();
        foreach ($req as $key => $value) {
            if (isset($value)) {
                switch ($key) {
                    case 'select':
                        if (!is_array($value)) {
                            throw new RuntimeException("Select must be an array");
                        }
                        if (!isset($ar[$key])) {
                            $ar[$key] = '';
                        }
                        foreach ($value as $v) {
                            if (!str_contains($v, '.') && !str_contains($v, '(')) {
                                $ar[$key] .= "{$req['from']}.$v, ";
                            } else {
                                $ar[$key] .= "$v, ";
                            }
                        }
                        $ar[$key] = substr($ar[$key], 0, -2);
                        break;
                    case 'where':
                        if (!isset($ar[$key])) {
                            $ar[$key] = '';
                        }
                        foreach ($value as $k => $v) {
                            if (!str_contains($v['field'], '.') && !str_contains($v['field'], '(')) {
                                $v['field'] = "{$req['from']}.$v[field]";
                            }

                            if (isset($v['custom'])) {
                                $ar[$key] .= $v['custom'];
                                if (isset($v['operatorAfter']) || isset($value[$k + 1])) {
                                    if (isset($value[$k + 1], $v['operatorAfter'])) {
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
                            } elseif ($v['operator'] === 'BETWEEN')   {
                                $ar[$key] .= sprintf("%s BETWEEN '%s' AND '%s'", $v['field'], $db->real_escape_string($v['value'][0]), $db->real_escape_string($v['value'][1]));
                            } else {
                                $ar[$key] .= sprintf("%s %s %s '%s' %s", $v['before'] ?? "", $v['field'], $v['operator'], $db->real_escape_string($v['value']), $v['end'] ?? "");
                            }
                            if (isset($v['operatorAfter']) || isset($value[$k + 1])) {
                                if (isset($value[$k + 1], $v['operatorAfter'])) {
                                    $ar[$key] .= sprintf(" %s ", $v['operatorAfter']);
                                } else if (isset($value[$k + 1]) && !isset($v['operatorAfter'])) {
                                    $ar[$key] .= " AND ";
                                }
                            }
                        }
                        break;
                    case 'join':
                    case 'leftJoin':
                    case 'rightJoin':
                    case 'innerJoin':
                        if (!isset($ar[$key])) {
                            $ar[$key] = '';
                        }
                        # Get join type
                        $joinType = match ($key) {
                            'join' => 'JOIN',
                            'leftJoin' => 'LEFT JOIN',
                            'rightJoin' => 'RIGHT JOIN',
                            'innerJoin' => 'INNER JOIN',
                        };
                        # Build join
                        foreach ($value as $v) {
                            $destinationField = is_array($v['on']) && !empty($v['on'][1]) ? $v['on'][1] : "id";
                            $fromField = is_array($v['on']) ? $v['on'][0] : $v['on'];
                            $table = $v['table'] . (isset($v['alias']) ? " " . $v['alias'] : "");
                            $alias = $v['alias'] ?? $v['table'];
                            $ar[$key] .= sprintf("%s %s ON %s=%s ",
                                $joinType,
                                $table,
                                (!str_contains($fromField, ".") && !str_contains($fromField, "(")) ? "{$ar['from']}.{$fromField}" : $fromField,
                                "{$alias}.$destinationField");
                            if (!empty($ar['select']))  {
                                $ar['select'] .= ", ".implode(", ", array_map(static function ($f) use ($v, $alias) {
                                        return "{$alias}.{$f}";
                                    }, $v['fields']));
                            }
                        }
                        break;
                    case 'limit':
                        $ar[$key] .= sprintf("%d, %d", $value[0], $value[1]);
                        break;

                    default:
                        if (is_array($value)) {
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
            }
        }
        return $ar;
    }

    /**
     * dbSelect
     *
     * @param mixed $req SELECT, FROM, JOIN(Array), WHERE(Array), ORDER, LIMIT, OTHER
     *
     * Example: $ar = array(
     * "select" => ["uid", "status"],
     * "from" => "pso_utenti pu",
     * "join" => [
     * [
     * "pso_status ps",
     * " ps.id=pu.idstatus"
     * ]
     * ],
     * "where" => [
     * [
     * "field" => "email",
     * "operator" => "=",
     * "value" => "mattymatty95@gmail.com",
     * "operatorAfter" => "AND"
     * ]
     * ],
     * "order" => "uid",
     * "limit" => [0, 1]
     * );
     *
     * print_r(dbSelect($ar));
     *
     * @return array|boolean
     */

    public function dbSelect($req, $paging = array(), $sqlOnly = false)
    {
        $db = $this->dataBase;
        // Pass req only for relevant keys: where, join, rightJoin, innerJoin, limit... Needed to prevent broken queries
        $ar = $this->buildWhere(
            array_intersect_key(
                $req,
                array_flip([
                    'select',
                    'from',
                    'join',
                    'rightJoin',
                    'innerJoin',
                    'where',
                    'group',
                    'order',
                    'limit',
                    'other'
                ])
            )
        );

        if (sizeof($paging) > 0) {
            $ar = $this->buildPaging($ar, $paging);
        }

        $ctes = [];
        if (isset($req['cte'])) {
            foreach ($req['cte'] as $v) {
                $ctePaging = empty($v['paging']) ? [] : $v['paging'];
                $ctePaging['noTotal'] = true;
                $ctes[] = [
                    "name" => $v['name'],
                    "sql" => $this->dbSelect($v, $ctePaging, true),
                ];
            }
        }

        if (isset($req['select'])) {
            $sql = sprintf(
                "%s SELECT %s FROM %s %s %s %s %s %s %s %s %s",
                !empty($ctes) ? implode(", ", array_map(function ($v) {
                    return "WITH " . $v['name'] . " AS (" . $v['sql'] . ")";
                }, $ctes)) : "",
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
                isset($ar['other']) ? $ar['other'] : ''
            );
        }

        if (isset($req['log']) && $req['log']) {
            $this->Log->log("Query: " . $sql, "DBSLC1");
        }

        if ($sqlOnly) {
            return $sql;
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

    /**
     * @throws JsonException
     * @throws Exception
     */
    public function select($req, $paging = array(), $sqlOnly = false): array|string
    {
        $db = $this->dataBase;
        // Pass req only for relevant keys: where, join, rightJoin, innerJoin, limit... Needed to prevent broken queries
        $ar = $this->buildSql(
            array_intersect_key(
                $req,
                array_flip([
                    'select',
                    'from',
                    'join',
                    'leftJoin',
                    'rightJoin',
                    'innerJoin',
                    'where',
                    'group',
                    'order',
                    'limit',
                    'other'
                ])
            )
        );

        if (sizeof($paging) > 0) {
            $ar = $this->buildPagingV2($ar, $paging);
        }

        $ctes = [];
        if (isset($req['cte'])) {
            foreach ($req['cte'] as $v) {
                $ctePaging = empty($v['paging']) ? [] : $v['paging'];
                $ctePaging['noTotal'] = true;
                $ctes[] = [
                    "name" => $v['name'],
                    "sql" => $this->dbSelect($v, $ctePaging, true),
                ];
            }
        }

        $sql = "";
        if (isset($req['select'])) {
            $sql = sprintf(
                "%s SELECT %s %s FROM %s %s %s %s %s %s %s %s %s",
                !empty($ctes) ? implode(", ", array_map(static function ($v) {
                    return "WITH " . $v['name'] . " AS (" . $v['sql'] . ")";
                }, $ctes)) : "",
                !empty($req['distinct']) ? 'DISTINCT' : '',
                $ar['select'],
                $ar['from'],
                $ar['join'] ?? '',
                $ar['rightJoin'] ?? '',
                $ar['innerJoin'] ?? '',
                !empty($ar['where']) ? "WHERE " . $ar['where'] : '',
                !empty($ar['group']) ? "GROUP BY " . $ar['group'] : '',
                !empty($ar['order']) ? "ORDER BY " . $ar['order'] : '',
                !empty($ar['limit']) ? "LIMIT " . $ar['limit'] : '',
                $ar['other'] ?? ''
            );
        } elseif (isset($req['delete'])) {
            $sql = sprintf(
                "DELETE %s FROM %s %s %s %s",
                is_string($req['delete']) ? $req['delete'] : '',
                $ar['from'],
                $ar['join'] ?? '',
                !empty($ar['where']) ? "WHERE " . $ar['where'] : '',
                $ar['other'] ?? ''
            );
        }

        if (isset($req['log']) && $req['log']) {
            $this->Log->log("Query: " . $sql, "DBSLC1");
        }

        if ($sqlOnly) {
            return $sql;
        }

        $res = $db->query($sql);
        if ($res) {
            if (isset($req['delete'])) {
                return [
                    "success" => true
                ];
            }

            $ret = [
                "data" => []
            ];
            // First take all records
            $records = [];
            while ($rec = $db->fetchassoc()) {
                $records[] = $rec;
            }
            // Then process them. -> This allows us to have nested queries
            foreach ($records as $rec)    {
                if (isset($req['decode'])) {
                    foreach ($req['decode'] as $value) {
                        if (!empty($rec[$value])) {
                            $rec[$value] = json_decode($rec[$value], true, 512, JSON_THROW_ON_ERROR);
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
            if (isset($req['count']) || count($paging) > 0) {
                $db->query("SELECT FOUND_ROWS()");
                $ret['total'] = (int)$db->fetcharray()[0];
                $ret['count'] = count($ret['data']);
                $ret['rows'] = $ret['data'];
                unset($ret['data']);
            }
            $db->freeresult();
            $ret['success'] = true;
            return $ret;
        }

        $this->Log->warning('Errore query: ' . $sql . "\r\n DB message: " . $db->error(), "DBSLC2");
        $db->freeresult();
        return [
            "success" => false,
            "error" => $db->error()
        ];
    }

    private function buildPaging($ar, $paging)
    {
        // Foreach filterable fields, add to where with and condition and =
        if (!empty($paging['filterableFields']))    {
            $filterWhere = array();
            foreach ($paging['filterableFields'] as $v) {
                if (isset($paging[$v])) {
                    $filterWhere[] = sprintf("$v = '%s'", $this->dataBase->real_escape_string($paging[$v]));
                }
            }
            if (!empty($filterWhere)) {
                $stringFilter = implode(" AND ", $filterWhere);
                if (isset($ar['where'])) {
                    $ar['where'] .= " AND ($stringFilter)";
                } else {
                    $ar['where'] = "($stringFilter)";
                }
            }
        }
        if (isset($paging['s'], $paging['searchableFields']) && strlen($paging['s']) > 1) {
            $searchWhere = array();
            foreach ($paging['searchableFields'] as $v) {
                $searchWhere[] = sprintf("$v like '%%%s%%'", $this->dataBase->real_escape_string($paging['s']));
            }
            $stringSearch = implode(" OR ", $searchWhere);
            if (isset($ar['where'])) {
                $ar['where'] .= " AND ($stringSearch)";
            } else {
                $ar['where'] = "($stringSearch)";
            }
        }
        if (isset($paging['srt'], $paging['o'])) {
            $ar["order"] = $paging['srt'] . " " . $paging['o'];
        }
        if (isset($paging['p'], $paging['c'])) {
            $count = $paging['c'] !== "" ? ($paging['c']) : 20;
            $start = $paging['p'] !== "" ? ($paging['p'] - 1) * $count : 0;
            $ar["limit"] = "$start, $count";
        }
        if (empty($paging['noTotal'])) {
            $ar["select"] = "SQL_CALC_FOUND_ROWS " . $ar["select"];
        }
        return $ar;
    }

    private function buildPagingV2($ar, $paging)
    {
        // Foreach filterable fields, add to where with and condition and =
        if (!empty($paging['filterableFields']))    {
            $filterWhere = array();
            foreach ($paging['filterableFields'] as $v) {
                $vv = !str_contains($v, '.') ? "{$ar['from']}.$v" : $v;

                if (isset($paging[$v])) {
                    $filterWhere[] = sprintf("$vv = '%s'", $this->dataBase->real_escape_string($paging[$v]));
                }
            }
            if (!empty($filterWhere)) {
                $stringFilter = implode(" AND ", $filterWhere);
                if (isset($ar['where'])) {
                    $ar['where'] .= " AND ($stringFilter)";
                } else {
                    $ar['where'] = "($stringFilter)";
                }
            }
        }
        if (isset($paging['s'], $paging['searchableFields']) && strlen($paging['s']) > 1) {
            $searchWhere = array();
            foreach ($paging['searchableFields'] as $k => $v) {
                $v = !str_contains($v, '.') ? "{$ar['from']}.$v" : $v;
                $searchWhere[] = sprintf("$v like '%%%s%%'", $this->dataBase->real_escape_string($paging['s']));
            }
            $stringSearch = implode(" OR ", $searchWhere);
            if (isset($ar['where'])) {
                $ar['where'] .= " AND ($stringSearch)";
            } else {
                $ar['where'] = "($stringSearch)";
            }
        }
        if (isset($paging['srt'], $paging['o'])) {
            if (!str_contains($paging['srt'], '.')) {
                $paging['srt'] = "{$ar['from']}.$paging[srt]";
            }
            $ar["order"] = $paging['srt'] . " " . $paging['o'];
        }
        if (isset($paging['p'], $paging['c'])) {
            $count = $paging['c'] !== "" ? ($paging['c']) : 20;
            $start = $paging['p'] !== "" ? ($paging['p'] - 1) * $count : 0;
            $ar["limit"] = "$start, $count";
        }
        if (empty($paging['noTotal'])) {
            $ar["select"] = "SQL_CALC_FOUND_ROWS " . $ar["select"];
        }
        return $ar;
    }

    public function _combo_list($req, $where = "", $log = false): false|array
    {
        if (!isset($req['table'])) {
            return false;
        }
        $table = $req['table'];
        $value = $req['value'] ?? "id";
        $text = $req['text'] ?? "text";
        $other_field = isset($req['other_field']) ? "," . $req['other_field'] : "";
        $order = $req['order'] ?? "text ASC";
        $where = $req['where'] ?? "";

        if ($where !== "") {
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

    public function resizeAndSaveImage($imagePath, $newImagePath, $newWidth): void
    {
        // Ottieni l'estensione del file originale
        $extension = pathinfo($imagePath, PATHINFO_EXTENSION);

        // Carica l'immagine
        if ($extension === 'jpg' || $extension === 'jpeg') {
            $image = imagecreatefromjpeg($imagePath);
        } elseif ($extension === 'png') {
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

        if ($extension === 'png') {
            // Imposta il colore trasparente per il nuovo PNG
            $transparent = imagecolorallocatealpha($newImage, 0, 0, 0, 127);
            imagefill($newImage, 0, 0, $transparent);
            imagesavealpha($newImage, true);
        }

        // Ridimensiona l'immagine originale alle nuove dimensioni
        imagecopyresampled($newImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        // Salva l'immagine ridimensionata nel formato corretto
        if ($extension === 'jpg' || $extension === 'jpeg') {
            imagejpeg($newImage, $newImagePath);
        } elseif ($extension === 'png') {
            imagepng($newImage, $newImagePath);
        }

        // Pulisci la memoria
        imagedestroy($image);
        imagedestroy($newImage);
    }


    /**
     * @param $app
     * @param string $errorMessage
     * @return void
     * @throws Exception
     * Function to handle errors in Slim
     * IMPORTANT: Remember to add $app->addRoutingMiddleware(); after $app = AppFactory::create();
     */
    public static function slimErrorHandler($app, string $errorMessage = "Si è verificato un errore."): void
    {
        /*** ERROR HANDLER */

        // Define Custom Error Handler
        $customErrorHandler = function (
            $request,
            $exception,
            $displayErrorDetails,
            $logErrors,
            $logErrorDetails,
            $logger = null
        ) use ($app, $errorMessage) {
            if ($exception instanceof \Slim\Exception\HttpNotFoundException) {
                $response = $app->getResponseFactory()->createResponse();
                $response->getBody()->write(file_get_contents(__DIR__ . "/404/1.html"));
                return $response
                    ->withStatus(404)
                    ->withHeader('Content-Type', 'text/html');
            }

            $logData = [
                "id" => uniqid('', false),
                "message" => $exception->getMessage(),
                "file" => $exception->getFile(),
                "line" => $exception->getLine(),
                "RequestURI" => $request->getUri()->getPath(),
                "RequestMethod" => $request->getMethod(),
                "RequestParams" => $request->getBody(),
                "QueryParams" => $request->getQueryParams(),
            ];

            try {
                $Logger = Logger::getInstance();
                $Logger->error("Exception " . $logData['id'] . " Message: " . $logData['message'], "SLIM_ERROR", $logData);
            } catch (Exception $e) {
                Notify::notify("Error in logging: " . $e->getMessage());
                error_log("Error in logging: " . $e->getMessage());
            }

            error_log(json_encode($logData, JSON_THROW_ON_ERROR), 0);

            $response = $app->getResponseFactory()->createResponse();
            if (empty($logData['QueryParams']['debug'])) {
                $response->getBody()->write($errorMessage);
            } else {
                $response->getBody()->write($exception->getMessage());
            }

            return $response->withStatus(500);
        };

        // Add Error Handling Middleware
        $errorMiddleware = $app->addErrorMiddleware(true, true, true);
        $errorMiddleware->setDefaultErrorHandler($customErrorHandler);

        /** FINE ERROR HANDLER */
    }

    /**
     * Generate a Swagger page with configurable parameters
     *
     * @param string $jsonEndpoint The endpoint where the Swagger JSON file is served
     * @param string $title Optional title for the Swagger UI
     * @return string HTML content for the Swagger UI
     */
    public static function getSwaggerPage(string $jsonEndpoint, string $title = 'API Documentation'): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="it">
<head>
    <title>{$title}</title>
    <link rel="stylesheet" type="text/css" href="https://cdnjs.cloudflare.com/ajax/libs/swagger-ui/5.18.2/swagger-ui.css" />
    <style>
    html {
        box-sizing: border-box;
        overflow: -moz-scrollbars-vertical;
        overflow-y: scroll;
    }
    
    *,
    *:before,
    *:after {
        box-sizing: inherit;
    }
    
    body {
        margin: 0;
        background: #fafafa;
    }
</style>
</head>
<body>
    <div id="swagger-ui"></div>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/swagger-ui/5.18.2/swagger-ui-bundle.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/swagger-ui/5.18.2/swagger-ui-standalone-preset.js"></script>
    <script>
        window.onload = function() {
            const ui = SwaggerUIBundle({
                url: '{$jsonEndpoint}',
                dom_id: '#swagger-ui',
                deepLinking: true,
                presets: [
                    SwaggerUIBundle.presets.apis,
                    SwaggerUIStandalonePreset
                ],
                plugins: [
                    SwaggerUIBundle.plugins.DownloadUrl
                ],
                layout: 'StandaloneLayout',
            });
        };
    </script>
</body>
</html>
HTML;
    }

    /**
     * Prevent the instance from being cloned
     */
    private function __clone() {}

    /**
     * Prevent from being unserialized
     * @throws Exception
     */
    public function __wakeup()
    {
        throw new RuntimeException("Cannot unserialize a singleton.");
    }
}

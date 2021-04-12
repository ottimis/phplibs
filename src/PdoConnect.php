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
        protected $result= false;
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
    
            $this->conn = new \PDO("sqlsrv:Server=$this->host,$this->port;Database=$this->database", "$this->user", "$this->password");
            if ($error) {
                $this->conn->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            }
            $this->debug = $error;
            return $this->conn;
        }
        // se error_reporting attivato riporto errore
        public function error()
        {
            return $this->conn->errorInfo();
        }
        public function startTransaction()
        {
            $this->transaction = $this->conn->beginTransaction();
            return $this->transaction;
        }
        public function commitTransaction()
        {
            $this->transaction = !$this->conn->commit();
            return $this->transaction;
        }
        public function rollBackTransaction()
        {
            $this->transaction = !$this->conn->rollBack();
            return $this->transaction;
        }
        // gruppo funzioni interrogazione
        public function query($sql)
        {
            $this->result = $this->conn->query($sql);
            return $this->result;
        }
        public function fetchassoc()
        {
            return $this->result->fetch();
        }
        public function fetchAll()
        {
            return $this->result->fetchAll(\PDO::FETCH_ASSOC);
        }
        public function prepare($sql)
        {
            $this->result = $this->conn->prepare($sql);
            return $this->result;
        }
        public function bindValue($field, $value)
        {
            $this->result->bindValue($field, $value);
            return $this->result;
        }
        public function execute($ar)
        {
            $this->result->execute($ar);
            return $this->result;
        }
        public function debugDumpParams()
        {
            return $this->result->debugDumpParams();
        }
        public function affectedRows()
        {
            return $this->result->rowCount();
        }
        public function insert_id()
        {
            return $this->conn->lastInsertId();
        }

        // START: Utils functions

        /**
         * getAll
         *
         * @param  string $table
         * @return void
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
            return $rec['data'][0];
        }

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

        public function insertRow($table, $ar = array())
        {
            $res = $this->dbSql(true, $table, $ar);
            return $res;
        }

        public function updateRow($table, $ar, $field = "", $value = "")
        {
            $res = $this->dbSql(false, $table, $ar, $field, $value);
            return $res;
        }

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
                        $z .= ($z != "") ? ", ":"";
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
                    // $log = new Logger();
                    // $log->error('Errore inseriemnto: ' . $this->error() . " Query: " . $sql, "DBSQL");
                    $ret['success'] = 0;
                    $ret['err'] = $errors;
                }
                return $ret;
            } catch (\Exception $e) {
                // $log = new Logger();
                // $log->error('Eccezione db: ' . $e->getMessage(), "DBSQL");
                $ret['success'] = 0;
                $ret['err'] = $e->getMessage();
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
         * @return Array
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
                $log = new LoggerPdo(DEBUG, DEBUG);
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
                    $countSelect = sprintf("SELECT COUNT(*) FROM %s", $ar['from']);
                    $this->query($countSelect);
                    $ret['total'] = intval($this->fetchassoc()[0]);
                    $ret['count'] = sizeof($ret['data']);
                    $ret['rows'] = $ret['data'];
                    unset($ret['data']);
                }
                return $ret;
            } else {
                $log = new LoggerPdo(DEBUG, DEBUG);
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
                    $ar['where'] .= "AND ($stringSearch)";
                } else {
                    $ar['where'] = "($stringSearch)";
                }
            }
            if (isset($paging['srt']) && isset($paging['o'])) {
                $ar["order"] = $paging['srt'] . " " . $paging['o'];
            }
            if (isset($paging['p']) && isset($paging['c'])) {
                $count = $paging['c'] != "" ? ($paging['c']) : 20;
                $start = $paging['p'] != "" ? ($paging['p']-1) * $count : 0;
                $ar["limit"] = "OFFSET $start ROWS FETCH NEXT $count ROWS ONLY";
            }
            return array("sql" => $ar, "params" => $params);
        }
    }

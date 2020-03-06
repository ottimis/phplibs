<?php

namespace ottimis\phplibs;

    class Pdo
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
            return $this->conn;
        }
        // se error_reporting attivato riporto errore
        public function error()
        {
            return $this->conn->errorInfo();
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
        public function fetchall()
        {
            return $this->result->fetchAll();
        }
        public function prepare($sql)
        {
            $this->result = $this->conn->prepare($sql);
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
            } catch (Exception $e) {
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
                            foreach ($value as $k => $v) {
                                if (!isset($ar[$key])) {
                                    $ar[$key] = '';
                                }
                                $subFieldPos = strrpos($v['field'], ".");
                                if ($subFieldPos !== false) {
                                    $v['bindField'] = substr($v['field'], $subFieldPos + 1);
                                } else {
                                    $v['bindField'] = $v['field'];
                                }
                                if (!isset($v['operator'])) {
                                    $ar[$key] .= sprintf("%s = :%s", $v['field'], $v['bindField']);
                                    $params[$v['bindField']] = $v['value'];
                                } elseif ($v['operator'] === 'IN') {
                                    if (sizeof($v['value']) > 0) {
                                        $inValues = array();
                                        foreach ($v['value'] as $kIN => $vIN) {
                                            $inValues[] = ":in$kIN";
                                            $params["in$kIN"] = $vIN;
                                        }
                                        $ar[$key] .= sprintf("%s IN(%s)", $v['field'], implode(',', $inValues));
                                    }
                                } else {
                                    $ar[$key] .= sprintf("%s %s :%s", $v['field'], $v['operator'], $v['bindField']);
                                    $params[$v['bindField']] = $v['value'];
                                }
                                if (isset($v['operatorAfter'])) {
                                    if (isset($value[$k + 1])) {
                                        $ar[$key] .= sprintf(" %s ", $v['operatorAfter']);
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
                        case 'limit':
                            if (!isset($ar[$key])) {
                                $ar[$key] = '';
                            }
                            $ar[$key] .= sprintf("%s %d", $value[0], $value[1]);
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
                    "SELECT %s %s FROM %s %s %s %s %s %s",
                    isset($ar['limit']) ? $ar['limit'] : '',
                    $ar['select'],
                    $ar['from'],
                    isset($ar['join']) ? $ar['join'] : '',
                    sizeof($ar['where']) > 0 ? "WHERE " . $ar['where'] : '',
                    isset($ar['order']) ? "ORDER BY " . $ar['order'] : '',
                    isset($ar['pageLimit']) ? $ar['pageLimit'] : '',
                    isset($ar['other']) ? $ar['other'] : ''
                );
            } elseif (isset($req['delete'])) {
                $sql = sprintf(
                    "DELETE FROM %s WHERE %s %s",
                    $ar['from'],
                    isset($ar['where']) ? "WHERE " . $ar['where'] : '',
                    $ar['other']
                );
            }
            
            if (isset($req['log'])) {
                // $log = new Logger();
                // $log->log("Query: " . $sql, "DBSLC1");
                echo "Query: " . $sql;
                print_r($params);
            }
            $this->prepare($sql);
            $this->execute($params);
            $errors = $this->error();
            if (intval($errors[0]) === 0) {
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
                // $log = new Logger();
                // $log->warning('Errore query: ' . $sql . "\r\n DB message: " . $db->error(), "DBSLC2");
                // $db->freeresult();
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
                $ar["pageLimit"] = "OFFSET $start ROWS FETCH NEXT $count ROWS ONLY";
            }
            return array("sql" => $ar, "params" => $params);
        }
    }

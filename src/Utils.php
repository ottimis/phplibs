<?php

namespace ottimis\phplibs;

	class Utils	{

		public $dataBase;
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

		function __construct()	{
			$this->dataBase = new dataBase();
		}


		function dbSql( $bInsert, $table, $ar, $idfield = "", $idvalue = "", $noUpdate = false ) {

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
						$z .= ($z != "") ? ",":"";
						if ($v !== "now()") {
							$z .= $k . "='" . $db->real_escape_string($v) . "'";
						} else {
							$z .= $k . "=now()";
						}
					}
					$sql = sprintf("UPDATE %s SET %s WHERE %s='%s'", $table, $z, $idfield, $idvalue);
				}

				$ret['sql'] = $sql;
				$r = $db->query( $sql );

				if( !$r ) {
					$log = new Logger();
			        $log->error('Errore inseriemnto: ' . $db->error() . " Query: " . $sql, "DBSQL");
					$ret['success'] = 0;
				} else {
					$ret['affectedRows'] = $db->affectedRows();
					$ret['id'] = $db->insert_id();
					$ret['success'] = 1;
				}
				return $ret;
			} catch( Exception $e ) {
				$log = new Logger();
		        $log->error('Eccezione db: ' . $e->getMessage(), "DBSQL");
				$ret['success'] = 0;
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

        function dbSelect( $req, $paging = array() )
        {
			$db = $this->dataBase;
			$ar = array();

            foreach ($req as $key => $value) {
                if (isset($req[$key])) {
                    switch ($key) {
						case 'where':
							foreach ($value as $k => $v) {
								if (!isset($ar[$key]))	{
									$ar[$key] = '';
								}
								if (!isset($v['operator'])) {
									$ar[$key] .= sprintf("%s='%s'", $v['field'], $db->real_escape_string($v['value']));
								} else if ($v['operator'] === 'IN')	{
									$inValues = array();
									foreach ($v['value'] as $kIN => $vIN) {
										$inValues[] = "'" . $db->real_escape_string($vIN) . "'";
									}
									$ar[$key] .= sprintf("%s IN(%s)", $v['field'], implode(',', $inValues));
								} else {
									$ar[$key] .= sprintf("%s%s'%s'", $v['field'], $v['operator'], $db->real_escape_string($v['value']));
								}
								if (isset($v['operatorAfter']))	{
									if (isset($value[$k + 1]))
										$ar[$key] .= sprintf(" %s ",$v['operatorAfter']);
								}
							}
							break;
						case 'join':
							if (!isset($ar[$key]))	{
								$ar[$key] = '';
							}
                            foreach ($value as $v) {
								$ar[$key] .= sprintf("LEFT JOIN %s ON %s ", $v[0], $v[1]);
                            }
                            break;
						case 'limit':
							if (!isset($ar[$key]))	{
								$ar[$key] = '';
							}
							$ar[$key] .= sprintf("%d, %d", $value[0], $value[1]);
                            break;

                        default:
                            if (gettype($value) == 'array') {
								if (!isset($ar[$key]))	{
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
			
			if (sizeof($paging) > 0)    {
                $res = $this->buildPaging($ar, $paging, $params);
                $ar = $res['sql'];
                $params = $res['params'];
            }

            if (isset($req['select'])) {
                $sql = sprintf(
                    "SELECT %s %s FROM %s %s %s %s %s %s",
					isset($req['count']) ? "SQL_CALC_FOUND_ROWS " : '',
                    $ar['select'],
                    $ar['from'],
                    isset($ar['join']) ? $ar['join'] : '',
                    isset($ar['where']) ? "WHERE " . $ar['where'] : '',
                    isset($ar['order']) ? "ORDER BY " . $ar['order'] : '',
                    isset($ar['limit']) ? "LIMIT " . $ar['limit'] : '',
                    isset($ar['other']) ? $ar['other'] : ''
                );
            }	else if(isset($req['delete']))	{
				$sql = sprintf("DELETE FROM %s WHERE %s %s",
						$ar['from'],
						isset($ar['where']) ? "WHERE " . $ar['where'] : '',
						$ar['other']);
			}
			
			if (isset($req['log']))	{
				$log = new Logger();
                $log->log("Query: " . $sql, "DBSLC1");
			}

			$res = $db->query($sql);
			if ($res)	{
				$ret = array();
				while ($rec = $db->fetchassoc()) {
					$ret['data'][] = $rec;
				}
				if (isset($req['count']))	{
					$db->query("SELECT FOUND_ROWS()");
                    $ret['total'] = intval($db->fetcharray()[0]);
                    $ret['count'] = sizeof($ret['data']);
                    $ret['rows'] = $ret['data'];
                    unset($ret['data']);
				}
				$db->freeresult();
				return $ret;
			} else {
				$log = new Logger();
                $log->warning('Errore query: ' . $sql . "\r\n DB message: " . $db->error(), "DBSLC2");
				$db->freeresult();
				return false;
			}
		}
		
		private function buildPaging( $ar, $paging, $params )  {
            if (isset($paging['s']) && strlen($paging['s']) > 1 && isset($paging['searchField']))    {
                $searchWhere = array();
                foreach ($paging['searchField'] as $k => $v)  {
                    $searchWhere[] = "$v like :s$k";
                    $params["s$k"] = "%" . $paging['s'] . "%";

                }
                $stringSearch = "(" . implode(' OR ', $searchWhere) . ")";
                if (isset($ar['where']))    {
                    $ar['where'] .= "AND ($stringSearch)";
                } else {
                    $ar['where'] = "WHERE ($stringSearch)";
                }
            }
            if (isset($paging['srt']) && isset($paging['o']))    {
                $ar["order"] = $paging['srt'] . " " . $paging['o'];
            }
            if (isset( $paging['p'] ) && isset( $paging['c'] ) ) {
                $count = $paging['c'] != "" ? ( $paging['c'] ) : 20;
                $start = $paging['p'] != "" ? ( $paging['p']-1 ) * $count : 0;
                $ar["limit"] = [$start, $count];
            }
            return array("sql" => $ar, "params" => $params);
        }

		function _combo_list( $req, $where = "", $log = false ) {
			$table = $req['table'];
			$value = $req['value'];
			$text = $req['text'];
			$order = $req['order'];
			$where = $ret_arr ? $where : $req['where'];
			
			if( $where != "" )
				$where = " WHERE " . $where;
			
			$sql = sprintf( "SELECT %s as id, %s as text
					FROM %s
					%s
					ORDER BY %s",
					$value,
					$text,
					$table,
					$where,
					$order );
			
			if ($log)	{
				$log = new Logger();
                $log->log('Query: ' . $sql, "CL");
			}

			$db = $this->dataBase;
			$res = $db->query( $sql );
			$ar = array();
			while( $rec = $db->fetchassoc() ) {
				$ar[] = $rec;
			}
			return $ar;
		}
	}
?>

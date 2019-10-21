<?php

namespace ottimis\phplibs;

	class Utils	{

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


		function dbSql( $bInsert, $table, $ar, $idfield, $idvalue ) {

			$db = new dataBase();
			$utils = new Utils();

			try {
				if( $bInsert ) {
					$columns = implode( ", ",array_keys($ar) );
					//$escaped_values = array_map( 'mysql_real_escape_string', array_values($ar) );
					//foreach( $ar as $k=>$v ) {

					//$escaped_values = array_map(array($db, 'real_escape_string'), $row);
					foreach( $ar as $k ) {
						// logme( $k );
						
						$values .= $values != '' ? "," : "";
						if( $k != 'now()' )
							$values .= "'" . $db->real_escape_string( $k ) . "'";
						else
							$values .= $db->real_escape_string( $k );
					}


					foreach( $ar as $k => $v ) {
						//echo "<br>$k - $v";
						$z .= $z != '' ? "," : "";
						//logme( $k . ' - ' . $v );
						if( $v !== "now()" )
							$z .= $k . "='" . $db->real_escape_string($v) . "'";
						else {
							$z .= $k . "=now()";
							// logme('=now()');
						}
					}

					//echo $z;
					$sql = "INSERT INTO $table ($columns) VALUES ($values) ON DUPLICATE KEY UPDATE $z";


				} else {
					$z = "";
					foreach( $ar as $k => $v ) {
						$z .= ($z != "" ) ? ",":"";
						if( $v != 'now()' )
							$z .= $k . "='" . $db->real_escape_string($v) . "'";
						else
							$z .= $k . "=" . $db->real_escape_string($v) . "";

					}
					$sql = sprintf( "UPDATE %s SET %s WHERE %s='%s'", $table, $z, $idfield, $idvalue );
				}

				$ret['sql'] = $sql;
				$r = $db->query( $sql );

				if( !$r ) {
					$this->logme( "!!! Errore --> " . $db->error(), true );
					$ret['success'] = 0;
				} else {
					$ret['id'] = $db->insert_id();
					$ret['success'] = 1;
				}
				$db->freeresult();
                $db->close();
				return $ret;
			} catch( Exception $e ) {
				$this->logme("Eccezione db --> " . $e->getMessage(), true);
				$ret['success'] = 0;
				$db->freeresult();
                $db->close();
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

        function dbSelect($req)
        {
            $db = new dataBase();

            foreach ($req as $key => $value) {
                if (isset($req[$key])) {
                    switch ($key) {
						case 'where':
							foreach ($value as $v) {
								if (!isset($v['operator'])) {
									$ar[$key] .= sprintf("%s='%s'", $v['field'], $db->real_escape_string($v['value']));
								} else {
									$ar[$key] .= sprintf("%s%s'%s'", $v['field'], $v['operator'], $db->real_escape_string($v['value']));
								}
								if (isset($v['operatorAfter']))	{
									if (isset($value[$key + 1]))
										$ar[$key] .= sprintf(" %s ",$v['operatorAfter']);
								}
							}
							break;
                        case 'join':
                            foreach ($value as $v) {
                                $ar[$key] .= sprintf("LEFT JOIN %s ON %s \r", $v[0], $v[1]);
                            }
                            break;
                        case 'limit':
                            $ar[$key] .= sprintf("%d, %d", $value[0], $value[1]);
                            break;

                        default:
                            if (gettype($value) == 'array') {
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

            if (isset($req['select'])) {
                $sql = sprintf(
                    "SELECT %s %s
						FROM %s
						%s
						%s
						%s
						%s %s",
					isset($req['count']) ? "SQL_CALC_FOUND_ROWS " : '',
                    $ar['select'],
                    $ar['from'],
                    $ar['join'],
                    isset($ar['where']) ? "WHERE " . $ar['where'] : '',
                    isset($ar['order']) ? "ORDER BY " . $ar['order'] : '',
                    isset($ar['limit']) ? "LIMIT " . $ar['limit'] : '',
                    $ar['other']
                );
            }	else if(isset($req['delete']))	{
				$sql = sprintf("DELETE
						FROM %s
						WHERE %s
						%s",
						$ar['from'],
						isset($ar['where']) ? "WHERE " . $ar['where'] : '',
						$ar['other']);
			}
			
			if (isset($req['log']))
				$this->logme($sql);

			$res = $db->query($sql);
			if ($res)	{
				$ret = array();
				while ($rec = $db->fetchassoc()) {
					$ret['data'][] = $rec;
				}
				if ($req['count'])	{
					$db->query("SELECT FOUND_ROWS()");
                    $ret['total'] = intval($db->fetcharray()[0]);
                    $ret['count'] = sizeof($ret['data']);
                    $ret['rows'] = $ret['data'];
                    unset($ret['data']);
				}
				$db->freeresult();
                $db->close();
				if (sizeof($ret['data']) == 1)
					return $ret['data'][0];
				else
					return $ret;
			} else {
				$this->logme("SQL --> " . $sql, true);
				$this->logme($db->error(), true);
				$db->freeresult();
                $db->close();
				return false;
			}

        }

		function outSend( $errorCode, $data = "", $error = "", $num_check = true ) {
			header("HTTP/1.1 " . $errorCode . ' ' . $this->httpCodes[$errorCode]);
			if( $error != "" )
				$ret['err'] = $error;
			if( $data != "" )
				$ret['data'] = $data;
			if ($num_check)
				echo utf8_encode( json_encode( $ret, JSON_NUMERIC_CHECK ) );
			else
				echo utf8_encode( json_encode( $ret ) );
			exit;
		}

		/**
		 * logme
		 *
		 * @param  string $s
		 * @param  boolean $berror
		 *
		 * @return void
		 */
		function logme( $s, $berror = false ) {
			
			$dt = date( "Ymd", time() );
			$tm = date( "H:i:s", time() );

			if (!$berror)
				$sFile = sprintf( "logs/%s.txt", $dt );
			else
				$sFile = sprintf("logs/%s_error.txt", $dt);
			file_put_contents( $sFile, $tm . " - " . $s . "\r\n", FILE_APPEND ); 
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
			
			if ($log)	
				$this->logme( $sql );

			$db = new dataBase();
			$res = $db->query( $sql );
			$ar = array();
			while( $rec = $db->fetchassoc() ) {
				$ar[] = $rec;
			}
			return $ar;
		}

		/**
		 * getParams
		 *
		 * @param  boolean $debug
		 *
		 * @return void
		 */
		function getParams($debug = false)	{
			$data = json_decode(file_get_contents("php://input"), true);
			$req = $_POST;
			$req = array_merge($req, $_GET);

			if ($data != null) {
				$req = array_merge($req, $data);
			}
			if ($debug)	{
				$this->logme("getParams --> " . json_encode($req));
			}
			return $req;
		}
	}
?>

<?php

namespace ottimis\phplibs;

	class utils	{

		function dbSql( $bInsert, $table, $ar, $idfield, $idvalue ) {

			$db = new dataBase();

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


			// logme( $sql );
			$ret['sql'] = $sql;
			$r = $db->query( $sql );
		//	logme( "errore " . $db->error() );

			if( !$r ) {
				//echo 'errore ' . $db->error();
				logme( "!!! Errore" );
				$ret['error'] = $db->error();
				$ret['success'] = 0;
			} else {
				logme( "OK" );
				$ret['id'] = $db->insert_id();
				$ret['success'] = 1;
			}
			return $ret;
			} catch( Exception $e ) {
				$ret['error'] = $e->getMessage();
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
									$ar[$key] .= sprintf(" %s ",$v['operatorAfter']);
								}
							}
							break;
                        case 'join':
                            foreach ($value as $v) {
                                $ar[$key] .= sprintf("LEFT JOIN %s ON %s", $v[0], $v[1]);
                            }
                            break;
                        case 'limit':
                            $ar[$key] .= sprintf("LIMIT %d, %d", $value[0], $value[1]);
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
            
            if ($ar['order'] == '') {
                $ar['order'] = (gettype($req['select']) == 'array') ? $req['select'][0] : $req['select'];
            }

            $sql = sprintf("SELECT %s
					FROM %s
					%s
					WHERE %s
					ORDER BY %s
					%s %s", $ar['select'], $ar['from'], $ar['join'], $ar['where'], $ar['order'], $ar['limit'], $ar['other']);
            echo $sql;
            $db->query($sql);
            while ($rec = $db->fetchassoc()) {
                $ret[] = $rec;
            }

            return $ret;
        }

		function outSend( $data, $success, $error = "", $num_check = true ) {
			$ret['success'] = $success;
			if( $error != "" )
				$ret['err'] = $error;
			$ret['data'] = $data;
			if ($num_check)
				echo utf8_encode( json_encode( $ret, JSON_NUMERIC_CHECK ) );
			else
				echo utf8_encode( json_encode( $ret ) );
		}

		// funzioni log
		function logme( $s ) {
			
			$dt = date( "Ymd", time() );
			$tm = date( "H:i:s", time() );

			$sFile = sprintf( "logs/%s.txt", $dt );
			file_put_contents( $sFile, $tm . " - " . $s . "\r\n", FILE_APPEND ); 
		}

		function _combo_list( $req, $ret_arr = false, $where = "" ) {
			$table = $req['table'];
			$value = $req['value'];
			$text = $req['text'];
			$order = $req['order'];
			$where = $ret_arr ? $where : $req['where'];
			
			if( $where != "" )
				$where = " WHERE " . $where;
			
			switch( $table ) {
				case "aifa_province":
					$sql = sprintf( "SELECT %s as id, %s as text, sigla
							FROM %s
							%s
							ORDER BY %s",
							$value,
							$text,
							$table,
							$where,
							$order );
					break;
				default:
					$sql = sprintf( "SELECT %s as id, %s as text
							FROM %s
							%s
							ORDER BY %s",
							$value,
							$text,
							$table,
							$where,
							$order );
					break;
			}
			
			//echo $sql;
			
			$this->logme( $sql );

			$db = new dataBase();
			$res = $db->query( $sql );
			$ar = array();
			while( $rec = $db->fetchassoc() ) {
				$ar[] = $rec;
			}

			if( $ret_arr )
				return $ar;
			
			$this->outSend( $ar, 1, "" );
		}
	}
?>

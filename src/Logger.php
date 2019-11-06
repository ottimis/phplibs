<?php

namespace ottimis\phplibs;

    use Psr\Http\Message\ResponseInterface as Response;
    use Psr\Http\Message\ServerRequestInterface as Request;
    use Slim\Routing\RouteCollectorProxy as RouteCollectorProxy;

    /**
     * Create table logs and log_types
        SET NAMES utf8mb4;
        SET FOREIGN_KEY_CHECKS = 0;

        -- ----------------------------
        -- Table structure for logs
        -- ----------------------------
        DROP TABLE IF EXISTS `logs`;
        CREATE TABLE `logs` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `type` int(11) DEFAULT NULL,
        `stacktrace` text,
        `note` varchar(255) DEFAULT NULL,
        `cod` varchar(10) DEFAULT NULL,
        `datetime` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
        ) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=latin1;

        SET FOREIGN_KEY_CHECKS = 1;

        <--------------------log_types------------------------------>

        SET NAMES utf8mb4;
        SET FOREIGN_KEY_CHECKS = 0;

        -- ----------------------------
        -- Table structure for log_types
        -- ----------------------------
        DROP TABLE IF EXISTS `log_types`;
        CREATE TABLE `log_types` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `log_type` varchar(15) DEFAULT NULL,
        `color` varchar(7) DEFAULT NULL,
        PRIMARY KEY (`id`)
        ) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=latin1;

        -- ----------------------------
        -- Records of log_types
        -- ----------------------------
        BEGIN;
        INSERT INTO `log_types` VALUES (1, 'log', '#259d00');
        INSERT INTO `log_types` VALUES (2, 'warning', '#d8a00d');
        INSERT INTO `log_types` VALUES (3, 'error', '#d81304');
        COMMIT;

        SET FOREIGN_KEY_CHECKS = 1;
     */

    class Logger
    {
        const LOGS = 1;
        const WARNINGS = 2;
        const ERRORS = 3;

        public function log( $note, $code = NULL )   {
            $utils = new Utils();

            $db = new dataBase();
            $sql = sprintf(
                "INSERT INTO logs (`type`, `note`, `code`) VALUES (1, '%s', '%s')",
                $db->real_escape_string($note),
                $db->real_escape_string($code)
            );
            $ret = $db->query($sql);
            if ($ret != false)    {
                return $ret['id'];
            } else {
                $this->error('Fallito log', 'LOG1');
            }
        }

        public function warning( $note, $code = NULL )   {
            $utils = new Utils();
            
            $db = new dataBase();
            $sql = sprintf(
                "INSERT INTO logs (`type`, `stacktrace`, `note`, `code`) VALUES (2, '%s', '%s', '%s')",
                $db->real_escape_string(json_encode(debug_backtrace())),
                $db->real_escape_string($note),
                $db->real_escape_string($code)
            );
            $ret = $db->query($sql);
            if ($ret != false)    {
                return $ret['id'];
            } else {
                $this->error('Fallito warning', 'LOG2');
            }
        }

        public function error( $note, $code = NULL )   {
            $utils = new Utils();

            $db = new dataBase();
            $sql = sprintf(
                "INSERT INTO logs (`type`, `stacktrace`, `note`, `code`) VALUES (3, '%s', '%s', '%s')",
                $db->real_escape_string(json_encode(debug_backtrace())),
                $db->real_escape_string($note),
                $db->real_escape_string($code)
            );
            $ret = $db->query($sql);
            if ($ret != false)    {
                return $ret['id'];
            } else {
                throw new Exception("Errore nella registrazione dell'errore... Brutto!", 1);
            }
        }

        
        /**
         * listLogs
         *
         * @param  mixed $req: type, datetime, limit
         *
         * @return void
         */
        public static function listLogs($req = array(), $array = false)
        {
            $utils = new Utils();

            $arSql = array(
                "select" => ["l.*", "lt.color", "lt.log_type"],
                "from" => "logs l",
                "join" => [
                    [
                        "log_types lt",
                        "lt.id=l.type"
                    ]
                ],
                "order" => "id desc"
            );

            if (isset($req['type'])) {
                $arSql['where'] = array(
                    array(
                        "field" => "type",
                        "value" => $req['type'],
                    )
                );
            }

            if (isset($req['datetime']) && isset($req['type'])) {
                $arrSql['where'][0]['operatorAfter'] = "AND";
                $arrSql['where'][] = array(
                    "field" => "datetime",
                    "operator" => ">",
                    "value" => $req['datetime']
                );
            }

            if (isset($req['limit'])) {
                $arrSql['limit'] = aray(0, $req['limit']);
            }
            
            $arrSql = $utils->dbSelect($arSql);

            if (!$array) {
                if (sizeof($arrSql['data']) == 1) {
                    $arrSql[] = $arrSql;
                }
                foreach ($arrSql['data'] as $value) {
                    $ret .= self::prepareHtml($value);
                }
                return $ret;
            } else {
                return $arrSql;
            }
        }

        private static function prepareHtml($log)
        {
            $text = "Tipo: <b>" . $log['log_type'] . "</b>";
            $text .= "<br>Data: <b>" . $log['datetime'] . "</b>";
            if ($log['code'] != "")
                $text .= "<br>Code: <b>" . $log['code'] . " </b>";
            if ($log['note'] != "") {
                $text .= "<br>Note: <b>" . $log['note'] . " </b>";
            }
            if ($log['stacktrace']) {
                $text .= "<br>Stacktrace: " . json_encode(json_decode($log['stacktrace'], true)[1]);
            }
            return "<span style='color: " . $log['color'] . "'>" . $text . "</span>" . "<br> <<--->> <br>";
        }


        public static function api($app)
        {
            $app->group('/logs', function (RouteCollectorProxy $group) {
                $group->get('', function (Request $request, Response $response) {
                    $logs = self::listLogs();

                    $response->getBody()->write($logs);
                    return $response
                            ->withHeader('Content-Type', 'text/html');
                });
                $group->get('/today', function (Request $request, Response $response) {
                    $logs = self::listLogs(array("datetime" => "CURDATE()"));

                    $response->getBody()->write($logs);
                    return $response
                            ->withHeader('Content-Type', 'text/html');
                });
                $group->group('/log', function (RouteCollectorProxy $groupLog) {
                    $groupLog->get('', function (Request $request, Response $response) {
                        $logs = self::listLogs(array("type" => self::LOGS));

                        $response->getBody()->write($logs);
                        return $response
                                ->withHeader('Content-Type', 'text/html');
                    });
                    $groupLog->get('/today', function (Request $request, Response $response) {
                        $logs = self::listLogs(array("type" => self::LOGS, "datetime" => "CURDATE()"));

                        $response->getBody()->write($logs);
                        return $response
                                ->withHeader('Content-Type', 'text/html');
                    });
                });
                $group->group('/warning', function (RouteCollectorProxy $groupLog) {
                    $groupLog->get('', function (Request $request, Response $response) {
                        $logs = self::listLogs(array("type" => self::WARNINGS));

                        $response->getBody()->write($logs);
                        return $response
                                ->withHeader('Content-Type', 'text/html');
                    });
                    $groupLog->get('/today', function (Request $request, Response $response) {
                        $logs = self::listLogs(array("type" => self::WARNINGS, "datetime" => "CURDATE()"));

                        $response->getBody()->write($logs);
                        return $response
                                ->withHeader('Content-Type', 'text/html');
                    });
                });
                $group->group('/error', function (RouteCollectorProxy $groupLog) {
                    $groupLog->get('', function (Request $request, Response $response) {
                        $logs = self::listLogs(array("type" => self::ERRORS));

                        $response->getBody()->write($logs);
                        return $response
                                ->withHeader('Content-Type', 'text/html');
                    });
                    $groupLog->get('/today', function (Request $request, Response $response) {
                        $logs = self::listLogs(array("type" => self::ERRORS, "datetime" => "CURDATE()"));

                        $response->getBody()->write($logs);
                        return $response
                                ->withHeader('Content-Type', 'text/html');
                    });
                });
            });
        }
    }

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
        `note` text,
        `code` varchar(10) DEFAULT NULL,
        `datetime` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
        ) ENGINE=MyISAM AUTO_INCREMENT=5397 DEFAULT CHARSET=latin1;

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
        ) ENGINE=MyISAM AUTO_INCREMENT=4 DEFAULT CHARSET=latin1;

        -- ----------------------------
        -- Records of log_types
        -- ----------------------------
        BEGIN;
        INSERT INTO `log_types` VALUES (1, 'Log', '#259d00');
        INSERT INTO `log_types` VALUES (2, 'Warning', '#d8a00d');
        INSERT INTO `log_types` VALUES (3, 'Error', '#d81304');
        COMMIT;

        SET FOREIGN_KEY_CHECKS = 1;
     */

    class LoggerPdo
    {
        protected $error;
        protected $debug;
        const LOGS = 1;
        const WARNINGS = 2;
        const ERRORS = 3;
        public $pdo;

        public function __construct($error, $debug, $dbName = "")
        {
            $this->debug = $debug;
            $this->error = $error;
            if ($debug) {
                $this->pdo = new Pdo($error, "DEBUG");
            } else {
                $this->pdo = new Pdo($error);
            }
        }

        private static function get_client_ip()
        {
            $ipaddress = '';
            if (getenv('HTTP_CLIENT_IP')) {
                $ipaddress = getenv('HTTP_CLIENT_IP');
            } elseif (getenv('HTTP_X_FORWARDED_FOR')) {
                $ipaddress = getenv('HTTP_X_FORWARDED_FOR');
            } elseif (getenv('HTTP_X_FORWARDED')) {
                $ipaddress = getenv('HTTP_X_FORWARDED');
            } elseif (getenv('HTTP_FORWARDED_FOR')) {
                $ipaddress = getenv('HTTP_FORWARDED_FOR');
            } elseif (getenv('HTTP_FORWARDED')) {
                $ipaddress = getenv('HTTP_FORWARDED');
            } elseif (getenv('REMOTE_ADDR')) {
                $ipaddress = getenv('REMOTE_ADDR');
            } else {
                $ipaddress = 'UNKNOWN';
            }
            return $ipaddress;
        }

        public function log($note, $code = null)
        {
            $db = $this->pdo;
            $ar = array(
                "type" => 1,
                "note" => $note,
                "code" => $code
            );
            $ret = $db->dbSql(true, "logs", $ar);
            if ($ret['success'] != false) {
                return $ret['id'];
            } else {
                $this->error('Fallito log', 'LOG1');
            }
        }

        public function warning($note, $code = null)
        {
            $db = $this->pdo;
            $ar = array(
                "type" => 2,
                "note" => $note,
                "stacktrace" => json_encode(debug_backtrace()),
                "code" => $code
            );
            $ret = $db->dbSql(true, "logs", $ar);
            if ($ret['success'] != false) {
                return $ret['id'];
            } else {
                $this->error('Fallito warning', 'LOG2');
            }
        }

        public function error($note, $code = null)
        {
            $db = $this->pdo;
            $ar = array(
                "type" => 3,
                "note" => $note,
                "stacktrace" => json_encode(debug_backtrace()),
                "code" => $code
            );
            $ret = $db->dbSql(true, "logs", $ar);
            if ($ret['success'] != false) {
                return $ret['id'];
            } else {
                throw new \Exception("Errore nella registrazione dell'errore... Brutto!", 1);
            }
        }

        
        /**
         * listLogs
         *
         * @param  mixed $req: type, datetime, limit
         *
         * @return void
         */
        public static function listLogs($debug, $req = array(), $array = false)
        {
            if ($debug) {
                $pdo = new Pdo($debug, "DEBUG");
            } else {
                $pdo = new Pdo();
            }
            $arSql = array(
                "select" => ["l.*"],
                "from" => "logs l",
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
                $arrSql['limit'] = array(0, $req['limit']);
            }
            
            $arrSql = $pdo->dbSelect($arSql);

            if (!$array) {
                if (sizeof($arrSql['data']) == 1) {
                    $arrSql[] = $arrSql;
                }
                $ret = '';
                foreach ($arrSql['data'] as $value) {
                    $ret .= self::prepareHtml($value);
                }
                $ret = self::prepareHeader() . $ret;
                return $ret;
            } else {
                return $arrSql;
            }
        }

        private static function prepareHtml($log)
        {
            $text = '';
            $text .= "<b>" . date("d-m-Y - H:i:s", strtotime($log['datetime'])) . "</b>";
            if ($log['code'] != "") {
                $text .= " - Code: <b>" . $log['code'] . "</b>";
            }
            if ($log['note'] != "") {
                $text .= "<br><code>" . $log['note'] . "</code> ";
            }
            if ($log['stacktrace']) {
                $text .= "<br>Stacktrace: " . json_encode(json_decode($log['stacktrace'], true)[1]);
            }
            return "<p class=\"c-" . $log['type'] . "\">" . $text . "</p><hr>";
        }

        private static function prepareHeader()
        {
            $text = "<!doctype html>
              <html>
              <head>
              <style>
                body {padding:5px; font-family:arial;}
                .c-1 {color:#259d00;}
                .c-2 {color:#d8a00d;}
                .c-3 {color:#d81304;}
              </style>
              </head>
              <body>";
            return $text;
        }


        public static function api($app, $secure = array(), $debug = false)
        {
            $secureMW = function (Request $request, RequestHandler $handler) use ($secure) {
                $requestIp = Logger::get_client_ip();
                if (sizeof($secure) > 0) {
                    if (!in_array($requestIp, $secure)) {
                        $response = new \Slim\Psr7\Response();
                        return $response
                                ->withStatus(404);
                    }
                }
                $response = $handler->handle($request);
                return $response;
            };
            $app->group('/logs', function (RouteCollectorProxy $group) use ($debug) {
                $group->get('', function (Request $request, Response $response) use ($debug) {
                    $logs = self::listLogs($debug, array());

                    $response->getBody()->write($logs);
                    return $response
                            ->withHeader('Content-Type', 'text/html');
                });
                $group->get('/today', function (Request $request, Response $response) use ($debug) {
                    $logs = self::listLogs($debug, array("datetime" => "CURDATE()"));

                    $response->getBody()->write($logs);
                    return $response
                            ->withHeader('Content-Type', 'text/html');
                });
                $group->group('/log', function (RouteCollectorProxy $groupLog) use ($debug) {
                    $groupLog->get('', function (Request $request, Response $response) use ($debug) {
                        $logs = self::listLogs($debug, array("type" => self::LOGS));

                        $response->getBody()->write($logs);
                        return $response
                                ->withHeader('Content-Type', 'text/html');
                    });
                    $groupLog->get('/today', function (Request $request, Response $response) use ($debug) {
                        $logs = self::listLogs($debug, array("type" => self::LOGS, "datetime" => "CURDATE()"));

                        $response->getBody()->write($logs);
                        return $response
                                ->withHeader('Content-Type', 'text/html');
                    });
                });
                $group->group('/warning', function (RouteCollectorProxy $groupLog) use ($debug) {
                    $groupLog->get('', function (Request $request, Response $response) use ($debug) {
                        $logs = self::listLogs($debug, array("type" => self::WARNINGS));

                        $response->getBody()->write($logs);
                        return $response
                                ->withHeader('Content-Type', 'text/html');
                    });
                    $groupLog->get('/today', function (Request $request, Response $response) use ($debug) {
                        $logs = self::listLogs($debug, array("type" => self::WARNINGS, "datetime" => "CURDATE()"));

                        $response->getBody()->write($logs);
                        return $response
                                ->withHeader('Content-Type', 'text/html');
                    });
                });
                $group->group('/error', function (RouteCollectorProxy $groupLog) use ($debug) {
                    $groupLog->get('', function (Request $request, Response $response) use ($debug) {
                        $logs = self::listLogs($debug, array("type" => self::ERRORS));

                        $response->getBody()->write($logs);
                        return $response
                                ->withHeader('Content-Type', 'text/html');
                    });
                    $groupLog->get('/today', function (Request $request, Response $response) use ($debug) {
                        $logs = self::listLogs($debug, array("type" => self::ERRORS, "datetime" => "CURDATE()"));

                        $response->getBody()->write($logs);
                        return $response
                                ->withHeader('Content-Type', 'text/html');
                    });
                });
            });
        }
    }

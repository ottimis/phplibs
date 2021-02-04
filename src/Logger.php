<?php

namespace ottimis\phplibs;

    use Psr\Http\Message\ResponseInterface as Response;
    use Psr\Http\Message\ServerRequestInterface as Request;
    use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
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

    class Logger
    {
        const LOGS = 1;
        const WARNINGS = 2;
        const ERRORS = 3;
        public $dataBase;

        public function __construct()
        {
            $this->dataBase = new dataBase();
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
            $utils = new Utils();

            $db = $this->dataBase;
            $sql = sprintf(
                "INSERT INTO logs (`type`, `note`, `code`) VALUES (1, '%s', '%s')",
                $db->real_escape_string($note),
                $db->real_escape_string($code)
            );
            $ret = $db->query($sql);
            if ($ret != false) {
                return true;
            } else {
                $error = $db->error();
                throw new \Exception("Errore nella registrazione dell'errore...( $error ) Brutto!", 1);
            }
        }

        public function warning($note, $code = null)
        {
            $utils = new Utils();

            $db = $this->dataBase;
            $sql = sprintf(
                "INSERT INTO logs (`type`, `stacktrace`, `note`, `code`) VALUES (2, '%s', '%s', '%s')",
                $db->real_escape_string(json_encode(debug_backtrace())),
                $db->real_escape_string($note),
                $db->real_escape_string($code)
            );
            $ret = $db->query($sql);
            if ($ret != false) {
                return true;
            } else {
                $error = $db->error();
                throw new \Exception("Errore nella registrazione dell'errore...( $error ) Brutto!", 1);
            }
        }

        public function error($note, $code = null)
        {
            $utils = new Utils();

            $db = $this->dataBase;
            $sql = sprintf(
                "INSERT INTO logs (`type`, `stacktrace`, `note`, `code`) VALUES (3, '%s', '%s', '%s')",
                $db->real_escape_string(json_encode(debug_backtrace())),
                $db->real_escape_string($note),
                $db->real_escape_string($code)
            );
            $ret = $db->query($sql);
            if ($ret != false) {
                return true;
            } else {
                $error = $db->error();
                throw new \Exception("Errore nella registrazione dell'errore...( $error ) Brutto!", 1);
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
                "select" => ["l.*"],
                "from" => "logs l",
                "order" => "id desc"
            );

            if (isset($req['where'])) {
                $arSql['where'] = $req['where'];
            }

            if (isset($req['type'])) {
                $arSql['where'] = array(
                    array(
                        "field" => "type",
                        "value" => $req['type'],
                    )
                );
            }

            if (isset($req['limit'])) {
                $arSql['limit'] = array(0, $req['limit']);
            }

            $arrSql = $utils->dbSelect($arSql);

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


        public static function api($app, $secure = array())
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
            $app->group('/logs', function (RouteCollectorProxy $group) {
                $group->get('', function (Request $request, Response $response) {
                    $logs = self::listLogs(array("limit" => 1000));

                    $response->getBody()->write($logs);
                    return $response
                            ->withHeader('Content-Type', 'text/html');
                });
                $group->get('/{code}', function (Request $request, Response $response) {
                    $where[] = array(
                        "field" => "code",
                        "value" => $args['code']
                    );
                    $logs = self::listLogs(array("where" => $where));

                    $response->getBody()->write($logs);
                    return $response
                            ->withHeader('Content-Type', 'text/html');
                });
            })->add($secureMW);
        }
    }

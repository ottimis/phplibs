<?php

namespace ottimis\phplibs;

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

    class Logger    {

        const LOGS = 1;
        const WARNINGS = 2;
        const ERRORS = 3;

        public function log( $note )   {
            $utils = new Utils();

            $ar = array(
                "type" => 1,
                "note" => $note
            );
            $ret = $utils->dbSql(true, "logs", $ar);
            if ($ret['success'])    {
                return $ret['id'];
            } else {
                $this->error(json_encode(debug_backtrace()));
            }
        }

        public function warning( $note, $stacktrace )   {
            $utils = new Utils();

            $ar = array(
                "type" => 2,
                "note" => $note,
                "stacktrace" => $stacktrace
            );
            $ret = $utils->dbSql(true, "logs", $ar);
            if ($ret['success'])    {
                return $ret['id'];
            } else {
                $this->error(json_encode(debug_backtrace()));
            }
        }

        public function error( $stacktrace )   {
            $utils = new Utils();

            $ar = array(
                "type" => 3,
                "stacktrace" => $stacktrace
            );
            $ret = $utils->dbSql(true, "logs", $ar);
            if ($ret['success'])    {
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
        public function listLogs( $req, $array = false )  {
            $utils = new Utils();

            $arSql = array(
                "select" => ["l.*", "lt.color", "lt.log_type"],
                "from" => "logs l",
                "join" => [
                    [
                        "log_types lt",
                        "lt.id=logs.type"
                    ]
                ],
                "order" => "datetime desc"
            );

            if ( isset($req['type']) )  {
                $arSql['where'] = array(
                    array(
                        "field" => "type",
                        "value" => $req['type'],
                    )
                );
            }

            if (isset($req['datetime']) && isset($req['type']))    {
                $arrSql['where'][0]['operatorAfter'] = "AND";
                $arrSql['where'][] = array(
                    "field" => "datetime",
                    "operator" => ">",
                    "value" => $req['datetime']
                );
            }

            if (isset($req['limit']))   {
                $arrSql['limit'] = aray(0, $req['limit']);
            }
            
            $arrSql = $utils->dbSelect($arSql);

            if (!$array) {
                if (sizeof($arrSql) == 1) {
                    $arrSql[] = $arrSql;
                }
                foreach ($arrSql as $value) {
                    $ret .= prepareHtml($value);
                }
                return $ret;
            } else {
                return $arrSql;
            }
        }

        private function prepareHtml($log)  {
            $text = "Tipo: <b>" . $log['log_type'] . "</b>";
            $text .= $log['note'] != null ? "Note: " . $log['note'] : "";
            $text .= $log['stacktrace'] != null? "Stacktrace: " . $log['stacktrace'] : "";
            return "<span style='color: " . $log['color'] . "'>" . $text . "</span>" . PHP_EOL;
        }


        public function api($app)   {
            $app->group('/logs', function (RouteCollectorProxy $group) {
                $group->get('/', function (Request $request, Response $response) {

                    $logs = $this->listLogs();

                    $response->getBody()->write($logs);
                    return $response
                            ->withHeader('Content-Type', 'text/html');
                });
                $group->get('/today', function (Request $request, Response $response) {

                    $logs = $this->listLogs(array("datetime" => "CURDATE()"));

                    $response->getBody()->write($logs);
                    return $response
                            ->withHeader('Content-Type', 'text/html');
                });
                $group->group('/log', function (RouteCollectorProxy $groupLog) {
                    $groupLog->get('/', function (Request $request, Response $response) {
                        $logs = $this->listLogs(array("type" => $this::LOGS));

                        $response->getBody()->write($logs);
                        return $response
                                ->withHeader('Content-Type', 'text/html');
                    });
                    $groupLog->get('/today', function (Request $request, Response $response) {
                        $logs = $this->listLogs(array("type" => $this::LOGS, "datetime" => "CURDATE()"));

                        $response->getBody()->write($logs);
                        return $response
                                ->withHeader('Content-Type', 'text/html');
                    });
                });
                $group->group('/warning', function (RouteCollectorProxy $groupLog) {
                    $groupLog->get('/', function (Request $request, Response $response) {
                        $logs = $this->listLogs(array("type" => $this::WARNINGS));

                        $response->getBody()->write($logs);
                        return $response
                                ->withHeader('Content-Type', 'text/html');
                    });
                    $groupLog->get('/today', function (Request $request, Response $response) {
                        $logs = $this->listLogs(array("type" => $this::WARNINGS, "datetime" => "CURDATE()"));

                        $response->getBody()->write($logs);
                        return $response
                                ->withHeader('Content-Type', 'text/html');
                    });
                });
                $group->group('/error', function (RouteCollectorProxy $groupLog) {
                    $groupLog->get('/', function (Request $request, Response $response) {
                        $logs = $this->listLogs(array("type" => $this::ERRORS));

                        $response->getBody()->write($logs);
                        return $response
                                ->withHeader('Content-Type', 'text/html');
                    });
                    $groupLog->get('/today', function (Request $request, Response $response) {
                        $logs = $this->listLogs(array("type" => $this::ERRORS, "datetime" => "CURDATE()"));

                        $response->getBody()->write($logs);
                        return $response
                                ->withHeader('Content-Type', 'text/html');
                    });
                });
            });
        }
    }

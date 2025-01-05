<?php

namespace ottimis\phplibs;

use Gelf\Transport\TcpTransport;
use Gelf\Transport\UdpTransport;

class Logger
{
    /**
     * @var string $appName
     */
    protected string $serviceName;
    /**
     * @var string $logDriver
     */
    protected string $logDriver = "db";
    protected string $logTagName;
    /**
     * @var string $logStashEndpoint
     */
    protected string $logStashEndpoint = "logstash.logs:8080";
    protected \Aws\CloudWatchLogs\CloudWatchLogsClient $CloudWatchClient;
    protected string $logGroupName;
    protected string $logStreamName;

    // Gelf Logger
    protected UdpTransport | TcpTransport $GelfTransport;
    protected \Gelf\Publisher $GelfPublisher;
    protected \Gelf\Logger $GelfLogger;

    public function __construct($appName = "default")
    {
        $this->serviceName = $appName !== "default" ? $appName : (getenv("LOG_SERVICE_NAME") ?: "default");
        $this->logDriver = getenv("LOG_DRIVER") ?: "local";
        $this->logTagName = getenv("LOG_TAG_NAME") ?: "service_name";
        $this->logStashEndpoint = getenv("LOG_ENDPOINT") ?: "logstash.logs:8080";
        if ($this->logDriver == "aws")  {
            $this->logGroupName = "{$this->serviceName}-log-group";
            $this->logStreamName = "{$this->serviceName}-log-stream";
            $this->CloudWatchClient = new \Aws\CloudWatchLogs\CloudWatchLogsClient([
                'version' => 'latest',
                'region' => getenv("AWS_REGION") ?: 'eu-central-1',
            ]);
        } else if ($this->logDriver == "gelf") {
            $this->GelfTransport = new \Gelf\Transport\UdpTransport(getenv("GELF_HOST"), getenv("GELF_PORT"), \Gelf\Transport\UdpTransport::CHUNK_SIZE_LAN);
            $this->GelfPublisher = new \Gelf\Publisher($this->GelfTransport);
            $this->GelfLogger = new \Gelf\Logger($this->GelfPublisher, [
                $this->logTagName => $this->serviceName,
            ]);
        } else if ($this->logDriver == "gelf-tcp")  {
            $this->GelfTransport = new \Gelf\Transport\TcpTransport(getenv("GELF_HOST"), getenv("GELF_PORT"));
            $this->GelfPublisher = new \Gelf\Publisher($this->GelfTransport);
            $this->GelfLogger = new \Gelf\Logger($this->GelfPublisher, [
                $this->logTagName => $this->serviceName,
            ]);
        }
    }

    /**
     * This function log (info) text in logtail
     *
     * @param mixed $note
     * @param mixed $code [optional]
     *
     * @return bool|void
     */
    public function log($note, $code = null, $data = array())
    {
        if ($this->logDriver == "logstash") {
            $this->logstashSend(array_merge([
                'level' => 'info',
                'code' => $code,
                'note' => $note,
            ], $data));
        } else if ($this->logDriver == "aws") {
            $this->awsCloudWatchSend(array_merge([
                'level' => 'info',
                'code' => $code,
                'note' => $note,
            ], $data));
        } else if ($this->logDriver == "gelf" || $this->logDriver == "gelf-tcp") {
            $this->GelfLogger->info($note, $data);
        } else if ($this->logDriver == "db") {
            $db = new dataBase();
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
        } else {
            error_log("$note $code");
        }
    }

    /**
     * This function log (warning) text in logtail
     *
     * @param mixed $note
     * @param mixed $code [optional]
     *
     * @return void|boolean
     */
    public function warning($note, $code = null, $data = array())
    {
        if ($this->logDriver == "logstash") {
            $this->logstashSend(array_merge([
                'level' => 'warning',
                'code' => $code,
                'note' => $note,
                'stacktrace' => json_encode(debug_backtrace()),
            ], $data));
        } else if ($this->logDriver == "aws") {
            $this->awsCloudWatchSend(array_merge([
                'level' => 'warning',
                'code' => $code,
                'note' => $note,
            ], $data));
        } else if ($this->logDriver == "gelf" || $this->logDriver == "gelf-tcp") {
            $this->GelfLogger->warning($note, $data);
        } else if ($this->logDriver == "db") {
            $db = new dataBase();
            $sql = sprintf(
                "INSERT INTO logs (`type`, `stacktrace`, `note`, `code`) VALUES (2, '%s', '%s', '%s')",
                $db->real_escape_string(json_encode(debug_backtrace())),
                $db->real_escape_string($note),
                $db->real_escape_string($code)
            );
            $ret = $db->query($sql);
            Notify::notify("Logger warning", array("note" => $note));
            if ($ret != false) {
                return true;
            } else {
                $error = $db->error();
                throw new \Exception("Errore nella registrazione dell'errore...( $error ) Brutto!", 1);
            }
        } else {
            error_log("$note $code");
        }
    }

    /**
     * This function log (error) text in logtail
     *
     * @param mixed $note
     * @param mixed $code [optional]
     *
     * @return bool|void
     */
    public function error($note, $code = null, $data = array())
    {
        Notify::notify("Logger error", array("note" => $note));
        if ($this->logDriver == "logstash") {
            $this->logstashSend(array_merge([
                'level' => 'error',
                'code' => $code,
                'note' => $note,
                'stacktrace' => json_encode(debug_backtrace()),
            ], $data));
        } else if ($this->logDriver == "aws") {
            $this->awsCloudWatchSend(array_merge([
                'level' => 'error',
                'code' => $code,
                'note' => $note,
                'stacktrace' => json_encode(debug_backtrace()),
            ], $data));
        } else if ($this->logDriver == "gelf" || $this->logDriver == "gelf-tcp") {
            $this->GelfLogger->error($note, $data);
        } else if ($this->logDriver == "db") {
            $db = new dataBase();
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
        } else {
            error_log($note . " $code\r\n Stacktrace: " . json_encode(debug_backtrace()));
        }
    }

    /**
     * This function reads the logs table of the db and returns a list of these
     *
     * @param array $req : type, datetime, limit
     * @param bool $array [optional]
     *
     * @return string
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

    /**
     * This function prepare the html code for every single row of the logs table
     *
     * @param mixed $log
     *
     * @return string
     */
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

    /**
     * This function prepare the html header for every single row of the logs table
     *
     * @return string
     */
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
        $secureMW = function ($request, $handler) use ($secure) {
            if (getenv("ENVIRONMENT") == "production") {
                $response = new \Slim\Psr7\Response();
                return $response
                    ->withStatus(404);
            }
            $response = $handler->handle($request);
            return $response;
        };
        $app->group('/logs', function ($group) {
            $group->get('', function ($request, $response) {
                $logs = self::listLogs(array("limit" => 1000));

                $response->getBody()->write($logs);
                return $response
                    ->withHeader('Content-Type', 'text/html');
            });
            $group->get('/{code}', function ($request, $response, $args) {
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

    private function logstashSend($data)  {
        $data['hostname'] = gethostname();
        $data['service'] = $this->serviceName;
        $curl = curl_init($this->logStashEndpoint);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 1);
        curl_exec($curl);
        if (curl_errno($curl)) {
            $error_msg = curl_error($curl);
            Notify::notify("Logstash error", array("note" => $error_msg));
        }
        curl_close($curl);
    }

    private function awsCloudWatchSend($data) {
        $logEvent = [
            'logGroupName' => $this->logGroupName,
            'logStreamName' => $this->logStreamName,
            'logEvents' => [
                [
                    'timestamp' => round(microtime(true) * 1000),
                    'message' => json_encode($data),
                ],
            ],
        ];
        try {
            $this->CloudWatchClient->putLogEvents($logEvent);
        } catch (\Exception $e) {
            Notify::notify("CloudWatch error", array("note" => $e->getMessage()));
        }
    }
}

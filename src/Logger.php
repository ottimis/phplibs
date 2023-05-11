<?php

namespace ottimis\phplibs;

use Monolog\Logger as MonologLogger;
use Logtail\Monolog\LogtailHandler;

class Logger
{
  /**
   * @var Monolog\Logger
   */
  public $Logger;

  public function __construct($appName = "default")
  {
    $appName = $appName !== "default" ? $appName : getenv("LOGTAIL_APP_NAME") ?? "default";
    $apiKey = getenv("LOGTAIL_API_KEY") ?? null;
    if ($apiKey != null) {
      $this->Logger = new MonologLogger($appName);
      $this->Logger->pushHandler(new LogtailHandler($apiKey));
    }
  }

  /**
   * This function log (info) text in logtail
   *
   * @param  mixed $note
   * @param  mixed $code [optional]
   * 
   * @return bool|void
   */
  public function log($note, $code = null, $data = array())
  {
    if (!getenv("LOGTAIL_API_KEY")) {
      return false;
    }
    $this->Logger->info($note, array_merge([
      'code' => $code
    ], $data));
  }

  /**
   * This function log (warning) text in logtail
   *
   * @param  mixed $note
   * @param  mixed $code [optional]
   * 
   * @return void|boolean
   */
  public function warning($note, $code = null, $data = array())
  {
    if (!getenv("LOGTAIL_API_KEY")) {
      return false;
    }
    $this->Logger->warning($note, array_merge([
      'code' => $code,
      'stacktrace' => json_encode(debug_backtrace()),
    ], $data));
  }

  /**
   * This function log (error) text in logtail
   *
   * @param  mixed $note
   * @param  mixed $code [optional]
   * 
   * @return bool|void
   */
  public function error($note, $code = null, $data = array())
  {
    if (!getenv("LOGTAIL_API_KEY")) {
      return false;
    }
    $this->Logger->error($note, array_merge([
      'code' => $code,
      'stacktrace' => json_encode(debug_backtrace()),
    ], $data));
    Notify::notify("Logger error", array("note" => $note));
  }
}

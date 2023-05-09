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
    if (getenv("LOGTAIL_API_KEY")) {
      $this->Logger = new Logger($appName);
      $this->Logger->pushHandler(new LogtailHandler(getenv("LOGTAIL_API_KEY")));
    }
  }

  /**
   * This function log (info) text in logtail
   *
   * @param  mixed $note
   * @param  mixed $code [optional]
   * 
   * @return bool|Exception
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
   * @return bool|Exception
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
   * @return bool|Exception
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
  }
}

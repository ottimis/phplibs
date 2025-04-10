<?php

namespace ottimis\phplibs;

use JsonException;

class Notify
{
  const int ALERT = 1;

  public function __construct()
  {
  }

    /**
     * @throws JsonException
     */
    public static function notify($title, $data = array(), $serviceName = null): array|null
  {
    if (getenv("NO_NOTIFY") === '1') {
      return null;
    }
    $headers = array('Content-Type: application/json');
    $ar = array(
      "idtype" => self::ALERT,
      "title" => $title,
      "data" => $data,
      "service_from" => $serviceName ?? $_SERVER['HTTP_HOST']
    );

    if (empty(getenv("NOTIFY_URL"))) {
      return null;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, getenv("NOTIFY_URL"));
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($ar, JSON_THROW_ON_ERROR));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2);
    $server_output = curl_exec($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return array(
      "body" => $server_output,
      "statusCode" => $statusCode
    );
  }
}

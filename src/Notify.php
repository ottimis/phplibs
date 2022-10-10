<?php

namespace ottimis\phplibs;

class Notify
{
  const ALERT = 1;

  public function __construct()
  {
  }

  public static function notify($title, $data = array(), $serviceName = null)
  {
    if (getenv("NO_NOTIFY") == 1) {
      return;
    }
    $headers = array('Content-Type: application/json');
    $ar = array(
      "idtype" => Notify::ALERT,
      "title" => $title,
      "data" => $data,
      "service_from" => $serviceName ?? $_SERVER['HTTP_HOST']
    );

    if (empty(getenv("NOTIFY_URL"))) {
      return;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, getenv("NOTIFY_URL"));
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($ar));
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

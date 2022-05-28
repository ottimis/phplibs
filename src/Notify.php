<?php

namespace ottimis\phplibs;

class Notify
{
    const ALERT = 1;

    public function __construct()
    {
    }

    public static function notify($title, $data = array())
    {
      if (getenv("NO_NOTIFY") == 1) {
        return;
      }
      $headers = array('Content-Type: application/json');
      $ar = array(
        "idtype" => Notify::ALERT,
        "title" => $title,
        "data" => $data,
        "service_from" => $_SERVER['HTTP_HOST']
      );
      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL, "https://notify.ottimis.com/alarm");
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

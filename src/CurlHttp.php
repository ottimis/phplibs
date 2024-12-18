<?php

namespace ottimis\phplibs;

class CurlHttp
{
    private array $basicAuth = [];
    private string $jwt = "";
    private array $headers = [];

    function __construct()
    {
        return $this;
    }

    public function withBasicAuth($user, $pass): CurlHttp
    {
        $this->basicAuth = array(
            "user" => $user,
            "pass" => $pass
        );
        return $this;
    }

    public function withJwt($jwt): CurlHttp
    {
        $this->jwt = $jwt;
        $this->headers[] = "Authorization: Bearer {$this->jwt}";
        return $this;
    }

    public function withHeader($name, $value): CurlHttp
    {
        $this->headers[] = "{$name}: {$value}";
        return $this;
    }

    function get($url): array
    {
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_URL => $url
        ]);

        // Auth
        if ($this->basicAuth) {
            curl_setopt($curl, CURLOPT_USERPWD, $this->basicAuth['user'] . ":" . $this->basicAuth['pass']);
        }

        if (!empty($this->headers)) {
            curl_setopt($curl, CURLOPT_HTTPHEADER, $this->headers);
        }

        $resp = curl_exec($curl);
        $statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        return array(
            "body" => $resp,
            "statusCode" => $statusCode
        );
    }

    function post($url, $ar): array
    {
        $curl = curl_init();

        $this->headers[] = 'Content-Type: application/json';

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => json_encode($ar),
            CURLOPT_HTTPHEADER => $this->headers,
            CURLOPT_RETURNTRANSFER => 1,
            CURLINFO_HEADER_OUT => true
        ]);

        $resp = curl_exec($curl);
        $statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        return array(
            "body" => $resp,
            "statusCode" => $statusCode
        );
    }
}

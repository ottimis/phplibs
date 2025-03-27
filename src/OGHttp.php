<?php

namespace ottimis\phplibs;

use JsonException;

class OGHttp
{
    private array $basicAuth = [];
    private string $jwt = "";
    private int $timeout = 30;

    public function withBasicAuth($user, $pass): OGHttp
    {
        $this->basicAuth = array(
            "user" => $user,
            "pass" => $pass
        );
        return $this;
    }

    public function withJwt($jwt): OGHttp
    {
        $this->jwt = $jwt;
        return $this;
    }

    public function get($url): array
    {
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_URL => $url,
            CURLOPT_TIMEOUT => $this->timeout,
        ]);

        // Auth
        if ($this->basicAuth) {
            curl_setopt($curl, CURLOPT_USERPWD, $this->basicAuth['user'] . ":" . $this->basicAuth['pass']);
        }
        if ($this->jwt) {
            curl_setopt($curl, CURLOPT_HTTPHEADER, array(
                "Authorization: Bearer {$this->jwt}"
            ));
        }

        $resp = curl_exec($curl);
        $statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        return array(
            "body" => $resp,
            "statusCode" => $statusCode,
            "timeout" => curl_errno($curl) === CURLE_OPERATION_TIMEDOUT
        );
    }

    /**
     * @throws JsonException
     */
    public function post($url, $ar = []): array
    {
        $curl = curl_init();

        $headers = [
            'Content-Type: application/json'
        ];
        if ($this->jwt) {
            $headers[] = "Authorization: Bearer {$this->jwt}";
        }

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => json_encode($ar, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => 1,
            CURLINFO_HEADER_OUT => true,
            CURLOPT_TIMEOUT => $this->timeout,
        ]);

        $resp = curl_exec($curl);
        $statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        return array(
            "body" => $resp,
            "statusCode" => $statusCode,
            "timeout" => curl_errno($curl) === CURLE_OPERATION_TIMEDOUT
        );
    }

    public function options($url): array
    {
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => "OPTIONS",
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_TIMEOUT => $this->timeout,
        ]);

        $resp = curl_exec($curl);
        $statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        return array(
            "body" => $resp,
            "statusCode" => $statusCode,
            "timeout" => curl_errno($curl) === CURLE_OPERATION_TIMEDOUT
        );
    }
}

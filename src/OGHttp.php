<?php

namespace ottimis\phplibs;

use JsonException;

class OGHttp
{
    public const string ENCODING_JSON = 'json';
    public const string ENCODING_FORM = 'form';

    private array $basicAuth = [];
    private string $jwt = "";
    private int $timeout;
    private array $headers = [];
    private string $encoding = self::ENCODING_JSON;

    public function __construct(int $timeout = 30)
    {
        $this->timeout = $timeout;
    }

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

    public function withTimeout(int $seconds): OGHttp
    {
        $this->timeout = $seconds;
        return $this;
    }

    /**
     * Header di default inviati con ogni richiesta (name => value).
     * Quelli passati per singola richiesta hanno precedenza (match
     * case-insensitive sul nome).
     */
    public function withHeaders(array $headers): OGHttp
    {
        $this->headers = array_merge($this->headers, $headers);
        return $this;
    }

    /**
     * I body array/object vengono codificati application/x-www-form-urlencoded
     * (es. endpoint token OAuth2) invece del default JSON.
     */
    public function asForm(): OGHttp
    {
        $this->encoding = self::ENCODING_FORM;
        return $this;
    }

    public function asJson(): OGHttp
    {
        $this->encoding = self::ENCODING_JSON;
        return $this;
    }

    /**
     * Richiesta HTTP generica.
     *
     * $body: null = nessun body; string = inviata raw così com'è (Content-Type
     * a carico del chiamante); array/object = codificato secondo l'encoding
     * dell'istanza (JSON di default, form con asForm()).
     *
     * Ritorna sempre:
     *  - body: string (raw, vuota su errore di trasporto)
     *  - statusCode: int (0 su errore di trasporto)
     *  - timeout: bool
     *  - headers: array header di risposta, nomi minuscoli (duplicati uniti con ", ")
     *  - error: ?string messaggio cURL su errore di trasporto, null altrimenti
     *
     * @throws JsonException
     */
    public function request(string $method, string $url, mixed $body = null, array $headers = []): array
    {
        // Merge case-insensitive: per-request vince sui default dell'istanza
        $merged = [];
        foreach ([$this->headers, $headers] as $set) {
            foreach ($set as $name => $value) {
                $merged[strtolower($name)] = [$name, $value];
            }
        }

        $payload = null;
        if (is_string($body)) {
            $payload = $body;
        } elseif ($body !== null) {
            if ($this->encoding === self::ENCODING_FORM) {
                $payload = http_build_query($body);
                $merged['content-type'] ??= ['Content-Type', 'application/x-www-form-urlencoded'];
            } else {
                $payload = json_encode($body, JSON_THROW_ON_ERROR);
                $merged['content-type'] ??= ['Content-Type', 'application/json'];
            }
        }

        if ($this->jwt && !isset($merged['authorization'])) {
            $merged['authorization'] = ['Authorization', "Bearer $this->jwt"];
        }

        $curlHeaders = [];
        foreach ($merged as [$name, $value]) {
            $curlHeaders[] = "$name: $value";
        }

        $responseHeaders = [];
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_HEADERFUNCTION => static function ($curlHandle, string $line) use (&$responseHeaders): int {
                // Nuova status line (redirect, 100-continue): si tengono solo
                // gli header dell'ultima risposta
                if (str_starts_with($line, 'HTTP/')) {
                    $responseHeaders = [];
                    return strlen($line);
                }
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $name = strtolower(trim($parts[0]));
                    $value = trim($parts[1]);
                    $responseHeaders[$name] = isset($responseHeaders[$name])
                        ? $responseHeaders[$name] . ', ' . $value
                        : $value;
                }
                return strlen($line);
            },
        ]);

        if ($this->basicAuth) {
            curl_setopt($curl, CURLOPT_USERPWD, $this->basicAuth['user'] . ":" . $this->basicAuth['pass']);
        }
        if ($payload !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);
        }

        $resp = curl_exec($curl);
        $statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $errno = curl_errno($curl);

        return array(
            "body" => $resp === false ? "" : $resp,
            "statusCode" => $statusCode,
            "timeout" => $errno === CURLE_OPERATION_TIMEDOUT,
            "headers" => $responseHeaders,
            "error" => $errno !== 0 ? curl_error($curl) : null,
        );
    }

    /**
     * @throws JsonException
     */
    public function get($url, array $headers = []): array
    {
        return $this->request('GET', $url, null, $headers);
    }

    /**
     * @throws JsonException
     */
    public function post($url, $ar = [], array $headers = []): array
    {
        return $this->request('POST', $url, $ar, $headers);
    }

    /**
     * @throws JsonException
     */
    public function put(string $url, mixed $body = null, array $headers = []): array
    {
        return $this->request('PUT', $url, $body, $headers);
    }

    /**
     * @throws JsonException
     */
    public function patch(string $url, mixed $body = null, array $headers = []): array
    {
        return $this->request('PATCH', $url, $body, $headers);
    }

    /**
     * @throws JsonException
     */
    public function delete(string $url, mixed $body = null, array $headers = []): array
    {
        return $this->request('DELETE', $url, $body, $headers);
    }

    /**
     * @throws JsonException
     */
    public function options($url, array $headers = []): array
    {
        return $this->request('OPTIONS', $url, null, $headers);
    }
}

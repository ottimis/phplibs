<?php

namespace ottimis\phplibs;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Oscura i dati sensibili prima che finiscano in un log (driver del Logger,
 * error_log, Sentry).
 *
 * Due regole:
 * - chiavi sensibili → valore sostituito da MASK, a qualsiasi profondità.
 *   Il match è case-insensitive e ignora `_`/`-` (`newPassword`,
 *   `new_password`, `NEW-PASSWORD` sono la stessa chiave) ed è per
 *   sottostringa (`reset_token`, `firebase_token`, `x-api-key` rientrano);
 * - il body di una richiesta non viene MAI loggato grezzo: se non è
 *   decodificabile (JSON o form) si logga solo la metainformazione
 *   (content-type e lunghezza), perché dentro una stringa non si sa cosa
 *   oscurare.
 *
 * Chiavi aggiuntive per progetto: env `LOG_REDACT_KEYS` (separate da virgola).
 */
final class Redactor
{
    public const MASK = '[REDACTED]';

    /** Frammenti normalizzati (minuscolo, senza `_`/`-`) che rendono sensibile una chiave. */
    private const SENSITIVE_FRAGMENTS = [
        'password',
        'passwd',
        'pwd',
        'secret',
        'token',
        'authorization',
        'cookie',
        'apikey',
        'privatekey',
        'credential',
        'sessionid',
        'cvv',
    ];

    /** Header ammessi nel log (allowlist: tutto il resto è scartato, non mascherato). */
    private const HEADER_ALLOWLIST = [
        'content-type',
        'content-length',
        'user-agent',
        'accept',
        'accept-language',
        'origin',
        'referer',
        'x-request-id',
    ];

    private const MAX_DEPTH = 16;

    public static function isSensitiveKey(int|string $key): bool
    {
        if (is_int($key)) {
            return false;
        }
        $normalized = str_replace(['_', '-', ' '], '', strtolower($key));
        if ($normalized === '') {
            return false;
        }
        foreach (self::fragments() as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Oscura ricorsivamente i valori delle chiavi sensibili. Gli scalari e le
     * liste senza chiavi sensibili passano invariati; gli oggetti vengono
     * convertiti in array (solo proprietà pubbliche).
     */
    public static function redact(mixed $data, int $depth = 0): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            return '[MAX_DEPTH]';
        }
        if ($data instanceof \JsonSerializable) {
            $data = $data->jsonSerialize();
        }
        if (is_object($data)) {
            $data = get_object_vars($data);
        }
        if (!is_array($data)) {
            return $data;
        }

        $out = [];
        foreach ($data as $key => $value) {
            $out[$key] = self::isSensitiveKey($key)
                ? self::MASK
                : self::redact($value, $depth + 1);
        }

        return $out;
    }

    /**
     * Body della richiesta in forma loggabile: decodificato e oscurato, oppure
     * la sola metainformazione se non decodificabile. Mai la stringa grezza.
     */
    public static function requestBody(ServerRequestInterface $request): mixed
    {
        $contentType = strtolower($request->getHeaderLine('Content-Type'));

        // multipart / form-urlencoded: PHP ha già fatto il parsing
        $parsed = $request->getParsedBody();
        if (is_array($parsed) && $parsed !== []) {
            $out = self::redact($parsed);
            $files = self::uploadedFiles($request->getUploadedFiles());
            if ($files !== []) {
                $out['_files'] = $files;
            }

            return $out;
        }

        $body = $request->getBody();
        $raw = (string)$body;
        if ($body->isSeekable()) {
            $body->rewind();
        }
        if (trim($raw) === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            return self::redact($decoded);
        } catch (\JsonException) {
            // non JSON
        }

        if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            parse_str($raw, $form);

            return self::redact($form);
        }

        return [
            '_unparsed' => true,
            'content_type' => $contentType !== '' ? $contentType : null,
            'length' => strlen($raw),
        ];
    }

    /**
     * Metadati della richiesta ammessi nel log (allowlist di header).
     *
     * @return array<string, string>
     */
    public static function requestHeaders(ServerRequestInterface $request): array
    {
        $out = [];
        foreach (self::HEADER_ALLOWLIST as $name) {
            if ($request->hasHeader($name)) {
                $out[$name] = $request->getHeaderLine($name);
            }
        }

        return $out;
    }

    /**
     * Callback `before_send` per Sentry: oscura body, query string, cookie e
     * header della richiesta allegata all'evento, gli argomenti dei frame e
     * l'extra/context.
     */
    public static function sentryEvent(\Sentry\Event $event): \Sentry\Event
    {
        $request = $event->getRequest();
        if ($request !== []) {
            if (isset($request['data'])) {
                $request['data'] = is_array($request['data'])
                    ? self::redact($request['data'])
                    : self::MASK;
            }
            if (isset($request['query_string']) && is_string($request['query_string'])) {
                parse_str($request['query_string'], $query);
                $request['query_string'] = http_build_query(self::redact($query));
            }
            if (isset($request['cookies'])) {
                $request['cookies'] = [];
            }
            if (isset($request['headers']) && is_array($request['headers'])) {
                $request['headers'] = self::redact($request['headers']);
            }
            $event->setRequest($request);
        }

        // Argomenti delle funzioni nei frame (catturati se
        // zend.exception_ignore_args è spento): chiave = nome del parametro.
        $stacktraces = [$event->getStacktrace()];
        foreach ($event->getExceptions() as $exception) {
            $stacktraces[] = $exception->getStacktrace();
        }
        foreach (array_filter($stacktraces) as $stacktrace) {
            foreach ($stacktrace->getFrames() as $frame) {
                if ($frame->getVars() !== []) {
                    $frame->setVars((array)self::redact($frame->getVars()));
                }
            }
        }

        $extra = $event->getExtra();
        if ($extra !== []) {
            $event->setExtra(self::redact($extra));
        }
        foreach ($event->getContexts() as $name => $context) {
            $event->setContext((string)$name, (array)self::redact($context));
        }

        return $event;
    }

    /** @return list<array{field: string, name: ?string, size: ?int}> */
    private static function uploadedFiles(array $files, string $prefix = ''): array
    {
        $out = [];
        foreach ($files as $field => $file) {
            $path = $prefix === '' ? (string)$field : "{$prefix}[{$field}]";
            if (is_array($file)) {
                array_push($out, ...self::uploadedFiles($file, $path));
            } elseif ($file instanceof \Psr\Http\Message\UploadedFileInterface) {
                $out[] = [
                    'field' => $path,
                    'name' => $file->getClientFilename(),
                    'size' => $file->getSize(),
                ];
            }
        }

        return $out;
    }

    /** @return list<string> */
    private static function fragments(): array
    {
        static $cache = null;
        $extra = getenv('LOG_REDACT_KEYS') ?: '';
        if ($cache !== null && $cache[0] === $extra) {
            return $cache[1];
        }
        $fragments = self::SENSITIVE_FRAGMENTS;
        foreach (explode(',', $extra) as $key) {
            $key = str_replace(['_', '-', ' '], '', strtolower(trim($key)));
            if ($key !== '') {
                $fragments[] = $key;
            }
        }
        $cache = [$extra, $fragments];

        return $fragments;
    }
}

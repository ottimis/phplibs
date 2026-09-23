<?php

/**
 * Standalone regression test for Redactor: sensitive keys masked at any
 * depth, request bodies never logged raw.
 *
 * No PHPUnit dependency: run directly with `php tests/RedactorTest.php`.
 * Exits 0 on success, 1 on failure.
 */

putenv('LOG_DRIVER=local');
require __DIR__ . '/../vendor/autoload.php';

use ottimis\phplibs\Redactor;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

$failures = 0;
$assert = static function (string $label, mixed $got, mixed $expected) use (&$failures) {
    if ($got !== $expected) {
        $failures++;
        echo "FAIL {$label}\n  expected: " . var_export($expected, true) . "\n  got:      " . var_export($got, true) . "\n";
    } else {
        echo "ok   {$label}\n";
    }
};

$request = static function (string $body, string $contentType, ?array $parsed = null) {
    $req = (new ServerRequestFactory())->createServerRequest('POST', '/dealer/auth/login')
        ->withHeader('Content-Type', $contentType)
        ->withHeader('Authorization', 'Bearer abc.def.ghi')
        ->withHeader('Cookie', 'PHPSESSID=xyz')
        ->withHeader('User-Agent', 'test-agent')
        ->withBody((new StreamFactory())->createStream($body));

    return $parsed === null ? $req : $req->withParsedBody($parsed);
};

// --- chiavi sensibili ---
foreach (['password', 'new_password', 'newPassword', 'NEW-PASSWORD', 'current_pwd', 'token', 'reset_token',
             'firebase_token', 'Authorization', 'cookie', 'x-api-key', 'client_secret', 'refreshToken'] as $key) {
    $assert("sensitive: {$key}", Redactor::isSensitiveKey($key), true);
}
foreach (['username', 'email', 'id_dealer', 'identifier', 'spot_price', 0, 1] as $key) {
    $assert("not sensitive: {$key}", Redactor::isSensitiveKey($key), false);
}

// --- redact ricorsivo ---
$assert('nested redaction', Redactor::redact([
    'username' => 'mario',
    'password' => 'Segreta1!',
    'user' => ['profile' => ['name' => 'M', 'Password' => 'x'], 'tokens' => ['a', 'b']],
    'items' => [['qty' => 1, 'api_key' => 'k']],
]), [
    'username' => 'mario',
    'password' => Redactor::MASK,
    'user' => ['profile' => ['name' => 'M', 'Password' => Redactor::MASK], 'tokens' => Redactor::MASK],
    'items' => [['qty' => 1, 'api_key' => Redactor::MASK]],
]);
$assert('scalar untouched', Redactor::redact('plain'), 'plain');
$assert('object converted', Redactor::redact((object)['token' => 't', 'a' => 1]), ['token' => Redactor::MASK, 'a' => 1]);

putenv('LOG_REDACT_KEYS=vat_number, iban');
$assert('extra keys from env', Redactor::redact(['vat_number' => 'IT1', 'IBAN' => 'x', 'zip' => '00100']),
    ['vat_number' => Redactor::MASK, 'IBAN' => Redactor::MASK, 'zip' => '00100']);
putenv('LOG_REDACT_KEYS');

// --- body della richiesta ---
$json = $request('{"username":"mario","password":"Segreta1!"}', 'application/json');
$assert('json body redacted', Redactor::requestBody($json), ['username' => 'mario', 'password' => Redactor::MASK]);
$assert('json body still readable after logging', (string)$json->getBody(), '{"username":"mario","password":"Segreta1!"}');

$form = $request('token=abc&password=p', 'application/x-www-form-urlencoded');
$assert('form body redacted', Redactor::requestBody($form), ['token' => Redactor::MASK, 'password' => Redactor::MASK]);

$parsed = $request('', 'multipart/form-data', ['new_password' => 'x', 'name' => 'y']);
$assert('parsed body redacted', Redactor::requestBody($parsed), ['new_password' => Redactor::MASK, 'name' => 'y']);

$raw = $request('password=Segreta1! not json', 'text/plain');
$assert('unparsable body not logged raw', Redactor::requestBody($raw),
    ['_unparsed' => true, 'content_type' => 'text/plain', 'length' => 27]);

$assert('empty body', Redactor::requestBody($request('', 'application/json')), null);

// --- header in allowlist ---
$headers = Redactor::requestHeaders($json);
$assert('allowlisted headers only', array_keys($headers), ['content-type', 'user-agent']);

// --- Sentry before_send ---
if (class_exists(\Sentry\Event::class)) {
    $event = \Sentry\Event::createEvent();
    $event->setRequest([
        'url' => 'https://api/x',
        'method' => 'POST',
        'query_string' => 'token=abc&page=2',
        'data' => ['password' => 'p', 'email' => 'e'],
        'cookies' => ['PHPSESSID' => 'x'],
        'headers' => ['Authorization' => ['Bearer x'], 'Accept' => ['*/*']],
    ]);
    $event->setExtra(['new_password' => 'x', 'ok' => 1]);
    $event->setContext('extra_data', ['RequestParams' => ['token' => 't']]);
    $frame = new \Sentry\Frame('verify', 'x.php', 1, null, null, ['password' => 'Segreta1!', 'hash' => '$2y$x']);
    $event->setStacktrace(new \Sentry\Stacktrace([$frame]));
    $event = Redactor::sentryEvent($event);
    $assert('sentry frame vars', $event->getStacktrace()->getFrames()[0]->getVars(), ['password' => Redactor::MASK, 'hash' => '$2y$x']);
    $req = $event->getRequest();
    $assert('sentry data', $req['data'], ['password' => Redactor::MASK, 'email' => 'e']);
    $assert('sentry query', $req['query_string'], 'token=' . urlencode(Redactor::MASK) . '&page=2');
    $assert('sentry cookies', $req['cookies'], []);
    $assert('sentry headers', $req['headers'], ['Authorization' => Redactor::MASK, 'Accept' => ['*/*']]);
    $assert('sentry extra', $event->getExtra(), ['new_password' => Redactor::MASK, 'ok' => 1]);
    $assert('sentry context', $event->getContexts()['extra_data'], ['RequestParams' => ['token' => Redactor::MASK]]);
}

echo $failures === 0 ? "\nAll Redactor tests passed\n" : "\n{$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);

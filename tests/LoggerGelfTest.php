<?php

/**
 * Standalone regression test for the gelf drivers of Logger: keys reserved or
 * invalid for GELF are renamed instead of making the Publisher reject the
 * whole message, and slimErrorHandler logs "errorId" (not "id").
 *
 * The real Gelf\Publisher (with its MessageValidator) is kept; only the
 * transport is replaced by an in-memory fake, so no network is involved.
 *
 * No PHPUnit dependency: run directly with `php tests/LoggerGelfTest.php`.
 * Exits 0 on success, 1 on failure.
 */

putenv('LOG_DRIVER=gelf');
putenv('GELF_HOST=127.0.0.1');
putenv('GELF_PORT=12201');
putenv('NO_NOTIFY=1');
putenv('SENTRY_DSN');
require __DIR__ . '/../vendor/autoload.php';

use Gelf\Message;
use Gelf\MessageInterface;
use Gelf\Publisher;
use Gelf\Transport\TransportInterface;
use ottimis\phplibs\Logger;
use ottimis\phplibs\Utils;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

// error_log() del Logger non deve sporcare l'output del test
ini_set('error_log', '/dev/null');

$failures = 0;
$assert = static function (string $label, mixed $got, mixed $expected) use (&$failures) {
    if ($got !== $expected) {
        $failures++;
        echo "FAIL {$label}\n  expected: " . var_export($expected, true) . "\n  got:      " . var_export($got, true) . "\n";
    } else {
        echo "ok   {$label}\n";
    }
};

final class MemoryTransport implements TransportInterface
{
    /** @var MessageInterface[] */
    public array $messages = [];

    public function send(MessageInterface $message): int
    {
        $this->messages[] = $message;
        return 1;
    }
}

$withFakeTransport = static function (Logger $logger): MemoryTransport {
    $transport = new MemoryTransport();
    $gelf = (new ReflectionProperty(Logger::class, 'GelfLogger'))->getValue($logger);
    $gelf->setPublisher(new Publisher($transport));
    return $transport;
};

// --- chiavi riservate/non valide, su tutti e tre i livelli ---
$logger = Logger::createNew('gelf-test');
$transport = $withFakeTransport($logger);

$data = ['id' => 42, 'order id' => 7, 'ok_key' => 'v', 'password' => 'Segreta1!'];
foreach (['log', 'warning', 'error'] as $method) {
    $threw = null;
    try {
        $logger->$method("{$method} message", 'CODE', $data);
    } catch (Throwable $e) {
        $threw = $e->getMessage();
    }
    $assert("{$method}: no exception", $threw, null);

    $msg = end($transport->messages) ?: null;
    $assert("{$method}: message published", $msg instanceof Message, true);
    if (!$msg instanceof Message) {
        continue;
    }
    $assert("{$method}: 'id' not sent", $msg->hasAdditional('id'), false);
    $assert("{$method}: 'id' renamed to ctx_id", $msg->getAdditional('ctx_id'), 42);
    $assert("{$method}: invalid chars replaced", $msg->getAdditional('order_id'), 7);
    $assert("{$method}: valid key untouched", $msg->getAdditional('ok_key'), 'v');
    $assert("{$method}: redaction still applied", $msg->getAdditional('password'), '[REDACTED]');
}
$assert('one message per call', count($transport->messages), 3);
$assert('stacktrace kept on error', $transport->messages[2]->hasAdditional('stacktrace'), true);

// --- collisioni, chiave vuota, lista ---
$logger->log('edge cases', null, ['id' => 1, 'ctx_id' => 2, '' => 3]);
$msg = end($transport->messages);
$assert('collision: original ctx_id kept', $msg->getAdditional('ctx_id'), 2);
$assert('collision: id moved further', $msg->getAdditional('ctx_ctx_id'), 1);
$assert('empty key renamed', $msg->getAdditional('ctx_'), 3);

$threw = null;
try {
    $logger->log('list data', null, ['a', 'b']);
} catch (Throwable $e) {
    $threw = $e->getMessage();
}
$assert('list data: no exception', $threw, null);
$assert('list data: int key as string', end($transport->messages)->getAdditional('ctx_0'), 'a');

// --- slimErrorHandler: l'eccezione arriva a Graylog con errorId ---
$transport = $withFakeTransport(Logger::getInstance());
$app = AppFactory::create();
$app->addRoutingMiddleware();
Utils::slimErrorHandler($app, 'Errore');
$app->get('/boom', static function () {
    throw new RuntimeException('kaboom');
});
$response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/boom'));

$assert('slim: 500', $response->getStatusCode(), 500);
$assert('slim: generic body', (string)$response->getBody(), 'Errore');
$assert('slim: exception reached gelf', count($transport->messages), 1);
$msg = $transport->messages[0] ?? null;
if ($msg instanceof Message) {
    $errorId = $msg->getAdditional('errorId');
    $assert('slim: errorId logged', is_string($errorId) && $errorId !== '', true);
    $assert('slim: no id/ctx_id', $msg->hasAdditional('id') || $msg->hasAdditional('ctx_id'), false);
    $assert('slim: errorId in short message', str_contains($msg->getShortMessage(), "Exception {$errorId} Message: kaboom"), true);
}

echo $failures === 0 ? "\nAll tests passed\n" : "\n{$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);

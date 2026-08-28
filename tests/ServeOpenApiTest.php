<?php

/**
 * Standalone regression test for the OpenAPI docs surface:
 *   - Utils::serveOpenApi()  → static file / live-generation (local) / 404 branches
 *   - Utils::docsEnabled()   → DOCS_ENABLED gate (open outside production)
 *   - Utils::getSwaggerPage() / serveSwaggerPage() → same gate
 *   - Utils::buildOpenApi()  → build-time spec generation
 *
 * No PHPUnit dependency: run directly with `php tests/ServeOpenApiTest.php`.
 * Exits 0 on success, 1 on failure. No DB connection needed (all statics).
 */

putenv('LOG_DRIVER=local');
require __DIR__ . '/../vendor/autoload.php';

use ottimis\phplibs\Utils;
use Slim\Psr7\Factory\ResponseFactory;

$failures = 0;
$assert = static function (string $label, mixed $got, mixed $expected) use (&$failures) {
    $ok = ($got === $expected);
    if (!$ok) {
        $failures++;
    }
    printf(
        "[%s] %-58s got=%s (expected %s)\n",
        $ok ? "PASS" : "FAIL",
        $label,
        var_export($got, true),
        var_export($expected, true)
    );
};

$factory = new ResponseFactory();
$newResponse = static fn() => $factory->createResponse();
$bodyOf = static function ($response): string {
    $body = $response->getBody();
    if ($body->isSeekable()) {
        $body->rewind();
    }
    return $body->getContents();
};

/** Set/clear ENV + DOCS_ENABLED in one shot (putenv('X') clears the var). */
$setEnv = static function (?string $env, ?string $docsFlag = null) {
    putenv($env === null ? 'ENV' : "ENV=$env");
    putenv('ENVIRONMENT');
    putenv($docsFlag === null ? 'DOCS_ENABLED' : "DOCS_ENABLED=$docsFlag");
};

// ---------------------------------------------------------------- fixtures
$tmp = sys_get_temp_dir() . '/og-openapi-test-' . getmypid();
@mkdir($tmp . '/scan', 0775, true);
$staticSpec = $tmp . '/openapi.json';
$staticJson = '{"openapi":"3.0.0","info":{"title":"Static spec","version":"1.0.0"},"paths":{}}';
file_put_contents($staticSpec, $staticJson);

// Sorgente annotata minima per la generazione live.
file_put_contents($tmp . '/scan/Api.php', <<<'PHPSRC'
<?php

use OpenApi\Attributes as OA;

#[OA\Info(title: "Live generated spec", version: "9.9.9")]
class ScannedApi
{
    #[OA\Get(path: "/ping", responses: [new OA\Response(response: 200, description: "pong")])]
    public function ping() {}
}
PHPSRC);

// swagger-php riflette sulle classi trovate: la fixture deve essere caricabile.
require $tmp . '/scan/Api.php';

$missingSpec = $tmp . '/does-not-exist.json';

// ---------------------------------------------------------------- 1. static branch
$setEnv('staging');
$res = Utils::serveOpenApi($newResponse(), $staticSpec, [$tmp . '/scan']);
$assert("static: status", $res->getStatusCode(), 200);
$assert("static: body is the file on disk", $bodyOf($res), $staticJson);
$assert("static: Content-Type", $res->getHeaderLine('Content-Type'), 'application/json');
$assert("static: Cache-Control", $res->getHeaderLine('Cache-Control'), 'public, max-age=3600');
$assert("static: Content-Length", $res->getHeaderLine('Content-Length'), (string) strlen($staticJson));

// Il file statico vince anche in locale (nessuna generazione live inutile).
$setEnv('local');
$res = Utils::serveOpenApi($newResponse(), $staticSpec, [$tmp . '/scan']);
$assert("static wins over live in local", $bodyOf($res), $staticJson);

// ---------------------------------------------------------------- 2. live generation (solo local)
$setEnv('local');
$res = Utils::serveOpenApi($newResponse(), $missingSpec, [$tmp . '/scan']);
$live = json_decode($bodyOf($res), true);
$assert("local live: status", $res->getStatusCode(), 200);
$assert("local live: spec generata dallo scan", $live['info']['title'] ?? null, "Live generated spec");
$assert("local live: path scansionato", isset($live['paths']['/ping']), true);
$assert("local live: Cache-Control", $res->getHeaderLine('Cache-Control'), 'no-store');

// Senza scanDirs non si genera nulla, nemmeno in locale.
$res = Utils::serveOpenApi($newResponse(), $missingSpec, []);
$assert("local senza scanDirs → 404", $res->getStatusCode(), 404);

// ---------------------------------------------------------------- 3. 404: niente file statico fuori da local
foreach ([['staging', null], [null, null]] as [$env, $flag]) {
    $setEnv($env, $flag);
    $res = Utils::serveOpenApi($newResponse(), $missingSpec, [$tmp . '/scan']);
    $label = $env ?? '(env non definita)';
    $assert("$label: spec assente → status", $res->getStatusCode(), 404);
    $assert("$label: spec assente → body", $bodyOf($res), '{"error":"docs_disabled"}');
    $assert("$label: spec assente → Content-Type", $res->getHeaderLine('Content-Type'), 'application/json');
}

// ---------------------------------------------------------------- 4. gate DOCS_ENABLED
foreach (['production', 'prod', 'PROD'] as $prodName) {
    $setEnv($prodName);
    $assert("$prodName: docsEnabled senza flag", Utils::docsEnabled(), false);
    $res = Utils::serveOpenApi($newResponse(), $staticSpec, [$tmp . '/scan']);
    $assert("$prodName: spec statica presente ma gate chiuso → 404", $res->getStatusCode(), 404);
    $assert("$prodName: body 404", $bodyOf($res), '{"error":"docs_disabled"}');

    $setEnv($prodName, 'true');
    $assert("$prodName + DOCS_ENABLED: docsEnabled", Utils::docsEnabled(), true);
    $res = Utils::serveOpenApi($newResponse(), $staticSpec, [$tmp . '/scan']);
    $assert("$prodName + DOCS_ENABLED: 200", $res->getStatusCode(), 200);
    $assert("$prodName + DOCS_ENABLED: body", $bodyOf($res), $staticJson);
}

// In produzione il flag deve essere esplicito: valori "falsi" non aprono.
foreach (['false', '0', 'off', ''] as $off) {
    $setEnv('production', $off);
    $assert("production + DOCS_ENABLED='$off' → chiuso", Utils::docsEnabled(), false);
}

// Fuori produzione i docs sono aperti anche senza flag (default invertito
// rispetto a ERROR_DETAILS_ENABLED / LOGS_UI_ENABLED).
foreach (['local', 'staging', 'dev', null] as $env) {
    $setEnv($env);
    $assert("docsEnabled fuori produzione (" . ($env ?? 'non definita') . ")", Utils::docsEnabled(), true);
}

// Anche in produzione DOCS_ENABLED riapre davvero (differenza voluta dagli
// altri due flag, che non riaprono mai la produzione).
$setEnv('production', 'yes');
$assert("production + DOCS_ENABLED=yes riapre", Utils::docsEnabled(), true);

// ---------------------------------------------------------------- 5. gate sulla Swagger UI
$setEnv('staging');
$page = Utils::getSwaggerPage('/openapi.json', 'Test Docs');
$assert("getSwaggerPage aperta: contiene swagger-ui", str_contains($page, 'swagger-ui'), true);
$assert("getSwaggerPage aperta: contiene l'endpoint", str_contains($page, '/openapi.json'), true);

$setEnv('production');
$page = Utils::getSwaggerPage('/openapi.json', 'Test Docs');
$assert("getSwaggerPage chiusa: niente swagger-ui", str_contains($page, 'swagger-ui'), false);
$assert("getSwaggerPage chiusa: niente endpoint spec", str_contains($page, '/openapi.json'), false);

$setEnv('production', 'true');
$page = Utils::getSwaggerPage('/openapi.json', 'Test Docs');
$assert("getSwaggerPage prod + flag: riaperta", str_contains($page, 'swagger-ui'), true);

$setEnv('production');
$res = Utils::serveSwaggerPage($newResponse(), '/openapi.json');
$assert("serveSwaggerPage chiusa: status", $res->getStatusCode(), 404);
$assert("serveSwaggerPage chiusa: body", $bodyOf($res), '{"error":"docs_disabled"}');

$setEnv('staging');
$res = Utils::serveSwaggerPage($newResponse(), '/openapi.json');
$assert("serveSwaggerPage aperta: status", $res->getStatusCode(), 200);
$assert("serveSwaggerPage aperta: Content-Type", $res->getHeaderLine('Content-Type'), 'text/html');
$assert("serveSwaggerPage aperta: contiene swagger-ui", str_contains($bodyOf($res), 'swagger-ui'), true);

// ---------------------------------------------------------------- 6. buildOpenApi (build-time)
$built = $tmp . '/nested/dir/built.json';
$bytes = Utils::buildOpenApi([$tmp . '/scan'], $built);
$assert("buildOpenApi: file creato (dir annidata inclusa)", is_file($built), true);
$assert("buildOpenApi: byte scritti", $bytes === filesize($built), true);
$decoded = json_decode((string) file_get_contents($built), true);
$assert("buildOpenApi: spec valida", $decoded['info']['title'] ?? null, "Live generated spec");

// La spec buildata viene poi servita dal ramo statico, anche in produzione col flag.
$setEnv('production', 'true');
$res = Utils::serveOpenApi($newResponse(), $built, []);
$assert("buildOpenApi → serveOpenApi statico: status", $res->getStatusCode(), 200);
$assert("buildOpenApi → serveOpenApi statico: body", $bodyOf($res), (string) file_get_contents($built));

// ---------------------------------------------------------------- cleanup
foreach ([$staticSpec, $tmp . '/scan/Api.php', $built] as $file) {
    @unlink($file);
}
foreach ([$tmp . '/scan', $tmp . '/nested/dir', $tmp . '/nested', $tmp] as $dir) {
    @rmdir($dir);
}

echo $failures === 0 ? "\nAll tests passed.\n" : "\n$failures test(s) failed.\n";
exit($failures === 0 ? 0 : 1);

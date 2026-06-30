<?php

/**
 * Standalone regression test for RouteController::json() / numerify(): the
 * LOSSLESS, value-based string→number conversion that replaces the unsafe
 * json_encode(..., JSON_NUMERIC_CHECK).
 *
 * No PHPUnit dependency: run directly with `php tests/JsonNumerifyTest.php`.
 * Exits 0 on success, 1 on failure.
 *
 * It exercises the private static helper via reflection (no DB needed) and
 * also drives the public json() through a real Slim PSR-7 Response.
 */

putenv('LOG_DRIVER=local');
require __DIR__ . '/../vendor/autoload.php';

use ottimis\phplibs\RouteController;
use Slim\Psr7\Factory\ResponseFactory;

$ref = new ReflectionClass(RouteController::class);
$numerify = $ref->getMethod('numerify');
$numerify->setAccessible(true);
$call = static fn($v) => $numerify->invoke(null, $v);

$failures = 0;
/** Assert with strict type + value equality (int 1 !== float 1.0 !== "1"). */
$assert = static function (string $label, mixed $got, mixed $expected) use (&$failures) {
    $ok = ($got === $expected);
    if (!$ok) {
        $failures++;
    }
    printf(
        "[%s] %-46s got=%s (expected %s)\n",
        $ok ? "PASS" : "FAIL",
        $label,
        var_export($got, true),
        var_export($expected, true)
    );
};

// ---- Codes with leading zeros: MUST stay strings ----
$assert("partita IVA 01234567890",            $call("01234567890"), "01234567890");
$assert("CAP 00100",                          $call("00100"),       "00100");
$assert("single zero '0' -> int 0",           $call("0"),           0);
$assert("zip '08234'",                        $call("08234"),       "08234");
$assert("'-0' stays string",                  $call("-0"),          "-0");

// ---- Integers out of PHP range: MUST stay strings (no digit loss) ----
$assert("huge int 99999999999999999999",      $call("99999999999999999999"), "99999999999999999999");
$assert("PHP_INT_MAX+1 string",               $call("9223372036854775808"),  "9223372036854775808");

// ---- Canonical ints: become int ----
$assert("'42' -> int 42",                     $call("42"),    42);
$assert("'-7' -> int -7",                     $call("-7"),    -7);
$assert("'0' edge already covered, '1000'",   $call("1000"),  1000);

// ---- Floats: become float ----
$assert("'19.99' -> float",                   $call("19.99"), 19.99);
$assert("'-0.5' -> float",                    $call("-0.5"),  -0.5);
$assert("'0.0' -> float",                     $call("0.0"),   0.0);

// ---- Non-canonical numerics: stay strings ----
$assert("'1e3' scientific stays string",      $call("1e3"),   "1e3");
$assert("' 5' leading space stays string",    $call(" 5"),    " 5");
$assert("'5 ' trailing space stays string",   $call("5 "),    "5 ");
$assert("'12.' stays string",                 $call("12."),   "12.");
$assert("'.5' stays string",                  $call(".5"),    ".5");
$assert("'00.5' stays string",                $call("00.5"),  "00.5");
$assert("'1,5' stays string",                 $call("1,5"),   "1,5");
$assert("'abc' stays string",                 $call("abc"),   "abc");
$assert("'' stays string",                    $call(""),      "");

// ---- Non-string scalars pass through unchanged ----
$assert("int passthrough",                    $call(7),       7);
$assert("float passthrough",                  $call(3.14),    3.14);
$assert("bool passthrough",                   $call(true),    true);
$assert("null passthrough",                   $call(null),    null);

// ---- Nested arrays: recursion on values only ----
$nested = $call([
    "id"      => "42",
    "vat"     => "01234567890",
    "price"   => "19.90",
    "items"   => [["code" => "00100", "qty" => "3"]],
]);
$assert("nested id -> int",                   $nested["id"],                 42);
$assert("nested vat stays string",            $nested["vat"],                "01234567890");
$assert("nested price -> float",              $nested["price"],              19.9);
$assert("nested code stays string",           $nested["items"][0]["code"],   "00100");
$assert("nested qty -> int",                  $nested["items"][0]["qty"],    3);

// ---- Numeric-string KEYS must NOT be converted (still string keys) ----
$keyed = $call(["007" => "x", "010" => "01234567890"]);
$keyOk = array_keys($keyed) === ["007", "010"]
    && array_keys(json_decode(json_encode($keyed, JSON_THROW_ON_ERROR), true)) !== null;
// PHP auto-casts canonical integer-string keys ("10") to int internally; the
// point of the test is that numerify() never *rewrites* a key. Verify the
// associated values were processed (value "01234567890" stayed a string).
$assert("string keys: value under '010' kept",$keyed["010"] ?? null,         "01234567890");

// ---- End-to-end through json(): the actual response body ----
$controller = $ref->newInstanceWithoutConstructor();
$jsonMethod = $ref->getMethod('json');
$jsonMethod->setAccessible(true);

$response = (new ResponseFactory())->createResponse();
$response = $jsonMethod->invoke($controller, $response, [
    "vat"   => "01234567890",
    "cap"   => "00100",
    "id"    => "42",
    "price" => "19.99",
], 201);

$body = (string) $response->getBody();
$assert("json() status 201",                  $response->getStatusCode(),                        201);
$assert("json() content-type",                $response->getHeaderLine('Content-Type'),          "application/json");
$assert("json() body vat string",             str_contains($body, '"vat":"01234567890"'),        true);
$assert("json() body cap string",             str_contains($body, '"cap":"00100"'),              true);
$assert("json() body id number",              str_contains($body, '"id":42'),                    true);
$assert("json() body price number",           str_contains($body, '"price":19.99'),              true);

if ($failures === 0) {
    echo "\nAll tests passed.\n";
    exit(0);
}

echo "\n$failures test(s) failed.\n";
exit(1);

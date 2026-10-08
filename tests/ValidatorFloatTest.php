<?php

/**
 * Standalone regression test for Validator with type: VALIDATOR_TYPE::FLOAT.
 * Before 8.4.1 every value was rejected (gettype() returns "double", never
 * "float"); now both the dot and the Italian decimal comma are accepted and
 * nothing is silently truncated.
 *
 * No PHPUnit dependency: run directly with `php tests/ValidatorFloatTest.php`.
 * Exits 0 on success, 1 on failure.
 */

require __DIR__ . '/../vendor/autoload.php';

use ottimis\phplibs\schemas\VALIDATOR_TYPE;
use ottimis\phplibs\Validator;

$validator = new Validator(required: false, type: VALIDATOR_TYPE::FLOAT);

$failures = 0;
/** $expected = float accepted value, or null if it must be rejected. */
$assert = static function (mixed $input, ?float $expected) use ($validator, &$failures) {
    $res = $validator->validate($input);
    $got = $res['success'] ? $res['value'] : null;
    $ok = ($got === $expected);
    if (!$ok) {
        $failures++;
    }
    printf(
        "[%s] %-14s got=%s (expected %s)\n",
        $ok ? "PASS" : "FAIL",
        var_export($input, true),
        var_export($got, true),
        var_export($expected, true)
    );
};

// Accepted
$assert(1.2, 1.2);
$assert(5, 5.0);
$assert("1.2", 1.2);
$assert("1,2", 1.2);
$assert("-1,25", -1.25);
$assert("+3,5", 3.5);
$assert(",5", 0.5);
$assert(" 1,2 ", 1.2);
$assert("0,0", 0.0);
$assert("1e3", 1000.0);
$assert("1,234", 1.234);   // comma is always decimal, never thousands

// Rejected (never truncated)
$assert("abc", null);
$assert("12abc", null);
$assert("1.234,56", null); // thousands separator: ambiguous
$assert("1,2,3", null);
$assert("1,", null);
$assert("INF", null);
$assert(true, null);
$assert([1.2], null);

// min/max work on the converted value
$bounded = new Validator(type: VALIDATOR_TYPE::FLOAT, min: 1, max: 2);
$ok = $bounded->validate("1,5")['success'] === true
    && $bounded->validate("2,5")['success'] === false;
printf("[%s] min/max on \"1,5\" / \"2,5\"\n", $ok ? "PASS" : "FAIL");
$failures += $ok ? 0 : 1;

echo $failures === 0 ? "\nAll tests passed\n" : "\n$failures failure(s)\n";
exit($failures === 0 ? 0 : 1);

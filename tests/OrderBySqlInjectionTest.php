<?php

/**
 * Standalone regression test for the ORDER BY SQL injection fix in
 * Utils::buildSafeOrderBy() (used by buildPaging / buildPagingV2).
 *
 * No PHPUnit dependency: run directly with `php tests/OrderBySqlInjectionTest.php`.
 * Exits 0 on success, 1 on failure.
 *
 * It exercises the private helper via reflection (no DB connection needed).
 */

putenv('LOG_DRIVER=local');
require __DIR__ . '/../vendor/autoload.php';

use ottimis\phplibs\Logger;
use ottimis\phplibs\Utils;

$ref = new ReflectionClass(Utils::class);
$utils = $ref->newInstanceWithoutConstructor();

$logProp = $ref->getProperty('Log');
$logProp->setAccessible(true);
$logProp->setValue($utils, Logger::getInstance());

$method = $ref->getMethod('buildSafeOrderBy');
$method->setAccessible(true);

// [srt, o, autoPrefix, expected]
$cases = [
    // Malicious payloads must be dropped (null), never reach the SQL.
    ["id;DROP",     "ASC",            true,  null],
    ["(SELECT 1)",  "ASC",            true,  null],
    ["1=1",         "ASC",            true,  null],
    // Malicious direction is normalized to a safe ASC/DESC keyword.
    ["name",        "ASC,(SELECT 1)", true,  "users.name DESC"],
    ["id",          "ASC, (SELECT pg_sleep(5))", true, "users.id DESC"],
    // Legitimate values keep working.
    ["name",        "asc",            true,  "users.name ASC"],
    ["name",        "DESC",           true,  "users.name DESC"],
    ["t.name",      "ASC",            true,  "t.name ASC"],
    // Legacy buildPaging behavior: no auto-prefix.
    ["name",        "ASC",            false, "name ASC"],
    ["t.name",      "ASC",            false, "t.name ASC"],
];

$failures = 0;
foreach ($cases as [$srt, $o, $autoPrefix, $expected]) {
    $got = $method->invoke($utils, $srt, $o, "users", $autoPrefix);
    $ok = $got === $expected;
    if (!$ok) {
        $failures++;
    }
    printf(
        "[%s] srt=%-12s o=%-28s prefix=%d => %s (expected %s)\n",
        $ok ? "PASS" : "FAIL",
        $srt,
        $o,
        $autoPrefix ? 1 : 0,
        var_export($got, true),
        var_export($expected, true)
    );
}

if ($failures === 0) {
    echo "\nAll tests passed.\n";
    exit(0);
}

echo "\n$failures test(s) failed.\n";
exit(1);

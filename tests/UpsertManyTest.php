<?php

/**
 * Standalone test for Utils::upsertMany() SQL generation, and regression check
 * that upsert() still builds the same SQL after the value-conversion refactor.
 *
 * No PHPUnit dependency: run directly with `php tests/UpsertManyTest.php`.
 * Exits 0 on success, 1 on failure.
 *
 * Uses a fake DatabaseInterface that records the queries (no DB connection).
 */

putenv('LOG_DRIVER=local');
require __DIR__ . '/../vendor/autoload.php';

use ottimis\phplibs\Interfaces\DatabaseInterface;
use ottimis\phplibs\Logger;
use ottimis\phplibs\schemas\UPSERT_MODE;
use ottimis\phplibs\Utils;

final class FakeDb implements DatabaseInterface
{
    public array $queries = [];

    public function __construct(private string $driver) {}

    public function query(string $sql): mixed { $this->queries[] = $sql; return true; }
    public function fetchassoc(mixed $result = null): array|false|null { return null; }
    public function fetcharray(mixed $result = null): false|array|null { return null; }
    public function fetchobject(mixed $result = null): object|false|null { return null; }
    public function numrows(mixed $result = null): int|string { return 0; }
    public function affectedRows(): int|string { return 0; }
    public function insert_id(): int|string { return 0; }
    public function real_escape_string(string $param): string { return str_replace("'", "\\'", $param); }
    public function error(): string|array { return ''; }
    public function startTransaction(): void {}
    public function commitTransaction(): void {}
    public function rollbackTransaction(): void {}
    public function close(): bool { return true; }
    public function freeresult(mixed $result = null): void {}
    public function getDriver(): string { return $this->driver; }
}

$ref = new ReflectionClass(Utils::class);

function makeUtils(ReflectionClass $ref, string $driver, ?bool $rowAlias = null): Utils
{
    $utils = $ref->newInstanceWithoutConstructor();
    $ref->getProperty('dataBase')->setValue($utils, new FakeDb($driver));
    $ref->getProperty('driver')->setValue($utils, $driver);
    $ref->getProperty('Log')->setValue($utils, Logger::getInstance());
    if ($rowAlias !== null) {
        $ref->getProperty('mysqlServer')->setValue($utils, ['rowAlias' => $rowAlias, 'increment' => 1]);
    }
    return $utils;
}

$failures = 0;
function check(string $label, mixed $got, mixed $expected): void
{
    global $failures;
    $ok = $got === $expected;
    if (!$ok) {
        $failures++;
    }
    printf("[%s] %s\n", $ok ? "PASS" : "FAIL", $label);
    if (!$ok) {
        echo "  got:      " . var_export($got, true) . "\n  expected: " . var_export($expected, true) . "\n";
    }
}

$rows = [
    ['code' => '001', 'name' => "O'Brien", 'active' => true, 'meta' => ['a' => 1], 'note' => null],
    ['code' => '002', 'name' => 'Bob', 'active' => false, 'meta' => [], 'note' => 'now()'],
];
$values = "('001', 'O\\'Brien', 1, '{\"a\":1}', NULL), ('002', 'Bob', 0, '[]', now())";
$cols = "(code, name, active, meta, note)";

// --- upsertMany SQL, one query per driver/mode ---
$cases = [
    ['mysql 8.0.19+ upsert', 'mysql', true, false,
        "INSERT INTO t $cols VALUES $values AS og_new ON DUPLICATE KEY UPDATE code=og_new.code, name=og_new.name, active=og_new.active, meta=og_new.meta, note=og_new.note"],
    ['mariadb / old mysql upsert', 'mysql', false, false,
        "INSERT INTO t $cols VALUES $values ON DUPLICATE KEY UPDATE code=VALUES(code), name=VALUES(name), active=VALUES(active), meta=VALUES(meta), note=VALUES(note)"],
    ['mysql noUpdate', 'mysql', true, true,
        "INSERT INTO t $cols VALUES $values"],
    ['pgsql upsert', 'pgsql', null, false,
        "INSERT INTO t $cols VALUES $values ON CONFLICT (code) DO UPDATE SET code=EXCLUDED.code, name=EXCLUDED.name, active=EXCLUDED.active, meta=EXCLUDED.meta, note=EXCLUDED.note RETURNING id"],
    ['pgsql noUpdate', 'pgsql', null, true,
        "INSERT INTO t $cols VALUES $values ON CONFLICT (code) DO NOTHING RETURNING id"],
];
foreach ($cases as [$label, $driver, $rowAlias, $noUpdate, $expected]) {
    $utils = makeUtils($ref, $driver, $rowAlias);
    $res = $utils->upsertMany('t', $rows, $noUpdate, ['code']);
    check("upsertMany $label: success", $res['success'], 1);
    check("upsertMany $label: sql", $utils->dataBase->queries, [$expected]);
}

// --- chunking ---
$utils = makeUtils($ref, 'mysql', true);
$many = array_map(static fn($i) => ['code' => (string)$i], range(1, 5));
$res = $utils->upsertMany('t', $many, true, ['id'], 2);
check('chunking: 5 rows / 2 = 3 queries', count($utils->dataBase->queries), 3);
check('chunking: chunks', $res['chunks'], 3);
check('chunking: last chunk', $utils->dataBase->queries[2], "INSERT INTO t (code) VALUES ('5')");

// --- key order does not matter, values follow the first row's column order ---
$utils = makeUtils($ref, 'mysql', true);
$utils->upsertMany('t', [['a' => 1, 'b' => 2], ['b' => 4, 'a' => 3]], true);
check('key order normalized', $utils->dataBase->queries, ["INSERT INTO t (a, b) VALUES ('1', '2'), ('3', '4')"]);

// --- validation: no query on bad input ---
$bad = [
    'different keys' => [[['a' => 1], ['b' => 2]], 500],
    'missing key' => [[['a' => 1, 'b' => 2], ['a' => 3]], 500],
    'list rows' => [[[1, 2]], 500],
    'empty row' => [[[]], 500],
    'chunkSize 0' => [[['a' => 1]], 0],
];
foreach ($bad as $label => [$badRows, $chunk]) {
    $utils = makeUtils($ref, 'mysql', true);
    $res = $utils->upsertMany('t', $badRows, false, ['id'], $chunk);
    check("invalid ($label): success 0", $res['success'], 0);
    check("invalid ($label): no query", $utils->dataBase->queries, []);
}

// --- empty input: no query ---
$utils = makeUtils($ref, 'pgsql');
$res = $utils->upsertMany('t', []);
check('empty rows', [$res, $utils->dataBase->queries], [['success' => 1, 'affectedRows' => 0, 'id' => null, 'chunks' => 0], []]);

// --- pgsql dedupe on conflict keys (last row wins, NULL keys kept) ---
$utils = makeUtils($ref, 'pgsql');
$utils->upsertMany('t', [
    ['code' => '1', 'v' => 'a'],
    ['code' => 1, 'v' => 'b'],
    ['code' => null, 'v' => 'c'],
    ['code' => null, 'v' => 'd'],
    ['code' => '2', 'v' => 'e'],
], false, ['code']);
check('pgsql dedupe', $utils->dataBase->queries[0],
    "INSERT INTO t (code, v) VALUES ('1', 'b'), (NULL, 'c'), (NULL, 'd'), ('2', 'e') ON CONFLICT (code) DO UPDATE SET code=EXCLUDED.code, v=EXCLUDED.v RETURNING id");

// noUpdate (DO NOTHING) tolerates duplicates: no dedupe
$utils = makeUtils($ref, 'pgsql');
$utils->upsertMany('t', [['code' => '1'], ['code' => '1']], true, ['code']);
check('pgsql noUpdate keeps duplicates', $utils->dataBase->queries[0],
    "INSERT INTO t (code) VALUES ('1'), ('1') ON CONFLICT (code) DO NOTHING RETURNING id");

// --- upsert() regression: same SQL as before the refactor ---
$single = ['name' => "O'Brien", 'active' => true, 'meta' => ['a' => 1], 'note' => null, 'ts' => 'now()'];
$set = "name='O\\'Brien', active=1, meta='{\"a\":1}', note=NULL, ts=now()";
$upsertCases = [
    ['mysql insert', 'mysql', UPSERT_MODE::INSERT, [], false,
        "INSERT INTO t (name, active, meta, note, ts) VALUES ('O\\'Brien', 1, '{\"a\":1}', NULL, now()) ON DUPLICATE KEY UPDATE $set"],
    ['mysql update', 'mysql', UPSERT_MODE::UPDATE, ['id' => 5], false,
        "UPDATE t SET $set WHERE id = '5'"],
    ['pgsql insert', 'pgsql', UPSERT_MODE::INSERT, [], false,
        "INSERT INTO t (name, active, meta, note, ts) VALUES ('O\\'Brien', 1, '{\"a\":1}', NULL, now()) ON CONFLICT (id) DO UPDATE SET name=EXCLUDED.name, active=EXCLUDED.active, meta=EXCLUDED.meta, note=EXCLUDED.note, ts=EXCLUDED.ts RETURNING id"],
];
foreach ($upsertCases as [$label, $driver, $mode, $where, $noUpdate, $expected]) {
    $utils = makeUtils($ref, $driver);
    $res = $utils->upsert($mode, 't', $single, $where, $noUpdate);
    check("upsert $label", $res['sql'], $expected);
}

if ($failures === 0) {
    echo "\nAll tests passed.\n";
    exit(0);
}

echo "\n$failures test(s) failed.\n";
exit(1);

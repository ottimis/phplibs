<?php

/**
 * Regression test for OGCache::clear() on a Redis database with many
 * non-matching keys.
 *
 * phpredis' default SCAN_NORETRY mode makes scan() return false for a batch
 * that contains no matching key even when the cursor is not exhausted. The
 * old `while ($keys = scan(...))` loop stopped at the first such batch and,
 * on a Redis shared by many tenants, deleted nothing. This test fills the
 * database with thousands of unrelated keys so that the first SCAN batch is
 * almost never the one containing the needles.
 *
 * Requires a reachable Redis (REDIS_HOST / REDIS_PORT, defaults 127.0.0.1:6379)
 * and a database that can be flushed (REDIS_DATABASE, default 15). Aborts if
 * that database contains keys not created by this test.
 *
 * No PHPUnit dependency: run directly with `php tests/OGCacheClearTest.php`.
 * Exits 0 on success, 1 on failure.
 */

if (getenv('REDIS_DATABASE') === false) {
    putenv('REDIS_DATABASE=15');
}
require __DIR__ . '/../vendor/autoload.php';

use ottimis\phplibs\OGCache;

const NOISE = 20000;
const TEST_MARK = 'ogcache_test:';

$failures = 0;
function check(bool $cond, string $label): void
{
    global $failures;
    if ($cond) {
        echo "  ok   $label\n";
    } else {
        $failures++;
        echo "  FAIL $label\n";
    }
}

$cache = OGCache::createNew(TEST_MARK . 'app');
$redis = $cache->getRedis();

// Safety: only flush a database that is empty or holds leftovers of this test.
$it = null;
do {
    $keys = $redis->scan($it, '*', 1000);
    foreach ($keys ?: [] as $k) {
        if (!str_starts_with($k, TEST_MARK)) {
            fwrite(STDERR, "Refusing to run: database " . getenv('REDIS_DATABASE') . " contains foreign key '$k'\n");
            exit(1);
        }
    }
} while ($it > 0);
$redis->flushDB();

function fill(\Redis $redis, int $noise, array $needles): void
{
    $pipe = $redis->multi(\Redis::PIPELINE);
    for ($i = 0; $i < $noise; $i++) {
        $pipe->set(TEST_MARK . 'other:company_' . ($i % 97) . ':key_' . $i, '1');
    }
    foreach ($needles as $k) {
        $pipe->set($k, '1');
    }
    $pipe->exec();
}

echo "Scenario 1: one needle among " . NOISE . " unrelated keys\n";
fill($redis, NOISE, [TEST_MARK . 'app:product_list_7']);
check($redis->dbSize() === NOISE + 1, 'database filled');
$deleted = $cache->clear('product_list*');
check($deleted === 1, "clear() returned 1 (got $deleted)");
check($redis->exists(TEST_MARK . 'app:product_list_7') === 0, 'needle deleted');
check($redis->dbSize() === NOISE, 'no unrelated key deleted');
$redis->flushDB();

echo "Scenario 2: 50 needles spread among the noise, siblings with same prefix survive\n";
$needles = [];
for ($i = 0; $i < 50; $i++) {
    $needles[] = TEST_MARK . 'app:product_list_' . $i;
}
$siblings = [TEST_MARK . 'app:home', TEST_MARK . 'app:product_42'];
fill($redis, NOISE, array_merge($needles, $siblings));
$deleted = $cache->clear('product_list*');
check($deleted === 50, "clear() returned 50 (got $deleted)");
check($redis->exists(...$needles) === 0, 'all needles deleted');
check($redis->exists(...$siblings) === 2, 'same-prefix siblings untouched');
check($redis->dbSize() === NOISE + 2, 'no unrelated key deleted');
$redis->flushDB();

echo "Scenario 3: clear('*') stays inside the instance prefix\n";
fill($redis, NOISE, $siblings);
$deleted = $cache->clear();
check($deleted === 2, "clear('*') returned 2 (got $deleted)");
check($redis->dbSize() === NOISE, 'keys outside the prefix untouched');
$redis->flushDB();

echo "Scenario 4: no match returns 0 without error\n";
fill($redis, 500, []);
check($cache->clear('nothing*') === 0, 'clear() on no match returns 0');
$redis->flushDB();

echo "Scenario 5: works with SCAN_RETRY too (option set by the project on getRedis())\n";
$redis->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);
fill($redis, NOISE, [TEST_MARK . 'app:product_list_1']);
check($cache->clear('product_list*') === 1, 'one needle deleted under SCAN_RETRY');
$redis->setOption(\Redis::OPT_SCAN, \Redis::SCAN_NORETRY);
$redis->flushDB();

echo $failures === 0 ? "\nAll OGCache clear() checks passed\n" : "\n$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);

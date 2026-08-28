<?php

/**
 * Standalone regression test for the per-session DB timezone
 * (Traits\SessionTimezone, used by dataBase and dataBasePgsql).
 *
 * No PHPUnit dependency: run directly with `php tests/DbSessionTimezoneTest.php`.
 * Exits 0 on success, 1 on failure.
 *
 * No DB connection needed: la risoluzione da env e la sintassi per dialetto
 * sono esercitate via reflection su istanze non costruite.
 */

putenv('LOG_DRIVER=local');
require __DIR__ . '/../vendor/autoload.php';

use ottimis\phplibs\dataBase;
use ottimis\phplibs\dataBasePgsql;
use ottimis\phplibs\Env;

$failures = 0;
$assert = static function (string $label, mixed $got, mixed $expected) use (&$failures) {
    $ok = ($got === $expected);
    if (!$ok) {
        $failures++;
    }
    printf(
        "[%s] %-56s got=%s (expected %s)\n",
        $ok ? "PASS" : "FAIL",
        $label,
        var_export($got, true),
        var_export($expected, true)
    );
};

$mysqlRef = new ReflectionClass(dataBase::class);
$pgRef = new ReflectionClass(dataBasePgsql::class);

$fromEnv = $mysqlRef->getMethod('timezoneFromEnv');
$resolve = static fn(string $dbname) => $fromEnv->invoke(null, $dbname);

$setEnv = static function (?string $default, ?string $named = null, ?string $generic = null, string $name = 'reports') {
    putenv($default === null ? 'DB_TIMEZONE' : "DB_TIMEZONE=$default");
    putenv($named === null ? "DB_TIMEZONE_$name" : "DB_TIMEZONE_$name=$named");
    putenv($generic === null ? 'TIMEZONE' : "TIMEZONE=$generic");
};

// ---------------------------------------------------------------- env non impostata → nessuna SET
$setEnv(null);
$assert("env assente → null (default server)", $resolve('default'), null);
$assert("env assente → null (connessione named)", $resolve('reports'), null);

$setEnv('');
$assert("env vuota → null", $resolve('default'), null);
$setEnv('   ');
$assert("env solo spazi → null", $resolve('default'), null);

// ---------------------------------------------------------------- risoluzione default / named
$setEnv('Europe/Rome');
$assert("default legge DB_TIMEZONE", $resolve('default'), 'Europe/Rome');
$assert("dbname vuoto = default", $resolve(''), 'Europe/Rome');
$assert("named senza override → fallback DB_TIMEZONE", $resolve('reports'), 'Europe/Rome');

$setEnv('Europe/Rome', 'UTC');
$assert("DB_TIMEZONE_{name} vince sul default", $resolve('reports'), 'UTC');
$assert("l'override named non tocca la default", $resolve('default'), 'Europe/Rome');

$setEnv(null, 'UTC');
$assert("solo DB_TIMEZONE_{name} impostata", $resolve('reports'), 'UTC');
$assert("...e la default resta senza timezone", $resolve('default'), null);

$setEnv('  Europe/Rome  ');
$assert("valore trimmato", $resolve('default'), 'Europe/Rome');

// ---------------------------------------------------------------- TIMEZONE (generica) e precedenze
$setEnv(null, null, 'Europe/Rome');
$assert("solo TIMEZONE → usata dalla default", $resolve('default'), 'Europe/Rome');
$assert("solo TIMEZONE → usata dalle named", $resolve('reports'), 'Europe/Rome');

$setEnv('+02:00', null, 'Europe/Rome');
$assert("DB_TIMEZONE vince su TIMEZONE", $resolve('default'), '+02:00');

$setEnv('+02:00', 'UTC', 'Europe/Rome');
$assert("DB_TIMEZONE_{name} vince su tutto", $resolve('reports'), 'UTC');
$assert("...e la default resta su DB_TIMEZONE", $resolve('default'), '+02:00');

$setEnv('', null, 'Europe/Rome');
$assert("DB_TIMEZONE vuota → fallback su TIMEZONE", $resolve('default'), 'Europe/Rome');

// ---------------------------------------------------------------- Env::timezone() / applyTimezone()
$setEnv(null, null, null);
$assert("Env::timezone() senza env", Env::timezone(), null);
$assert("applyTimezone() senza env non applica", Env::applyTimezone(), false);

$setEnv(null, null, '  Europe/Rome  ');
$assert("Env::timezone() trimmata", Env::timezone(), 'Europe/Rome');

$previous = date_default_timezone_get();
$assert("applyTimezone() con nome IANA", Env::applyTimezone(), true);
$assert("...default PHP impostato", date_default_timezone_get(), 'Europe/Rome');

// Il caso di attrito documentato: l'offset va bene per MySQL senza tz tables,
// ma NON è un identificatore PHP valido → applyTimezone() rifiuta senza rompere.
$setEnv(null, null, '+02:00');
$assert("applyTimezone() con offset → false", Env::applyTimezone(), false);
$assert("...default PHP invariato", date_default_timezone_get(), 'Europe/Rome');
$assert("...ma l'offset resta valido per il DB", $resolve('default'), '+02:00');

$setEnv(null, null, 'SYSTEM');
$assert("applyTimezone() con SYSTEM → false", Env::applyTimezone(), false);

date_default_timezone_set($previous);

// ---------------------------------------------------------------- formati validi
foreach (['Europe/Rome', 'UTC', 'SYSTEM', 'Local', '+02:00', '-05:00', 'Etc/GMT+3', 'America/Argentina/Buenos_Aires'] as $tz) {
    $setEnv($tz);
    $assert("formato valido: $tz", $resolve('default'), $tz);
}

// ---------------------------------------------------------------- formati rifiutati (il valore finisce in un literal SQL)
$invalid = [
    "Europe/Rome'; DROP TABLE users; --",
    "Europe/Rome'",
    "Europe Rome",
    "UTC; SELECT 1",
    '"UTC"',
    str_repeat('A', 65),
];
foreach ($invalid as $tz) {
    $setEnv($tz);
    $thrown = false;
    try {
        $resolve('default');
    } catch (\RuntimeException $e) {
        $thrown = str_contains($e->getMessage(), 'Invalid DB timezone');
    }
    $assert("rifiutato: " . substr($tz, 0, 28), $thrown, true);
}

// ---------------------------------------------------------------- sintassi per dialetto
$mysqlSql = $mysqlRef->getMethod('timezoneSql');
$pgSql = $pgRef->getMethod('timezoneSql');

$assert(
    "MySQL statement",
    $mysqlSql->invoke($mysqlRef->newInstanceWithoutConstructor(), 'Europe/Rome'),
    "SET time_zone = 'Europe/Rome'"
);
$assert(
    "PostgreSQL statement",
    $pgSql->invoke($pgRef->newInstanceWithoutConstructor(), 'Europe/Rome'),
    "SET TIME ZONE 'Europe/Rome'"
);

// ---------------------------------------------------------------- applySessionTimezone: nessuna query se env assente,
// errore fatale se il server rifiuta il timezone (mai proseguire con date sfasate).
$fake = new class {
    use \ottimis\phplibs\Traits\SessionTimezone;

    public array $queries = [];
    public mixed $queryResult = true;
    public ?\Throwable $throwOnQuery = null;
    public string $lastError = 'Unknown or incorrect time zone';

    protected function timezoneSql(string $tz): string
    {
        return "SET time_zone = '" . $tz . "'";
    }

    public function query(string $sql): mixed
    {
        $this->queries[] = $sql;
        if ($this->throwOnQuery !== null) {
            throw $this->throwOnQuery;
        }
        return $this->queryResult;
    }

    public function error(): string
    {
        return $this->lastError;
    }

    public function apply(string $dbname): void
    {
        $this->applySessionTimezone($dbname);
    }
};

$setEnv(null);
$fake->apply('default');
$assert("env assente → nessuna query eseguita", $fake->queries, []);

$setEnv('Europe/Rome');
$fake->apply('default');
$assert("env presente → SET eseguita", $fake->queries, ["SET time_zone = 'Europe/Rome'"]);

// query che ritorna false (mysqli senza exception mode)
$fake->queries = [];
$fake->queryResult = false;
$thrown = null;
try {
    $fake->apply('default');
} catch (\RuntimeException $e) {
    $thrown = $e->getMessage();
}
$assert("SET fallita (false) → RuntimeException", $thrown !== null, true);
$assert("...messaggio col timezone", str_contains((string) $thrown, "Europe/Rome"), true);
$assert("...messaggio con l'errore del server", str_contains((string) $thrown, "Unknown or incorrect time zone"), true);
$assert("...messaggio con la via d'uscita (offset)", str_contains((string) $thrown, "+02:00"), true);

// query che lancia (mysqli/PDO in exception mode)
$fake->queryResult = true;
$fake->throwOnQuery = new \RuntimeException("SQLSTATE[HY000]: Unknown or incorrect time zone: 'Europe/Rome'");
$thrown = null;
try {
    $fake->apply('default');
} catch (\RuntimeException $e) {
    $thrown = $e;
}
$assert("SET che lancia → RuntimeException", $thrown instanceof \RuntimeException, true);
$assert("...eccezione originale come previous", $thrown?->getPrevious() === $fake->throwOnQuery, true);

// cleanup env
$setEnv(null, null, null);

echo $failures === 0 ? "\nAll tests passed.\n" : "\n$failures test(s) failed.\n";
exit($failures === 0 ? 0 : 1);

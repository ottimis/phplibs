# Changelog

## [7.0.1] - 2026-06-12

### Added

- `RouteController::addGlobalMiddlewares()`: il middleware CORS ora aggiunge `Access-Control-Max-Age: 86400` alle risposte delle richieste preflight (`OPTIONS`), permettendo al browser di cachearne l'esito per 24h invece di ripetere una OPTIONS per ogni chiamata API (le raffiche di preflight saturavano i worker php-fpm).

### Fixed

- `Utils::buildSql()`: nel caso `join`/`leftJoin`/`rightJoin`/`innerJoin` la clausola ON veniva prefissata con `$ar['from']` (l'array di output in costruzione) invece di `$req['from']` (l'input). Se nelle chiavi dell'array richiesta i join precedevano `from` — l'ordine prodotto da `RouteController::get()`/`list()` con le `$options` v7.0.0 — si otteneva `Undefined array key "from"` e una ON malformata (prefisso vuoto). Ora il prefisso usa sempre `$req['from']`, indipendentemente dall'ordine delle chiavi.
- `Utils::buildSql()`: stessa classe di bug per i `fields` dei join — venivano appesi al SELECT durante l'elaborazione del join solo se la chiave `select` era già stata processata; con i join prima di `select` nell'array richiesta venivano **scartati in silenzio**. Ora i fields sono accumulati e appesi al SELECT a fine costruzione, indipendentemente dall'ordine delle chiavi (l'ordine dei campi nell'output resta quello dei join).

## [7.0.0] - 2026-06-11

### Security

- **`Utils::slimErrorHandler()` — `?debug=1` non espone più i dettagli dell'eccezione in produzione.** Il messaggio dell'eccezione (che può contenere query SQL fallite, path interni, ecc.) viene mostrato solo se `ENVIRONMENT !== "production"`, coerentemente con il gating già usato da `Logger::api()`. In produzione la risposta è sempre il messaggio generico.
- **`dataBase` (mysqli) — charset esplicito sulla connessione.** Dopo la connessione viene chiamato `set_charset()` (default `utf8mb4`, configurabile via env `DB_CHARSET`): l'escaping di `real_escape_string` è charset-dependent e senza charset esplicito dipendeva dal default del server (bypass teorico con charset multibyte). Inoltre il fallimento di connessione non usa più `or die(...)` (che stampava l'errore mysqli in output): ora logga il dettaglio via `error_log` e lancia `RuntimeException("Database connection failed")` generica.
- **`OGMail::sendAWS()` (branch SES) — header/MIME injection.** Il messaggio raw inviato a SES concatenava senza sanitizzazione subject, nome file allegati, Content-ID e Content-Type delle immagini inline: un valore contenente CRLF poteva iniettare header o parti MIME arbitrarie. Ora: il Subject è codificato RFC 2047 (`=?UTF-8?B?...?=`, che neutralizza l'injection e corregge anche i subject con caratteri non-ASCII, prima inviati raw); filename, Content-ID e Content-Type passano dal nuovo helper `sanitizeMimeValue()` (strip CR/LF/NUL), con le doppie virgolette nel filename sostituite da apici. Il branch PHPMailer era già protetto dalla libreria.

### Breaking Changes

- **`RouteController::get()` e `RouteController::list()` — firma cambiata da parametri posizionali a un array `$options`.** Prima `get($id, $joinTables = [], $select = null)` e `list(array $q)`; ora `get($id, array $options = [])` e `list(array $q, array $options = [])`. Le sottoclassi che chiamavano `parent::get($id, $joins, $select)` o passavano join/select posizionali vanno aggiornate.
  - **Migrazione**:
    - `get($id, $joins)` → `get($id, ["join" => $joins])`
    - `get($id, $joins, $select)` → `get($id, ["join" => $joins, "select" => $select])`
    - `list($q)` resta invariato (il 2° parametro è opzionale).
  - L'array `$options` viene inoltrato a `Utils::select()` e accetta tutte le sue chiavi (`select`, `join`/`leftJoin`/`rightJoin`/`innerJoin`, `group`, `order`, `decode`, `map`, `distinct`, `cte`, …), eliminando l'accumulo di parametri posizionali.
  - **Chiavi speciali** gestite dal controller (non passate a `select()` così com'è):
    - `where` — gli ulteriori filtri vengono **appesi** al `where` di base, così il filtro soft-delete `id_status = ACTIVE` (e in `get()` il match su `id`) resta sempre applicato.
    - `withDeleted` (bool) — se `true`, salta il filtro di default `id_status = ACTIVE` per includere anche i record cancellati.
  - `from` e `where` sono forzati dopo il merge: `tableName` resta autorevole e non è sovrascrivibile via `$options`.

## [6.0.2] - 2026-06-10

### Security

- **SQL injection via ORDER BY nei metodi di paginazione di `Utils`** — i parametri di paging `srt` (campo di ordinamento) e `o` (direzione), tipicamente inoltrati dalla query string HTTP, venivano concatenati nella clausola `ORDER BY` senza validazione né escaping. Era sfruttabile da qualunque endpoint paginato che inoltra `srt`/`o` dalla request (estrazione blind/error-based via subquery in ORDER BY).
  - **Fix**: nuovo helper privato `Utils::buildSafeOrderBy()` usato sia da `buildPaging()` (`dbSelect()`) sia da `buildPagingV2()` (`select()`):
    - `o` viene normalizzato a `ASC`/`DESC` (case-insensitive); qualsiasi altro valore → `DESC`.
    - `srt` viene validato (sul valore grezzo) contro il pattern identificatore sicuro `^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$` (colonna o `tabella.colonna`/`alias.colonna`). Se non matcha, l'ORDER BY derivato dall'utente non viene applicato e viene loggato un warning (`PAGING_SRT_INVALID`).
    - I valori legittimi (nomi colonna semplici e `tabella.colonna`) continuano a funzionare, mantenendo l'auto-prefix `{from}.srt` di `select()`.
  - Le logiche di `searchableFields`/`filterableFields` non sono toccate (i nomi campo arrivano dal codice sviluppatore, i valori sono già passati con escaping).

## [6.0.1] - 2026-06-03

### Fixed

- `RouteController::validateRecord()`: i valori validi ma "falsy" (`false`, `0`, `0.0`, `""`, `"0"`, `[]`) venivano scartati dal record a causa del check `!empty()`, quindi ad esempio un campo booleano impostato a `false` non veniva mai scritto in INSERT/UPDATE. Ora viene saltato solo `null` (campo assente / senza default), mentre tutti gli altri valori validati vengono inclusi.

## [6.0.0] - 2026-06-01

### Breaking Changes

- **zircote/swagger-php aggiornata da v4 a v6** — In swagger-php v6 il metodo statico `\OpenApi\Generator::scan()` è stato **rimosso** (era deprecato in 5.x). I progetti che generano lo spec OpenAPI (es. endpoint `/docs`) si rompono con `Call to undefined method OpenApi\Generator::scan()`.
  - **Migrazione**: sostituire `\OpenApi\Generator::scan([...])` con `(new \OpenApi\Generator())->generate([...])` — stesso array di sorgenti, stesso `->toJson()`. In alternativa usare il nuovo helper `Utils::generateOpenApi([...])`.
  - Eventuali opzioni passate come 2° argomento a `scan()` vanno spostate sui setter del `Generator` (`setVersion()`, `setLogger()`, `setProcessorPipeline()`) o sugli argomenti di `generate($sources, $analysis, $validate)`.
  - swagger-php v6 richiede PHP ≥ 8.2 (già soddisfatto: phplibs richiede `^8.4`).

### Added

- `Utils::generateOpenApi(iterable $sources, bool $validate = true): ?\OpenApi\Annotations\OpenApi` — helper statico che incapsula la generazione dello spec OpenAPI, offrendo ai progetti un punto d'ingresso stabile rispetto ai futuri cambi d'API di swagger-php.

## [5.3.1] - 2026-04-29

### Fixed

- `OGStorage`: l'ACL di default `'private'` causava errore `AccessControlListNotSupported` sui bucket AWS S3 moderni con object ownership `BucketOwnerEnforced` (impostazione di default dal 2023). Ora l'ACL è opzionale (`?string $acl = null`) in `upload()`, `put()`, `putBase64()`, `copy()` e `getSignedUploadUrl()`: se `null` (default) non viene incluso nei parametri della richiesta. Comportamento equivalente a `'private'` per bucket con ACL abilitato (S3 di default rende privati gli oggetti senza ACL esplicito). Chi passava un valore esplicito non è impattato.

## [5.3.0] - 2026-04-29

### Added

- `OGStorage`: nuovo parametro opzionale `cdnUrl` nel costruttore (con fallback all'env var `S3_CDN_URL`) e metodo `getCdnUrl(string $key)` per comporre l'URL CDN di un oggetto. Se il CDN non è configurato, `getCdnUrl()` ritorna l'URL S3 standard.
- `OGStorage`: i metodi `upload()`, `put()`, `putBase64()` e `copy()` ora includono `cdn_url` nel `data` di `OGResponse`, accanto a `key` e `url`. Permette ai chiamanti di salvare direttamente l'URL pubblico in DB.
- `OGStorage`: nuovo parametro opzionale `configOverride` nel costruttore, che bypassa la lettura delle env var (`S3_REGION`, `S3_ENDPOINT`, `S3_ACCESS_KEY`, ...) e usa la configurazione passata. Permette di istanziare client multipli verso bucket/cloud diversi nello stesso processo (utile ad esempio per script di migrazione cross-cloud).

## [5.2.3] - 2026-04-21

### Added

- Aggiunto supporto per la variabile d'ambiente `SQL_MODE` che permette di impostare flag SQL specifici per sessione. Utile per la migrazione graduale dalla modalità legacy (`SQL_MODE_LEGACY=true`) alla modalità strict di MariaDB/MySQL.

## [5.2.2] - 2026-04-10

### Fixed

- Risolto un bug per cui passare una stringa vuota `""` come `$dbname` in `dataBase::__construct()` e `Utils::__construct()` causava la ricerca di variabili d'ambiente inesistenti (`DB_HOST_`, `DB_USER_`, ecc.), risultando in una connessione fallita. Una stringa vuota viene ora normalizzata a `"default"`. Introdotto in v5.1.0.

## [5.2.1] - 2026-04-07

### Fixed

- Risolto un bug nel fallback della porta del database se le variabili d'ambiente personalizzate non sono definite (ora ritorna sempre `3306` di default).
- Aggiunto type casting esplicito a `int` per la porta in `dataBase.php` per evitare errori "Strict Type" in PHP 8.1+ con `mysqli_connect()`.

## [5.0.0] - 2026-03-11

### Breaking Changes

- **firebase/php-jwt aggiornata da v6 a v7.0.3** — La v7 risolve una CVE relativa alla mancata validazione della lunghezza della secret key. La libreria ora lancia un'eccezione se la chiave JWT (`JWT_SECRET`) è troppo corta rispetto all'algoritmo utilizzato. I progetti che usano chiavi corte devono aggiornarle prima di migrare alla v5.

### Migration Guide

1. Aggiornare `JWT_SECRET` in tutti gli ambienti con una chiave di lunghezza adeguata (minimo 256 bit / 32 caratteri per HS256).
2. Aggiornare `ottimis/phplibs` a `^5.0.0` in `composer.json`.
3. Eseguire `composer update ottimis/phplibs`.

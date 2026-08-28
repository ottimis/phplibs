# Changelog

## [8.2.0] - 2026-08-28

### Added

- **`TIMEZONE` — timezone dell'applicazione, condivisa tra PHP e DB.** Una sola variabile per il default PHP e per il timezone di sessione della connessione DB, con override sempre più specifici dove i due devono divergere. Catena, la prima definita vince: `DB_TIMEZONE_{name}` → `DB_TIMEZONE` → `TIMEZONE`. Nessuna definita (o vuota) = nessuna `SET` e default PHP invariato: retrocompatibile.
  - **Lato DB** (`dataBase` → `SET time_zone = '...'`, `dataBasePgsql` → `SET TIME ZONE '...'`, subito dopo la connessione): stesso schema di `SQL_MODE` ma valido su entrambi i driver. Logica condivisa nel trait `ottimis\phplibs\Traits\SessionTimezone`, ogni classe fornisce solo la sintassi del proprio dialetto. Il valore è validato prima di finire nel literal SQL (nome IANA, `UTC`/`SYSTEM`/`Local`, offset `+02:00`; max 64 caratteri): un apice o un `;` nell'env viene rifiutato con `RuntimeException`. Un timezone che il server rifiuta fa fallire la connessione invece di proseguire: continuare significherebbe leggere e scrivere date sfasate in silenzio.
  - **Lato PHP**: `Env::timezone(): ?string` legge la variabile, `Env::applyTimezone(): bool` imposta il default PHP (`date()`, `strtotime()`, `DateTime`). Da chiamare esplicitamente in `index.php` — la libreria non lo fa da sola, è stato globale del processo.
  - **⚠️ Attrito di formato tra i due consumatori**: MySQL senza tz tables caricate (`mysql_tzinfo_to_sql`, **assenti nelle immagini `mysql` ufficiali**) accetta solo l'offset `+02:00`, che però **non è un identificatore PHP valido** (`date_default_timezone_set('+02:00') === false`, come `SYSTEM`/`Local`). In quel caso: `TIMEZONE=Europe/Rome` per l'app e `DB_TIMEZONE=+02:00` solo per la connessione — è esattamente il motivo per cui esiste il livello intermedio. `applyTimezone()` in quel caso ritorna `false` senza toccare nulla né emettere warning. PostgreSQL conosce sempre i nomi IANA.
  - Non tocca `OGPdo`/`PdoConnect`, che restano con la configurazione del server.

## [8.1.0] - 2026-08-26

### Added

- **`Utils::serveOpenApi($response, $staticPath, $scanDirs = [])`** — serve la spec OpenAPI senza rigenerarla ad ogni richiesta. Finora ogni progetto chiamava `OpenApi\Generator` dentro l'handler di `/docs`: ~7 s di CPU e ~700 KB per GET su un endpoint pubblico, cioè un DoS gratuito. Ordine di risoluzione: gate chiuso → 404; `$staticPath` leggibile → file servito in streaming con `Cache-Control: public, max-age=3600`; `Env::isLocal()` + `$scanDirs` → generazione live con `Cache-Control: no-store` (in dev `src` è montato e la spec statica non esiste); altrimenti 404 `{"error":"docs_disabled"}`. Il 404 è identico nei due casi: non rivela se la spec sia stata buildata.
- **`Utils::buildOpenApi(array $dirs, string $out): int`** e il comando **`vendor/bin/og-openapi-build <scanDirs...> <out.json>`** — generano la spec a build-time (da chiamare nel `Dockerfile` del progetto, es. `RUN php vendor/bin/og-openapi-build routes classes schemas public/openapi.json`). Il comando avvisa su stderr se la spec risulta senza path (sintomo tipico di classi non autoloadabili: swagger-php le salta in silenzio e produce una spec vuota ma valida).
- **`Utils::serveSwaggerPage($response, $jsonEndpoint, $title)`** — variante di `getSwaggerPage()` che applica il gate e sa rispondere con status 404.
- **`Utils::docsEnabled(): bool`** — gate delle superfici di documentazione.
- **`DOCS_ENABLED`** — nuova env var. **Semantica invertita rispetto a `ERROR_DETAILS_ENABLED`/`LOGS_UI_ENABLED`**: quelli sono `flag && !isProduction()` (il flag non riapre mai la produzione), qui fuori produzione i docs sono aperti di default e in produzione servono `DOCS_ENABLED=true`. La spec è documentazione d'API, non disclosure di interni: chiuderla in produzione è il default sensato, riaprirla su una API pubblica è legittimo.

### Changed

- **`Utils::getSwaggerPage()` ora rispetta il gate docs**: in produzione senza `DOCS_ENABLED=true` restituisce la pagina 404 della libreria invece della Swagger UI (nessun riferimento all'endpoint della spec). La firma resta `string` per retrocompatibilità, quindi lo status resta quello impostato dal chiamante: per rispondere con un 404 corretto usare `serveSwaggerPage()`.
  - **Migrazione**: se la Swagger UI deve restare visibile in produzione, settare `DOCS_ENABLED=true`.

## [8.0.1] - 2026-08-18

### Fixed

- **`Utils::upsert()` quota gli identificatori di colonna** (backtick su MySQL, doppi apici su PostgreSQL, anche in forma `alias.colonna`): le parole riservate (`key`, `group`, `order`, `rank`, ...) funzionano senza intervento. Il nome tabella non viene quotato (può contenere alias/schema); su PG il quoting rende il nome case-sensitive, usare colonne minuscole.
- **`OGCache` preserva la parte decimale dei float** (`JSON_PRESERVE_ZERO_FRACTION`): un `19.0` messo in cache torna `float`, non più `int`.

## [8.0.0] - 2026-08-18

### Breaking Changes

- **Errori di validazione → 400 JSON strutturato (non più `text/plain`).** Il `ValidationMiddleware` (agganciato automaticamente alle route con `#[Schema]`) su errore risponde `{"error": "VALIDATION_ERROR", "message": "...", "errors": [{"field", "message"}, ...]}` con **tutti** i campi non validi, non solo il primo: `RouteController::validateRecord()` raccoglie ogni errore e lancia una `ValidationException` (`ottimis\phplibs\ValidationException`, estende `RuntimeException` → i catch esistenti continuano a funzionare) con la lista in `getErrors()` e il messaggio aggregato (`Validation failed: 'campo': msg; ...` — formato diverso dal vecchio `There is an error validating '...'`).
  - **Migrazione**: nessuna modifica ai controller. I client che facevano parsing del 400 `text/plain` devono leggere `message` (o `errors`) dal JSON.
- **Body vuoto con `#[Schema]` dichiarato: ora viene validato SEMPRE.** Prima della 8.0.0 una richiesta a body vuoto saltava del tutto la validazione; ora i campi `required` falliscono come per qualsiasi payload incompleto. Senza schema il comportamento è invariato.
  - **Migrazione**: se il body vuoto è legittimo su un endpoint con schema, rendere i campi `required: false` o togliere lo schema.
- **Body JSON malformato → 400 `{"error": "INVALID_JSON", "message": "Request body is not valid JSON"}`** (se non ci sono form param). Prima veniva silenziosamente trattato come body vuoto.
- **`?debug=1` in `Utils::slimErrorHandler()` ora richiede il flag esplicito `ERROR_DETAILS_ENABLED` (default: spento).** Prima bastava `ENVIRONMENT !== "production"`: la disclosure era dedotta dal nome dell'ambiente, e uno staging esposto su internet mostrava messaggi d'eccezione (query SQL, path interni) a chiunque. Doppia cintura: anche col flag acceso, in produzione la risposta resta il messaggio generico.
  - **Migrazione**: negli ambienti di sviluppo dove si usa `?debug=1`, settare `ERROR_DETAILS_ENABLED=true`. Senza flag il debug **non funziona più nemmeno in locale**.
- **Le rotte `/logs` di `Logger::api()` ora richiedono il flag esplicito `LOGS_UI_ENABLED` (default: spento → 404).** Stessa logica e stessa doppia cintura di `ERROR_DETAILS_ENABLED`: in produzione 404 anche col flag acceso.
  - **Migrazione**: settare `LOGS_UI_ENABLED=true` negli ambienti non-produzione dove si consulta `/logs`.
- **`ENV`/`ENVIRONMENT` consolidate nel nuovo accessor `Env` — cambia la risoluzione quando le due divergono o se ne setta una sola.** `ENV` è la canonica, `ENVIRONMENT` il fallback per i progetti storici: la prima definita vince (`?:`), la divergenza tra le due smette di essere significativa. Conseguenze osservabili:
  - `OGPdo`/`PdoConnect` prima facevano OR (bastava una delle due a dire produzione): ora se `ENV` è definita, `ENVIRONMENT` viene ignorata.
  - I siti che leggevano solo `ENVIRONMENT` (`Utils::slimErrorHandler`, `Logger::api`) ora vedono anche `ENV`: un progetto con solo `ENV=production` prima risultava *non*-produzione (bug), ora produzione.
  - **`prod` ora conta come produzione** accanto a `production` (`Env::isProduction()`): un deploy con `ENVIRONMENT=prod` prima risultava non-produzione (con `?debug=1` e `/logs` aperti!), ora è correttamente chiuso. Verificare i ConfigMap che usano questo valore.
  - Il matching è case-insensitive e con trim; nessuna delle due definita = non-produzione e non-local (degrado permissivo storico, ora mitigato dai flag espliciti sopra).
  - I check `local` per le credenziali AWS SSO (`OGMail`, `OGStorage`, `Logger` driver aws) mantengono la stessa semantica: attivano l'SSO **solo** in locale; staging e produzione continuano a usare la default credential chain (IAM role della EC2 / pod identity).

### Added

- **`Env`** (`ottimis\phplibs\Env`) — accessor unico per il nome dell'ambiente: `name()`, `is(...$names)`, `isProduction()`, `isLocal()`, `flag($name)` (parser booleano per flag espliciti: `true`/`1`/`on`/`yes`). Tutti i `getenv('ENV')`/`getenv('ENVIRONMENT')` della libreria passano da qui.
- **`OGHttp` — client HTTP completo**:
  - `request(string $method, string $url, mixed $body = null, array $headers = [])` generico + helper `put()`, `patch()`, `delete()`; `get()`, `post()`, `options()` accettano ora `$headers` per-richiesta.
  - `withHeaders(array $headers)` — header di default dell'istanza (i per-richiesta vincono, match case-insensitive); `withTimeout(int $seconds)` e timeout nel costruttore (`new OGHttp(10)`).
  - `asForm()` / `asJson()` — encoding del body array/object (`application/x-www-form-urlencoded` o JSON, default). Una stringa passa raw; un `Content-Type` esplicito negli header vince sempre.
  - Response arricchita (retrocompatibile): `headers` (header di risposta, nomi minuscoli, duplicati uniti con `", "`, solo l'ultima risposta in caso di redirect) e `error` (messaggio cURL su errore di trasporto, `null` altrimenti).
- **`ValidationException`** — porta la lista completa dei campi non validi (`getErrors()`).

## [7.3.1] - 2026-06-30

### Changed

- **`RouteController::json()` — il bare `"0"` ora diventa l'intero `0`** (prima era lasciato stringa). Dai progetti i valori arrivano dal DB (mysqli) come stringhe e `"0"` è quasi sempre un flag/contatore numerico (`id_status`, conteggi): tenerlo stringa creava l'asimmetria `id_status: 1` (numero) vs `id_status: 0` (stringa) che rompe i confronti `=== 0` lato frontend. La conversione resta lossless (nessuna cifra persa). Gli altri casi sono invariati: `"00100"`/`"01234567890"`/`"-0"` restano stringa, gli interi fuori dal range PHP restano stringa.

## [7.3.0] - 2026-06-30

### Added

- **`RouteController::json()` — helper per response JSON con conversione numerica LOSSLESS.** Le route serializzavano spesso le response con `json_encode(..., JSON_NUMERIC_CHECK)`: il flag è globale e cieco, converte **ogni** stringa numerica in numero e corrompe in modo permanente i campi-codice con zeri iniziali (partita IVA `"01234567890"` → `1234567890`, CAP `"00100"`, `code`, `id_ext`, `zip`). Il nuovo metodo `protected function json(ResponseInterface $response, mixed $data, int $status = 200): ResponseInterface` scrive il body, imposta `Content-Type: application/json` e lo status, e ritorna la response.
  - La conversione stringa→numero è **value-based** (mai sulle chiavi) e **lossless**, applicata ricorsivamente: una stringa diventa `int` solo se matcha `^-?(0|[1-9]\d*)$` **e** il round-trip è esatto (`(string)(int)$v === $v`, quindi niente zeri iniziali né overflow oltre il range PHP), `float` solo se matcha `^-?(0|[1-9]\d*)\.\d+$`; in ogni altro caso resta **stringa**. Così `"01234567890"`, `"00100"`, `"0"` e gli interi fuori range restano intatti, mentre `id`/prezzi/percentuali diventano numeri.
  - Niente whitelist di chiavi: la protezione è puramente sul valore. Serializzazione con `JSON_THROW_ON_ERROR`.
  - **Additivo e retrocompatibile**: le route esistenti continuano a funzionare; adottare `$this->json()` al posto di `json_encode(..., JSON_NUMERIC_CHECK)`.

## [7.1.1] - 2026-06-24

### Security

- **`PdoConnect` e `OGPdo` — l'errore di connessione al DB non espone più i dettagli in produzione.** Il `new PDO(...)` nel costruttore non era protetto: una `PDOException` non gestita propagava il messaggio (DSN con host/porta/database, dettagli del driver) fino alla response, esponendolo in produzione. Ora la connessione è in `try/catch (PDOException)`:
  - l'errore **reale completo** viene loggato via `Logger::error()` (driver configurato + Sentry; fallback `error_log` se il Logger fallisce) → resta visibile nei log del container per il debug;
  - in produzione (`ENVIRONMENT=production` o `ENV=production`) al client viene rilanciata una `PDOException` generica (`"Errore di connessione al database"`), senza dettagli;
  - in locale/staging l'eccezione originale viene rilanciata invariata.

## [7.1.0] - 2026-06-12

### Added

- `RouteController::addGlobalMiddlewares()`: il middleware CORS ora aggiunge `Access-Control-Max-Age` alle risposte delle richieste preflight (`OPTIONS`), permettendo al browser di cachearne l'esito invece di ripetere una OPTIONS per ogni chiamata API (le raffiche di preflight saturavano i worker php-fpm).
- `RouteController::addGlobalMiddlewares()`: header CORS ora configurabili via env (default = comportamento storico, retrocompatibile):
  - `CORS_ALLOW_ORIGIN` (default `*`) — origin singola, oppure allowlist separata da virgola (`https://a.com, https://b.com`): in tal caso si risponde con l'`Origin` della richiesta se presente in lista (altrimenti la prima della lista) e si aggiunge `Vary: Origin`.
  - `CORS_ALLOW_HEADERS` (default `X-Requested-With, Content-Type, Accept, Origin, Authorization`).
  - `CORS_ALLOW_METHODS` (default `GET, POST, PUT, PATCH, DELETE, OPTIONS`).
  - `CORS_MAX_AGE` (default `86400`).
  - `CORS_ALLOW_CREDENTIALS` (default disattivo): se `true` emette `Access-Control-Allow-Credentials: true`, ma solo quando l'origin risolta non è `*` (incompatibilità da spec CORS).

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

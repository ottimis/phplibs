# Changelog

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
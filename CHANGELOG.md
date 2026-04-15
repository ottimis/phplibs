# Changelog

## [5.2.3] - 2026-04-15

### Fixed

- Risolto un bug in `dbSelect()` per cui `SQL_CALC_FOUND_ROWS` non veniva aggiunto alla query quando `limit` era passato direttamente nelle opzioni (invece che tramite `$paging`). In questo caso `FOUND_ROWS()` restituiva il numero di righe della pagina corrente invece del totale, rendendo la paginazione non funzionante. Introdotto in v5.1.0.

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
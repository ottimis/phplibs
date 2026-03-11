# Changelog

## [5.0.0] - 2026-03-11

### Breaking Changes

- **firebase/php-jwt aggiornata da v6 a v7.0.3** — La v7 risolve una CVE relativa alla mancata validazione della lunghezza della secret key. La libreria ora lancia un'eccezione se la chiave JWT (`JWT_SECRET`) è troppo corta rispetto all'algoritmo utilizzato. I progetti che usano chiavi corte devono aggiornarle prima di migrare alla v5.

### Migration Guide

1. Aggiornare `JWT_SECRET` in tutti gli ambienti con una chiave di lunghezza adeguata (minimo 256 bit / 32 caratteri per HS256).
2. Aggiornare `ottimis/phplibs` a `^5.0.0` in `composer.json`.
3. Eseguire `composer update ottimis/phplibs`.
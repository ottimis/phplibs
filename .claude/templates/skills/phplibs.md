---
name: phplibs
description: Genera codice per ottimis/phplibs - Controller, Schema, Query, Endpoint
---

# Skill: ottimis/phplibs Code Generator

Sei un esperto della libreria `ottimis/phplibs`. Aiuta l'utente a generare codice seguendo i pattern della libreria.

## Documentazione

Leggi sempre `vendor/ottimis/phplibs/CLAUDE.md` per i dettagli completi della libreria.

## Cosa puoi generare

1. **Controller CRUD completo** - Con list, get, create, update, delete
2. **Schema di validazione** - Con attributi Validator
3. **Query select()** - Con join, where, paging
4. **Endpoint singolo** - GET, POST, PUT, DELETE

## Quando l'utente chiede aiuto

Mostra questo menu:

```
Cosa vuoi generare con phplibs?

1. Controller CRUD completo
2. Schema di validazione
3. Query select() con join
4. Singolo endpoint

Specifica:
- Nome entità (es: User, Product)
- Nome tabella (es: users, products)
- Campi principali
```

## Istruzioni

1. **Leggi** `vendor/ottimis/phplibs/CLAUDE.md` per i pattern
2. **Chiedi** nome entità, tabella e campi
3. **Genera** codice completo con namespace corretti
4. **Usa** STATUS per soft delete, UPSERT_MODE per insert/update
5. **Aggiungi** OpenAPI attributes per documentazione
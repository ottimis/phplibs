# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

<!-- Modifica questa sezione per il tuo progetto -->
- **Nome**: [Nome Progetto]
- **Descrizione**: [Descrizione]
- **PHP Version**: 8.4+
- **Framework**: Slim 4.x + ottimis/phplibs

## Libreria phplibs

Questo progetto usa `ottimis/phplibs` per database, routing, validazione e utilities.

**Documentazione completa**: `vendor/ottimis/phplibs/CLAUDE.md`

### Quick Reference

```php
use ottimis\phplibs\Utils;
use ottimis\phplibs\RouteController;
use ottimis\phplibs\Validator;
use ottimis\phplibs\schemas\UPSERT_MODE;
use ottimis\phplibs\schemas\STATUS;
```

### Pattern comuni in questo progetto

#### Controller
```php
class EntityController extends RouteController {
    protected string $tableName = "entities";

    public function _getList(...) { }  // GET /entities
    public function _get(...) { }       // GET /entities/{id}
    public function _post(...) { }      // POST /entities
    public function _put(...) { }       // PUT /entities/{id}
    public function _delete(...) { }    // DELETE /entities/{id}
}
```

#### Query
```php
$this->Utils->select([
    "select" => ["*"],
    "from" => "table",
    "where" => [["field" => "id_status", "value" => STATUS::ACTIVE->value]]
], $paging);
```

#### Upsert
```php
$this->Utils->upsert(UPSERT_MODE::INSERT, "table", $data);
$this->Utils->upsert(UPSERT_MODE::UPDATE, "table", $data, ["id" => $id]);
```

## Comandi

```bash
composer install          # Installa dipendenze
composer dump-autoload    # Rigenera autoloader
php -S localhost:8080     # Dev server (nella cartella public/)
```

## Struttura Progetto

<!-- Modifica in base alla tua struttura -->
```
├── public/
│   └── index.php         # Entry point
├── src/
│   ├── Controllers/      # Route controllers
│   ├── Schemas/          # Validation schemas
│   ├── Middlewares/      # Custom middlewares
│   └── Services/         # Business logic
├── config/
│   └── routes.php        # Route mapping
└── composer.json
```

## Environment Variables

```env
DB_HOST=localhost
DB_USER=root
DB_PASSWORD=
DB_NAME=database
DB_PORT=3306

LOG_DRIVER=local
LOG_SERVICE_NAME=my-service
```
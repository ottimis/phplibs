# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**ottimis/phplibs** is a PHP library (v4.10.0) providing tools for building RESTful APIs with Slim Framework. It includes database abstraction, routing, validation, logging, email, and HTTP utilities.

- **Namespace**: `ottimis\phplibs`
- **PHP Version**: 8.4+
- **Framework**: Slim 4.x

## Commands

```bash
composer install          # Install dependencies
composer update           # Update dependencies
composer dump-autoload    # Regenerate autoloader
```

## Architecture

### Database Layer (Three Options)

1. **`dataBase`** - MySQL/MariaDB via mysqli (singleton pattern)
2. **`OGPdo`** - Multi-database PDO wrapper (MySQL, PostgreSQL, SQL Server, SQLite, Oracle)
3. **`PdoConnect`** - SQL Server specific PDO wrapper

### Core Classes

- **`Utils`** - Main query builder with `select()`, `upsert()`, pagination, and search. Wraps `dataBase` singleton.
- **`RouteController`** - Base controller with attribute-based routing for Slim
- **`Validator`** - PHP attribute for property validation
- **`Logger`** - Multi-driver logging (db, logstash, AWS CloudWatch, GELF/Graylog)
- **`Auth`** - JWT authentication with Firebase JWT library
- **`OGMail`** - Email via SMTP (PHPMailer) or AWS SES

### Environment Variables

Database:
- `DB_HOST`, `DB_USER`, `DB_PASSWORD`, `DB_NAME`, `DB_PORT`
- Multi-db: `DB_HOST_{name}`, `DB_USER_{name}`, etc.

Logging:
- `LOG_DRIVER` (db, logstash, aws, gelf, gelf-tcp, local)
- `LOG_SERVICE_NAME`, `GELF_HOST`, `GELF_PORT`

---

## Utils Class (Complete Reference)

### Instantiation

```php
// Singleton (recommended) - reuses same DB connection
$utils = new Utils();                    // default database
$utils = new Utils("secondary");         // named database (uses DB_HOST_secondary, etc.)
$utils = Utils::getInstance();           // explicit singleton
$utils = Utils::getInstance("secondary");

// New instance (separate connection)
$utils = Utils::createNew();
$utils = Utils::createNew("secondary");
```

### Transactions

```php
$utils->startTransaction();
try {
    // ... operations ...
    $utils->commitTransaction();
} catch (Exception $e) {
    $utils->rollbackTransaction();
}
```

---

### select() Method - Main Query Builder

**Signature**: `select(array $req, array $paging = [], bool $sqlOnly = false): array|string`

#### Basic Structure

```php
$result = $utils->select([
    "select" => ["id", "name", "email"],  // REQUIRED: array of fields
    "from" => "users",                     // REQUIRED: table name (with optional alias)
    "where" => [...],                      // conditions
    "join" => [...],                       // LEFT JOIN by default
    "leftJoin" => [...],                   // explicit LEFT JOIN
    "rightJoin" => [...],                  // RIGHT JOIN
    "innerJoin" => [...],                  // INNER JOIN
    "group" => "category_id",              // GROUP BY
    "order" => "name ASC",                 // ORDER BY
    "limit" => [0, 10],                    // LIMIT offset, count
    "other" => "FOR UPDATE",               // raw SQL appended at end
    "distinct" => true,                    // SELECT DISTINCT
    "count" => true,                       // return total count
    "decode" => ["json_field"],            // auto json_decode these fields
    "map" => fn($row) => $row,             // transform each row
    "log" => true,                         // log query to Logger
    "cte" => [...],                        // Common Table Expressions
]);
```

#### Select Fields

```php
"select" => ["*"]                          // all fields from main table
"select" => ["id", "name"]                 // auto-prefixed: users.id, users.name
"select" => ["u.id", "p.name"]             // explicit table prefix (not modified)
"select" => ["COUNT(*) as total"]          // functions (not modified)
"select" => ["id", "name AS full_name"]    // aliases
```

**Important**: Fields without `.` or `(` are auto-prefixed with the `from` table name.

#### Where Conditions

```php
"where" => [
    // Simple equality (no operator = equals)
    ["field" => "id", "value" => 1],

    // With operator
    ["field" => "age", "operator" => ">=", "value" => 18],
    ["field" => "name", "operator" => "LIKE", "value" => "%john%"],
    ["field" => "status", "operator" => "!=", "value" => "deleted"],

    // IN operator
    ["field" => "id", "operator" => "IN", "value" => [1, 2, 3]],

    // BETWEEN operator
    ["field" => "date", "operator" => "BETWEEN", "value" => ["2024-01-01", "2024-12-31"]],

    // IS NULL / IS NOT NULL
    ["field" => "deleted_at", "operator" => "IS", "value" => "NULL"],
    ["field" => "email", "operator" => "IS NOT", "value" => "NULL"],

    // Custom raw SQL
    ["custom" => "(status = 1 OR role = 'admin')"],

    // Logical operators between conditions (default is AND)
    ["field" => "a", "value" => 1, "operatorAfter" => "OR"],
    ["field" => "b", "value" => 2],  // a = 1 OR b = 2

    // Parentheses with before/end
    ["field" => "x", "value" => 1, "before" => "(", "operatorAfter" => "OR"],
    ["field" => "y", "value" => 2, "end" => ")"],
]
```

**Auto-prefixing**: Fields without `.` are prefixed with the `from` table.

#### Joins (New Syntax for select())

```php
"join" => [  // or "leftJoin", "rightJoin", "innerJoin"
    [
        "table" => "profiles",           // REQUIRED
        "alias" => "p",                  // optional, defaults to table name
        "on" => ["id_profile"],          // simple: main.id_profile = profiles.id
        "on" => ["id_profile", "id"],    // explicit: main.id_profile = profiles.id
        "fields" => ["name", "bio"],     // auto-added to SELECT with alias prefix
    ],
    [
        "table" => "roles",
        "alias" => "r",
        "on" => ["id_role", "role_id"],  // main.id_role = r.role_id
        "fields" => ["name AS role_name"],
    ]
]
```

**Join builds**: `{joinType} {table} {alias} ON {from}.{on[0]} = {alias}.{on[1] ?? 'id'}`

#### Return Values

**Without paging/count**:
```php
[
    "success" => true,
    "data" => [
        ["id" => 1, "name" => "John"],
        ["id" => 2, "name" => "Jane"],
    ]
]
```

**With paging or count**:
```php
[
    "success" => true,
    "rows" => [...],      // data array
    "total" => 100,       // total records (SQL_CALC_FOUND_ROWS)
    "count" => 10,        // records in this page
]
```

**On error**:
```php
[
    "success" => false,
    "error" => "MySQL error message"
]
```

#### Data Transformation

```php
// Auto JSON decode specific fields
"decode" => ["settings", "metadata"],

// Transform each row with callback
"map" => function($row) {
    $row['full_name'] = $row['first_name'] . ' ' . $row['last_name'];
    return $row;  // return null to exclude row from results
},
```

#### Get SQL Only

```php
$sql = $utils->select([...], [], true);  // third param = sqlOnly
// Returns: "SELECT ... FROM ... WHERE ..."
```

---

### Pagination System

```php
$paging = [
    "p" => 1,                              // page number (1-based)
    "c" => 20,                             // count per page (default 20)
    "s" => "john",                         // search term (min 2 chars)
    "srt" => "name",                       // sort field
    "o" => "ASC",                          // order: ASC or DESC

    // Define searchable fields (OR search with LIKE %term%)
    "searchableFields" => ["name", "email", "p.description"],

    // Define filterable fields (exact match)
    "filterableFields" => ["status", "category_id"],

    // Pass filter values directly in paging array
    "status" => "active",                  // adds: status = 'active'
    "category_id" => 5,                    // adds: category_id = '5'

    "noTotal" => true,                     // skip SQL_CALC_FOUND_ROWS (performance)
];

$result = $utils->select($arSql, $paging);
// Returns: rows, total, count
```

**How it works**:
1. `searchableFields` + `s`: Creates `(field1 LIKE '%s%' OR field2 LIKE '%s%')`
2. `filterableFields` + values: Creates `field = 'value' AND ...`
3. `srt` + `o`: Sets ORDER BY
4. `p` + `c`: Calculates LIMIT offset
5. Adds `SQL_CALC_FOUND_ROWS` for total count (unless `noTotal`)

---

### upsert() Method

**Signature**: `upsert(UPSERT_MODE $mode, string $table, array $ar, array $fieldWhere = [], bool $noUpdate = false): array`

```php
use ottimis\phplibs\schemas\UPSERT_MODE;

// INSERT (with ON DUPLICATE KEY UPDATE by default)
$result = $utils->upsert(UPSERT_MODE::INSERT, "users", [
    "name" => "John",
    "email" => "john@example.com",
    "created_at" => "now()",        // Special: MySQL NOW()
    "is_active" => true,            // Converted to 1
    "deleted_at" => null,           // Converted to NULL
    "settings" => ["theme" => "dark"], // Arrays/objects auto JSON encoded
]);

// INSERT without ON DUPLICATE KEY UPDATE
$result = $utils->upsert(UPSERT_MODE::INSERT, "users", $data, [], true);

// UPDATE
$result = $utils->upsert(UPSERT_MODE::UPDATE, "users",
    ["name" => "John Updated"],     // fields to update
    ["id" => 1]                     // WHERE conditions
);

// UPDATE with multiple WHERE conditions
$result = $utils->upsert(UPSERT_MODE::UPDATE, "users",
    ["status" => "inactive"],
    ["id" => 1, "tenant_id" => 5]   // WHERE id = 1 AND tenant_id = 5
);
```

#### Special Values in upsert()

| Value | Converted To |
|-------|--------------|
| `'now()'` | `now()` (MySQL function) |
| `true` | `1` |
| `false` | `0` |
| `null` | `NULL` |
| `array` / `object` | JSON encoded string |
| other | Escaped string |

#### Return Value

```php
[
    "success" => 1,              // or 0 on error
    "id" => 123,                 // last insert ID
    "affectedRows" => 1,
    "sql" => "INSERT INTO ...",  // generated SQL
    "error" => "..."             // only on error
]
```

---

### dbSelect() - Legacy Method

Similar to `select()` but uses older join syntax:

```php
$utils->dbSelect([
    "select" => ["u.id", "p.name"],
    "from" => "users u",
    "join" => [
        ["profiles p", "p.user_id = u.id"],  // [table, condition]
    ],
    "where" => [...],
]);
```

**Prefer `select()` for new code** - it has better auto-prefixing and join syntax.

---

### Helper Methods

#### _combo_list() - Dropdown Data

```php
$options = $utils->_combo_list([
    "table" => "categories",
    "value" => "id",           // default: "id"
    "text" => "name",          // default: "text"
    "other_field" => "code",   // additional field
    "order" => "name ASC",     // default: "text ASC"
    "where" => "active = 1",   // raw WHERE clause
]);
// Returns: [["id" => 1, "text" => "Category 1"], ...]
```

#### slimErrorHandler() - Slim Error Middleware

```php
// In index.php after AppFactory::create()
$app->addRoutingMiddleware();  // REQUIRED before error handler
Utils::slimErrorHandler($app, "Errore personalizzato");
```

Handles:
- 404/405 errors → custom HTML page
- Other exceptions → logs to Logger, returns 500
- `?debug=1` query param shows exception message

#### getSwaggerPage() - Swagger UI

```php
$app->get('/docs', function ($request, $response) {
    $html = Utils::getSwaggerPage('/api/openapi.json', 'My API Docs');
    $response->getBody()->write($html);
    return $response->withHeader('Content-Type', 'text/html');
});
```

---

### Common Patterns

#### Soft Delete

```php
use ottimis\phplibs\schemas\STATUS;

// Select only active records
$utils->select([
    "select" => ["*"],
    "from" => "users",
    "where" => [
        ["field" => "id_status", "value" => STATUS::ACTIVE->value]
    ]
]);

// Soft delete
$utils->upsert(UPSERT_MODE::UPDATE, "users",
    ["id_status" => STATUS::CANCELLED->value],
    ["id" => $id]
);
```

#### Paginated API Endpoint

```php
public function _getList(Request $request, Response $response): Response {
    $q = $request->getQueryParams();

    $paging = [
        "p" => $q['page'] ?? 1,
        "c" => $q['per_page'] ?? 20,
        "s" => $q['search'] ?? "",
        "srt" => $q['sort'] ?? "created_at",
        "o" => $q['order'] ?? "DESC",
        "searchableFields" => ["name", "email"],
        "filterableFields" => ["status", "role"],
        "status" => $q['status'] ?? null,
        "role" => $q['role'] ?? null,
    ];

    $result = $this->Utils->select([
        "select" => ["*"],
        "from" => "users",
        "where" => [
            ["field" => "id_status", "value" => STATUS::ACTIVE->value]
        ]
    ], $paging);

    $response->getBody()->write(json_encode($result));
    return $response->withHeader('Content-Type', 'application/json');
}
```

---

## Logger Class (Complete Reference)

Sistema di logging multi-driver con supporto per database, Logstash, AWS CloudWatch e GELF/Graylog.

### Instantiation

```php
use ottimis\phplibs\Logger;

// Singleton (recommended)
$logger = Logger::getInstance();
$logger = Logger::getInstance("my-service");  // con service name

// Nuova istanza
$logger = Logger::createNew("my-service");
```

### Environment Variables

```env
LOG_DRIVER=local          # Driver: db, logstash, aws, gelf, gelf-tcp, local
LOG_SERVICE_NAME=my-app   # Nome servizio (usato in tutti i driver)
LOG_TAG_NAME=service_name # Nome tag per GELF (default: service_name)
LOG_ENDPOINT=logstash.logs:8080  # Endpoint Logstash

# Per GELF/Graylog
GELF_HOST=graylog.example.com
GELF_PORT=12201

# Per AWS CloudWatch
AWS_REGION=eu-central-1   # Region AWS (default: eu-central-1)
```

### Log Drivers

| Driver | Destinazione | Note |
|--------|--------------|------|
| `local` | `error_log()` | Default, scrive nei log PHP |
| `db` | Tabella `logs` | Richiede tabella MySQL |
| `logstash` | HTTP endpoint | Invia JSON via cURL |
| `aws` | CloudWatch Logs | Usa AWS SDK |
| `gelf` | Graylog (UDP) | Usa libreria gelf-php |
| `gelf-tcp` | Graylog (TCP) | Connessione TCP persistente |

### Metodi di Logging

```php
// INFO - Log informativo semplice
$logger->log("Operazione completata", "CODE_001");
$logger->log("User login", "AUTH", ["user_id" => 123]);

// WARNING - Include stacktrace automatico + notifica
$logger->warning("Parametri mancanti", "WARN_001");
$logger->warning("Rate limit quasi raggiunto", "RATE", ["current" => 95]);

// ERROR - Include stacktrace + notifica Notify::notify()
$logger->error("Connessione database fallita", "DB_ERR");
$logger->error("Payment failed", "PAY_ERR", ["order_id" => 456]);
```

### Signature dei metodi

```php
log(string $note, ?string $code = null, array $data = []): bool|void
warning(string $note, ?string $code = null, array $data = []): bool|void
error(string $note, ?string $code = null, array $data = []): bool|void
```

| Parametro | Descrizione |
|-----------|-------------|
| `$note` | Messaggio di log (obbligatorio) |
| `$code` | Codice identificativo (opzionale, per filtrare) |
| `$data` | Dati aggiuntivi come array (opzionale) |

### Livelli e comportamento

| Metodo | Type DB | Stacktrace | Notifica |
|--------|---------|------------|----------|
| `log()` | 1 | No | No |
| `warning()` | 2 | Sì | Sì |
| `error()` | 3 | Sì | Sì |

### Schema tabella `logs` (per driver db)

```sql
CREATE TABLE logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type TINYINT NOT NULL,           -- 1=info, 2=warning, 3=error
    note TEXT,
    code VARCHAR(50),
    stacktrace TEXT,
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### Lettura Logs

```php
// Lista ultimi 1000 log (HTML)
$html = Logger::listLogs(["limit" => 1000]);

// Filtra per type
$html = Logger::listLogs(["type" => 3]);  // solo errori

// Filtra per code
$html = Logger::listLogs([
    "where" => [
        ["field" => "code", "value" => "AUTH"]
    ]
]);

// Ritorna array invece di HTML
$logs = Logger::listLogs(["limit" => 100], true);
```

### API Endpoint (Slim)

```php
// In index.php - aggiunge /logs e /logs/{code}
Logger::api($app);
```

Crea automaticamente:
- `GET /logs` → Lista ultimi 1000 log (HTML)
- `GET /logs/{code}` → Log filtrati per codice

**Nota**: Disabilitato automaticamente in produzione (`ENVIRONMENT=production`).

### Integrazione con Utils

`Utils` usa automaticamente `Logger` per errori nelle query:

```php
// Se una query fallisce, viene loggato automaticamente:
// "Errore query: SELECT ... DB message: ..." con code "DBSLC2"
```

### Pattern: Try-Catch con Logging

```php
$logger = Logger::getInstance();

try {
    // operazione rischiosa
    $result = $this->processPayment($order);
    $logger->log("Payment processed", "PAY_OK", ["order_id" => $order->id]);
} catch (PaymentException $e) {
    $logger->error("Payment failed: " . $e->getMessage(), "PAY_ERR", [
        "order_id" => $order->id,
        "amount" => $order->amount,
        "gateway_response" => $e->getGatewayResponse()
    ]);
    throw $e;
}
```

### Pattern: Log in Controller

```php
class OrderController extends RouteController
{
    public function _post(Request $request, Response $response): Response
    {
        $data = $request->getAttribute('validatedBody');
        $logger = Logger::getInstance();

        $result = $this->Utils->upsert(UPSERT_MODE::INSERT, "orders", $data);

        if ($result['success']) {
            $logger->log("Order created", "ORDER_NEW", ["id" => $result['id']]);
        } else {
            $logger->error("Order creation failed", "ORDER_ERR", $data);
        }

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
```

### Output per Driver

**Driver `db`**: Inserisce nella tabella `logs`

**Driver `logstash`**: Invia JSON
```json
{
    "level": "error",
    "code": "PAY_ERR",
    "note": "Payment failed",
    "stacktrace": "[...]",
    "hostname": "server-01",
    "service": "my-service",
    "order_id": 456
}
```

**Driver `gelf`/`gelf-tcp`**: Invia a Graylog con:
- Messaggio: `$note`
- Additional fields: `$data` + `stacktrace`
- Tag: `service_name` (configurabile via `LOG_TAG_NAME`)

**Driver `aws`**: Invia a CloudWatch con:
- Log Group: `{serviceName}-log-group`
- Log Stream: `{serviceName}-log-stream`
- Messaggio: JSON completo

---

## Sentry Integration

Sentry error tracking runs **in parallel** with the configured `LOG_DRIVER`. Only `error()` calls are sent to Sentry.

### Environment Variables

```env
SENTRY_DSN=https://examplePublicKey@o0.ingest.sentry.io/0   # Required to activate Sentry
SENTRY_ENVIRONMENT=production    # Environment tag (default: production)
SENTRY_RELEASE=1.2.3             # Release/version tag (optional)
SENTRY_TRACES_SAMPLE_RATE=0.0    # Performance tracing rate (default: 0.0 = disabled)
```

### Behavior

| Condition | Result |
|-----------|--------|
| `SENTRY_DSN` not set | Sentry is completely disabled, no overhead |
| `SENTRY_DSN` set | Sentry initializes on Logger construction |
| `error()` called | Event sent to Sentry with `service` and `error_code` tags |
| `error()` with `$exception` | Full stack trace captured via `captureException()` |
| `error()` without `$exception` | Message captured via `captureMessage()` |
| Sentry unreachable | Silently fails, error logged to `error_log()` |

### Usage

```php
$logger = Logger::getInstance();

// Simple error message → captureMessage to Sentry
$logger->error("Database connection failed", "DB_ERR");

// With exception → captureException to Sentry (full stack trace)
try {
    riskyOperation();
} catch (\Exception $e) {
    $logger->error("Operation failed: " . $e->getMessage(), "OP_ERR", ["context" => "data"], $e);
}
```

### Slim Error Handler

`Utils::slimErrorHandler()` automatically passes the caught exception to Sentry via `Logger::error()`. If Logger itself fails, a direct Sentry fallback captures the exception.

---

## Route Mapping Convention

Controller methods follow this pattern:
```php
public function _getUsers()    // GET /users
public function _postUser()    // POST /user
public function _putUser()     // PUT /user
public function _deleteUser()  // DELETE /user
```

Use `#[Path("/custom/path")]` to override, `#[Methods([Method::GET, Method::POST])]` for multiple methods.

---

## Validation Schema

```php
class UserSchema {
    #[Validator(required: true, minLength: 3)]
    public string $username;

    #[Validator(format: VALIDATOR_FORMAT::EMAIL)]
    public string $email;

    #[Validator(type: VALIDATOR_TYPE::INTEGER, min: 0)]
    public ?int $age;

    #[Validator(readOnly: true)]  // excluded from validated data
    public ?string $id;
}

// In controller
#[Schema(UserSchema::class)]
public function _post(Request $request, Response $response) {
    $validated = $request->getAttribute('validatedBody');
}
```

### Key Enums

- `UPSERT_MODE::INSERT` / `UPSERT_MODE::UPDATE`
- `STATUS::ACTIVE` (1) / `STATUS::CANCELLED` (2)
- `VALIDATOR_TYPE::STRING`, `INTEGER`, `FLOAT`, `BOOLEAN`, `ARRAY`
- `VALIDATOR_FORMAT::DATE`, `DATE_TIME`, `EMAIL`

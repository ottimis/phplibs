---
name: phplibs
description: Genera codice per ottimis/phplibs - Controller, Schema, Query, Endpoint
---

# Skill: ottimis/phplibs Code Generator

Sei un esperto della libreria `ottimis/phplibs`. Quando l'utente invoca questa skill, aiutalo a generare codice seguendo i pattern della libreria.

## Riferimento Rapido

### Namespace
```php
use ottimis\phplibs\Utils;
use ottimis\phplibs\RouteController;
use ottimis\phplibs\Validator;
use ottimis\phplibs\schemas\UPSERT_MODE;
use ottimis\phplibs\schemas\STATUS;
use ottimis\phplibs\schemas\VALIDATOR_TYPE;
use ottimis\phplibs\schemas\VALIDATOR_FORMAT;
use ottimis\phplibs\Middleware;
use ottimis\phplibs\Path;
use ottimis\phplibs\Methods;
use ottimis\phplibs\Method;
use ottimis\phplibs\Schema;
```

## Cosa puoi generare

Chiedi all'utente cosa vuole creare:

1. **Controller CRUD completo** - Con list, get, create, update, delete
2. **Schema di validazione** - Con attributi Validator
3. **Query select()** - Con join, where, paging
4. **Endpoint singolo** - GET, POST, PUT, DELETE
5. **Middleware di autenticazione**

## Template: Controller CRUD

```php
<?php

namespace App\Controllers;

use ottimis\phplibs\RouteController;
use ottimis\phplibs\Middleware;
use ottimis\phplibs\Path;
use ottimis\phplibs\Schema;
use ottimis\phplibs\schemas\UPSERT_MODE;
use ottimis\phplibs\schemas\STATUS;
use Slim\Psr7\Request;
use Slim\Psr7\Response;

#[Middleware(["auth"])]
class {{EntityName}}Controller extends RouteController
{
    protected string $tableName = "{{table_name}}";

    // GET /{{base_path}} - Lista con paginazione
    #[Path("/{{base_path}}")]
    public function _getList(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();

        $paging = [
            "p" => $q['page'] ?? 1,
            "c" => $q['per_page'] ?? 20,
            "s" => $q['search'] ?? "",
            "srt" => $q['sort'] ?? "created_at",
            "o" => $q['order'] ?? "DESC",
            "searchableFields" => [{{searchable_fields}}],
            "filterableFields" => [{{filterable_fields}}],
        ];

        $result = $this->Utils->select([
            "select" => ["*"],
            "from" => $this->tableName,
            "where" => [
                ["field" => "id_status", "value" => STATUS::ACTIVE->value]
            ]
        ], $paging);

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // GET /{{base_path}}/{id} - Singolo record
    #[Path("/{{base_path}}/{id}")]
    public function _get(Request $request, Response $response, array $args): Response
    {
        try {
            $result = $this->get($args['id']);
            $response->getBody()->write(json_encode($result));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode(["error" => $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }
    }

    // POST /{{base_path}} - Crea nuovo
    #[Path("/{{base_path}}")]
    #[Schema({{EntityName}}Schema::class)]
    public function _post(Request $request, Response $response): Response
    {
        $data = $request->getAttribute('validatedBody');
        $data['created_at'] = 'now()';
        $data['id_status'] = STATUS::ACTIVE->value;

        $result = $this->Utils->upsert(UPSERT_MODE::INSERT, $this->tableName, $data);

        if ($result['success']) {
            $response->getBody()->write(json_encode([
                "success" => true,
                "id" => $result['id']
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
        }

        $response->getBody()->write(json_encode(["error" => $result['error']]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
    }

    // PUT /{{base_path}}/{id} - Aggiorna
    #[Path("/{{base_path}}/{id}")]
    #[Schema({{EntityName}}Schema::class)]
    public function _put(Request $request, Response $response, array $args): Response
    {
        $data = $request->getAttribute('validatedBody');
        $data['updated_at'] = 'now()';

        $result = $this->Utils->upsert(UPSERT_MODE::UPDATE, $this->tableName, $data, [
            "id" => $args['id']
        ]);

        $response->getBody()->write(json_encode(["success" => (bool)$result['success']]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // DELETE /{{base_path}}/{id} - Soft delete
    #[Path("/{{base_path}}/{id}")]
    public function _delete(Request $request, Response $response, array $args): Response
    {
        try {
            $result = $this->delete($args['id']);
            $response->getBody()->write(json_encode($result));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode(["error" => $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }
}
```

## Template: Schema di Validazione

```php
<?php

namespace App\Schemas;

use ottimis\phplibs\Validator;
use ottimis\phplibs\schemas\VALIDATOR_TYPE;
use ottimis\phplibs\schemas\VALIDATOR_FORMAT;
use OpenApi\Attributes as OA;

#[OA\Schema(schema: "{{EntityName}}")]
class {{EntityName}}Schema
{
    #[OA\Property(readOnly: true)]
    #[Validator(readOnly: true)]
    public ?string $id = null;

    #[OA\Property(description: "Nome")]
    #[Validator(required: true, minLength: 2, maxLength: 100)]
    public string $name;

    #[OA\Property(description: "Email")]
    #[Validator(required: true, format: VALIDATOR_FORMAT::EMAIL)]
    public string $email;

    #[OA\Property(description: "Età")]
    #[Validator(required: false, type: VALIDATOR_TYPE::INTEGER, min: 0, max: 150)]
    public ?int $age = null;

    #[OA\Property(description: "Data di nascita")]
    #[Validator(required: false, format: VALIDATOR_FORMAT::DATE)]
    public ?string $birth_date = null;

    #[OA\Property(description: "Ruolo")]
    #[Validator(required: false, enum: ["admin", "user", "guest"])]
    public ?string $role = null;
}
```

## Template: Query Complessa

```php
$result = $this->Utils->select([
    "select" => ["id", "name", "email"],
    "from" => "{{table_name}}",
    "join" => [
        [
            "table" => "{{join_table}}",
            "alias" => "jt",
            "on" => ["id_{{join_table}}", "id"],
            "fields" => ["name AS {{join_table}}_name"]
        ]
    ],
    "where" => [
        ["field" => "id_status", "value" => STATUS::ACTIVE->value],
        ["field" => "created_at", "operator" => ">=", "value" => $startDate],
    ],
    "order" => "created_at DESC",
], $paging);
```

## Istruzioni

1. **Chiedi sempre** il nome dell'entità e della tabella
2. **Chiedi i campi** necessari per lo schema
3. **Genera codice completo** e funzionante
4. **Usa i pattern** della libreria (STATUS per soft delete, UPSERT_MODE, ecc.)
5. **Includi sempre** i namespace corretti
6. **Aggiungi OpenAPI** attributes per la documentazione automatica

## Quando l'utente chiede aiuto

Se l'utente non specifica cosa vuole, mostra questo menu:

```
Cosa vuoi generare con phplibs?

1. Controller CRUD completo
2. Schema di validazione
3. Query select() con join
4. Singolo endpoint
5. Setup index.php con routing

Specifica anche:
- Nome entità (es: User, Product)
- Nome tabella (es: users, products)
- Campi principali
```
<?php

namespace ottimis\phplibs;

use Attribute;
use Exception;
use ottimis\phplibs\Middlewares\ValidationMiddleware;
use ottimis\phplibs\schemas\Base\OGResponse;
use ottimis\phplibs\schemas\STATUS;
use ottimis\phplibs\schemas\UPSERT_MODE;
use ReflectionClass;
use ReflectionException;
use RuntimeException;
use Slim\App;
use Slim\Psr7\Response;
use OpenApi\Attributes as OA;

#[Attribute]
class Middleware
{
    public function __construct(public array $middlewares)
    {
    }
}

#[Attribute]
class Path
{
    public function __construct(public string $path)
    {
    }
}

// Repeatable attribute
#[Attribute]
class Methods
{
    public function __construct(public array $methods)
    {
    }
}

class Method
{
    public const string GET = 'GET';
    public const string POST = 'POST';
    public const string PUT = 'PUT';
    public const string DELETE = 'DELETE';
    public const string PATCH = 'PATCH';
    public const string OPTIONS = 'OPTIONS';
    public const string HEAD = 'HEAD';
}

#[Attribute(Attribute::TARGET_METHOD)]
class Schema
{
    public function __construct(public string $class)
    {
    }
}

class RouteController
{
    protected static array $middlewareRegistry = [];
    protected Utils $Utils;
    protected string $tableName;

    public function __construct($dbName = "default")
    {
        if ($dbName !== false) {
            $this->Utils = new Utils($dbName);
        }
    }

    /**
     * @throws Exception
     */
    private function checkDbConsistency(): void
    {
        // Check if Utils is instanced
        if (!$this->Utils->dataBase) {
            throw new RuntimeException("Database is not initialized");
        }
        if (!$this->tableName) {
            throw new RuntimeException("Table name not set");
        }
    }

    /**
     * @throws ReflectionException
     */
    public function validateRecord(array $data, mixed $schema): array
    {
        // Get all variable attributes from the schema
        $reflection = new ReflectionClass($schema);
        $properties = $reflection->getProperties();

        $record = [];
        foreach ($properties as $property) {
            $isReadOnly = false;

            // Check if the property has the OpenApi Property attribute readOnly
            $propertyAttributes = $property->getAttributes(OA\Property::class);
            $propertyName = $property->getName();
            foreach ($propertyAttributes as $attribute) {
                $propertyAttribute = $attribute->newInstance();
                if ($propertyAttribute->readOnly === true) {
                    $isReadOnly = true;
                    continue;
                }
            }
            // Check if the property has the Validator attribute
            $validatorAttributes = $property->getAttributes(Validator::class);
            if (empty($validatorAttributes)) {
                $record[$propertyName] = $data[$propertyName] ?? null;
                continue;
            }
            foreach ($validatorAttributes as $attribute) {
                $validator = $attribute->newInstance();
                if ($validator->readOnly) {
                    $isReadOnly = true;
                    continue;
                }
                // Validate property
                $resValid = $validator->validate($data[$propertyName] ?? null);
                if (!$resValid['success']) {
                    throw new RuntimeException("There is an error validating '$propertyName': " . $resValid['message']);
                }
            }
            if (!$isReadOnly && !empty($resValid['value'])) {
                $record[$propertyName] = $resValid['value'];
            }
        }

        return $record;
    }

    /**
     * @throws Exception
     */
    protected function get($id, $joinTables = [], $select = null): OGResponse
    {
        $this->checkDbConsistency();
        $arSql = [
            "select" => $select ?? [
                    "*"
                ],
            "from" => $this->tableName,
            "join" => $joinTables,
            "where" => [
                [
                    "field" => "id",
                    "value" => $id,
                ],
                [
                    "field" => "id_status",
                    "value" => STATUS::ACTIVE->value,
                ]
            ]
        ];

        $res = $this->Utils->select($arSql);
        if (count($res['data']) === 0) {
            throw new RuntimeException("Record not found");
        }

        $res = $res['data'][0];
        return new OGResponse(
            success: true,
            data: $res,
        );
    }

    /**
     * @throws Exception
     */
    protected function list(array $q): OGResponse
    {
        $this->checkDbConsistency();
        $arSql = [
            "select" => [
                "*"
            ],
            "from" => $this->tableName,
            "where" => [
                [
                    "field" => "id_status",
                    "value" => STATUS::ACTIVE->value,
                ]
            ]
        ];

        $res = $this->Utils->select($arSql, $q);

        return new OGResponse(
            success: true,
            data: $res
        );
    }

    /**
     * @throws Exception
     */
    public function delete(string $id): OGResponse
    {
        $this->checkDbConsistency();
        $ar = array(
            "id_status" => STATUS::CANCELLED->value,
        );

        $res = $this->Utils->upsert(UPSERT_MODE::UPDATE, $this->tableName, $ar, [
            "id" => $id
        ]);
        if (!$res['success']) {
            throw new RuntimeException($res['error']);
        }

        return new OGResponse(
            success: true,
        );
    }

    // Metodo per mappare le rotte in modo statico per ciascun controller

    /**
     * @throws ReflectionException
     */
    public static function mapControllerRoutes(App $app, string $controllerClass, string $basePath = ''): void
    {
        $controllerInstance = new $controllerClass(); // Istanza temporanea solo per il reflection
        $reflection = new ReflectionClass($controllerInstance);

        $globalMiddlewareAttributes = $reflection->getAttributes(Middleware::class) ?? [];

        $routes = [];
        foreach ($reflection->getMethods() as $method) {
            $methodName = $method->getName();

            // Verifica che il nome del metodo inizi con un underscore
            if (str_starts_with($methodName, '_') && preg_match('/^(get|post|put|delete|patch|options|head)(.*)/i', substr($methodName, 1), $matches)) {
                $httpMethods = [strtoupper($matches[1])];
                $methodAttributes = $method->getAttributes(Methods::class);
                if (!empty($methodAttributes)) {
                    $extra = $methodAttributes[0]->newInstance()->methods;
                    foreach ($extra as $m) {
                        $httpMethods[] = strtoupper($m);
                    }
                    $httpMethods = array_unique($httpMethods);
                }

                // Verifica se è presente l'attributo Path per sovrascrivere il percorso predefinito
                if ($path = $method->getAttributes(Path::class)) {
                    $routePath = $basePath . $path[0]->newInstance()->path;
                } else if ($matches[2]) {
                    $routePath = $basePath . "/" . lcfirst($matches[2]);
                } else {
                    $routePath = $basePath;
                }

                $middlewareNames = $globalMiddlewareAttributes;
                foreach ($method->getAttributes(Middleware::class) as $mw) {
                    $middlewareNames[] = $mw;
                }

                $routes[] = [
                    "httpMethods" => $httpMethods,
                    "path" => $routePath,
                    "methodName" => $methodName,
                    "Middlewares" => $middlewareNames
                ];
            }
        }

        // Sort routes: first routes without path params, then routes with path params
        // This is necessary to avoid shadowing routes with path params
        usort($routes, static function ($a, $b) {
            $aParamCount = substr_count($a['path'], '{');
            $bParamCount = substr_count($b['path'], '{');
            return $aParamCount <=> $bParamCount;
        });

        foreach ($routes as $route) {
            $routeInstances = [];
            foreach ($route['httpMethods'] as $httpMethod) {
                $routeInstances[] = $app->map(
                    [$httpMethod],
                    $route['path'],
                    [$controllerInstance, $route['methodName']]
                );
            }

            // Applica i middleware dinamici definiti negli attributi
            foreach ($route['Middlewares'] as $attribute) {
                $middlewareNames = $attribute->newInstance()->middlewares;

                foreach ($middlewareNames as $name) {
                    if (isset(self::$middlewareRegistry[$name])) {
                        foreach ($routeInstances as $routeInstance) {
                            $routeInstance->add(self::$middlewareRegistry[$name]);
                        }
                    }
                }
            }

            // Middleware automatico di validazione dallo schema (se presente)
            $methodReflection = $reflection->getMethod($route['methodName']);
            $schemaAttr = $methodReflection->getAttributes(Schema::class);
            if (!empty($schemaAttr)) {
                $schemaClass = $schemaAttr[0]->newInstance()->class;
            }
            foreach ($routeInstances as $routeInstance) {
                $routeInstance->add(new ValidationMiddleware($controllerInstance, $schemaClass ?? null));
            }
        }
    }

    // Aggiunge i middleware globali (CORS, trailing slash, preflight OPTIONS)
    public static function addGlobalMiddlewares(App $app): void
    {
        // Middleware CORS
        $app->add(function ($request, $handler) {
            $response = $handler->handle($request);
            return $response
                ->withHeader('Access-Control-Allow-Origin', '*')
                ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
                ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        });

        // Middleware per il trailing slash
        $app->add(function ($request, $handler) {
            $uri = $request->getUri();
            $path = $uri->getPath();

            if ($path !== '/' && str_ends_with($path, '/')) {
                // recursively remove slashes when its more than 1 slash
                while (str_ends_with($path, '/')) {
                    $path = substr($path, 0, -1);
                }
                // permanently redirect paths with a trailing slash
                // to their non-trailing counterpart
                $uri = $uri->withPath($path);
                if ($request->getMethod() === 'GET') {
                    $response = new Response();
                    return $response
                        ->withHeader('Location', (string)$uri)
                        ->withStatus(301);
                }

                $request = $request->withUri($uri);
            } else {
                $request = $request->withUri($uri->withPath($path));
            }

            return $handler->handle($request);
        });

        // Middleware per le richieste OPTIONS (preflight)
        $app->options('{routes:.+}', function ($request, $response) {
            return $response->withStatus(200);
        });
    }

    // Aggiunge i middleware comuni
    public static function initializeMiddlewareRegistry($middlewares): void
    {
        self::$middlewareRegistry = $middlewares;
    }
}

<?php

namespace ottimis\phplibs;

use Attribute;
use ReflectionClass;
use ReflectionException;
use Slim\App;
use Slim\Psr7\Response;

#[Attribute]
class Middleware {
    public function __construct(public array $middlewares) {}
}

#[Attribute]
class Path {
    public function __construct(public string $path) {}
}

#[Attribute]
class Method {
    public function __construct(public string $method) {}
}

class Methods {
    public const string GET = 'GET';
    public const string POST = 'POST';
    public const string PUT = 'PUT';
    public const string DELETE = 'DELETE';
    public const string PATCH = 'PATCH';
    public const string OPTIONS = 'OPTIONS';
    public const string HEAD = 'HEAD';
}

class RouteController
{
    protected static array $middlewareRegistry = [];
    protected Utils $Utils;

    public function __construct($dbName = "")
    {
        if ($dbName !== false)  {
            $this->Utils = new Utils($dbName);
        }
    }

    private function get($table, $id) {
        $arSql = [
            "select" => [
                "*"
            ],
            "from" => $table,
            "where" => [
                [
                    "field" => "id",
                    "value" => $id,
                ]
            ]
        ];
        $ret = $this->Utils->dbSelect($arSql);
        return $ret['data'][0];
    }

    protected function list($table, $paging = []) {
        $arSql = [
            "select" => [
                "*"
            ],
            "from" => $table,
            "where" => [
                [
                    "field" => "id_status",
                    "value" => 1,
                ]
            ]
        ];
        $ret = $this->Utils->dbSelect($arSql, $paging);
        return $ret['data'];
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
                $methodAttributes = $method->getAttributes(Method::class);
                if (!empty($methodAttributes)) {
                    $httpMethods = array_merge($httpMethods, array_map(fn($attr) => strtoupper($attr->newInstance()->method), $methodAttributes));
                }

                // Verifica se è presente l'attributo Path per sovrascrivere il percorso predefinito
                if ($path = $method->getAttributes(Path::class)) {
                    $routePath = $basePath . $path[0]->newInstance()->path;
                } else if ($matches[2]) {
                    $routePath = $basePath . "/" . lcfirst($matches[2]);
                } else {
                    $routePath = $basePath;
                }

                $middlewareNames = $method->getAttributes(Middleware::class);
                $middlewareNames = array_merge($globalMiddlewareAttributes, $middlewareNames);

                $routes[] = [
                    "httpMethods" => $httpMethods,
                    "path" => $routePath,
                    "methodName" => $methodName,
                    "middlewares" => $middlewareNames
                ];
            }
        }

        // Sort routes: first routes without path params, then routes with path params
        // This is necessary to avoid shadowing routes with path params
        usort($routes, function ($a, $b) {
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
            foreach ($route['middlewares'] as $attribute) {
                $middlewareNames = $attribute->newInstance()->middlewares;

                foreach ($middlewareNames as $name) {
                    if (isset(self::$middlewareRegistry[$name])) {
                        foreach ($routeInstances as $routeInstance) {
                            $routeInstance->add(self::$middlewareRegistry[$name]);
                        }
                    }
                }
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

            if ($path != '/' && str_ends_with($path, '/')) {
                // recursively remove slashes when its more than 1 slash
                while (str_ends_with($path, '/')) {
                    $path = substr($path, 0, -1);
                }
                // permanently redirect paths with a trailing slash
                // to their non-trailing counterpart
                $uri = $uri->withPath($path);
                if ($request->getMethod() == 'GET') {
                    $response = new Response();
                    return $response
                        ->withHeader('Location', (string)$uri)
                        ->withStatus(301);
                } else {
                    $request = $request->withUri($uri);
                }
            } else {
                $request = $request->withUri($uri->withPath($path));
            }

            return $handler->handle($request);
        });

        // Middleware per le richieste OPTIONS (preflight)
        $app->options('/{routes:.+}', function ($request, $response) {
            return $response->withStatus(200);
        });
    }

    // Aggiunge i middleware comuni
    public static function initializeMiddlewareRegistry($middlewares): void
    {
        self::$middlewareRegistry = $middlewares;
    }
}

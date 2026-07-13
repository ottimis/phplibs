<?php

namespace ottimis\phplibs;

use Attribute;
use Exception;
use ottimis\phplibs\Middlewares\ValidationMiddleware;
use ottimis\phplibs\schemas\Base\OGResponse;
use ottimis\phplibs\schemas\STATUS;
use ottimis\phplibs\schemas\UPSERT_MODE;
use Psr\Http\Message\ResponseInterface;
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
     * Scrive $data come JSON nel body della response, impostando Content-Type
     * e status, e la ritorna.
     *
     * Da usare SEMPRE al posto di json_encode(..., JSON_NUMERIC_CHECK): quel
     * flag è globale e cieco — converte OGNI stringa numerica in numero,
     * corrompendo in modo permanente i campi-codice con zeri iniziali (partita
     * IVA "01234567890" → 1234567890, CAP "00100", id_ext, ecc.).
     *
     * Qui la conversione stringa→numero è value-based e LOSSLESS: una stringa
     * diventa numero SOLO se la sua forma è canonica e il round-trip è esatto
     * (vedi numerify()). Niente whitelist di chiavi.
     *
     * Un fallimento di serializzazione (UTF-8 malformato, ricorsione, risorse)
     * è un errore non recuperabile del payload: viene rilanciato come
     * RuntimeException (unchecked) così i controller non devono dichiarare/gestire
     * una checked \JsonException — se ne occupa lo slimErrorHandler → 500.
     */
    protected function json(ResponseInterface $response, mixed $data, int $status = 200): ResponseInterface
    {
        try {
            $json = json_encode(self::numerify($data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Serializzazione JSON della response fallita: ' . $e->getMessage(), 0, $e);
        }
        $response->getBody()->write($json);

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }

    /**
     * Conversione numerica LOSSLESS, ricorsiva e value-based.
     *
     * - Tocca solo i VALORI, mai le chiavi (le chiavi numeriche-stringa di un
     *   array associativo restano invariate).
     * - Una stringa diventa:
     *     int   se matcha ^-?(0|[1-9]\d*)$ E il round-trip è esatto
     *           ((string)(int)$v === $v): esclude zeri iniziali ("00100"),
     *           "-0", e interi fuori dal range PHP (che resterebbero stringa);
     *     float se matcha ^-?(0|[1-9]\d*)\.\d+$ (un solo punto, decimali
     *           presenti, niente zeri iniziali sulla parte intera).
     *   In ogni altro caso la stringa resta STRINGA — così "01234567890",
     *   "00100", "0", "1e3", " 5" restano intatte.
     * - Gli altri tipi (int, float, bool, null, ecc.) passano invariati.
     */
    private static function numerify(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::numerify($v); // chiave $k mai toccata
            }
            return $out;
        }

        if (!is_string($value)) {
            return $value;
        }

        // int canonico con round-trip esatto: esclude zeri iniziali e overflow.
        // Il bare "0" diventa int 0 (lossless): dal DB i flag/contatori numerici
        // arrivano come stringa e tenere "0" stringa romperebbe l'asimmetria con
        // gli altri interi (es. id_status "1" numero vs "0" stringa).
        if (preg_match('/^-?(0|[1-9]\d*)$/', $value) === 1) {
            $asInt = (int) $value;
            if ((string) $asInt === $value) {
                return $asInt;
            }
            return $value; // fuori range PHP: resta stringa per non perdere cifre
        }

        // float canonico: parte intera senza zeri iniziali + decimali obbligatori
        if (preg_match('/^-?(0|[1-9]\d*)\.\d+$/', $value) === 1) {
            return (float) $value;
        }

        return $value;
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
            $resValid = ['value' => null];
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
            // Skip only null (field absent / no default): falsy-but-valid values
            // like false, 0, "" or [] must still be written to the record.
            if (!$isReadOnly && $resValid['value'] !== null) {
                $record[$propertyName] = $resValid['value'];
            }
        }

        return $record;
    }

    /**
     * @throws Exception
     */
    protected function get($id, array $options = []): OGResponse
    {
        $this->checkDbConsistency();

        $where = [
            [
                "field" => "id",
                "value" => $id,
            ],
        ];
        if (empty($options['withDeleted'])) {
            $where[] = [
                "field" => "id_status",
                "value" => STATUS::ACTIVE->value,
            ];
        }
        if (!empty($options['where'])) {
            $where = array_merge($where, $options['where']);
        }

        $arSql = array_merge(
            ["select" => ["*"]],
            array_diff_key($options, array_flip(["where", "withDeleted"])),
            [
                "from" => $this->tableName,
                "where" => $where,
            ],
        );

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
    protected function list(array $q, array $options = []): OGResponse
    {
        $this->checkDbConsistency();

        $where = [];
        if (empty($options['withDeleted'])) {
            $where[] = [
                "field" => "id_status",
                "value" => STATUS::ACTIVE->value,
            ];
        }
        if (!empty($options['where'])) {
            $where = array_merge($where, $options['where']);
        }

        $arSql = array_merge(
            ["select" => ["*"]],
            array_diff_key($options, array_flip(["where", "withDeleted"])),
            [
                "from" => $this->tableName,
                "where" => $where,
            ],
        );

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
    public static function mapControllerRoutes(App $app, string|array $controllerClass, string $basePath = ''): void
    {
        if (is_array($controllerClass)) {
            foreach ($controllerClass as $singleControllerClass) {
                self::mapControllerRoutes($app, $singleControllerClass, $basePath);
            }
            return;
        }
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
            $schemaClass = !empty($schemaAttr) ? $schemaAttr[0]->newInstance()->class : null;
            foreach ($routeInstances as $routeInstance) {
                $routeInstance->add(new ValidationMiddleware($controllerInstance, $schemaClass));
            }
        }
    }

    // Aggiunge i middleware globali (CORS, trailing slash, preflight OPTIONS)
    public static function addGlobalMiddlewares(App $app): void
    {
        // Middleware CORS — configurabile via env, default = comportamento storico (* aperto)
        $allowOrigin  = getenv('CORS_ALLOW_ORIGIN') ?: '*';
        $allowHeaders = getenv('CORS_ALLOW_HEADERS') ?: 'X-Requested-With, Content-Type, Accept, Origin, Authorization';
        $allowMethods = getenv('CORS_ALLOW_METHODS') ?: 'GET, POST, PUT, PATCH, DELETE, OPTIONS';
        $maxAge       = getenv('CORS_MAX_AGE') ?: '86400';
        $allowCreds   = getenv('CORS_ALLOW_CREDENTIALS') === 'true';
        // Allowlist multipla: "https://a.com, https://b.com" → si risponde con l'Origin
        // della richiesta solo se presente in lista (necessario per Allow-Credentials)
        $originList = ($allowOrigin !== '*' && str_contains($allowOrigin, ','))
            ? array_map('trim', explode(',', $allowOrigin))
            : null;

        $app->add(function ($request, $handler) use ($allowOrigin, $allowHeaders, $allowMethods, $maxAge, $allowCreds, $originList) {
            $response = $handler->handle($request);

            $resolvedOrigin = $allowOrigin;
            $varyOrigin = false;
            if ($originList !== null) {
                $requestOrigin = $request->getHeaderLine('Origin');
                $resolvedOrigin = in_array($requestOrigin, $originList, true) ? $requestOrigin : $originList[0];
                $varyOrigin = true; // la risposta dipende dall'Origin: evita cache cross-origin errata
            }

            $response = $response
                ->withHeader('Access-Control-Allow-Origin', $resolvedOrigin)
                ->withHeader('Access-Control-Allow-Headers', $allowHeaders)
                ->withHeader('Access-Control-Allow-Methods', $allowMethods);

            if ($varyOrigin) {
                $response = $response->withHeader('Vary', 'Origin');
            }
            // Allow-Credentials è incompatibile con origin "*" (spec CORS): emesso solo con origin specifica
            if ($allowCreds && $resolvedOrigin !== '*') {
                $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
            }
            // Max-Age ha senso solo sulla preflight: permette al browser di
            // cachearla ed evitare una OPTIONS per ogni richiesta
            if ($request->getMethod() === 'OPTIONS') {
                $response = $response->withHeader('Access-Control-Max-Age', $maxAge);
            }
            return $response;
        });

        // Middleware per il trailing slash
        $app->add(function ($request, $handler) {
            $uri = $request->getUri();
            $path = $uri->getPath();

            if ($path !== '/' && str_ends_with($path, '/')) {
                // recursively remove slashes when it's more than 1 slash
                while (str_ends_with($path, '/')) {
                    $path = substr($path, 0, -1);
                }
                // permanently redirect paths with a trailing slash
                // to their non-trailing counterpart
                $uri = $uri->withPath($path);
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

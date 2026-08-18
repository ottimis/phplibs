<?php

namespace ottimis\phplibs\Middlewares;

use Exception;
use ottimis\phplibs\RouteController;
use ottimis\phplibs\ValidationException;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * Middleware di validazione agganciato automaticamente alle route (v8.0.0+):
 *  - errori di validazione → 400 JSON strutturato
 *    {"error": "VALIDATION_ERROR", "message": "...", "errors": [{"field", "message"}, ...]}
 *  - con uno schema #[Schema] dichiarato il body viene validato SEMPRE, anche
 *    se vuoto: i campi required falliscono come per ogni payload incompleto
 *  - body JSON malformato → 400 {"error": "INVALID_JSON", ...}
 */
readonly class ValidationMiddleware
{
    public function __construct(
        private RouteController $controller,
        private ?string         $schemaClass = null
    ) {}

    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        // Get application/json and x-www-form-urlencoded body
        $raw = (string) $request->getBody();
        $decoded = json_decode($raw, true);
        $formParams = $request->getParsedBody();

        if (trim($raw) !== '' && $decoded === null && json_last_error() !== JSON_ERROR_NONE && empty($formParams)) {
            return $this->errorResponse('INVALID_JSON', 'Request body is not valid JSON');
        }

        $body = array_merge(
            is_array($decoded) ? $decoded : [],
            is_array($formParams) ? $formParams : []
        );

        if (empty($this->schemaClass)) {
            if (!empty($body)) {
                $request = $request->withAttribute('validatedBody', $body);
            }
            return $handler->handle($request);
        }

        try {
            $validated = $this->controller->validateRecord($body, $this->schemaClass);
        } catch (ValidationException $e) {
            return $this->errorResponse('VALIDATION_ERROR', $e->getMessage(), $e->getErrors());
        } catch (Exception $e) {
            return $this->errorResponse('VALIDATION_ERROR', $e->getMessage());
        }

        $request = $request->withAttribute('validatedBody', $validated);

        return $handler->handle($request);
    }

    private function errorResponse(string $code, string $message, array $errors = []): Response
    {
        $payload = ['error' => $code, 'message' => $message];
        if (!empty($errors)) {
            $payload['errors'] = $errors;
        }

        $response = new \Slim\Psr7\Response();
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(400);
    }
}

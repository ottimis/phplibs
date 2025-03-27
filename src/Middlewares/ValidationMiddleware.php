<?php

namespace ottimis\phplibs\Middlewares;

use Exception;
use ottimis\phplibs\RouteController;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Http\Message\ResponseInterface as Response;

readonly class ValidationMiddleware
{
    public function __construct(
        private RouteController $controller,
        private ?string         $schemaClass = null
    ) {}

    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        // Get application/json and x-www-form-urlencoded body
        $body = json_decode($request->getBody(), true);
        $formParams = $request->getParsedBody();
        $body = array_merge($body ?? [], $formParams ?? []);

        if (empty($body)) {
            return $handler->handle($request);
        }

        if (empty($this->schemaClass)) {
            $request = $request->withAttribute('validatedBody', $body);
            return $handler->handle($request);
        }

        try {
            $validated = $this->controller->validateRecord($body, $this->schemaClass);
        } catch (Exception $e) {
            $response = new \Slim\Psr7\Response();
            $response->getBody()->write($e->getMessage());
            return $response->withHeader('Content-Type', 'text/plain')->withStatus(400);
        }

        $request = $request->withAttribute('validatedBody', $validated);

        return $handler->handle($request);
    }
}

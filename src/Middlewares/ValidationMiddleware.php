<?php

namespace ottimis\phplibs\Middlewares;

use Exception;
use ottimis\phplibs\RouteController;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Http\Message\ResponseInterface as Response;

class ValidationMiddleware
{
    public function __construct(
        private readonly RouteController $controller,
        private readonly string $schemaClass
    ) {}

    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        $body = json_decode($request->getBody()->getContents(), true);
        if (!is_array($body)) {
            $response = new \Slim\Psr7\Response();
            $response->getBody()->write("Invalid JSON body.");
            return $response->withHeader('Content-Type', 'text/plain')->withStatus(400);
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

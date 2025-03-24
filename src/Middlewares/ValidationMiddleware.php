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

    /**
     * @throws Exception
     */
    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        $body = json_decode($request->getBody()->getContents(), true);
        if (!is_array($body)) {
            throw new Exception("Invalid JSON body");
        }

        $validated = $this->controller->validateRecord($body, $this->schemaClass);
        $request = $request->withAttribute('validatedBody', $validated);

        return $handler->handle($request);
    }
}

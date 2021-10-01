<?php

namespace ottimis\phplibs;

    use OAuth2\Server as OAServer;
    use OAuth2\Scope;
    use OAuth2\GrantType\ClientCredentials;
    use OAuth2\GrantType\AuthorizationCode;
    use OAuth2\GrantType\RefreshToken;
    use OAuth2\GrantType\JwtBearer;
    use OAuth2\OpenID\GrantType\AuthorizationCode as OIDCAuthorizationCode;
    use OAuth2\Request as OAuthRequest;
    use OAuth2\Response as OAuthResponse;

    use Psr\Http\Message\ResponseInterface as Response;
    use Psr\Http\Message\ServerRequestInterface as Request;
    use Slim\Routing\RouteCollectorProxy as RouteCollectorProxy;

    use Firebase\JWT\JWT;

    class OAuth2
    {
        const CLIENT_CREDENTIAL = 1;
        const AUTH_CODE = 2;
        const REFRESH_TOKEN = 3;
        const JWT_BEARER = 4;
        const OPENID = 5;

        protected $dsn = '';
        protected $dbname = '';
        protected $host = '';
        protected $username = '';
        protected $password = '';
        protected $key = '';
        protected $storage;
        protected $server;
        protected $authenticationUrl = '';

        public function __construct($storage, $serverConfig = array(), $key = "", $driver = 'mysql')
        {
            $this->storage = $storage;
            $this->key = $key;
            $this->server = new OAServer($this->storage, $serverConfig);
        }

        // START: Private functions

        private function response($response, $responseOAuth)
        {
            $response->getBody()->write($responseOAuth->getResponseBody('json'));
            $response = $response->withHeader('Content-Type', 'application/json');
            foreach ($responseOAuth->getHttpHeaders() as $name => $header) {
                $response = $response->withHeader($name, $header);
            }
            return $response
                    ->withStatus($responseOAuth->getStatusCode());
        }

        private function defaultAuthPage()
        {
            echo '<form action="" method="post">
                    <input type="text" name="username">
                    <input type="text" name="password">
                    <button type="submit">Invia</button>
                </form>';
            exit;
        }

        // START: Public functions

        public function addGrantType($type, $arConfig = array())
        {
            switch ($type) {
                case 1:
                    $this->server->addGrantType(new ClientCredentials($this->storage));
                    break;
                case 2:
                    $this->server->addGrantType(new AuthorizationCode($this->storage));
                    break;
                case 3:
                    $this->server->addGrantType(new RefreshToken($this->storage, $arConfig));
                    break;
                case 4:
                    $this->server->addGrantType(new JwtBearer($this->storage, $arConfig['issuer']));
                    break;
                case 5:
                    $this->server->addGrantType(new OIDCAuthorizationCode($this->storage));
                    break;
                default:
                    break;
            }
        }

        public function setAuthenticationUrl($url)
        {
            $this->authenticationUrl = $url;
            return true;
        }

        public function successLogin($id, $state)
        {
            return $this->storage->successRequest($id, $state);
        }

        public function getClient($client_id)
        {
            return $this->storage->getClient($client_id);
        }

        public function setSupportedScopes($scopes)
        {
            $scope = new Scope(array(
                'supported_scopes' => $scopes
            ));
            $this->server->setScopeUtil($scope);
        }

        public function discoveryEndpoint($app, $json)
        {
            $app->get('/.well-known/openid-configuration', function (Request $request, Response $response) use ($json) {
                $response->getBody()->write($json);
                return $response
                    ->withHeader('Content-Type', 'application/json');
            });
        }

        public function jwkEndpoint($app, $keys)
        {
            $app->get('/oauth2/certs', function (Request $request, Response $response) use ($keys) {
                $ar = array('keys' => $keys);
                $response->getBody()->write(json_encode($ar));
                return $response
                    ->withHeader('Content-Type', 'application/json');
            });
        }

        public function api($app)
        {
            $app->group('/oauth2', function (RouteCollectorProxy $group) {
                $group->map(['GET', 'POST'], '/authorize', function (Request $request, Response $response) {
                    $request = OAuthRequest::createFromGlobals();
                    $responseOAuth = new OAuthResponse();

                    // validate the authorize request
                    if (!$this->server->validateAuthorizeRequest($request, $responseOAuth)) {
                        return $this->response($response, $responseOAuth);
                    }
                    $params = $request->getAllQueryParameters();

                    // Cerco se c'è il cookie di autenticazione e se è ancora valido
                    $token = $_COOKIE['oauth-token-temp'];
                    if (isset($token)) {
                        try {
                            $decoded = (array) JWT::decode($token, file_get_contents($this->key), array('RS256'));
                            $params['id'] = $token;
                        } catch (\Exception $e) {
                            $decoded = false;
                            $token = false;
                        }
                    }

                    // display an authorization form
                    if (!isset($params['id'])) {
                        if ($this->authenticationUrl != '') {
                            // Save incoming request to verifiy on authentication client
                            // if (!$this->storage->setRequest($params['client_id'], $params['state']))    {
                            //     $response->getBody()->write('Errore 33 - Contatta l\'amministratore del sistema.');
                            //     return $response
                            //             ->withStatus(400)
                            //             ->withHeader('Content-Type', 'text/plain');
                            // }
                            // Set redirect to authentication client
                            $responseOAuth->setParameters($params);
                            $responseOAuth->setRedirect(302, $this->authenticationUrl, $params['state']);
                            return $this->response($response, $responseOAuth);
                        } else {
                            defaultAuthPage();
                        }
                    }
                    // Se l'id è zero vuol dire che il sistema di autenticazione non ha validato con successo la richiesta.
                    if ($params['id'] === 0) {
                        $response->getBody()->write('Errore 10 - Contatta l\'amministratore del sistema.');
                        return $response
                                ->withStatus(500)
                                ->withHeader('Content-Type', 'text/plain');
                    } else {
                        $success = true;
                        try {
                            $decoded = $decoded ? $decoded : (array) JWT::decode($params['id'], file_get_contents('keys/jwtRS256.key.pub'), array('RS256'));
                            setcookie("oauth-token-temp", $params['id'], time() + 43200, '/', ".unidata.it", true, true);
                            $idUser = $decoded['idUser'];
                        } catch (\Exception $e) {
                            $response->getBody()->write('Errore 50 - Token non valido.');
                            return $response
                                ->withStatus(500)
                                ->withHeader('Content-Type', 'text/plain');
                        }
                        // $success = $this->storage->verifyRequest($params['client_id'], $params['state'], true, $params['id']);
                    }
                    $this->server->handleAuthorizeRequest($request, $responseOAuth, $success, $idUser);

                    // Setto nei cookie il "code" di autenticazione
                    parse_str(parse_url($responseOAuth->getHttpHeader("Location"), PHP_URL_QUERY), $params);
                    if ($params['code']) {
                        setcookie("oauth-uni", $params['code'], time() + 60, '/', ".unidata.it");
                    }

                    return $this->response($response, $responseOAuth);
                });

                // Funzione di verifica temporanea della richiesta ricevuta sul client
                $group->post('/verify', function (Request $request, Response $response) {
                    $Log = new Logger();
                    $request = OAuthRequest::createFromGlobals();
                    $responseOAuth = new OAuthResponse();

                    $body = json_decode($request->getContent(), true);

                    // if ($this->storage->verifyRequest($body['client_id'], $body['state']))    {
                    $responseOAuth->setStatusCode(200);
                    // } else {
                    //     $responseOAuth->setStatusCode(400);
                    // }

                    return $this->response($response, $responseOAuth);
                });
                $group->map(['GET', 'POST'], '/userinfo', function (Request $request, Response $response) {
                    $request = OAuthRequest::createFromGlobals();
                    $responseOAuth = new OAuthResponse();

                    if (!$this->server->verifyResourceRequest($request, $responseOAuth)) {
                        return $this->response($response, $responseOAuth);
                        die;
                    }

                    $this->server->handleUserInfoRequest($request, $responseOAuth);
                    return $this->response($response, $responseOAuth);
                });
                $group->post('/token', function (Request $request, Response $response) {
                    $request = OAuthRequest::createFromGlobals();
                    $responseOAuth = new OAuthResponse();

                    $this->server->handleTokenRequest($request, $responseOAuth);

                    // La chiamate non avviene da browser quindi non funzionerà mai
                    // $body = json_decode($responseOAuth->getResponseBody('json'), true);
                    // if (isset($body['access_token']))    {
                    //     setcookie("oauth-token", $body['access_token'], time() + 3600, '/', ".unidata.it", true, true);
                    // }

                    return $this->response($response, $responseOAuth);
                });
                $group->map(['GET', 'POST'], '/logout', function (Request $request, Response $response) {
                    $request = OAuthRequest::createFromGlobals();
                    $responseOAuth = new OAuthResponse();
                    $params = $request->getAllQueryParameters();

                    setcookie('oauth-token', '', time() - 3600, '/', ".unidata.it", true, true);
                    setcookie('oauth-token-temp', '', time() - 3600, '/', ".unidata.it", true, true);

                    if (isset($params['redirect_uri'])) {
                        $responseOAuth->setRedirect(302, $params['redirect_uri']);
                    } else {
                        $responseOAuth->setRedirect(302, $this->authenticationUrl);
                    }

                    return $this->response($response, $responseOAuth);
                });

                $group->group('/verify', function (RouteCollectorProxy $groupVerify) {
                    $groupVerify->post('/token', function (Request $request, Response $response) {
                        $request = OAuthRequest::createFromGlobals();
                        $responseOAuth = new OAuthResponse();

                        if (!$this->server->verifyResourceRequest($request, $responseOAuth)) {
                            return $this->response($response, $responseOAuth);
                            die;
                        }
                        
                        return $this->response($response, $responseOAuth);
                    });
                });
            });
        }

        public function crudApi($app)
        {
            // Admin users endpoints
            $app->group('/admin', function (RouteCollectorProxy $group) {
                $group->get('/users', function (Request $request, Response $response) {
                    $queryParams = $request->getQueryParams();
                    $search = isset($queryParams['s']) ? $queryParams['s'] : '';
                    unset($queryParams['s']);
                    $pagination = $queryParams;

                    $params = json_decode($request->getBody(), true);
                    if (isset($params['id'])) {
                        $users = $this->storage->getUser($params['id']);
                    } else {
                        $users = $this->storage->listUsers($search, $pagination);
                    }
                    if ($users === false) {
                        return $response
                                    ->withStatus(400)
                                    ->withHeader('Content-Type', 'text/plain');
                    }
                    $response->getBody()->write(json_encode($users, JSON_NUMERIC_CHECK));
                    return $response
                                ->withStatus(200)
                                ->withHeader('Content-Type', 'application/json');
                });
                $group->post('/user', function (Request $request, Response $response) {
                    $params = json_decode($request->getBody(), true);
                    $user = $this->storage->saveUser($params);
                    if ($user === false) {
                        return $response
                                    ->withStatus(400)
                                    ->withHeader('Content-Type', 'text/plain');
                    }
                    return $response
                                ->withStatus(200);
                });
                $group->delete('/user/{id}', function (Request $request, Response $response, $args) {
                    $user = $this->storage->deleteUser($args['id']);
                    if ($user === false) {
                        return $response
                                    ->withStatus(400)
                                    ->withHeader('Content-Type', 'text/plain');
                    }
                    return $response
                                ->withStatus(200);
                });
                $group->get('/clients', function (Request $request, Response $response) {
                    $queryParams = $request->getQueryParams();
                    $search = isset($queryParams['s']) ? $queryParams['s'] : '';
                    unset($queryParams['s']);
                    $pagination = $queryParams;

                    $params = json_decode($request->getBody(), true);
                    if (isset($params['id'])) {
                        $clients = $this->storage->getClient($params['id']);
                    } else {
                        $clients = $this->storage->listClients($search, $pagination);
                    }
                    if ($clients === false) {
                        return $response
                                    ->withStatus(400)
                                    ->withHeader('Content-Type', 'text/plain');
                    }
                    $response->getBody()->write(json_encode($clients, JSON_NUMERIC_CHECK));
                    return $response
                                ->withStatus(200)
                                ->withHeader('Content-Type', 'application/json');
                });
                $group->post('/client', function (Request $request, Response $response) {
                    $params = json_decode($request->getBody(), true);
                    $cient = $this->storage->saveClient($params);
                    if ($cient === false) {
                        return $response
                                    ->withStatus(400)
                                    ->withHeader('Content-Type', 'text/plain');
                    }
                    return $response
                                ->withStatus(200);
                });
                $group->delete('/client/{id}', function (Request $request, Response $response, $args) {
                    $client = $this->storage->deleteClient($args['id']);
                    if ($client === false) {
                        return $response
                                    ->withStatus(400)
                                    ->withHeader('Content-Type', 'text/plain');
                    }
                    return $response
                                ->withStatus(200);
                });
                $group->get('/roles', function (Request $request, Response $response) {
                    $queryParams = $request->getQueryParams();
                    $search = isset($queryParams['s']) ? $queryParams['s'] : '';
                    unset($queryParams['s']);
                    $pagination = $queryParams;

                    $params = json_decode($request->getBody(), true);
                    if (isset($params['id'])) {
                        $roles = $this->storage->getRole($params['id']);
                    } else {
                        $roles = $this->storage->listRoles($search, $pagination);
                    }
                    if ($roles === false) {
                        return $response
                                    ->withStatus(400)
                                    ->withHeader('Content-Type', 'text/plain');
                    }
                    $response->getBody()->write(json_encode($roles, JSON_NUMERIC_CHECK));
                    return $response
                                ->withStatus(200)
                                ->withHeader('Content-Type', 'application/json');
                });
                $group->post('/role', function (Request $request, Response $response) {
                    $params = json_decode($request->getBody(), true);
                    $role = $this->storage->saveRole($params);
                    if ($role === false) {
                        return $response
                                    ->withStatus(400)
                                    ->withHeader('Content-Type', 'text/plain');
                    }
                    return $response
                                ->withStatus(200);
                });
                $group->delete('/role', function (Request $request, Response $response) {
                    $params = json_decode($request->getBody(), true);
                    $role = $this->storage->deleteRole($params['id']);
                    if ($role === false) {
                        return $response
                                    ->withStatus(400)
                                    ->withHeader('Content-Type', 'text/plain');
                    }
                    return $response
                                ->withStatus(200);
                });
            });
        }
    }

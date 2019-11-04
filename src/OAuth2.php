<?php

namespace ottimis\phplibs;

    use OAuth2\Server as OAServer;
    use OAuth2\GrantType\ClientCredentials;
    use OAuth2\GrantType\AuthorizationCode;
    use OAuth2\GrantType\RefreshToken;
    use OAuth2\Request as OAuthRequest;
    use OAuth2\Response as OAuthResponse;

    use Psr\Http\Message\ResponseInterface as Response;
    use Psr\Http\Message\ServerRequestInterface as Request;
    use Slim\Routing\RouteCollectorProxy as RouteCollectorProxy;

    class OAuth2
    {
        const CLIENT_CREDENTIAL = 1;
        const AUTH_CODE = 2;
        const REFRESH_TOKEN = 3;

        protected $dsn = '';
        protected $dbname = '';
        protected $host = '';
        protected $username = '';
        protected $password = '';
        protected $storage;
        protected $server;
        

        public function __construct($driver = 'mysql', $serverConfig = array())
        {
            $this->dbname = 'dbname=' . getenv('DB_NAME') . ';';
            $this->host = 'host=' . getenv('DB_HOST') . ';';
            $this->username = getenv('DB_USER');
            $this->password = getenv('DB_PASSWORD');
            $this->dsn = $driver . ':' . $this->dbname . $this->host;
            $this->storage = new PdoOA(array('dsn' => $this->dsn, 'username' => $this->username, 'password' => $this->password));
            $this->server = new OAServer($this->storage, $serverConfig);
        }

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
                    $this->server->addGrantType(new RefreshToken($this->storage), $arConfig);
                    break;
                default:
                    break;
            }
        }

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
                    // display an authorization form
                    if (empty($_POST)) {
                        echo '<form action="" method="post">
                                <input type="text" name="username">
                                <input type="text" name="password">
                                <button type="submit">Invia</button>
                            </form>';
                        exit;
                    }
                    $ret['success'] = false;
                    // print the authorization code if the user has authorized your client
                    if ($_POST['username'] == 'admin' && $_POST['password'] == 'admin') {
                        $ret['success'] = true;
                    }
                    
                    $this->server->handleAuthorizeRequest($request, $responseOAuth, $ret['success']);
                    return $this->response($response, $responseOAuth);
                });
                $group->post('/verify', function (Request $request, Response $response) {
                    $request = OAuthRequest::createFromGlobals();
                    $responseOAuth = new OAuthResponse();

                    if (!$this->server->verifyResourceRequest($request, $responseOAuth)) {
                        return $this->response($response, $responseOAuth);
                        die;
                    }

                    $data = $server->getAccessTokenData($request);
                    $responseOAuth->setParameters($data);
                    return $this->response($response, $responseOAuth);
                });
                $group->post('/token', function (Request $request, Response $response) {
                    $request = OAuthRequest::createFromGlobals();
                    $responseOAuth = new OAuthResponse();

                    $this->server->handleTokenRequest($request, $responseOAuth);

                    return $this->response($response, $responseOAuth);
                });
            });
        }
    }

<?php

namespace ottimis\phplibs;

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
        

        public function __construct($driver = 'mysql', $serverConfig = array())   {
            $this->dbname = 'dbname=' . getenv('DB_NAME') . ';';
            $this->host = 'host=' . getenv('DB_HOST') . ';';
            $this->username = getenv('DB_USER');
            $this->password = getenv('DB_PASSWORD');
            $this->dsn = $driver . ':' . $this->dbname . $this->host;
            $this->storage = new OAuth2\Storage\Pdo(array('dsn' => $this->dsn, 'username' => $this->username, 'password' => $this->password));
            $this->server = new OAuth2\Server($storage, $serverConfig);
        }

        public function addGrantType($type, $arConfig = array())  {
            switch ($type) {
                case 1:
                    $this->server->addGrantType(new OAuth2\GrantType\ClientCredential($this->storage));
                    break;
                case 2:
                    $this->server->addGrantType(new OAuth2\GrantType\AuthorizationCode($this->storage));
                    break;
                case 3:
                    $this->server->addGrantType(new OAuth2\GrantType\RefreshToken($this->storage), $arConfig);
                    break;
                default:
                    break;
            }
        }


        public static function api($app)
        {
            $app->group('/oauth2', function (RouteCollectorProxy $group) {
                $group->get('authorize', function (Request $request, Response $response) {
                    $request = OAuth2\Request::createFromGlobals();
                    $response = new OAuth2\Response();

                    // validate the authorize request
                    if (!$server->validateAuthorizeRequest($request, $response)) {
                        $response->send();
                        die;
                    }
                    // display an authorization form
                    if (empty($_POST)) {
                        echo 'ciao';
                        exit;
                    }
                    // print the authorization code if the user has authorized your client
                    $ret['success'] = true;
                    $is_authorized = $ret['success'] ? true : false;
                    
                    $server->handleAuthorizeRequest($request, $response, true);
                    $response->send();
                });
            });
        }
    }

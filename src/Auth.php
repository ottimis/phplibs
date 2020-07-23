<?php

namespace ottimis\phplibs;
use Firebase\JWT\JWT;

    class Auth
    {
        protected $tokenKey = 'KEY_DEFAULT_Ott1m1s!&!';
        protected $funcField = '';
        protected $scopeField = '';
        protected $extraField = '';
        protected $scopeTable = '';
        protected $tokenTable = '';
        protected $tokenExpiration = '';
        protected $arWhiteList = array();
        protected $scopes = array();
        /**
         * Example: ["_user_list" => 1, "_pazienti_list" => 2]
         */

        /**
         * __construct
         *
         * @param  mixed $key
         * @param  mixed $scopes
         * @param  mixed $funcField
         * @param  mixed $scopeField
         * @param  mixed $scopeTable
         * @param  mixed $tokenTable
         *
         * @return void
         */
        public function __construct($key, $funcField = '', $scopeField = '', $scopeTable = '', $extraField = '', $tokenTable = 'token', $tokenExpiration = '')
        {
            $this->tokenKey = $key;
            $this->funcField = $funcField;
            $this->scopeField = $scopeField;
            $this->extraField = $extraField;
            $this->scopeTable = $scopeTable;
            $this->tokenTable = $tokenTable;
            $this->tokenExpiration = $tokenExpiration;
        }

        protected function checkScopes($cmd, $idRole, $extra = '')
        {
            $db = new dataBase();
            if ($this->funcField != '' && $this->scopeField != '' && $this->scopeTable != '') {
                $sql = sprintf("SELECT %s, %s, %s FROM %s WHERE %s='%s' AND %s='%s'", $this->funcField, $this->scopeField,
                    $this->extraField, $this->scopeTable, $this->funcField, $db->real_escape_string($cmd),
                    $this->extraField, $db->real_escape_string($extra));
                $res = $db->query($sql);
                if ($res)   {
                    $rec = $db->fetchassoc();
                    if ( array_search($idRole, json_decode($rec[$this->scopeField])) !== false) {
                        return true;
                    }
                }
            }
            return false;
        }

        /**
         * Get header Authorization
         * */
        protected function getAuthorizationHeader()
        {
            $headers = null;
            if (isset($_SERVER['Authorization'])) {
                $headers = trim($_SERVER["Authorization"]);
            } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) { //Nginx or fast CGI
                $headers = trim($_SERVER["HTTP_AUTHORIZATION"]);
            } elseif (function_exists('apache_request_headers')) {
                $requestHeaders = apache_request_headers();
                // Server-side fix for bug in old Android versions (a nice side-effect of this fix means we don't care about capitalization for Authorization)
                $requestHeaders = array_combine(array_map('ucwords', array_keys($requestHeaders)), array_values($requestHeaders));
                //print_r($requestHeaders);
                if (isset($requestHeaders['Authorization'])) {
                    $headers = trim($requestHeaders['Authorization']);
                }
            }
            return $headers;
        }
        /**
         * get access token from header
         * */
        public function getBearerToken()
        {
            $headers = $this->getAuthorizationHeader();
                
            // HEADER: Get the access token from the header
            if (!empty($headers)) {
                if (preg_match('/Bearer\s(\S+)/', $headers, $matches)) {
                    return $matches[1];
                }
            }
            return null;
        }



        /**
         * tokenCheck
         *
         * @param  mixed $cmd
         *
         * @return void
         */
        public function tokenCheck($cmd, $extraField = '')
        {
            if (array_search($cmd, $this->arWhiteList) === false) {
                $utils = new Utils();
                $db = new dataBase();

                $jwt = $this->getBearerToken();

                if ($jwt) {
                    try {
                        $decoded = (array) JWT::decode($jwt, $this->tokenKey, array('HS256'));
                    } catch (Exception $e) {
                        if ($_SERVER['REQUEST_METHOD'] !== 'OPTIONS')   {
                            $utils->outSend(401);
                        }
                    }

                    if (time() > $decoded['exp']) {
                        $utils->outSend(401);
                    } else {
                        unset($decoded['exp']);
                        if ($this->checkScopes($cmd, $decoded['idrole'], $extraField)) {
                            return $decoded;
                        } else {
                            $utils->outSend(401);
                        }
                    }
                } else {
                    $utils->outSend(401);
                }
            } else {
                return true;
            }
        }

        /**
         * tokenRefresh
         *
         * @param  mixed $idRole
         * @param  mixed $data
         *
         * @return void
         */
        public function tokenRefresh($idRole, $data = array())
        {
            $utils = new Utils();
            $time = $this->tokenExpiration != '' ? $this->tokenExpiration : time() + (60 * 60 * 24 * 10); 
            $data['exp'] = $time;
            $data['idrole'] = $idRole;
            $jwt = JWT::encode($data, $this->tokenKey);
            
            $ar['token'] = $jwt;
            $ar['token_date'] = 'now()';
            $ret = $utils->dbSql(true, $this->tokenTable, $ar, "", "");
            return $jwt;
        }

        /**
         * tokenDecode
         *
         * @param  String $token
         *
         * @return void
         */
        public function tokenDecode($token)
        {
            try {
                $decoded = (array) JWT::decode($token, $this->tokenKey, array('HS256'));
            } catch (\Exception $e) {
                return false;
            }
            return $decoded;
        }

        public function addToWhiteList($ar) {
            array_merge($this->arWhiteList, $ar);
            return true;
        }
    }

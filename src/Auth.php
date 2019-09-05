<?php

namespace ottimis\phplibs;
use Firebase\JWT\JWT;

    class Auth
    {
        protected $tokenKey = 'KEY_DEFAULT_Ott1m1s!&!';
        protected $funcField = '';
        protected $roleIdField = '';
        protected $roleTable = '';
        protected $tokenTable = '';
        protected $scopes = array();
        /**
         * Example: ["_user_list" => 1, "_pazienti_list" => 2]
         */

        public function __construct($key, $scopes, $funcField = '', $roleIdField = '', $roleTable = '', $tokenTable = 'token')
        {
            $this->$tokenKey = $key;
            $this->$scopes = $scopes;
            $this->$funcField = $funcField;
            $this->$roleIdField = $roleIdField;
            $this->$roleTable = $roleTable;
            $this->$tokenTable = $tokenTable;
        }

        protected function getScopes()
        {
            if ($this->$funcField != '' && $this->$roleIdField != '' && $this->$roleTable != '') {
                $sql = sprintf("SELECT %s, %s FROM %s", $this->$funcField, $this->$roleIdField, $this->$roleTable);
                $db->query($sql);
                while ($rec = $db->fetchassoc()) {
                    $ar[$rec[$this->$funcField]] = $rec[$this->$roleIdField];
                }
            } else {
                return false;
            }
        }

        protected function checkScopes($ar, $cmd, $idRole)
        {
            if (isset($ar[$cmd])) {
                if ($ar[$cmd] <= $idRole) {
                    return true;
                } else {
                    return false;
                }
            } else {
                return true;
            }
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
        protected function getBearerToken()
        {
            $headers = getAuthorizationHeader();
                
            // HEADER: Get the access token from the header
            if (!empty($headers)) {
                if (preg_match('/Bearer\s(\S+)/', $headers, $matches)) {
                    return $matches[1];
                }
            }
            return null;
        }



        public function tokenCheck($cmd)
        {
            $utils = new Utils();
            $db = new dataBase();

            $token = getBearerToken();

            try {
                $decoded = (array) JWT::decode($jwt, $token_key, array('HS256'));
            } catch (Exception $e) {
                return 0;
            }

            if (time() > $decoded['exp']) {
                return false;
            } else {
                unset($decoded['exp']);
                if ($ar = getScopes()) {
                    if (checkScope($ar, $cmd, $decoded['idrole'])) {
                        return $decoded;
                    } else {
                        return false;
                    }
                } else {
                    throw new Exception("Cannot retreive scopes information", 2);
                }
            }
        }

        public function tokenRefresh($idRole, $data)
        {
            $data['exp'] = time() + (60 * 60 * 24 * 10);
            $jwt = JWT::encode($data, $this->$tokenKey);
            
            $ar['token'] = $jwt;
            $ar['token_date'] = 'now()';
            $ret = dbSql(true, $this->$tokenTable, $ar, "", "");
            return $jwt;
        }
    }

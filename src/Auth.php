<?php

namespace ottimis\phplibs;
use Firebase\JWT\JWT;

    class Auth
    {
        protected $tokenKey = 'KEY_DEFAULT_Ott1m1s!&!';
        protected $funcField = '';
        protected $scopeField = '';
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
        public function __construct($key, $funcField = '', $scopeField = '', $scopeTable = '', $tokenTable = 'token', $tokenExpiration = '')
        {
            $this->tokenKey = $key;
            $this->funcField = $funcField;
            $this->scopeField = $scopeField;
            $this->scopeTable = $scopeTable;
            $this->tokenTable = $tokenTable;
            $this->tokenExpiration = $tokenExpiration;
        }

        protected function getScopes()
        {
            $db = new dataBase();
            if ($this->funcField != '' && $this->scopeField != '' && $this->scopeTable != '') {
                $sql = sprintf("SELECT %s, %s FROM %s", $this->funcField, $this->scopeField, $this->scopeTable);
                $db->query($sql);
                while ($rec = $db->fetchassoc()) {
                    $ar[$rec[$this->funcField]] = $rec[$this->scopeField];
                }
                return $ar;
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
        public function tokenCheck($cmd)
        {
            if (array_search($cmd, $this->arWhiteList) === false) {
                $utils = new Utils();
                $db = new dataBase();

                $jwt = $this->getBearerToken();

                if ($jwt) {
                    try {
                        $decoded = (array) JWT::decode($jwt, $this->tokenKey, array('HS256'));
                    } catch (Exception $e) {
                        return 0;
                    }

                    if (time() > $decoded['exp']) {
                        return false;
                    } else {
                        unset($decoded['exp']);
                        if ($ar = $this->getScopes()) {
                            if ($this->checkScopes($ar, $cmd, $decoded['idrole'])) {
                                return $decoded;
                            } else {
                                return false;
                            }
                        } else {
                            throw new Exception("Cannot retreive scopes information", 2);
                        }
                    }
                } else {
                    return false;
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
        public function tokenRefresh($idRole, $data)
        {
            $time = $this->tokenExpiration != '' ? $this->tokenExpiration : time() + (60 * 60 * 24 * 10); 
            $data['exp'] = $time;
            $jwt = JWT::encode($data, $this->tokenKey);
            
            $ar['token'] = $jwt;
            $ar['token_date'] = 'now()';
            $ret = dbSql(true, $this->tokenTable, $ar, "", "");
            return $jwt;
        }

        public function addToWhiteList($ar) {
            array_merge($this->arWhiteList, $ar);
            return true;
        }
    }

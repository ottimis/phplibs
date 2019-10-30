<?php

namespace ottimis\phplibs;

    use OAuth2\Storage\Pdo as PDO;

    class PdoOA extends PDO
    {
        protected function hashPassword( $password )    {
            return password_hash($password, PASSWORD_DEFAULT);
        }

        protected function checkPassword( $user, $password )    {
            return password_verify($password, $user['password']);
        }
    }
        
<?php

namespace ottimis\phplibs;

    use OAuth2\Storage\Pdo as PDO;

    class PdoOA extends PDO
    {
        protected function hashPassword($password)
        {
            return password_hash($password, PASSWORD_DEFAULT);
        }

        protected function checkPassword($user, $password)
        {
            return password_verify($password, $user['password']);
        }

        protected function getRequest($client_id, $state)
        {
            try {
                $stmt = $this->db->prepare(sprintf('SELECT * from %s where client_id = :client_id AND state = :state AND expires > now()', 'oauth_request'));
                $stmt->execute(compact('client_id', 'state'));
                $request = $stmt->fetch(\PDO::FETCH_ASSOC);
                return $request;
            } catch (\PDOException $e) {
                return false;
            } catch (Exception $e) {
                return false;
            }
        }

        protected function unsetRequest($client_id, $state)
        {
            try {
                $stmt = $this->db->prepare(sprintf('DELETE FROM %s WHERE client_id = :client_id AND state = :state', 'oauth_request'));
                $stmt->execute(compact('client_id', 'state'));
                return $stmt->rowCount() > 0;
            } catch (\PDOException $e) {
                return false;
            } catch (Exception $e) {
                return false;
            }
        }

        public function setRequest($client_id, $state)
        {
            try {
                $stmt = $this->db->prepare(sprintf('INSERT INTO %s (client_id, state, expires) VALUES (:client_id, :state, now() + INTERVAL 10 MINUTE)', 'oauth_request'));
                $stmt->execute(compact('client_id', 'state'));
                return true;
            } catch (\PDOException $e) {
                return false;
            } catch (Exception $e) {
                return false;
            }
        }

        public function verifyRequest($client_id, $state, $unset = false)
        {
            $request = $this->getRequest($client_id, $state);
            
            if (!empty($request)) {
                if ($unset) {
                    return $this->unsetRequest($client_id, $state);
                } else {
                    return true;
                }
            } else {
                return false;
            }
        }

        public function getUserData($userId)
        {
            try {
                $stmt = $this->db->prepare(sprintf('SELECT * from %s where user_id = :userId', 'oauth_users_data'));
                $stmt->execute(compact('userId'));
                $request = $stmt->fetch(\PDO::FETCH_ASSOC);
                return $request;
            } catch (\PDOException $e) {
                return false;
            } catch (Exception $e) {
                return false;
            }
        }
    }

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

        // START: Users

        public function listUsers($search = '', $pagination = array())
        {
            try {
                $start = isset($pagination['p']) ? (($pagination['p'] -1) * 10) : 0;
                $count = 10;
                $order = isset($pagination['srt']) ? $pagination['srt'] : "user_id";
                $direction = isset($pagination['o']) ? $pagination['o'] : "asc";

                if (strlen($search) > 2) {
                    $sql = sprintf('SELECT SQL_CALC_FOUND_ROWS t1.id_policy, t1.last_password_change, t1.last_login, t1.lockout,
                        t2.*, CONCAT(t2.first_name, " ", t2.last_name) as full_name
                        FROM %s t1
                        LEFT JOIN %s t2 ON t1.id_utente=t2.user_id
                        WHERE cancellato=0
                        AND ( t2.first_name LIKE :search OR t2.last_name LIKE :search OR t2.email LIKE :search )
                        ORDER BY %s %s
                        LIMIT :start, :count', 'oauth_users', 'oauth_users_data', $order, $direction);
                    $stmt = $this->db->prepare($sql);
                    $stmt->bindValue(':search', $search . "%");
                    $stmt->bindValue(':start', $start, \PDO::PARAM_INT);
                    $stmt->bindValue(':count', $count, \PDO::PARAM_INT);
                    $stmt->execute();
                } else {
                    $sql = sprintf('SELECT SQL_CALC_FOUND_ROWS t1.id_policy, t1.last_password_change, t1.last_login, t1.lockout, t2.*, CONCAT(t2.first_name, " ", t2.last_name) as full_name
                        FROM %s t1
                        LEFT JOIN %s t2 ON t1.id_utente=t2.user_id
                        WHERE cancellato=0
                        ORDER BY %s %s
                        LIMIT :start, :count', 'oauth_users', 'oauth_users_data', $order, $direction);
                    $stmt = $this->db->prepare($sql);
                    $stmt->bindValue(':start', $start, \PDO::PARAM_INT);
                    $stmt->bindValue(':count', $count, \PDO::PARAM_INT);
                    $stmt->execute();
                }
                $ar = array(
                    "data" => [],
                    "total" => 0
                );
                while ($request = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    $ar['data'][] = $request;
                }
                $sql = "SELECT FOUND_ROWS() as total";
                $stmt = $this->db->prepare($sql);
                $stmt->execute();
                $ar['total'] = $stmt->fetch(\PDO::FETCH_ASSOC)['total'];
                return $ar;
            } catch (\PDOException $e) {
                echo $e;
                return false;
            } catch (Exception $e) {
                return false;
            }
        }

        public function getUser($idUser)
        {
            try {
                $sql = sprintf('SELECT t1.id_policy, t1.last_password_change, t1.last_login, t1.lockout, t2.*
                    FROM %s t1
                    LEFT JOIN %s t2 ON t1.id_utente=t2.user_id
                    WHERE cancellato=0 
                    AND t1.id_utente = :idUser', 'oauth_users', 'oauth_users_data');
                $stmt = $this->db->prepare($sql);
                $stmt->execute(compact('idUser'));
                $request = $stmt->fetch(\PDO::FETCH_ASSOC);
                return $request;
            } catch (\PDOException $e) {
                return false;
            } catch (Exception $e) {
                return false;
            }
        }

        public function deleteUser($idUser)
        {
            try {
                $sql = sprintf('DELETE FROM %s
                    WHERE id_utente = :idUser', 'oauth_users');
                $stmt = $this->db->prepare($sql);
                $stmt->execute(compact('idUser'));

                $sql = sprintf('DELETE FROM %s
                    WHERE user_id = :idUser', 'oauth_users_data');
                $stmt = $this->db->prepare($sql);
                $stmt->execute(compact('idUser'));
                return true;
            } catch (\PDOException $e) {
                return false;
            } catch (Exception $e) {
                return false;
            }
        }

        public function saveUser($user)
        {
            $utils = new Utils();
            $user_id = isset($user['user_id']) ? $user['user_id'] : 0;

            $arUser = array(
                "email" => $user['email'],
                "id_policy" => $user['id_policy'],
                "lockout" => isset($user['lockout']) ? $user['lockout'] : 0
            );

            $userInsert = $utils->dbSql($user_id == 0 ? true : false, "oauth_users", $arUser, "id_utente", $user_id);

            if ($user_id == 0) {
                $user_id = $userInsert['id'];
            }

            if (!$userInsert['success'] || $user_id == 0) {
                return false;
            }

            $arUserData = array(
                "user_id" => $user_id,
                "email" => $user['email'],
                "first_name" => $user['first_name'],
                "last_name" => $user['last_name'],
                "authorizations" => json_encode($user['authorizations'])
            );

            $userDataInsert = $utils->dbSql(true, "oauth_users_data", $arUserData);

            if ($userDataInsert['success']) {
                return true;
            } else {
                return false;
            }
        }

        // END: Users
        // START: Clients

        public function listClients($search = '', $pagination = array())
        {
            try {
                $start = isset($pagination['p']) ? (($pagination['p'] -1) * 10) : 0;
                $count = 10;
                $order = isset($pagination['srt']) ? $pagination['srt'] : "id";
                $direction = isset($pagination['o']) ? $pagination['o'] : "asc";

                if (strlen($search) > 2) {
                    $sql = sprintf('SELECT SQL_CALC_FOUND_ROWS * 
                        FROM %s
                        WHERE (name LIKE :search OR client_id LIKE :search)
                        ORDER BY %s %s
                        LIMIT :start, :count', 'oauth_clients', $order, $direction);
                    $stmt = $this->db->prepare($sql);
                    $stmt->bindValue(':search', "%" . $search . "%");
                    $stmt->bindValue(':start', $start, \PDO::PARAM_INT);
                    $stmt->bindValue(':count', $count, \PDO::PARAM_INT);
                    $stmt->execute();
                } else {
                    $sql = sprintf('SELECT SQL_CALC_FOUND_ROWS * 
                        FROM %s
                        ORDER BY %s %s
                        LIMIT :start, :count', 'oauth_clients', $order, $direction);
                    $stmt = $this->db->prepare($sql);
                    $stmt->bindValue(':start', $start, \PDO::PARAM_INT);
                    $stmt->bindValue(':count', $count, \PDO::PARAM_INT);
                    $stmt->execute();
                }
                $ar = array(
                    "data" => [],
                    "total" => 0
                );
                while ($request = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    $ar['data'][] = $request;
                }
                $sql = "SELECT FOUND_ROWS() as total";
                $stmt = $this->db->prepare($sql);
                $stmt->execute();
                $ar['total'] = $stmt->fetch(\PDO::FETCH_ASSOC)['total'];
                return $ar;
            } catch (\PDOException $e) {
                return false;
            } catch (Exception $e) {
                return false;
            }
        }

        public function getClient($idClient)
        {
            try {
                $sql = sprintf('SELECT client_id, client_secret, name, redirect_uri
                    FROM %s
                    WHERE id = :idClient', 'oauth_clients');
                $stmt = $this->db->prepare($sql);
                $stmt->execute(compact('idClient'));
                $request = $stmt->fetch(\PDO::FETCH_ASSOC);
                return $request;
            } catch (\PDOException $e) {
                return false;
            } catch (Exception $e) {
                return false;
            }
        }

        public function deleteClient($idClient)
        {
            try {
                $sql = sprintf('DELETE FROM %s
                    WHERE id = :idClient', 'oauth_clients');
                $stmt = $this->db->prepare($sql);
                $stmt->execute(compact('idClient'));
                return true;
            } catch (\PDOException $e) {
                return false;
            } catch (Exception $e) {
                return false;
            }
        }

        public function saveClient($client)
        {
            $utils = new Utils();
            $id_client = isset($client['id']) ? $client['id'] : 0;

            $arClient = array(
                "name" => $client['name'],
                "client_id" => $client['client_id'],
                "redirect_uri" => trim($client['redirect_uri'])
            );

            if ($id_client == 0) {
                $arClient['client_secret'] = bin2hex(random_bytes(32));
            }

            $clientInsert = $utils->dbSql($id_client == 0 ? true : false, "oauth_clients", $arClient, "id", $id_client);

            if ($clientInsert['success']) {
                return true;
            } else {
                return false;
            }
        }

        // END: Clients
        // START: Roles

        public function listRoles($search = '', $pagination = array())
        {
            try {
                $start = isset($pagination['p']) ? (($pagination['p'] -1) * 10) : 0;
                $count = 10;
                $order = isset($pagination['srt']) ? $pagination['srt'] : "id";
                $direction = isset($pagination['o']) ? $pagination['o'] : "asc";

                if (strlen($search) > 2) {
                    $sql = sprintf('SELECT SQL_CALC_FOUND_ROWS * 
                        FROM %s
                        WHERE (name LIKE :search OR description LIKE :search)
                        ORDER BY %s %s
                        LIMIT :start, :count', 'oauth_roles', $order, $direction);
                    $stmt = $this->db->prepare($sql);
                    $stmt->bindValue(':search', "%" . $search . "%");
                    $stmt->bindValue(':start', $start, \PDO::PARAM_INT);
                    $stmt->bindValue(':count', $count, \PDO::PARAM_INT);
                    $stmt->execute();
                } else {
                    $sql = sprintf('SELECT SQL_CALC_FOUND_ROWS * 
                        FROM %s
                        ORDER BY %s %s
                        LIMIT :start, :count', 'oauth_roles', $order, $direction);
                    $stmt = $this->db->prepare($sql);
                    $stmt->bindValue(':start', $start, \PDO::PARAM_INT);
                    $stmt->bindValue(':count', $count, \PDO::PARAM_INT);
                    $stmt->execute();
                }
                $ar = array(
                    "data" => [],
                    "total" => 0
                );
                while ($request = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    $ar['data'][] = $request;
                }
                $sql = "SELECT FOUND_ROWS() as total";
                $stmt = $this->db->prepare($sql);
                $stmt->execute();
                $ar['total'] = $stmt->fetch(\PDO::FETCH_ASSOC)['total'];
                return $ar;
            } catch (\PDOException $e) {
                return false;
            } catch (Exception $e) {
                return false;
            }
        }

        public function getRole($idRole)
        {
            try {
                $sql = sprintf('SELECT *
                    FROM %s
                    WHERE id = :idRole', 'oauth_roles');
                $stmt = $this->db->prepare($sql);
                $stmt->execute(compact('idRole'));
                $request = $stmt->fetch(\PDO::FETCH_ASSOC);
                return $request;
            } catch (\PDOException $e) {
                return false;
            } catch (Exception $e) {
                return false;
            }
        }

        public function deleteRole($idRole)
        {
            try {
                $sql = sprintf('DELETE FROM %s
                    WHERE id = :idRole', 'oauth_roles');
                $stmt = $this->db->prepare($sql);
                $stmt->execute(compact('idRole'));
                return true;
            } catch (\PDOException $e) {
                return false;
            } catch (Exception $e) {
                return false;
            }
        }

        public function saveRole($role)
        {
            try {
                // Inserisco i dati dell'utente
                $sql = sprintf('INSERT INTO %s (name, description)
                    VALUES(:name, :description)', 'oauth_roles');
                $stmt = $this->db->prepare($sql);
                $stmt->execute($role);
                return true;
            } catch (\PDOException $e) {
                return false;
            } catch (Exception $e) {
                return false;
            }
        }

        // END: Roles
    }

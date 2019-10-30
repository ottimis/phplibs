<?php

namespace ottimis\phplibs;

	class Pdo	{

		protected $host = '';
		protected $user = '';
		protected $password = '';
		protected $database = '';
		protected $port = '';
		protected $persistent = false;
		protected $preparedSql = NULL;
		protected $conn = NULL;
		protected $result= false;
		protected $error_reporting = true;


        function __construct(array $db)
        {
            $this->host = isset($db) ? getenv('DB_HOST_' . $db) : getenv('DB_HOST');
            $this->user = isset($db) ? getenv('DB_USER_' . $db) : getenv('DB_USER');
            $this->password = isset($db) ? getenv('DB_PASSWORD_' . $db) : getenv('DB_PASSWORD');
            $this->database = isset($db) ? getenv('DB_NAME_' . $db) : getenv('DB_NAME');
            $this->port = isset($db) ? getenv('DB_PORT_' . $db) : getenv('DB_PORT');

            $this->conn = new PDO("odbc:host=$this->host:$this->port;dbname=$this->database", "$this->user", "$this->password");
            return $this->conn;
        }

		// se error_reporting attivato riporto errore
		function error() {
			return $this->conn->errorInfo();
		}

		// gruppo funzioni interrogazione
		function query($sql) {
			foreach ($this->conn->query($sql) as $row) {
				$ar[] = $row;
			}
			if (sizeof($ar) == 1)	{
				$ar = $row;
			}
			$this->result = $ar;
			return($ar);
		}

		function prepare($sql) {
			$this->$preparedSql = $this->conn->prepare($sql);
		}

		function execute($ar) {
			return $this->preparedSql->execute($ar);
		}

		function numrows() {
			return $this->conn->rowCount();
		}


		function insert_id()	{
			return $this->conn->lastInsertId();
		}

	}
?>
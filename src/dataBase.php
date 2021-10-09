<?php

namespace ottimis\phplibs;

	class dataBase	{

		protected $host = '';
		protected $user = '';
		protected $password = '';
		protected $database = '';
		protected $port = '';
		protected $persistent = false;
		protected $conn = NULL;
		protected $result= false;
		protected $error_reporting = true;


		function __construct($dbname = "")	{
			$this->host = ($dbname == "" ? getenv('DB_HOST') : getenv('DB_HOST_' . $dbname));
            $this->user = ($dbname == "" ? getenv('DB_USER') : getenv('DB_USER_' . $dbname));
            $this->password = ($dbname == "" ? getenv('DB_PASSWORD') : getenv('DB_PASSWORD_' . $dbname));
            $this->database = ($dbname == "" ? getenv('DB_NAME') : getenv('DB_NAME_' . $dbname));
            $this->port = ($dbname == "" ? (getenv('DB_PORT') ? getenv('DB_PORT') : 3306) : getenv('DB_PORT_' . $dbname));

			$this->conn = mysqli_connect( $this->host, $this->user, $this->password, $this->database, $this->port ) or die ("Could not connect " . mysqli_connect_error($this->conn));
			$this->result = mysqli_query($this->conn, "SET character_set_results = 'utf8', character_set_client = 'utf8', character_set_connection = 'utf8', character_set_database = 'utf8', character_set_server = 'utf8'");
			return $this->result;
		}

		// chiusura DB
		function close() {
			return (mysqli_close($this->conn));
		}

		// se error_reporting attivato riporto errore
		function error() {
			return (mysqli_error($this->conn)) ;
		}

		function startTransaction() {
			mysqli_begin_transaction($this->conn);
		}

		function commitTransaction() {
			mysqli_commit($this->conn);
		}

		function rollbackTransaction() {
			mysqli_rollback($this->conn);
		}

		// gruppo funzioni interrogazione
		function query($sql) {
			$this->result = mysqli_query($this->conn, $sql);
			return($this->result);
		}

		function affectedRows() {
			return(mysqli_affected_rows($this->conn));
		}

		function numrows() {
			return(mysqli_num_rows($this->result));
		}

		function fetchobject() {
			return(mysqli_fetch_object($this->result));
		}

		function fetcharray() {
			return(mysqli_fetch_array($this->result));
		}

		function fetchassoc() {
			return( mysqli_fetch_assoc($this->result) );
		}

		function freeresult() {
			if ($this->result)
				return $this->result->free();
		}

		function real_escape_string($param)	{
			return(mysqli_real_escape_string($this->conn, $param));
		}

		function insert_id()	{
			return(mysqli_insert_id($this->conn) );
		}

	}
?>
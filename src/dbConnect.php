<?php

namespace phplibs\Database;

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


	function __construct()	{
		$this->host = getenv('DB_HOST');
		$this->user = getenv('DB_USER');
		$this->password = getenv('DB_PASSWORD');
		$this->database = getenv('DB_NAME');
		$this->port = getenv('DB_PORT');

		$this->conn = mysqli_connect( $this->$host, $this->$user, $this->$password, $this->$database, $this->$port );
		$this->result = mysqli_query($this->conn, "SET character_set_results = 'utf8', character_set_client = 'utf8', character_set_connection = 'utf8', character_set_database = 'utf8', character_set_server = 'utf8'");
		return $this->result;
	}

	// chiusura DB
	function close() {
		return (@mysqli_close($this->conn));
	}

	// se error_reporting attivato riporto errore
	function error() {
		// if ($this->error_reporting) {
			return (mysqli_error($this->conn)) ;
		// }
	} // chiusura open

    // gruppo funzioni interrogazione
    function query($sql) {
		$this->result = mysqli_query($this->conn, $sql);
        return($this->result);
    }

    function affectedRows() {
        return(@mysqli_affected_rows($this->conn));
    }

    function numrows() {
        return(@mysqli_num_rows($this->result));
    }

    function fetchobject() {
         return(@mysqli_fetch_object($this->result));
    }

     function fetcharray() {
          return(mysqli_fetch_array($this->result));
     }

     function fetchassoc() {
         return( mysqli_fetch_assoc($this->result) );
     }

     function freeresult() {
          return(@mysqli_free_result($this->result));
     }

	 function real_escape_string($param)	{
		 return(mysqli_real_escape_string($this->conn, $param));
	 }

	 function insert_id()	{
		 return(mysqli_insert_id($this->conn) );
	 }

}
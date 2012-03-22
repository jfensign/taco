<?php

define("DB_DRIVER",  "mongo"); //mysql, mongo
define("DB_HOST", "localhost"); //db host
define("DB_USER", "jfensign"); //db user
define("DB_PASS", "George89"); //db pass
define("DB_NAME", "taco"); //db name
define("DB_TABLE", "ip_blacklist"); //table (in this context) is analogous to mongo's collection

//Handles page access and ip blacklisting
class AccessControl {

  private $snapShot = array(); //snapshot of when access was attempted
  private $db; //db driver object
	
  public function __construct($blackList = TRUE, array $customUserFields = NULL) {
  
	$this->snapShot['ip_address'] = filter_var($_SERVER['REMOTE_ADDR'], 
											   FILTER_VALIDATE_IP); //grab ip address
	$this->snapShot['unix_timestamp'] = time(); //grab current unix timestamp
	$this->snapShot['active'] = !is_bool($blackList) ? 
								TRUE : 
								$blackList; //should this user be blacklisted ... default is TRUE
	$this->db = TacoDB::OpenConn(); //establish db connection
	$this->db->selectDB(DB_NAME); //select DB
  }
  
  public function checkBlackListStatus() { //check whether the user is currently blacklisted or not
	$blackListStatus = $this->db->findOne("ip_blacklist", 
							   array( "ip_address" => $this->snapShot['ip_address'],
									  "active" => 1),
							   "object"
							  );
	  
	if(is_object($blackListStatus)) { //	 
		return intval($blackListStatus->active) === 1 ? TRUE : FALSE;
	}
	elseif(is_array($blackListStatus)) {
		return intval($blackListStatus['active']) === 1 ? TRUE : FALSE;
	}
	else {
		return $blackListStatus;
	}
							 
  }
  
  public function blackListUser() {
	$blackListInsert = $this->db->insert("ip_blacklist", $this->snapShot);
  }
}

class TacoDB {

  private static $_Instance,
				 $_dbDriver,
				 $_Conn;
							  
  private function __construct() {
	self::$_dbDriver = $this->{DB_DRIVER}(); 
  }

  //Makes this a singleton.
  public static function OpenConn() {
	if(empty(self::$_instance)) {
		self::$_Instance = new self();
	}
	
	return self::$_Instance;
  }
  
  private function mongo() {
	return new TacoDB_Mongo();
  }
  
  private function mysql() {
	return new TacoDB_MySQL();
  }
  
  private function apc() {
	return new TacoDB_APC();
  }
  
  public function selectDB($dbName) {
	self::$_Conn = self::$_dbDriver->setDB($dbName);
	return self::$_Conn;
  }
  
  public function selectCollection($tableName) {
	self::$_Conn = self::$_dbDriver->setTable($tableName);
	return self::$_Conn;
  }
  
  public function findOne($collection, $args, $format = "") {
	self::$_Conn = self::$_dbDriver->find_one($collection, $args, $format);
	return self::$_Conn;
  }
  
  public function insert($collection, $args) {
	self::$_Conn = self::$_dbDriver->insert($collection, $args);
	return self::$_Conn;
  }
  
  public function dump() {
	self::$_Conn = self::$_dbDriver->dump();
	return self::$_Conn;
  }
  
}

class TacoDB_Mongo extends Mongo {

	private $_Conn,
			$_DB,
			$_Coll,
			$_Mongo,
			$_result;
	
	public function __construct() {
	  if(!$this->connected) {
		try {
		  $this->_Conn = new Mongo(DB_HOST,
								   array( 
									      "username" => DB_USER,
										  "password" => DB_PASS
							   )
							 );
		   return $this->_Conn->connect();
		}
		catch(MongoException $e) {
			echo $e->getMessage();
		}
	  }
	}
	
	public function setDB($db_name) {
	  try {
		$this->_DB = $this->_Conn->selectDB($db_name);
		return $this->_DB;
	  }
	  catch(MongoException $e) {
		echo $e->getMessage();
	  }	
	}
	
	public function setTable($coll_name) {
	  try {
		$this->_Coll = $this->_DB->selectCollection($coll_name);
	  }
	  catch(MongoException $e) {
		echo $this->getMessage();
	  }
	}
	
	public function find_one($collection, $args, $format = "") {
		try {
			$this->_result = $this->_DB->$collection->findOne($args);
			return $this->formatReturnResult($format);
		}
		catch(MongoException $e) {
			echo $e->getMessage();
		}
	}
	
	public function insert($collection, $args) {
		$this->_DB->$collection->insert($args, true);
		$this->_DB->$collection->ensureIndex(array("id" => 1), array("unique" => 1, 
														  "dropDups" => 1));
	}
	
	private function formatReturnResult($format = "") {
		
		$returnResult = NULL;
		
		switch($format) {
			case "object":
				$returnResult = (object) $this->_result; //converts array to stdType Object
			break;
			case "json":
				$returnResult = json_encode($this->_result); //returns json object
			break;
			default:
				$returnResult = $this->_result; //returns plain array w/ _id
			break;
		}	
		
		return $returnResult;
		
	}
}

class TacoDB_MySQL {
	
	private $_Conn,
			$_DB,
			$_Table,
			$_resultResource;
			
	public function __construct() {
	  $this->_Conn = mysql_connect(
								   DB_HOST,
								   DB_USER,
								   DB_PASS
					  ) or die(mysql_error());	//connect to db				   
	}
	
	public function setDB($db_name) {
		try { //select db
		  mysql_select_db($db_name, $this->_Conn);
		}
		catch(Excpetion $e) {
		  echo mysql_error();
		}
	}
	
	public function setTable($table_name) {
		$this->_Table = $table_name;
	}
	
	public function insert($table, $args) {
		
		$args = $this->sanitizeQueryParams($args);
		
		$sql = "INSERT INTO $table
				(" .
				implode(", ", array_keys($args))
				. ") VALUES (" .
				implode(", ", array_values($args))
				. ")";
				
		$this->resultResource = mysql_query($sql)
								or die(mysql_error());
								
		return mysql_insert_id();
	}
	
	public function find_one($table, $args, $returnType = "") {

		$args = $this->sanitizeQueryParams($args);
		$sql = "SELECT * 
		        FROM $table
				WHERE " .
				str_replace("&", " AND ", urldecode(http_build_query($args)));
		
		$this->resultResource = mysql_query($sql)
								or die(mysql_error());
		
		return $this->formatReturnResult($returnType);
	}
	
	private function sanitizeQueryParams(array $queryParts = NULL) {
		
		array_map("mysql_real_escape_string", $queryParts);
		
		foreach($queryParts as $key=>$val) {
			mysql_real_escape_string($key);
			
			if(gettype($val) === "string") {
				$queryParts[$key] = "'"  . $val . "'";
			}
			elseif(gettype($val) === "integer") {
				$queryParts[$key] = intval($val);
			}
		}
		
		return $queryParts;
	}
	
	private function formatReturnResult($type = "") {
		$returnVals;
		switch($type) {
			case "object":
				$returnVals = mysql_fetch_object($this->resultResource);
			break;
			case "count":
				$returnVals = mysql_num_rows($this->resultResource);
			break;
			case "json":
				return json_encode($this->formatReturnResult());
			default:
				$returnVals = mysql_fetch_assoc($this->resultResource);
			break;
		}
		
		return $returnVals;
	}
	
}

?>
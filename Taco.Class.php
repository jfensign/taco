<?php

define("DB_DRIVER",  "mongo"); //mysql, mongo
define("DB_HOST", "localhost"); //db host
define("DB_USER", "root"); //db user
define("DB_PASS", ""); //db pass
define("DB_NAME", "taco"); //db name
define("DB_TABLE", "ip_blacklist"); //table (in this context) is analogous to mongo's collection

//Handles page access and ip blacklisting
class AccessControl {

  private $snapShot = array(); //snapshot of when access was attempted
  private $db; //db driver object

  
  public function __construct($blackList = TRUE, $customUserFields=NULL) {
  
	$this->snapShot['ip_address'] = filter_var($_SERVER['REMOTE_ADDR'], 
											   FILTER_VALIDATE_IP); //grab ip address
	$this->snapShot['unix_timestamp'] = time(); //grab current unix timestamp
	$this->snapShot['active'] = !is_bool($blackList) ? 
								TRUE : 
								$blackList; //should this user be blacklisted ... default is TRUE
								
	if($customUserFields != NULL && is_array($customUserFields)) { //check if $customUserFields is a populated array
		foreach($customUserFields as $key => $val) { //iterate through associated elements
			$this->snapshot[$key] = $val; //add elements to array
		}
	}
	
	$this->db = TacoDB::OpenConn(); //establish db connection
	$this->db->selectDB(DB_NAME); //select DB
  }
  
  public function checkBlackListStatus() { //check whether the user is currently blacklisted or not
  
    //attempt to find record of previous violation
	$blackListStatus = $this->db->findOne("ip_blacklist", 
							   array( "ip_address" => $this->snapShot['ip_address'],
									  "active" => 1),
							   "count"
							  );
	return intval($blackListStatus) > 0 ? TRUE : FALSE; //if the db query returns an int result greater than 0 return TRUE
  }
  //insert user into table/create doc
  public function blackListUser() {
	$blackListInsert = $this->db->insert("ip_blacklist", $this->snapShot);
  }
}

//db abstraction
class TacoDB {

  private static $_Instance; //singleton instance
  private static $_dbDriver; //db driver instance
  private static $_Conn; //db connection instance
							  
  private function __construct() {
	$driver = DB_DRIVER;
	self::$_dbDriver = $this->$driver(); //call driver through bootstrap function
  }

  //Return Singleton Instance
  public static function OpenConn() {
	if(empty(self::$_instance)) {
		self::$_Instance = new self(); //create instance
	}
	
	return self::$_Instance;
  }
  
  private function mongo() { //load mongo driver
	return new TacoDB_Mongo();
  }
  
  private function mysql() { //load mysql driver
	return new TacoDB_MySQL();
  }
  
  private function apc() { //load apc extension
	return new TacoDB_APC();
  }
  
  public function selectDB($dbName) { //select DB
	self::$_Conn = self::$_dbDriver->setDB($dbName);
	return self::$_Conn;
  }
  
  public function selectCollection($tableName) { //selects table(mysql) or collection(mongo)
	self::$_Conn = self::$_dbDriver->setTable($tableName);
	return self::$_Conn;
  }
  
  public function findOne($collection, $args, $format = "") { //find single result
	self::$_Conn = self::$_dbDriver->find_one($collection, $args, $format);
	return self::$_Conn;
  }
  
  public function insert($collection, $args) { //insert
	self::$_Conn = self::$_dbDriver->insert($collection, $args);
	return self::$_Conn;
  }
  
  public function dump() { //dump (development)
	self::$_Conn = self::$_dbDriver->dump();
	return self::$_Conn;
  }
  
}
//mongo
class TacoDB_Mongo extends Mongo {

	private $_Conn,
			$_DB,
			$_Coll,
			$_Mongo,
			$_result;
	
	public function __construct() { 
	  if(!$this->connected) { //Mongo::connected
		try {
		  $this->_Conn = new Mongo(DB_HOST, //create new instance
							   array( 
							   "username" => DB_USER,
							   "password" => DB_PASS
							   )
							 );
		   return $this->_Conn->connect(); //connect to mongod server
		}
		catch(MongoException $e) { //or catch MongoException
			echo $e->getMessage();
		}
	  }
	}
	
	public function setDB($db_name) { //set the database
	  try {
		$this->_DB = $this->_Conn->selectDB($db_name);
		return $this->_DB;
	  }
	  catch(MongoException $e) {
		echo $e->getMessage();
	  }	
	}
	
	public function setTable($coll_name) { //set the collection
	  try {
		$this->_Coll = $this->_DB->selectCollection($coll_name);
	  }
	  catch(MongoException $e) {
		echo $this->getMessage();
	  }
	}
	
	public function find_one($collection, $args, $format = "") { //find a single result
		try {
			return $this->_DB->$collection->findOne($args);
		}
		catch(MongoException $e) {
			echo $e->getMessage();
		}
	}
	
	public function insert($collection, $args) { //insert
		$this->_DB->$collection->insert($args, true);
		$this->_DB->$collection->ensureIndex(array("id" => 1), array("unique" => 1, 
														  "dropDups" => 1)); //don't allow/drop any existing redundancies
	}
}

//mysql
class TacoDB_MySQL {
	
	private $_Conn,
			$_DB,
			$_Table,
			$_resultResource;
			
	public function __construct() {
	  //mysql connect
	  $this->_Conn = mysql_connect(
								   DB_HOST,
								   DB_USER,
								   DB_PASS
								   ) or 
								   die(mysql_error());					   
	}
	//select db
	public function setDB($db_name) {
		try {
		  mysql_select_db($db_name, $this->_Conn);
		}
		catch(Excpetion $e) {
		  echo mysql_error();
		}
	}
	//not really necessary, but I might incorporate an interface down the road
	public function setTable($table_name) {
		$this->_Table = $table_name;
	}
	//insert
	public function insert($table, $args) {
		//sanitize query values
		$args = $this->sanitizeQueryParams($args);
		//sql
		$sql = "INSERT INTO $table
				(" .
				implode(", ", array_keys($args))
				. ") VALUES (" .
				implode(", ", array_values($args))
				. ")";
		//execute query
		$this->resultResource = mysql_query($sql)
								or die(mysql_error());
		//return last inserted id						
		return mysql_insert_id();
	}
	
	/*find one row
	//$table (string) table name
	*/
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
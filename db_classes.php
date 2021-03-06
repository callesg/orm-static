<?php
class DBException extends Exception{
}
class DBSelectException extends DBException{
}
class DBDeleteException extends DBException{
}
class DBUpdateInsertException extends DBException{
}

//Parrent of the table classes
class DBObj {
	//Ids for db objects
	public $_id;

	//if FromDB is true source is the row id to featch
	public function __construct($Source = NULL, $FromDB = true){
		global $MY_SQL_Handle;
		if(isset($Source)){
			if($FromDB){
				//Get Data from DB
				$_id = intval($Source);
				$Result = $MY_SQL_Handle->query('SELECT * from `'.static::$_tableName.'` WHERE _id = '.$_id);
				if(!$Result){
					throw new DBSelectException("Data Base query Error: ".$MY_SQL_Handle->error);
				}
				if(!($data = $Result->fetch_array(MYSQLI_ASSOC))){
					throw new Exception(get_class($this).' with Source _id: '.$_id.' ('.$Source.') does not exist');
				}
				$Result->free();
			}else if(is_array($Source)){//For Mass querys
				$data = $Source;
			}
		}

		if(isset($data)){
			//Fill data that we got in to the fields of the object
			$Properies = get_class_vars(get_class($this));
			unset($Properies['_tableName']);
			foreach($Properies AS $PropName => $DefinedValue){
				if(isset($data[$PropName])){
					$this->$PropName = $data[$PropName];
				}
			}
		}
	}

	//Creates a array of object from a array of data
	public static function FillListFromArray($ArrayRows){
		$ListOfRows = array();
		foreach($ArrayRows AS $data){
			if(!isset($data['_id'])){
				throw new Exception("Data in FillListFromArray does not have the right field");
			}
			$ListOfRows[$data['_id']] = new static($data, false);
		}
		return($ListOfRows);
	}

	//Draws a html table very usefull for debuging
	public function HTMLTable(){
		echo "\n<table class=\"table\">\n";
		echo "	<thead>\n";
		echo "		<tr>\n";

		$Properies = get_class_vars(get_class($this));
		unset($Properies['_tableName']);
		foreach($Properies AS $PropName => $DefinedValue){
				echo "<th>".$PropName."</th>";
		}

		echo "		</tr>\n";
		echo "	</thead>\n";
		echo "	<tbody>\n";
		echo "		<tr>\n";
		foreach($Properies AS $PropName => $DefinedValue){
				echo "<td>".$this->$PropName."</td>";
		}
		echo "	</tbody>\n";
		echo "</table>\n";
	}

	//tries to get single object if avalible
	public static function GetObjByProperty($Properties){
		$list = static::GetListByProperty($Properties);
		if(count($list) > 0){
			return array_shift($list);
		}
		return false;
	}

	//Fetches a list of objects from the databse based of some specified properties
	public static function GetListByProperty($Properties){
		global $MY_SQL_Handle;
		$WhereStr = static::GenerateDBQuery($Properties, true);
		$Result = $MY_SQL_Handle->query('SELECT * from `'.static::$_tableName.'` '.$WhereStr);
		if(!$Result){
			throw new DBSelectException("Data Base query Error: ".$MY_SQL_Handle->error);
		}
		$ListOfRows = array();
		while(($data = $Result->fetch_array(MYSQLI_ASSOC))){
			$ListOfRows[$data['_id']] = new static($data, false);
		}
		$Result->free();
		return($ListOfRows);
	}

	public function SetVisual($VisualVal){
		$this->_visualName = $VisualVal;
	}
	public function GetVisual(){
		if(isset($this->_visualName)){
			return strval($this->_visualName);
		}
		return '['.$this->_id.']';
	}

	//Gets data that was conencted in the executed query
	public function RefRow($TableName, array $QueryResultTable, $ConnectedList = false){
		//Test What Tables are avalible from both directions
		if(!isset($QueryResultTable[$TableName])){
			throw new Exception("Tried to Use A table: ".$TableName." that was not in the recived query.".
				"The recived tables was: ".implode(", ", array_keys($QueryResultTable)));
		}
		if(!isset($this->_refs[$TableName])){
			throw new Exception("Can not Use A table: ".$TableName." that was not in the recived in the same query as ".
				"the current object. The tables that was recived with the object was: ".implode(", ", array_keys($this->_refs)));
		}
		if($ConnectedList){
			$ListOfThings = array();
			foreach($this->_refs[$TableName] AS $ThingId){
				$ListOfThings[$ThingId] = $QueryResultTable[$TableName][$ThingId];
			}
			return($ListOfThings);
		}

		//Test Row references
		if(!isset($this->_refs[$TableName][0])){
			throw new Exception("The current obejct: ".$this->_id." in table: ".static::$_tableName." has no reference to ".
				"the table: ".$TableName." In the query that the object was featched in.");
		}
		if(!isset($QueryResultTable[$TableName][$this->_refs[$TableName][0]])){
			throw new Exception("The current row: ".$this->_id." in table: ".static::$_tableName." has no reference to the table: ".
				$TableName." In the query that the object was featched in.");
		}
		return($QueryResultTable[$TableName][$this->_refs[$TableName][0]]);
	}


	public function remove(){
		global $MY_SQL_Handle;
		if(isset($this->_id)){
			$Result = $MY_SQL_Handle->query("DELETE FROM `".static::$_tableName."` WHERE _id = ".intval($this->_id));
			if(!$Result){
				throw new DBDeleteException("Data Base query Error: ".$MY_SQL_Handle->error);
			}
		}
	}
	public function save(){
		global $MY_SQL_Handle;

		$QueryTypeStr = 'INSERT INTO';
		$QueryWhereStr = '';
		//If this is object is already in the databse
		if(isset($this->_id)){
			$QueryWhereStr = 'WHERE _id = '.intval($this->_id);
			$QueryTypeStr = 'UPDATE';
		}

		$QueryValues = array();
		$Properies = get_class_vars(get_class($this));
		foreach($Properies AS $PropName => $DefinedValue){

			//Stuff that starts with _ is not to be in the database
			if(substr($PropName, 0, 1) != '_'){
				$PropVal = $this->$PropName;
				$Key = '`'.static::$_tableName.'`.`'.$PropName.'`';
				if(is_null($PropVal)){
					$Val = 'NULL';
				}else{
					$Val = '\''.$MY_SQL_Handle->real_escape_string($PropVal).'\'';
				}

				$AddToQuery = true;

				//If a value is null and this is a new object dont set the value to null
				if(!isset($PropVal) && !isset($this->_id)){
					$AddToQuery = false;
				}
				if($AddToQuery){
					$QueryValues[] = $Key." = ".$Val;
				}
			}
		}
		$QuerySetStr = '';
		if(count($QueryValues) != 0){
			$QuerySetStr = 'SET '.implode($QueryValues, ', ');
		}
		$Qs = $QueryTypeStr.' `'.static::$_tableName.'` '.$QuerySetStr.' '.$QueryWhereStr;
		/*if($QueryTypeStr == 'UPDATE'){
			var_dump($Qs);
			exit();
		}*/
		$Result = $MY_SQL_Handle->query($Qs);
		if(!$Result){
			throw new DBUpdateInsertException("Data Base query Error: ".$MY_SQL_Handle->error);
		}

		//If this is newly inserted get the new _id
		if(!isset($this->_id)){
			return($MY_SQL_Handle->insert_id);
		}
		return($this->_id);
	}

	//Creates Where or a set sql string based on the input properties
	public static function GenerateDBQuery(array $Values, $Question){
		global $MY_SQL_Handle;
		$QueryValues = array();
		$Joiner = ', ';
		$SqlType = 'SET';
		if($Question){
			$Joiner = ' && ';
			$SqlType = 'WHERE';
		}

		if(count($Values) == 0){
			$SqlType = '';
		}

		foreach($Values AS $KeyName => $PropVal){
			$Key = '`'.static::$_tableName.'`.`'.$KeyName.'`';
			$Val = '\''.$MY_SQL_Handle->real_escape_string($PropVal).'\'';
			$QueryValues[] = $Key." = ".$Val;
		}
		return($SqlType.' '.implode($Joiner, $QueryValues));
	}
}

//This is the class that aggregation collumns get
class GenericTable extends DBObj {
	public function __construct($Source = NULL, $FromDB = true){
		foreach($Source AS $PropName => $Value){
			$this->$PropName = $Value;
		}
	}
}

//Helper for escapig sql values
function DB_Esc($Thing){
	global $MY_SQL_Handle;
	return('\''.$MY_SQL_Handle->real_escape_string($Thing).'\'');
}

//Executes a generic question and returns a array of arrays with objects that was returned
function DB_query($QueryStr){
	global $MY_SQL_Handle, $TABLE_Classes;
	$Result = $MY_SQL_Handle->query($QueryStr);
	if(!$Result){
		throw new Exception("Data Base query Error: ".$MY_SQL_Handle->error);
	}

	//Map query result Columns to object fields
	$CollumnMetas = $Result->fetch_fields();
	$RowFieldMapping = array();
	foreach($CollumnMetas AS $CollumnNum => $CollumnMeta){
		if(!isset($RowFieldMapping[$CollumnMeta->orgtable])){
			$RowFieldMapping[$CollumnMeta->orgtable] = array();

			//Check that we wont miss any fields and if orgtable is empty this will be classified as a GenericTable
			if($CollumnMeta->orgtable != "" && !isset($TABLE_Classes[$CollumnMeta->orgtable])){
				throw new Exception("The table \"".$CollumnMeta->orgtable."\" Does not have a class mapping. Query: ".$QueryStr);
			}
		}
		$RowFieldMapping[$CollumnMeta->orgtable][] = $CollumnNum;
	}
	//Create Holder arrays for the result
	$ResultTables = array();
	foreach($RowFieldMapping AS $tableName => $CollumnNums){
		$ResultTables[$tableName] = array();
	}
	$RowNum = 0;
	while(($ResultRow = $Result->fetch_row())){

		//Separete the data from the row to the diffrent tables
		$TablesRowData = array();
		$RowIds = array();
		$CalcValues = array();
		foreach($RowFieldMapping AS $tableName => $CollumnNums){
			$TableRow = array();
			if($tableName == ""){
				$TableRow['_id'] = $RowNum;
				foreach($CollumnNums AS $ColumnNum){
					$TableRow[$CollumnMetas[$ColumnNum]->name] = $ResultRow[$ColumnNum];
				}
			}else{
				foreach($CollumnNums AS $ColumnNum){
					$TableRow[$CollumnMetas[$ColumnNum]->orgname] = $ResultRow[$ColumnNum];
				}
			}
			if(isset($TableRow['_id'])){
				$TablesRowData[$tableName] = $TableRow;
				$RowIds[$tableName] = $TableRow['_id'];
			}
		}

		//Create the objects and add refrences betwen the stuff from the same row
		foreach($TablesRowData AS $tableName => $TableRow){
			if(!isset($ResultTables[$tableName][$TableRow['_id']])){
				//Create Object
				if($tableName == ""){//If it is a calculated value use the GenericTable class
					$ResultTables[$tableName][$TableRow['_id']] = new GenericTable($TableRow, false);
				}else{
					$ClassName = $TABLE_Classes[$tableName];
					$ResultTables[$tableName][$TableRow['_id']] = new $ClassName($TableRow, false);
				}

				//Create The reference Holder
				$EmptyRefs = array();
				foreach($RowFieldMapping AS $Tbn => $meh){
					$EmptyRefs[$Tbn] = array();
				}
				$ResultTables[$tableName][$TableRow['_id']]->_refs = $EmptyRefs;
			}

			//Fill references
			foreach($RowIds AS $IdTableName => $RowId){
				if(!in_array($RowId, $ResultTables[$tableName][$TableRow['_id']]->_refs[$IdTableName])){
					$ResultTables[$tableName][$TableRow['_id']]->_refs[$IdTableName][] = $RowId;
				}
			}
		}
		$RowNum++;
	}
	$Result->free();
	return($ResultTables);
}

//Generated classes based on tables in the databse
function GenerateDBClasses($DataBase, $ClassTables, $MY_SQL_Handle){

	//TO get References
	$References = $MY_SQL_Handle->query('SELECT * FROM `information_schema`.`KEY_COLUMN_USAGE`
	WHERE
		`information_schema`.`KEY_COLUMN_USAGE`.`TABLE_SCHEMA` = "'.$DataBase.'" AND
		`information_schema`.`KEY_COLUMN_USAGE`.`REFERENCED_TABLE_SCHEMA` IS NOT NULL');

	$ReferencesTableCounts = array();
	while($Reference = $References->fetch_assoc()){

		$TableName = $Reference['TABLE_SCHEMA'].'/'.$Reference['TABLE_NAME'];
		if(!isset($ReferencesTableCounts[$TableName])){
			$ReferencesTableCounts[$TableName] = 0;
		}
		$ReferencesTableCounts[$TableName] += 1;

		$RefTableName = $Reference['REFERENCED_TABLE_SCHEMA'].'/'.$Reference['REFERENCED_TABLE_NAME'];
		if(!isset($ReferencesTableCounts[$RefTableName])){
			$ReferencesTableCounts[$RefTableName] = 0;
		}
		$ReferencesTableCounts[$RefTableName] += 1;
	}

	//Main Columns only have one reference
	foreach($ReferencesTableCounts AS $RefTableCount){
		if($RefTableCount == 1){

		}
	}
	$References->free();

	$PHPCode = "<?php\n";
	$ClassNames = array();
	$TableInfo = array();

	foreach($ClassTables AS $Table){
		//Fetch Column info
		$Collumns = $MY_SQL_Handle->query("SHOW COLUMNS FROM ".$Table);
		$CollumnArray = array();
		while($Collumn = $Collumns->fetch_assoc()){
			$CollumnArray[] = $Collumn;
		}
		$Collumns->free();


		//Could add name checking we have certain expectations on columns and tables such as them ending with an s and beeing valid php variables

		$ClassName = ucfirst(strtolower($Table));
		//Could use the Type of the db to ensure type integrity on the object
		//var_dump($DatCol);

		//Remove final s to make singular
		$ClassName = substr($ClassName, 0, -1);
		$ClassNames[] = '\''.$Table.'\' => \''.$ClassName.'\'';

		$PhpClass = "//computer generated class, made from the database table ".$Table."\n".
		"class ".$ClassName." extends DBObj {\n\t".
		'public static $_tableName = \''.$Table."';\n";
		"\tprivate ".'$_visualName'." = NULL;\n";
		"\tpublic ".'$_refs'." = array();\n";
		$table_info = array();
		foreach($CollumnArray AS $Collumn){
			if($Collumn['Field'] != '_id'){
				$PhpClass .= "\tpublic $".$Collumn['Field'].";\n";
			}
			$column_name = $Collumn['Field'];
			unset($Collumn['Field']);
			if($Collumn['Null'] ==  'NO'){
				$Collumn['Null'] = false;
			}else{
				$Collumn['Null'] = true;
			}
			$table_info[$column_name] = $Collumn;
		}
		$TableInfo[] = '\''.$Table.'\' => '.var_export($table_info, true);
		$PhpClass .= "}\n\n";
		$PHPCode .= $PhpClass;
	}

	$PHPCode .= '$TABLE_Classes = array('.implode(', ', $ClassNames).');'."\n";
	$PHPCode .= '$TABLE_info = array('.implode(', ', $TableInfo).');'."\n";

	$PHPCode .= '?'.">\n";
	return($PHPCode);
}

//takes all tables in the database and generates classes for them
function SaveDBClassesToFile($DataBase, $FileName, $MY_SQL_Handle){
	$ClassTables = array();
	$Tables = $MY_SQL_Handle->query("SHOW TABLES");
	while(($ResultRow = $Tables->fetch_row())){
		$ClassTables[] = array_pop($ResultRow);
	}
	$Tables->free();

	$PHPCode = GenerateDBClasses($DataBase, $ClassTables, $MY_SQL_Handle);
	file_put_contents($FileName, $PHPCode);
}

//Helper for pivoting Object results
/*
DB_Result_Pivot(
	RuseultTable = array of objects to pivot,
	CollumName = collumn to get value from, (if used with CollumKeyName: true means use the array key as the value, false means get the entire object as the value)
	[CollumKeyName] = the collumn to use as a a key in the result array
	[DuplicateValues] = get more one value for each key in the result array

	 if set to true, it will get the keys is the array
)
*/
function DB_Result_Pivot($RuseultTable, $CollumName, $CollumKeyName = NULL, $DuplicateValues = false){
	$ReturnArray = array();
	if(!is_array($RuseultTable)){
		throw new Exception('DB_Result_Pivot $RuseultTable is not an array');
	}
	if(isset($CollumKeyName)){
		if($DuplicateValues){
			if($CollumName === true){
				foreach($RuseultTable AS $key => $TableRow){
					$ReturnArray[$TableRow->$CollumKeyName][] = $key;
				}
			}else if($CollumName === false){
				foreach($RuseultTable AS $key => $TableRow){
					$ReturnArray[$TableRow->$CollumKeyName][] = $TableRow;
				}
			}else{
				foreach($RuseultTable AS $TableRow){
					$ReturnArray[$TableRow->$CollumKeyName][] = $TableRow->$CollumName;
				}
			}
		}else{
			if($CollumName === true){
				foreach($RuseultTable AS $key => $TableRow){
					$ReturnArray[$TableRow->$CollumKeyName] = $key;
				}
			}else if($CollumName === false){
				foreach($RuseultTable AS $key => $TableRow){
					$ReturnArray[$TableRow->$CollumKeyName] = $TableRow;
				}
			}else{
				foreach($RuseultTable AS $TableRow){
					$ReturnArray[$TableRow->$CollumKeyName] = $TableRow->$CollumName;
				}
			}
		}
	}else{
		foreach($RuseultTable AS $TableRow){
			$ReturnArray[] = $TableRow->$CollumName;
		}
	}
	return($ReturnArray);
}


//Helper for pivoting arrays of arrays
/*
DB_Abstraction_Get_Collumn(
	RuseultTable = array of arrays to pivot,
	CollumName = collumn to get value from, (if used with CollumKeyName: true means use the array key as the value, false means get the entire row as the value)
	[CollumKeyName] = the collumn to use as a a key in the result array
	[DuplicateValues] = get more one value for each key in the result array

	 if set to true, it will get the keys is the array
)
*/
function DB_Abstraction_Get_Collumn($RuseultTable, $CollumName, $CollumKeyName = NULL, $DuplicateValues = false){
	$ReturnArray = array();
	if(!is_array($RuseultTable)){
		throw new Exception('DB_Abstraction_Get_Collumn $RuseultTable is not an array');
	}
	if(isset($CollumKeyName)){
		if($DuplicateValues){
			if($CollumName === true){
				foreach($RuseultTable AS $key => $TableRow){
					$ReturnArray[$TableRow[$CollumKeyName]][] = $key;
				}
			}else if($CollumName === false){
				foreach($RuseultTable AS $key => $TableRow){
					$ReturnArray[$TableRow[$CollumKeyName]][] = $TableRow;
				}
			}else{
				foreach($RuseultTable AS $TableRow){
					$ReturnArray[$TableRow[$CollumKeyName]][] = $TableRow[$CollumName];
				}
			}
		}else{
			if($CollumName === true){
				foreach($RuseultTable AS $key => $TableRow){
					$ReturnArray[$TableRow[$CollumKeyName]] = $key;
				}
			}else if($CollumName === false){
				foreach($RuseultTable AS $key => $TableRow){
					$ReturnArray[$TableRow[$CollumKeyName]] = $TableRow;
				}
			}else{
				foreach($RuseultTable AS $TableRow){
					$ReturnArray[$TableRow[$CollumKeyName]] = $TableRow[$CollumName];
				}
			}
		}
	}else{
		foreach($RuseultTable AS $TableRow){
			$ReturnArray[] = $TableRow[$CollumName];
		}
	}
	return($ReturnArray);
}

function DB_implode_in($Column, $implodes, $WhenEmpty = false){
	global $MY_SQL_Handle;
	if(!isset($implodes) || count($implodes) == 0){
		if($WhenEmpty){
			return('true');
		}else{
			return('false');
		}
	}
	foreach($implodes AS $key => $Value){
		$implodes[$key] = DB_Esc($Value);
	}

	return($MY_SQL_Handle->real_escape_string($Column).' IN ('.implode(', ', $implodes).')');
}

function csv_dump($data, $Split = ','){
	$outstream = fopen("php://temp", 'r+');

	//Get column names
	$Keys = array();
	foreach($data as $dataRow){
		$pos = 0;
		foreach($dataRow as $NameKey => $cell){
			$Keys[$NameKey] = $pos;
			$pos += 1;
		}
	}

	if(count($Keys) != 0){
		$KeysPrint = array_keys($Keys);
		fputcsv($outstream, $KeysPrint, $Split, '"');
	}

	foreach($data as $dataRow){
		$File_data = array();
		foreach($Keys as $Key => $pos){
			if(isset($dataRow[$Key])){
				$File_data[] = $dataRow[$Key];
			}else{
				$File_data[] = '';
			}
		}
		fputcsv($outstream, $File_data, $Split, '"');
	}
	rewind($outstream);
	$csv = '';
	while(!feof($outstream)){
		$csv .= fgets($outstream);
	}
    fclose($outstream);
    return $csv;
}


function load_csv($file, $search_head = NULL, $separator = ';'){
	$data = array();
	//parse the price list file
	$close = false;
	if(is_string($file)){
		if(($handle = fopen($file, "r")) !== FALSE){
			$close = true;
		}else{
			throw Exception("Could not open csv file: $file\n");
		}
	}else{
		$handle = $file;
	}
	$found_column_headers = false;
	$headers = array();
	while (($line = fgetcsv($handle, 0, $separator)) !== FALSE) {
		//Skip the first couple of useles lines and find the headers
		if(!$found_column_headers){
			$use_as_head = false;
			if(isset($search_head)){
				$num_matches = 0;
				foreach($line AS $val){
					$val = preg_replace('/\s/', '_', preg_replace('/[^[:print:]]/', '', $val));
					if(in_array($val, $search_head)){
						$num_matches += 1;
					}
				}
				if(count($search_head) == $num_matches){
					$use_as_head = true;
				}
			}else{
				$use_as_head = true;
			}

			if($use_as_head){
				$found_column_headers = true;
				foreach($line AS $c => $val) {
					$val = preg_replace('/\s/', '_', preg_replace('/[^[:print:]]/', '', $val));
					$headers[$val] = $c;
				}
			}
		}else if($found_column_headers){//We found the headers lets start taking the data
			$dline = array();
			foreach($headers AS $name => $id){
				$dline[$name] = $line[$headers[$name]];
			}
			$data[] = $dline;
		}
	}

	if($close){
		fclose($handle);
	}
	return $data;
}

function Array_Table($arr){
	if(count($arr) == 0){
		throw new Exception("No values in array");
	}
	echo('<table border="1">');

	//Print The Keys
	$First = reset($arr);
	echo("<tr>");
	foreach($First as $key => $val){
		echo("<th>" . $key . "</th>");
	}
	echo("</tr>\n");

	//Print all values
	foreach($arr as $Collumn => $Collumn_val){
		echo("<tr>");
		foreach($Collumn_val as $key => $val){
			echo("<td>" . $val . "</td>");
		}
		echo("</tr>\n");
	}
	echo('</table>');
}


function simple_DB_query($QueryStr){
	global $MY_SQL_Handle;
	$Result = $MY_SQL_Handle->query($QueryStr);
	if(!$Result){
		throw new Exception("Data Base query Error: ".$MY_SQL_Handle->error);
	}

	//Map query result Columns to object fields
	$CollumnMetas = $Result->fetch_fields();
	$ColumnNames = array();
	foreach($CollumnMetas AS $CollumnNum => $CollumnMeta){
		$ColumnNames[$CollumnNum] = $CollumnMeta->table.'.'.$CollumnMeta->name;
	}

	$rows = array();
	while(($ResultRow = $Result->fetch_row())){
		$rows[] = array_combine($ColumnNames, $ResultRow);
	}

	$Result->free();
	return($rows);
}

//Selects certain columns from a db result set
function DB_collumn_select($RuseultTable, $columns){
	$ret = array();
	foreach($RuseultTable AS $_id => $row){
		$limRow = array();
		foreach($columns AS $column){
			$limRow[$column] = $row->$column;
		}
		$ret[$_id] = $limRow;
	}
	return $ret;
}

?>

<?php
if (!defined('IN_PHPBURNBOT'))
{
	exit;
}

/**
 * Database library
 * 
 * @author Anthony 'IBurn36360' Diaz
 * @final
 * @name db
 * @version 1.0.0
 * 
 * Handles database connection and tasks
 */
final class db {
    protected $link;
    protected $hostname;
    protected $port;
    protected $username;
    protected $password;
    protected $database;
    
    public function __construct($hostname, $port, $username, $password, $database)
    {
        $this->hostname = (stristr($hostname, 'localhost') ? '127.0.0.1' : $hostname);
        $this->port     = intval($port);
        $this->username = $username;
        $this->password = $password;
        $this->database = $database;
        
        $this->connect();
    }
    
    /**
     * Connects to a MySQL database and sets the database
     */
    public function connect() {
        $this->link = new mysqli('p:' . $this->hostname, $this->username, $this->password, $this->database, $this->port, 'mysql');

		if (mysqli_connect_error()) {
			trigger_error(mysqli_connect_errno() . ':' . mysqli_connect_error() . ' [Could not make a database link]');
		}

		$this->link->set_charset('utf8');
		$this->link->query('SET SQL_MODE = \'\'');
        $this->link->query('SET NAMES utf8');
    }
    
    /**
     * Performs a MySQL query, grabbing all rows and cleaning memory after
     * 
     * @param $sql[string] - String SQL query to perform
     * 
     * @return $query[object] - Object all data, including all returned rows from the query.  Returns true is query failed
     * 
     * @return $query->row[array] - Array(Indexed) all data from first row return
     * @return $query->rows[array] - Nested array of all returned rows
     * @return $query->num_rows[int] - Int number of returned rows
     * @return $query->error[string] - The error output from the query if there was an issue
     */
    public function query($sql) 
    {
        if (!$this->connected())
        {
            $this->connect();
        }
        
        if ($this->connected())
        {
            $query = $this->link->query($sql);

    		if (!$this->link->errno){
    			if (isset($query->num_rows)) {
    				$data = array();
    
    				while ($row = $query->fetch_assoc()) {
    					$data[] = $row;
    				}
    
    				$result = new stdClass();
    				$result->numRows = $query->num_rows;
    				$result->row = isset($data[0]) ? $data[0] : array();
    				$result->rows = $data;
                    $result->error = '';
    
    				unset($data);
    
    				$query->close();
    
    				return $result;
    			} else{
    				return true;
    			}
    		} else {
                $result = new stdClass();
                $result->numRows = 0;
                $result->row = array();
                $result->rows = array();
                $result->error = $this->link->errno . ':' . $this->link->error . " [$sql]";
                
                // Trigger an error here so that it logs no matter what
                trigger_error($result->error);
                
    			return $result;
    		}
        }
        
        $result = new stdClass();
        $result->numRows = 0;
        $result->row = array();
        $result->rows = array();
        $result->error = 'Error: No database link has been found';
        
        // Trigger an error here so that it logs no matter what
        trigger_error($result->error);
        
		return $result;
    }
    
    /**
     * Escapes a value to be safe for MySQL queries
     * 
     * @param $value[mixed] - The value to be escaped
     * 
     * @return $escaped[string] - The escaped value, safe for MySQL queries
     */
    public function escape($value) 
    {
    	return $this->link->real_escape_string($value);
    }
    
    /**
     * Counts the affected rows of the last MySQl query
     * 
     * @return $rows[int] - Number of affected rows
     */
    public function countAffected() 
    {
    	return $this->link->affected_rows;
    }
    
    /**
     * Gets the last ID generated from the last INSERT query
     * 
     * @return $id[int] - The last ID generated
     */
    public function getLastId() 
    {
    	return $this->link->insert_id;
    }
    
    /**
     * Gets the character set defined by the link
     * 
     * @return $charset[string] - String charset for the link
     */
     public function getCharset()
     {
        return $this->link->character_set_name();
     }
    
    /**
     * Checks if a table exists in the current DB
     * 
     * @param $table[string] - String table name to check for
     * 
     * @return $exists[bool] - Bool table exists
     */
    public function tebleExists($table)
    {
        return ($this->query('SELECT 1 FROM ' . $table . ';') !== false) ? true : false;
    }
    
    /**
     * Valudates a value for a MySQL query and prepares it for the operation
     * 
     * @param $var[mixed] - The value to validate
     * 
     * @return $validated[mixed] - The validated value for prepared queries
     */
    protected function validateValue($var)
    {
		if (is_null($var))
		{
			return 'NULL';
		} else if (is_string($var)) {
			return '\'' . $this->escape($var) . '\'';
		} else {
			return (is_bool($var)) ? intval($var) : $var;
		}
    }
    
    /**
     * Checks of the MySQL database connection is still alive
     * 
     * @return $connected[bool] - Weather or not the database is still connected
     */
    public function connected()
    {
        return $this->link->ping();
    }
    
    // Query constructors
    
    /**
     * Constructs a safe INSERT query, valudating all values
     * 
     * @param (string)$table - Target table for the query
     * @param (array)$sql_ary - Keyed array of all data to insert into the target table
     * 
     * @return (string)$sql - Constructed and sanitized SQL query
     */
    public function buildInsert($table, $sql_ary)
    {
        $ary = array();
		foreach ($sql_ary as $id => $value)
		{
            $ary[] = $this->validateValue($value);
		}

		return 'INSERT INTO ' . $table . ' (' . implode(',', array_keys($sql_ary)) . ') VALUES (' . implode(',', $ary) . ');';
    }
    
    /**
     * Constructs a safe UPDATE query, valudating all values
     * 
     * @param (string)$table - Target table for the query
     * @param (array)$update - Keyed array of all data to update in the target table
     * @param (array)$conditions - Keyed array of conditions for the update
     * 
     * @return (string)$sql - Constructed and sanitized SQL query
     */
    public function buildUpdate($table, $update, $conditions = array())
    {
        $set = '';
        $condition = '';
        
        foreach ($update as $collumn => $value)
        {
            $set .= $this->escape($collumn) . '=' . $this->validateValue($value) . ',';
        }
        
        $set = rtrim($set, ',');
        
        if (!empty($conditions))
        {
            foreach ($conditions as $collumn => $value)
            {
                if ($condition != '')
                {
                    $condition .= ' AND ';
                }
                
                $condition .= $this->escape($collumn) . '=' . $this->validateValue($value);
            }
        }
        
        $sql = ($condition != '') ? 'UPDATE ' . $this->escape($table) . " SET $set WHERE $condition;" : 'UPDATE ' . $this->escape($table) . " SET $set;";
        
        return $sql;
    }
    
    /**
     * Constructs a safe SELECT query, validating all values
     * 
     * @param (string)$table - Target table for the query
     * @param (array)$select - Unkeyed array of all collumns to select
     * @param (array)$conditions - Keyed array of conditions for the select
     * 
     * @return (string)$sql - Constructed and sanitized SQL query
     */
    public function buildSelect($table, $select, $conditions = array())
    {
        $selectStr = '';
        $condition = '';
        
        foreach ($select as $collumn)
        {
            $selectStr .= $this->escape($collumn) . ',';
        }
        
        $selectStr = rtrim($selectStr, ',');
        
        if (!empty($conditions))
        {
            foreach ($conditions as $collumn => $value)
            {
                if ($condition != '')
                {
                    $condition .= ' AND ';
                }
                
                $condition .= $this->escape($collumn) . "=" . $this->validateValue($value) . "";
            }
        }
        
        $sql = ($condition != '') ? "SELECT $selectStr FROM "  . $this->escape($table) . " WHERE $condition;" : "SELECT $selectStr FROM "  . $this->escape($table) . ';' ;

        return $sql;
    }
    
    /**
     * Constructs a safe DELETE query, valudating all values
     * 
     * @param (string)$table - Target table for the query
     * @param (array)$conditions - Keyed array of conditions for the delete
     * 
     * @return (string)$sql - Constructed and sanitized SQL query
     */
    public function buildDelete($table, $conditions = array())
    {
        $condition = '';
        
        if (!empty($conditions))
        {
            foreach ($conditions as $collumn => $value)
            {
                if ($condition != '')
                {
                    $condition .= ' AND ';
                }
                
                $condition .= $this->escape($collumn) . '=' . $this->validateValue($value);
            }
        }
        
        $sql = ($condition != '') ? 'DELETE FROM '  . $this->escape($table) . " WHERE $condition;" : 'DELETE FROM '  . $this->escape($table) . ';';

        return $sql;
    }
}
?>
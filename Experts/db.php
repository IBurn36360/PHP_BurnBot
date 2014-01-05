<?php
// This is basically a very stripped down version of phpBB's DBAL (DataBase Abstraction Layer)
// All of the cache information and processes are gone and multi-database compatability is gone
// The way this is coded, you can plug this direction into phpBB's DBAL with no issues for compatability with ANY SQL DB type
class db
{
	var $db_connect_id;
	var $query_result;
    
	var $persistency = false;
	var $user = '';
	var $server = '';
	var $dbname = '';
    var $dbport = '';
    var $newlink = true;
    
    // Since we are going to reconnect, we need to know this.  Store as a private var
    private $dbpass = '';
    
    public function sql_connect($sqlserver, $sqluser, $sqlpassword, $database, $port = false, $persistency = false, $new_link = false)
    {
		$this->persistency = $persistency;
		$this->user = $sqluser;
		$this->server = $sqlserver . (($port) ? ':' . $port : '');
		$this->dbname = $database;
        $this->dbport = ($port) ? $port : false;
        $this->newLink = $new_link;
        
        $this->db_connect_id = ($this->persistency) ? @mysql_pconnect($this->server, $this->user, $sqlpassword) : @mysql_connect($this->server, $this->user, $sqlpassword, $new_link);
        
        if ($this->db_connect_id && $this->dbname != '')
        {
            // Attempt to select our DB
            if (@mysql_select_db($this->dbname, $this->db_connect_id))
            {
                // Return our link
                return $this->db_connect_id;
            }
        }
        
        // Generic return, will be populated with data from sql_error()
        return $this->sql_error('');
    }
    
    private function connected()
    {
        return mysql_ping($this->db_connect_id);
    }
    
    public function sql_query($query = '')
    {
        // Do we have a connection to the DB right now?
        if (!$this->connected())
        {
            // Reconnect here
            $this->sql_connect($this->server, $this->user, $this->dbpass, $this->dbname, $this->dbport, $this->persistency, $this->newlink);
        }
        
        if ($query != '')
        {
            // Okay, perform the query and store it in query_result
            if (($this->query_result = @mysql_query($query, $this->db_connect_id)) === false)
            {
                $this->sql_error($query);
            }
        } else {
            return false;
        }
        
        return $this->query_result;
    }
    
   	public function sql_affectedrows()
	{
		return ($this->db_connect_id) ? @mysql_affected_rows($this->db_connect_id) : false;
	}
    
 	public function sql_fetchrow($query_id = false)
	{
		if ($query_id === false)
		{
			$query_id = $this->query_result;
		}

		return ($query_id !== false) ? @mysql_fetch_assoc($query_id) : false;
	}
    
	public function sql_fetchrowset($query_id = false)
	{
		if ($query_id === false)
		{
			$query_id = $this->query_result;
		}

		if ($query_id !== false)
		{
			$result = array();
			while ($row = $this->sql_fetchrow($query_id))
			{
				$result[] = $row;
			}

			return $result;
		}

		return false;
	}
    
	public function sql_rowseek($rownum, &$query_id)
	{
		if ($query_id === false)
		{
			$query_id = $this->query_result;
		}

		return ($query_id !== false) ? @mysql_data_seek($query_id, $rownum) : false;
	}
    
    public function sql_nextid()
	{
		return ($this->db_connect_id) ? @mysql_insert_id($this->db_connect_id) : false;
	}
    
    public function sql_freeresult($query_id = false)
	{
		if ($query_id === false)
		{
			$query_id = $this->query_result;
		}

		return ($query_id !== false) ? @mysql_free_result($query_id) : false ;
	}
    
    public function sql_escape($msg)
	{
		if (!$this->db_connect_id)
		{
			return @mysql_real_escape_string($msg);
		}

		return @mysql_real_escape_string($msg, $this->db_connect_id);
	}
    
    private function sql_error()
	{
		if (!$this->db_connect_id)
		{
            // error_handler(@mysql_error() . ':' . @mysql_errno());
            return;
		}

        // error_handler(@mysql_error($this->db_connect_id) . ':' . @mysql_errno($this->db_connect_id));
        return;
	}
    
    public function sql_close()
	{
		return @mysql_close($this->db_connect_id);
	}
    
    public function sql_build_insert($table, $sql_ary)
    {
        $ary = array();
		foreach ($sql_ary as $id => $value)
		{
            $ary[] = $this->sql_validate_value($value);
		}

		return 'INSERT INTO ' . $table . ' ' . ' (' . implode(',', array_keys($sql_ary)) . ') VALUES (' . implode(',', $ary) . ');';
    }			

    
    public function sql_build_update($table, $update, $conditions = array())
    {
        // Start by breaking down the array of parameters
        $set = '';
        $condition = '';
        
        foreach ($update as $collumn => $value)
        {
            $set .= $this->sql_escape($collumn) . "=" . $this->sql_validate_value($value) . ",";
        }
        
        $set = rtrim($set, ',');
        
        if (!empty($conditions))
        {
            foreach ($conditions as $collumn => $value)
            {
                // Cheap way of getting the AND in there
                if ($condition != '')
                {
                    $condition .= ' AND ';
                }
                
                $condition .= $this->sql_escape($collumn) . "=" . $this->sql_validate_value($value) . "";
            }
        }
        
        // Build the query and return it
        $sql = ($condition != '') ? "UPDATE " . $this->sql_escape($table) . " SET $set WHERE $condition;" : "UPDATE " . $this->sql_escape($table) . " SET $set;";
        
        return $sql;
    }
    
    public function sql_build_select($table, $select, $conditions = array())
    {
        // Start by breaking down the array of parameters
        $selectStr = '';
        $condition = '';
        
        foreach ($select as $collumn)
        {
            $selectStr .= $this->sql_escape($collumn) . ',';
        }
        
        $selectStr = rtrim($selectStr, ',');
        
        if (!empty($conditions))
        {
            foreach ($conditions as $collumn => $value)
            {
                // Cheap way of getting the AND in there
                if ($condition != '')
                {
                    $condition .= ' AND ';
                }
                
                $condition .= $this->sql_escape($collumn) . "=" . $this->sql_validate_value($value) . "";
            }
        }
        
        // Build and return
        $sql = ($condition != '') ? "SELECT $selectStr FROM "  . $this->sql_escape($table) . " WHERE $condition;" : "SELECT $selectStr FROM "  . $this->sql_escape($table) . ";" ;

        return $sql;
    }
    
    public function sql_build_delete($table, $conditions = array())
    {
        $condition = '';
        
        if (!empty($conditions))
        {
            foreach ($conditions as $collumn => $value)
            {
                // Cheap way of getting the AND in there
                if ($condition != '')
                {
                    $condition .= ' AND ';
                }
                
                $condition .= $this->sql_escape($collumn) . "=" . $this->sql_validate_value($value) . "";
            }
        }
        
        // Build and return
        $sql = ($condition != '') ? "DELETE FROM "  . $this->sql_escape($table) . " WHERE $condition;" : "DELETE FROM "  . $this->sql_escape($table) . ";" ;

        return $sql;
    }
    
	function sql_validate_value($var)
	{
		if (is_null($var))
		{
			return 'NULL';
		}
		else if (is_string($var))
		{
			return "'" . $this->sql_escape($var) . "'";
		}
		else
		{
			return (is_bool($var)) ? intval($var) : $var;
		}
	}
}
?>
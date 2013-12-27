<?php
// Was this accessed directly?  If so, exit.
if (!defined('IN_IRC'))
{
	exit;
}

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
    
    public function sql_connect($sqlserver, $sqluser, $sqlpassword, $database, $port = false, $persistency = false, $new_link = false)
    {
		$this->persistency = $persistency;
		$this->user = $sqluser;
		$this->server = $sqlserver . (($port) ? ':' . $port : '');
		$this->dbname = $database;
        
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
    
    public function sql_query($query = '')
    {
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
	   global $irc;
       
		if (!$this->db_connect_id)
		{
            $irc->_log_error(@mysql_error() . ':' . @mysql_errno());
            return;
		}

        $irc->_log_error(@mysql_error($this->db_connect_id) . ':' . @mysql_errno($this->db_connect_id));
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

    
    public function sql_build_update($table, $insert, $conditions = array())
    {
        // Start by breaking down the array of parameters
        $set = '';
        $condition = '';
        
        foreach ($insert as $collumn => $value)
        {
            $set .= "$collumn='$value',";
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
                
                $condition .= "$collumn='$value'";
            }
        }
        
        // Build the query and return it
        $sql = ($condition != '') ? "UPDATE $table SET $set WHERE $condition;" : "UPDATE $table SET $set;";
        
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
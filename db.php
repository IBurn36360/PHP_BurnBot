<?php
// Was this accessed directly?  If so, exit.
if (!defined('IN_IRC'))
{
	exit;
}

// This is basically a very stripped down version of phpBB's DBAL (DataBase Abstraction Layer)
// All of the cache information and processes are cone and multi-database compatability is gone
// The way this is coded, you can plug this direction into phpBB's DBAL with no issues for compatability with ANY SQL DB type
class db
{
	var $db_connect_id;
	var $query_result;
    
	var $persistency = false;
	var $user = '';
	var $server = '';
	var $dbname = '';
    
    public static function sql_connect($sqlserver, $sqluser, $sqlpassword, $database, $port = false, $persistency = false, $new_link = false)
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
    
    public static function sql_query($query = '')
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
    
   	public static function sql_affectedrows()
	{
		return ($this->db_connect_id) ? @mysql_affected_rows($this->db_connect_id) : false;
	}
    
 	public static function sql_fetchrow($query_id = false)
	{
		if ($query_id === false)
		{
			$query_id = $this->query_result;
		}

		return ($query_id !== false) ? @mysql_fetch_assoc($query_id) : false;
	}
    
	public static function sql_rowseek($rownum, &$query_id)
	{
		if ($query_id === false)
		{
			$query_id = $this->query_result;
		}

		return ($query_id !== false) ? @mysql_data_seek($query_id, $rownum) : false;
	}
    
    public static function sql_nextid()
	{
		return ($this->db_connect_id) ? @mysql_insert_id($this->db_connect_id) : false;
	}
    
    public static function sql_freeresult($query_id = false)
	{
		if ($query_id === false)
		{
			$query_id = $this->query_result;
		}

		return ($query_id !== false) ? @mysql_free_result($query_id) : false ;
	}
    
    public static function sql_escape($msg)
	{
		if (!$this->db_connect_id)
		{
			return @mysql_real_escape_string($msg);
		}

		return @mysql_real_escape_string($msg, $this->db_connect_id);
	}
    
    private static function _sql_error()
	{
		if (!$this->db_connect_id)
		{
			return array(
				'message'	=> @mysql_error(),
				'code'		=> @mysql_errno()
			);
		}

		return array(
			'message'	=> @mysql_error($this->db_connect_id),
			'code'		=> @mysql_errno($this->db_connect_id)
		);
	}
    
    public static function _sql_close()
	{
		return @mysql_close($this->db_connect_id);
	}
}
?>
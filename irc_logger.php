<?php
// Was this accessed directly?  If so, exit.
if (!defined('IN_IRC'))
{
	exit;
}

// This extends the irc to use a logger function
class irc_logger extends irc
{
    public static function _write($socket, $cmd, $file)
    {
        // Simply write data to the socket
        $this->_log_outgoing($file, $cmd);
        return $this->write($socket, $cmd);
    }
    
    public static function _read($socket, $file, $len = 4096)
    {
    	$read = fgets($socket, $len);
    	$read = rtrim($read);
        $this->_log_incoming($file, $read);
    	return $read;
    }
    
    // Sends a channel message
    public static function _sendPrivateMessage($socket, $message, $chan, $file)
    {
        $this->_log_outgoing($file, "PRIVMSG $chan $message");
        return $this->write($socket, "PRIVMSG $chan $message");
    }
    
    // Sends a /me style command
    public static function _sendAction($socket, $action, $chan, $file)
    {
        $chr = chr(1);
        
        $this->_log_outgoing($file, "PRIVMSG $chan $chr$action$chr");
        return $this->write($socket, "PRIVMSG $chan $chr$action$chr");
    }
    
    public static function _joinChannel($socket, $chan, $file)
    {
        $this->_log_outgoing($file, "JOIN $chan");
        return $this->write($socket, "JOIN $chan");
    }
    
    public static function _setMode($socket, $chan, $mode, $file, $user = null)
    {
        if ($user != null)
        {
            $this->_log_outgoing($file, "MODE $chan $mode $user");
            return $this->write($socket, "MODE $chan $mode $user");
        } else {
            $this->_log_outgoing($file, "MODE $chan $mode");
            return $this->write($socket, "MODE $chan $mode");
        }
    }
    
    public static function _log_incoming($file, $str)
    {
        // does our log file exist?
        if (!file_exists($file))
        {
            $h = @fopen($file, 'w');
            @fwrite($h, '<?php exit; ?>');
        } else {
            $h = @fopen($file, 'a');
        }
        
        $logTime = date('m-d-y~H:i:s', time());
        @fwrite($h, "\n[INCOMING]$logTime || $str");
        @fclose($h);
    }
    
    public static function _log_outgoing($file, $str)
    {
        // does our log file exist?
        if (!file_exists($file))
        {
            $h = @fopen($file, 'w');
            @fwrite($h, '<?php exit; ?>');
        } else {
            $h = @fopen($file, 'a');
        }
        
        $logTime = date('m-d-y~H:i:s', time());
        @fwrite($h, "\n[OUTGOING]$logTime || $str");
        @fclose($h);
    }
    
    public static function _log_action($file, $str)
    {
        // does our log file exist?
        if (!file_exists($file))
        {
            $h = @fopen($file, 'w');
            @fwrite($h, '<?php exit; ?>');
        } else {
            $h = @fopen($file, 'a');
        }
        
        $logTime = date('m-d-y~H:i:s', time());
        @fwrite($h, "\n[ACTION]$logTime || $str");
        @fclose($h);        
    }
}
?>
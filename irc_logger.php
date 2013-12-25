<?php
// Was this accessed directly?  If so, exit.
if (!defined('IN_IRC'))
{
	exit;
}

// This extends the irc to use a logger function
class irc_logger extends irc
{
    public function _write($socket, $cmd)
    {
        global $file;
        
        // Simply write data to the socket
        $this->_log_outgoing($file, $cmd);
        return $this->write($socket, $cmd);
    }
    
    public function _read($socket, $len = 4096)
    {
        global $file;
        
    	$read = fgets($socket, $len);
    	$read = rtrim($read);
        $this->_log_incoming($file, $read);
    	return $read;
    }
    
    // Sends a channel message
    public function _sendPrivateMessage($socket, $message, $chan)
    {
        global $file;
        
        $this->_log_outgoing($file, "PRIVMSG $chan $message");
        return $this->write($socket, "PRIVMSG $chan $message");
    }
    
    // Sends a /me style command
    public function _sendAction($socket, $action, $chan)
    {
        global $file;
        $chr = chr(1);
        
        $this->_log_outgoing($file, "PRIVMSG $chan $chr$action$chr");
        return $this->write($socket, "PRIVMSG $chan $chr$action$chr");
    }
    
    public function _joinChannel($socket, $chan)
    {
        global $file;
        
        $this->_log_outgoing($file, "JOIN $chan");
        return $this->write($socket, "JOIN $chan");
    }
    
    public function _setMode($socket, $chan, $mode, $user = null)
    {
        global $file;
        
        if ($user != null)
        {
            $this->_log_outgoing($file, "MODE $chan $mode $user");
            return $this->write($socket, "MODE $chan $mode $user");
        } else {
            $this->_log_outgoing($file, "MODE $chan $mode");
            return $this->write($socket, "MODE $chan $mode");
        }
    }
    
    public function _log_incoming($str)
    {
        global $file;
        
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
    
    public function _log_outgoing($str)
    {
        global $file;
        
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
    
    public function _log_action($str)
    {
        global $file;
        
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
    
    public function _log_error($str)
    {
        global $file;
        
        // does our log file exist?
        if (!file_exists($file))
        {
            $h = @fopen($file, 'w');
            @fwrite($h, '<?php exit; ?>');
        } else {
            $h = @fopen($file, 'a');
        }
        
        $logTime = date('m-d-y~H:i:s', time());
        @fwrite($h, "\n[ERROR]$logTime || $str");
        @fclose($h);        
    }
}
?>
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
        // Simply write data to the socket
        $this->_log_outgoing($cmd);
        return $this->write($socket, $cmd);
    }
    
    public function _read($socket, $len = 4096)
    {
    	$read = socket_read($socket, 4096, PHP_NORMAL_READ);
        if ($read != '')
        {
            while ((strstr($read, "\r\n") == ''))
            {
                $read .= socket_read($socket, 4096, PHP_NORMAL_READ);
            }
        }
    	$read = rtrim($read);
        
        if (strlen($read) > 0)
        {
            $this->_log_incoming($read);
        }
        
    	return $read;
    }
    
    // Sends a channel message
    public function _sendPrivateMessage($socket, $message, $chan)
    {
        $this->_log_outgoing("PRIVMSG $chan :$message");
        return $this->write($socket, "PRIVMSG $chan :$message");
    }
    
    // Sends a /me style command
    public function _sendAction($socket, $action, $chan)
    {
        $chr = chr(1);
        
        $this->_log_outgoing("PRIVMSG $chan :$chr" . "ACTION $action$chr");
        return $this->write($socket, "PRIVMSG $chan :$chr" . "ACTION $action$chr");
    }
    
    public function _joinChannel($socket, $chan)
    {
        $this->_log_outgoing("JOIN $chan");
        return $this->write($socket, "JOIN $chan");
    }
    
    public function _setMode($socket, $chan, $mode, $user = null)
    {
        if ($user != null)
        {
            $this->_log_outgoing("MODE $chan $mode $user");
            return $this->write($socket, "MODE $chan $mode $user");
        } else {
            $this->_log_outgoing("MODE $chan $mode");
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
        @fwrite($h, "\n[INCOMING]$logTime <= $str");
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
        @fwrite($h, "\n[OUTGOING]$logTime => $str");
        @fclose($h);
    }
    
    public function _log_action($str, $name = 'action')
    {
        global $file;
        
        $name = strtoupper($name);
        
        // does our log file exist?
        if (!file_exists($file))
        {
            $h = @fopen($file, 'w');
            @fwrite($h, '<?php exit; ?>');
        } else {
            $h = @fopen($file, 'a');
        }
        
        $logTime = date('m-d-y~H:i:s', time());
        @fwrite($h, "\n[$name]$logTime || $str");
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
    
    public function _log_error_handler($errLevel, $errStr, $stack)
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
        @fwrite($h, "\n[$errLevel]$logTime || $errStr");
        
        // Now write the stacktrace
        if (is_array($stack) && !empty($stack))
        {
            array_shift($stack);
            
            // Space the stacktrace out
            $whitespace = '                           ';
            for ($i = 1; $i <= strlen($errLevel); $i++)
            {
                $whitespace .= ' ';
            }
            
            foreach ($stack as $row)
            {
                if ($row['class'] != '')
                {
                    $str = "\n" . $whitespace . $row['class'] . '::' . $row['function'] . '(';
                } else {
                    $str = "\n" . $whitespace . $row['function'] . '(';
                }
                
                foreach($row['args'] as $arg)
                {
                    $str .= "$arg, ";
                }
                $str = rtrim($str, ', ');
                $str .= ')' . '.  In file ' . $row['file'] . ' on line ' . $row['line'];
                
                @fwrite($h, $str);
            }
        }
        
        @fclose($h);        
    }
}
?>
<?php
// Was this accessed directly?  If so, exit.
if (!defined('IN_PHPBURNBOT'))
{
	exit;
}

class irc
{
    protected $sock;
    protected $connected;
    protected $addr;
    protected $port;
    
    public function __construct($addr, $port)
    {
        $this->addr = $addr;
        $this->port = $port;
        
        $this->create();
    }
    
    public function create()
    {
        $this->sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    }
    
    /**
     * Creates a socket resource for the IRC library
     */
    public function connect()
    {
        socket_connect($this->sock, $this->addr, $this->port);
        
        $this->connected = true;
    }
    
    /**
     * Checks if the sockets is till considered connected
     * 
     * @return [bool] - Still connected
     */
    public function isConnected()
    {
        return $this->connected;
    }
  
    /**
     * Reads a line from a socket if data is waiting to be received
     *
     * @return $read[string] - String data from peer server or empty if none was in buffer
     * @return $read[false]  - Fail state if the socket could not be read from
     */
    public function read()
    {
        $r = array($this->sock);
        $n = null;
        
    	if (($changed = socket_select($r, $n, $n, 0)) === false)
        {
            return ($this->connected = false);
        }
        
        if ($changed > 0)
        {
            // Read from the socket
            if (($read = @socket_read($this->sock, 4096, PHP_NORMAL_READ)) === false)
            {
                // The socket was closed
                return ($this->connected = false);
            } else {
                if (!strstr($read, "\r\n"))
                {
                    $read .= socket_read($this->sock, 4096, PHP_NORMAL_READ);
                }
                
                $read = rtrim($read, "\r\n");
            }
        }
        
    	return (((!isset($read)) || $read === false) ? '' : $read);
    }
    
    /**
     * Write data to a socket as-is
     *
     * @return $bytes[int] - Number of bytes written to the socket
     * @return $connected[bool] - False on socket write error
     */
    public function write($cmd)
    {
        return (($bytes = socket_write($this->sock, $cmd . "\r\n", strlen($cmd . "\r\n"))) === 0) ? ($this->connected = false) : $bytes;
    }
    
    /**
     * Disconnect from a peer server and close the socket resource
     */
    public function disconnect()
    {
        socket_close($this->sock);
        $this->connected = false;
    }
    
    /**
     * Sets a socket to blocking mode
     * 
     * @return [bool] - Success of setting theblock
     */
    public function setBlocking()
    {
        return socket_set_block($this->sock);
    }
    
    /**
     * Sets a socket to non-blocking mode
     * 
     * @return [bool] - Success of setting the non-block
     */
    public function setNonBlocking()
    {
        return socket_set_nonblock($this->sock);
    }
    
    /**
     * Retrieves the last error number from the socket connection
     * 
     * @return $errNo[int] - Integer error number for the last generated error
     */
    public function getLastError()
    {
        return socket_last_error($this->sock);
    }
    
    /**
     * Retrieves the last error from the socket connection
     * 
     * @return $errStr[string] - Error string for the last generated error
     */
    public function getLastErrorStr()
    {
        return socket_strerror(socket_last_error($this->sock));
    }
  
    /**
     * Send a private message to a target
     * 
     * @param $message[string] - The string text to send to the target
     * @param $target[string] - The string target for the text message
     * 
     * @return $bytes[int] - Number of bytes written to the socket
     * @return $connected[bool] - False on socket write error
     */
    public function sendPrivateMessage($message, $target)
    {
        return $this->write("PRIVMSG $target :$message");
    }
    
    /**
     * Send an action to a target
     * 
     * @param $message[string] - The string text to send to the target
     * @param $target[string] - The string target for the text message
     * 
     * @return $bytes[int] - Number of bytes written to the socket
     * @return $connected[bool] - False on socket write error
     */
    public function sendAction($action, $target)
    {
        $chr = chr(1);
        return $this->write("PRIVMSG $target :$chr" . "ACTION $action$chr");
    }
    
    /**
     * Join a channel.  Does not check a channel name for validity before attempting
     * 
     * @param $chan[string] - String channel name to join
     * 
     * @return $bytes[int] - Number of bytes written to the socket
     * @return $connected[bool] - False on socket write error
     */
    public function joinChannel($chan)
    {
        return $this->write("JOIN $chan");
    }
    
    /**
     * Set a mode or flag.  Defaults to self if no user context is given
     * 
     * @param $chan[string] - String channel name to set mode on
     * @param $mode[string] - String modes to set for the channel (Will pass as-is, so +ov would attempt to set +o and +v)
     * @param $user[string](OPTIONAL) - String username to set mode for 
     * 
     * @return $bytes[int] - Number of bytes written to the socket
     * @return $connected[bool] - False on socket write error
     */
    public function setMode($chan, $mode, $user = null)
    {
        if (!$user)
        {
            return $this->write("MODE $chan $mode $user");
        } else {
            return $this->write("MODE $chan $mode");
        }
    }
  
    /**
     * Take a raw message and convert it into an array of parts for easyuse of the information
     * 
     * @param $message[string] - String message to decode
     * 
     * @return $messageArr[array] - Array parts of the decoded message.  Structure and values described on GIT
     */
    public function checkRawMessage($message)
    {
        $messageArr = array();
        
        // We can all thank Taw from QNet for this regex.
        $pattern = '/^(?::?(?:([^ !]+)(?:!(\S+?)(?:@(\S+))?)?) )?([A-Za-z0-9]+)(?: ([^:\s]+))?(?: :?(.*))?$/';
        preg_match($pattern, $message, $parts);
        
        // Check to see if the pattern even managed to get anything out of the raw
        if (count($parts) == 0)
        {
            $messageArr = array(
                'type' => 'undecoded',
                'raw'  => $message
            );
            
            return $messageArr;
        }
        
        if (stristr($parts[4], 'privmsg'))
        {
            // Private message.  Straight forward
            $messageArr = array(
                'type'     => 'private',
                'nick'     => $parts[1],
                'ident'    => $parts[2],
                'hostname' => $parts[3],
                'target'   => $parts[5],
                'message'  => $parts[6],
                'raw'      => $message
            );                
        } else {
            if (is_numeric($parts[4]))
            {
                // Serive ID is attached to the message.  Switch and break up
                switch ($parts[4])
                {
                    
                    case '353': // NAMES list
                        $prts = explode(':', $parts[6]);
                        array_shift($prts);
                    
                        $messageArr = array(
                            'type'       => 'system',
                            'service_id' => $parts[4],
                            'issuer'     => $parts[1],
                            'target'     => $parts[5],
                            'message'    => implode(':', $prts),
                            'raw'        => $message
                        );
                        break;
                        
                    case '221': // Default mode as a service ID
                        $sets = explode(' ', $parts[6]);
                        $messageArr = array(
                            'type'    => 'system',
                            'is_mode' => true,
                            'issuer'  => $parts[1],
                            'modes'   => array(array('nick' => $parts[1], 'mode' => $sets[0])),
                            'raw'     => $message
                        );
                        break;
                    
                    default:
                        // Generic array construction.  Used if we don't want to build it to a different ruleset 
                        $messageArr = array(
                            'type'       => 'system',
                            'service_id' => $parts[4],
                            'issuer'     => $parts[1],
                            'target'     => $parts[5],
                            'message'    => $parts[6],
                            'raw'        => $message
                        );
                }
            } elseif (stristr($parts['1'], 'notice')) {
                $messageArr = array(
                    'type'      => 'system',
                    'is_notice' => true,
                    'issuer'    => '*',
                    'target'    => $parts[4],
                    'message'   => $parts[6],
                    'raw'       => $message
                );
            } elseif (stristr($parts[4], 'notice')) {
                $messageArr = array(
                    'type'      => 'system',
                    'is_notice' => true,
                    'issuer'    => $parts[1],
                    'target'    => $parts[5],
                    'message'   => $parts[6],
                    'raw'       => $message
                );
            } elseif (stristr($parts[4], 'join')) {
                $messageArr = array(
                    'type'     => 'system',
                    'is_join'  => true,
                    'nick'     => $parts[1],
                    'ident'    => $parts[2],
                    'hostname' => $parts[3],
                    'chan'     => $parts[5],
                    'raw'      => $message
                );
            } elseif (stristr($parts[4], 'invite')) {
                $messageArr = array(
                    'type'      => 'system',
                    'is_invite' => true,
                    'nick'      => $parts[1],
                    'ident'     => $parts[2],
                    'hostname'  => $parts[3],
                    'chan'      => $parts[5],
                    'raw'       => $message
                );
            } elseif (stristr($parts[4], 'part')) {
                $messageArr = array(
                    'type'     => 'system',
                    'is_part'  => true,
                    'nick'     => $parts[1],
                    'ident'    => $parts[2],
                    'hostname' => $parts[3],
                    'chan'     => $parts[5],
                    'raw'      => $message
                );
            } elseif (stristr($parts[4], 'quit')) {
                $messageArr = array(
                    'type'     => 'system',
                    'is_quit'  => true,
                    'nick'     => $parts[1],
                    'ident'    => $parts[2],
                    'hostname' => $parts[3],
                    'reason'   => $parts[6],
                    'raw'      => $message
                );
            } elseif (stristr($parts[4], 'kick')) {
                $messageArr = array(
                    'type'     => 'system',
                    'is_kick'  => true,
                    'nick'     => $parts[1],
                    'ident'    => $parts[2],
                    'hostname' => $parts[3],
                    'reason'   => $parts[6],
                    'raw'      => $message
                );
            } elseif (stristr($parts[4], 'ping')) {
                $messageArr = array(
                    'type'    => 'system',
                    'is_ping' => true,
                    'message' => $parts[6],
                    'raw'     => $message
                );
            } elseif (stristr($parts[4], 'pong')) {
                $messageArr = array(
                    'type'    => 'system',
                    'is_pong' => true,
                    'issuer'  => $parts[1],
                    'message' => $parts[6],
                    'raw'     => $message
                );
            } elseif (stristr($parts[4], 'mode')) {
                $sets = explode(' ', $parts[6]);
                
                if (count($sets) == 1)
                { // Self
                    $messageArr = array(
                        'type'    => 'system',
                        'is_mode' => true,
                        'issuer'  => $parts[1],
                        'modes'   => array(array('nick' => $parts[1], 'mode' => $sets[0])),
                        'raw'     => $message
                    );
                } elseif (count($sets) == 2) { // Single user
                    $messageArr = array(
                        'type'    => 'system',
                        'is_mode' => true,
                        'issuer'  => $parts[1],
                        'chan'    => $parts[5],
                        'modes'   => array(array('nick' => $sets[1], 'mode' => $sets[0])),
                        'raw'     => $message
                    );
                } else { // Multi-user/multi-mode case
                    $modes = str_split($sets[0], 1);
                    array_shift($sets);
                    
                    $removing = ($modes[0] == '-') ? true : false;
                    $counter = 0;
                    $store = array();
                    
                    foreach ($modes as $mode)
                    {
                        if ($mode == '+')
                        {
                            $removing = false;
                            continue;
                        }
                        
                        if ($mode == '-')
                        {
                            $removing = true;
                            continue;
                        }
                        
                        if ($removing)
                        {
                            $store[] = array('nick' => $sets[$counter], 'mode' => '-' . $mode);
                            $counter++;
                        } else {
                            $store[] = array('nick' => $sets[$counter], 'mode' => '+' . $mode);
                            $counter++;
                        }
                    }
                    
                    $messageArr = array(
                        'type'    => 'system',
                        'is_mode' => true,
                        'issuer'  => $parts[1],
                        'chan'    => $parts[5],
                        'modes'   => $store,
                        'raw'     => $message
                    );
                }
            } elseif (stristr($parts[4], 'error')) {
                $messageArr = array(
                    'type' => 'system',
                    'is_error' => true,
                    'message' => $parts[6],
                    'raw'      => $message
                );
                
            } elseif (stristr($parts[4], 'nick')) {
                $messageArr = array(
                    'type' => 'system',
                    'is_nick' => true,
                    'old_nick' => $parts[1],
                    'ident'    => $parts[2],
                    'hostname' => $parts[3],
                    'new_nick' => $parts[6],
                    'raw' => $message
                );
            } else {
                // We don't understand this message.  Pass it off as undecoded
                $messageArr = array(
                    'type' => 'undecoded',
                    'raw'  => $message
                );
            }
        }
        
        // Done, return the array
        return $messageArr;
    }
}
?>
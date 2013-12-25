<?php
// Was this accessed directly?  If so, exit.
if (!defined('IN_IRC'))
{
	exit;
}

class irc
{
    public function connect($ircAddr, $ircPort)
    {
        // Simply open a new socket.  nothing new with that at all
        return fsockopen($ircAddr, $ircPort);
    }
    
    public function read($socket, $len = 4096)
    {
    	$read = fgets($socket, $len);
    	$read = rtrim($read);
    	return $read;
    }
    
    public function write($socket, $cmd)
    {
        // Simply write data to the socket
        return @fputs($socket, $cmd . "\r\n");
    }
    
    public function disconnect($socket)
    {
        return @fclose($socket);
    }
    
    public function setBlocking($socket)
    {
        return socket_set_blocking($socket, false);
    }
    
    // Sends a channel message
    public function sendPrivateMessage($socket, $message, $chan)
    {
        return $this->write($socket, "PRIVMSG $chan $message");
    }
    
    // Sends a /me style command
    public function sendAction($socket, $action, $chan)
    {
        $chr = chr(1);
        return $this->write($socket, "PRIVMSG $chan $chr$action$chr");
    }
    
    public function joinChannel($socket, $chan)
    {
        return $this->write($socket, "JOIN $chan");
    }
    
    public function setMode($socket, $chan, $mode, $user = null)
    {
        if ($user != null)
        {
            return $this->write($socket, "MODE $chan $mode $user");
        } else {
            return $this->write($socket, "MODE $chan $mode");
        }
    }
    
    // Takes a raw string from the socket and determines all of the data it can about the string
    public function checkRawMessage($message)
    {
        $type = 'system';
        $messageArr = array();
        
        // Do we have a private message of any kind? (This also stops people from faking server messages)
        if (preg_match('[PRIVMSG]', $message) != 0)
        {
            $type = 'private';
            
            // Split the message into hostname and message
            $split = explode('PRIVMSG', $message);
            $hostnames = explode('!~', $split[0]);
            $username = trim($hostnames[0], ':');
            $hostname = rtrim($hostnames[1], ' ');
            $splits = explode(' ', trim(' ', $split[1])); // Do this to split out the channel name
            $chan = trim($splits[0], ' ');
            array_shift($splits);
            $words = implode(' ', $splits); // implode to get the message string back (Fix for people being able to use ' :' as a way of avoiding the check)
            
            // Build the message array
            $messageArr = array(
                'type' => $type,
                'nick' => $username,
                'host' => $hostname,
                'chan' => $chan,
                'message' => $words
            );
        } else {
            // We have a system message.
            $split = explode(' ', $message);
            $type = 'system';
            
            // Do we have a 3 digit number for our second set?
            if (preg_match('([0-9]{3,3})', $split[1]) != 0)
            {
                
                // Looks like we do, time to get the rest of the data
                $hostname = trim($split[0], ':');
                $serviceID = $split[1];
                
                // Okay, no channel name...Time to look at the service ID instead
                switch ($serviceID)
                {
                    // These messages don't have a channel attached to them
                    case '001': // Greet
                    case '002': // Host
                    case '003': // Creation Time
                    case '004': // Host and Modes available
                    case '005': // Config Opts
                    case '251': // User Count
                    case '252': // Operator Count (Global Ops only)
                    case '253': // Unknown connections
                    case '254': // Channels Formed
                    case '255': // Number of clients and servers
                    case '375': // MOTD Init
                    case '372': // MOTD
                    case '376': // MOTD end
                        
                        // Set all of the data for this type of message
                        $nick = $split[2];
                        $words = trim($split[3], ':');
                        
                        // Build the array
                        $messageArr = array(
                            'type' => $type,
                            'nick' => $nick,
                            'host' => $hostname,
                            'service_id' => $serviceID,
                            'message' => $words
                        );
                        
                        break;
                    
                    // These messages have a channel attached to them
                    case '331': // No topic
                    case '332': // Channel Topic
                    case '333': // Channel Auth Nick
                    case '353': // Channel WHO (Join)
                    case '366': // End WHO
                        
                        // Set all of the data for this type of message
                        $nick = $split[2];
                        $chan = $split[3];
                        $words = trim($split[4], ':');
                        
                        // Build the array
                        $messageArr = array(
                            'type' => $type,
                            'nick' => $nick,
                            'host' => $hostname,
                            'chan' => $chan,
                            'service_id' => $serviceID,
                            'message' => $words
                        );
                        
                        break;
                    
                    default: // We don't know this service ID.  It may be put in in a future update
                        break;
                }
            } else {
                // We have no service ID.  Time to look for a command
                
                // Start with MODE
                if (preg_match('[mode]i', $message) != 0)
                {
                    // Set our values that need to be modified
                    $chan = $split[2];
                    $mode = $split[3];
                    $user = $split[4];
                    
                    $messageArr = array(
                        'type' => $type,
                        'chan' => $chan,
                        'mode' => $mode,
                        'user' => $user
                    );
                }
                
                // PING
                if (preg_match('[ping]i', $message) != 0)
                {
                    $explode = explode(':', $message);
                    
                    // Were we sent some timestamp data?
                    if (preg_match_all('#[-a-zA-Z0-9@:%_\+.~\#?&//=]{2,256}\.[a-z]{2,4}\b(\/[-a-zA-Z0-9@:%_\+.~\#?&//=]*)?#si', $explode[1], $exp) != 0)
                    {
                        // Buid the array with no message value
                        $messageArr = array(
                            'type' => $type,
                            'isPing' => true
                        );
                    } else {
                        // Include the extra data (Used to auth)
                        $messageArr = array(
                            'type' => $type,
                            'isPing' => true,
                            'message' => $explode[1]
                        );
                    }
                }
                
                // AUTH
                if (preg_match('[auth]i', $message) != 0)
                {
                    $explode = explode(':', $message);
                    
                    $messageArr = array(
                        'type' => $type,
                        'isAuth' => true,
                        'message' => $explode[1]
                    );
                }
            }
        }
        
        return $messageArr;
    }
}
?>
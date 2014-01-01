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
        return $this->write($socket, "PRIVMSG $chan :$message");
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
        $message = trim($message, ':');
        $messageArr = array();
        
        // Do we have a private message of any kind? (This also stops people from faking server messages)
        if (preg_match('[PRIVMSG]', $message) != 0)
        {
            $type = 'private';
            
            // Split the message into hostname and message
            $split = explode(' PRIVMSG ', $message);
            
            // Standard Private message (Except EXTREME edge case)
            if ($split [0] != 'jtv')
            {
                $hostnames = explode('!', $split[0]);
                $username = $hostnames[0];
                if (isset($hostnames[1]))
                {
                    $hostname = rtrim($hostnames[1], ' ');
                } else {
                    return array();
                }
                $hostname = trim($hostname, '~');
                if (isset($split[1]))
                {
                    $splits = explode(' ', $split[1]); // Do this to split out the channel name
                } else {
                    return array();
                }
                $chan = trim($splits[0], ' ');
                array_shift($splits);
                $words = trim(implode(' ', $splits), ':'); // implode to get the message string back (Fix for people being able to use ' :' as a way of avoiding the check)
                
                // Build the message array
                $messageArr = array(
                    'type' => $type,
                    'nick' => $username,
                    'host' => $hostname,
                    'chan' => $chan,
                    'message' => $words
                );                
            } else {
                // Resplit and start figuring out what info we have now
                $type = 'twitch_message';
                $split = explode(':', $message);
                
                // The base information from the message is actually useless to us entirely.  What we want is in the message
                $words = explode(' ', $split[1]);
                
                // Set the fields
                $command = $words[0];
                $user = $words[1];
                $value = rtrim(trim($words[2], '['), ']');
                
                // Build the array
                $messageArr = array(
                    'type' => $type,
                    'command' => $command,
                    'user' => $user,
                    'value' => $value
                );
            }
            
            return $messageArr;
        } else {
            // We have a system message.
            $split = explode(' ', $message);
            $type = 'system';
            
            // Do we have a 3 digit number for our second set?  The second check stops PING with a lag timer from tripping this
            if (isset($split[1]) && (preg_match('([0-9]{3,3})', $split[1]) != 0) && (preg_match('[ping]i', $split[0]) == 0))
            {
                // Looks like we do, time to get the rest of the data
                $hostname = $split[0];
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
                        
                    case '353': // Channel WHO (Join)
                    
                         // Set all of the data for this type of message
                        $nick = $split[2];
                        $chan = $split[4];
                        array_shift($split);array_shift($split);array_shift($split);array_shift($split);array_shift($split);
                        $words = trim(implode(' ', $split), ':');
                        
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
                        
                    case '221': // Default user mode (Used to trigger channel JOIN)
                        
                        // Build the array
                    
                        $messageArr = array(
                            'type' => $type,
                            'nick' => $split[2],
                            'host' => $hostname,
                            'mode' => $split[3],
                            'service_id' => $serviceID
                        );
                    
                        break;
                    
                    default: // We don't know this service ID.  It may be put in in a future update
                        break;
                }
                
                return $messageArr;
            } else {
                // We have no service ID.  Time to look for a command
                
                // Start with MODE
                if (preg_match('[mode]i', $message) != 0)
                {
                    // Set our values that need to be modified
                    $chan = $split[2];
                    $mode = $split[3];
                    if (isset($split[4]))
                    {
                        $user = $split[4];
                    }
                    
                    if (isset($user))
                    {
                        // Standard MODE message, proceed as normal
                        $messageArr = array(
                            'type' => $type,
                            'chan' => $chan,
                            'mode' => $mode,
                            'user' => $user
                        );                        
                    } else {
                        // This is a case where the mode is our default.  It doesn't have a service ID attached, so we will build the array differently here for the bot to recognize it properly
                        $messageArr = array(
                            'type' => $type,
                            'service_id' => '221',
                            'nick' => $chan,
                            'mode' => $mode
                        );
                    }
                    
                    return $messageArr;
                }
                
                // JOIN
                if (preg_match('[join]i', $message) != 0)
                {
                    $hostnames = $split[0];
                    
                    // Split the hostnames based on what chars we find
                    if (preg_match('[!]i', $message) != 0)
                    {
                        $splits = explode('!', $hostnames);
                        $nick = $splits[0];
                        $hostnames = explode('@', $splits[1]);
                        $hostname = trim($hostnames[1], '~');
                    } else {
                        $splits = explode('@', $hostnames);
                        $nick = $splits[0];
                        $hostname = $splits[0];
                    }
                    
                    $messageArr = array(
                        'type' => $type,
                        'isJoin' => true,
                        'nick' => $nick,
                        'host' => $hostname,
                        'chan' => $split[2]
                    );
                    
                    return $messageArr;
                }
                
                // PART
                if (preg_match('[part]i', $message) != 0)
                {
                    $hostnames = $split[0];
                    
                    // Split the hostnames based on what chars we find
                    if (preg_match('[!]i', $message) != 0)
                    {
                        $splits = explode('!', $hostnames);
                        $nick = $splits[0];
                        $hostnames = explode('@', $splits[1]);
                        $hostname = trim($hostnames[1], '~');
                    } else {
                        $splits = explode('@', $hostnames);
                        $nick = $splits[0];
                        $hostname = $splits[0];
                    }
                    
                    $messageArr = array(
                        'type' => $type,
                        'isPart' => true,
                        'nick' => $nick,
                        'host' => $hostname,
                        'chan' => $split[2]
                    );
                    
                    return $messageArr;
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
                    
                    return $messageArr;
                }
                
                // PONG response
                if (preg_match('[pong]i', $message) != 0)
                {
                    $messageArr = array(
                        'type' => $type,
                        'isPong' => true,
                        'host' => $split[0],
                        'message' => $split[3]
                    );
                    
                    return $messageArr;
                }
                
                // NICK changes
                // :IBurn36360_!~IBurn3636@c-75-70-217-195.hsd1.co.comcast.net NICK :IBurn36360
                if (preg_match('[nick]i', $message) != 0)
                {
                    $hostnames = explode('!', $split[0]);
                    $oldNick = $hostnames[0];
                    $hostname = trim($hostname[1], '~');
                    $newNick = trim($split[2], ':');
                    
                    $messageArr = array(
                        'type' => $type,
                        'isNick' => true,
                        'oldNick' => $oldNick,
                        'newNick' => $newNick,
                        'host' => $hostname
                    );
                    
                    return $messageArr;
                }
                
                // AUTH (No-NickServ)
                if ((preg_match('[NOTICE AUTH]i', $message) != 0))
                {
                    $explode = explode(':', $message);
                    
                    $messageArr = array(
                        'type' => $type,
                        'isAuth' => true,
                        'message' => $explode[1]
                    );
                    
                    return $messageArr;
                }
                
                // AUTH (NickServ)
                if (preg_match('[NOTICE *]i', $message) != 0)
                {
                    $explode = explode(':', $message);
                    $msg = (isset($explode[1])) ? $explode[1] : '';
                    
                    $messageArr = array(
                        'type' => $type,
                        'isAuth' => true,
                        'message' => $msg
                    );
                    
                    return $messageArr;
                }
            }
        }
        
        return $messageArr;
    }
}
?>
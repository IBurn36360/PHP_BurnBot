<?php
// Was this accessed directly?  If so, exit.
if (!defined('IN_IRC'))
{
	exit;
}

// defines all Burnbot logic
class burnbot
{
    var $version = '.5';
    var $overrideKey = '';
    var $tickLimiter = .1; // This sets the time each cycle is expected to take (in seconds).  Will be used in the sleep calculation
    var $tickStartTime = 0;
    var $tickCurrentTime = 0;
    var $lastPingTime = 0;
    var $isTwitch = false;
    var $commandDelimeter = '!';
    var $reconnect = true; // Default to allow reconnecting to the server
    var $reconnectCounter = 5; // The maximum number of times a socket can be attempted to be recovered
    
    // Connection details (used in some commands and in reconnecting)
    var $host = '';
    var $chan = '';
    var $nick = '';
    var $pass = '';
    var $port = 6667; // Default just in case EVERYTHING else fails for some horrable reason
    
    // The socket for the IRC connection
    var $sessionID = 0;
    var $hasAuthd = false;
    
    // Arrays (Large storage)
    var $loadedCommands = array();
    var $userCommands = array();
    var $loadedModules = array();
    var $operators = array();
    var $regulars = array();
    
    /**
     * Structure: UNKEYED ARRAY
     *  [0] => array('message Type', array('message Args'))
    */
    var $messageQue = array();
    
    // Limits message sends
    var $limitSends = true; // Override this later if we don't want the limiter enabled
    var $messageTTL = array();
    var $TTL = 30; // The number of seconds that a message is kept alive for
    var $TTLStack = 20; // The limit of messages in the stack
    
    function __construct()
    {
        // grab our info from startup
        global $chan, $host, $port, $nick, $pass, $irc, $db;
        
        $this->chan = $chan;
        $this->host = $host;
        $this->port = $port;
        $this->nick = $nick;
        $this->pass = $pass;
        $this->lastPingTime = time(); // We assume we will be getting a ping on AUTH, so this is only here for a second at max
        
        // Structure (used for listcom)
        // 'trigger' => array('module', 'function_callback', OPOnly, RegOnly, SubOnly);
        
        $commands = array(
            'contact' => array('burnbot', 'burnbot_contact', false, false, false),
            'google'  => array('burnbot', 'burnbot_google', false, false, false),
            'listcom' => array('burnbot', 'burnbot_listcom', false, false, false),
            'listops' => array('burnbot', 'burnbot_listops', false, false, false),
            'listreg' => array('burnbot', 'burnbot_listreg', false, false, false),
            'modules' => array('burnbot', 'burnbot_loadedModules', false, false, false),
            'version' => array('burnbot', 'burnbot_version', false, false, false),
            'nick'    => array('burnbot', 'burnbot_nick', false, true, false),
            'slap'    => array('burnbot', 'burnbot_slap', false, true, false),
            'addcom'  => array('burnbot', 'burnbot_addcom', true, false, false),
            'delcom'  => array('burnbot', 'burnbot_delcom', true, false, false),
            'editcom' => array('burnbot', 'burnbot_editcom', true, false, false),
            'quit'    => array('burnbot', 'burnbot_quit', true, false, false),
            'addreg'  => array('burnbot', 'burnbot_addreg', true, false, false),
            'delreg'  => array('burnbot', 'burnbot_delreg', true, false, false)
        );
        
        if ($this->isTwitch)
        {
            // Disable some commands while on Twitch specifically
            unset($commands['nick']);
        } else {
            // In standard channels, we disable regulars since we can rely on modes
            unset($commands['addreg'], $commands['delreg']);
        }
        
        // Register BurnBot's base commands that provide feedback and perform no actions
        $this->registerCommads($commands);
        
        $irc->_log_action('Burnbot constructed and commands registered');
    }
    
    // Be sure to properly close the socket BEFORE the script dies for whatever reason
    function exitHandler()
    {
        global $irc, $socket;
        
        $irc->_write($socket, 'QUIT :Script was killed or exited');
        
        // Wait for the peer to get the command, if they do not disconnect us, we will close the socket forcibly
        usleep(500000);
        $irc->disconnect($socket);
        
        // Update our Auth in case we want to reconnect
        $this->hasAuthd = false;
        
        // Hard stop the script here since we were passed here
        echo '<hr />Script was passed to exit handler.  Now killing script entirely<hr />';
        exit;
    }
    
    // Store the socket as a class var we can use easily
    public function init()
    {
        global $db, $host, $chan, $irc;
        
        // Now grab the session ID we will be using for DB queries
        $sql = 'SELECT id FROM ' . BURNBOT_CONNECTIONS . ' WHERE host=\'' . $db->sql_escape($host) . '\' AND channel=\'' .  $db->sql_escape($chan) . '\';';
        $result = $db->sql_query($sql);
        
        if ($result !== false)
        {
            // We know what ID to use, store it
            $this->sessionID = $db->sql_fetchrow($result)['id'];
            $db->sql_freeresult($result);
        } else {
            $sql = 'INSERT INTO ' . BURNBOT_CONNECTIONS . ' (host, channel) VALUES (\'' . $db->sql_escape($host) . '\', \'' . $db->sql_escape($chan) . '\');';
            $result = $db->sql_query($sql);
            $sql = 'SELECT id FROM ' . BURNBOT_CONNECTIONS . ' WHERE host=\'' . $db->sql_escape($host) . '\' AND channel=\'' .  $db->sql_escape($chan) . '\';';
            $result = $db->sql_query($sql);
            
            // Okay, now we have the ID, store it
            $this->sessionID = $db->sql_fetchrow($result)['id'];
            $db->sql_freeresult($result);
        }
        
        $this->overrideKey = md5($this->version . time() . rand(0, ($this->tickLimiter * 100000)));
        
        $irc->_log_action('Setting Session ID: ' . $this->sessionID);
        $irc->_log_action('Quit Override Key: ' . $this->overrideKey);
        
        // Register modules
        $this->registerModule(array("burnbot", "irc"));
        
        // Now grab the data for the channel
        $this->grabCommands();
        $this->getRegulars();
    }
    
    // Accessors to get var data
    public function getCounter()
    {
        return $this->reconnectCounter;
    }
    public function getOverrideKey()
    {
        return $this->overrideKey;
    }
    
    /** Registers commands from modules into the main array for checking.  Commands will have the following structure:
     * 
     * The remaining message is the full message stripped of the command
     * 
     * 'test' => array('Module', 'function', 'msg')
     */
    public function registerCommads($commands = array())
    {
        $this->loadedCommands = array_merge($this->loadedCommands, $commands);
    }
    
    public function registerUserCommands($commands = array())
    {
        $this->userCommands = array_merge($this->userCommands, $commands);
    }
    
    public function registerModule($module = array())
    {
        $this->loadedModules = array_merge($this->loadedModules, $module);
    }

    public function auth()
    {
        global $irc, $socket;
        
        if (!$this->hasAuthd)
        {
            // Auth to the server when we get our trigger
            $irc->_write($socket,  "USER $this->nick i * $this->nick@$this->nick");
            $irc->_write($socket,  "NICK $this->nick");
            
            // If we have a password, now is the time to pass it to the server as well
            if ($this->pass != '')
            {
                $irc->_write($socket, "PASS $this->pass");
            }
            
            $this->hasAuthd = true;
        }
    }
    
    public function grabCommands()
    {
        global $db;
        
        // Unset the main command array since we are about to grab a fresh batch
        $this->userCommands = array();
        $commands = array();
        
        $sql = 'SELECT 
        _trigger FROM ' . BURNBOT_COMMANDS . ' WHERE id=\'' . $db->sql_escape($this->sessionID) . '\';';
        $result = $db->sql_query($sql);
        $arr = $db->sql_fetchrow($result);
        $db->sql_freeresult($result);
        
        if (is_array($arr))
        {
            foreach ($arr as $row)
            {
                $commands = array_merge($commands, array($row => array('user')));
            }            
        }
        
        $this->registerUserCommands($commands);
    }
    
    public function insertCommand($trigger, $output, $ops = false, $regulars = false, $subs = false)
    {
        global $db, $irc;
        
        $trigger = trim($trigger, $this->commandDelimeter);
        
        if (array_key_exists($trigger, $this->userCommands))
        {
            $this->addMessageToQue("Command $trigger already exists, please edit the command instead");
            return;
        }
        
        $sql = $db->sql_build_insert(BURNBOT_COMMANDS, array(
            'id' => $this->sessionID,
            '_trigger' => $trigger,
            'output' => $output,
            'ops_only' => $ops,
            'regulars_only' => $regulars,
            'subs_only' => $subs
        ));        
        $result = $db->sql_query($sql);
        $arr = $db->sql_fetchrow($result);
        $db->sql_freeresult($result);
        
        if ($arr !== false)
        {
            $this->addMessageToQue("Command $trigger was added successfully");
        } else {
            $this->addMessageToQue("Command $trigger was unable to be added");
        }
        
        // Reshell our commands in case we get a false negative
        $this->grabCommands();
    }
    
    public function removeCommand($trigger)
    {
        global $db;
        
        $sql = 'DELETE FROM ' . BURNBOT_COMMANDS . ' WHERE id=\'' . $db->sql_escape($this->sessionID) . '\' AND _trigger=\'' . $db->sql_escape($trigger) . '\';';
        $result = $db->sql_query($sql);
        $arr = $db->sql_fetchrow($result);
        $db->sql_freeresult($result);
        
        // Now unregister it
        if ($arr !== false)
        {
            unset($this->userCommands[$trigger]);
            $this->addMessageToQue("Command $trigger was successfully deleted");
        } else {
            $this->addMessageToQue("Command $trigger was not able to be deleted");
        }
    }
    
    public function editCommand($command, $commandArr = array())
    {
        global $db, $irc;
        
        $whereArr = array('id' => $this->sessionID, '_trigger' => $command);
        
        $sql = $db->sql_build_update(BURNBOT_COMMANDS, $commandArr, $whereArr);
        
        $irc->_log_action("Running query: $sql");
        
        $result = $db->sql_query($sql);
        $success = $db->sql_fetchrow($result);
        $db->sql_freeresult($result);
        
        // Anything other than false is considered a success
        if ($success !== false)
        {
            $this->addMessageToQue("Command $command has been updated.  Please test command to ensure that everything is correct.");
        } else {
            $this->addMessageToQue("Command $command was unable to be updated.  Either it does not exist or your syntax was incorrect.");
        }
        
        // Weather or not it succeeded, flush and grab all of our commands
        $this->grabCommands();
    }
    
    public function addRegular($username)
    {
        $sql = 'INSERT INTO ' . BURNBOT_REGULARS . ' (id, username) VALUES (\'' . $db->sql_escape($this->sessionID) . '\', \'' . $db->sql_escape($username) . '\');';
        $result = $db->sql_query($sql);
        $arr = $db->sql_fetchrow($result);
        $db->sql_freeresult($result);
    }
    
    public function removeRegular($username)
    {
        $sql = 'DELETE * FROM ' . BURNBOT_REGULARS . ' WHERE id=\'' . $db->sql_escape($this->sessionID) . '\' AND username=\'' . $db->sql_escape($username) . '\';';
        $result = $db->sql_query($sql);
        $arr = $db->sql_fetchrow($result);
        $db->sql_freeresult($result);
        
        $this->getRegulars();
    }
    
    public function getRegulars()
    {
        global $db;
        
        // Purge the list
        $this->regulars = array();
        
        $sql = 'SELECT username FROM ' . BURNBOT_REGULARS . ' WHERE id=\'' . $db->sql_escape($this->sessionID) . '\';';
        $result = $db->sql_query($sql);
        $arr = $db->sql_fetchrow($result);
        $db->sql_freeresult($result);
        
        if (is_array($arr))
        {
            foreach ($arr as $row)
            {
                $this->regulars[$row['username']] = false;
            }
        }
    }
    
    // Permission checks
    public function checkRegulars($user)
    {
        global $irc;
        $irc->_log_action("Checking regulars array for user $user");
        
        foreach($this->regulars as $username => $false)
        {
            if ($user == $username)
            {
                $irc->_log_action("User $user is regular");
                return true;
            }
        }
        
        $irc->_log_action("User $user is not regular");
        return false;
    }
    
    public function checkOperators($user)
    {
        global $irc;
        $irc->_log_action("Checking operator array for user $user");
        
        foreach($this->operators as $username => $false)
        {
            if ($user == $username)
            {
                $irc->_log_action("User $user is operator");
                return true;
            }
        }
        
        $irc->_log_action("User $user is not operator");
        return false;        
    }
     
    // Attempts to reconnect to a socket that we seem to have lost connection to
    public function reconnect()
    {
        global $socket;
        
        // DO NOT TRY TO RECONNECT IF THE SOCKET IS STILL ALIVE!
        if ($socket !== null)
        {
            global $irc;
            
            // Init a new socket.  Read will handle everything from there
            $socket = $irc->connect($this->host, $this->port);
            
            if (is_resource($socket))
            {
                return $socket;
            }
        }
        
        // We were unable to create a valid socket for some reason.  Set the socket to null and return false.
        $socket = null;
        return 0;
    }
    
    // This is the ONLY message we auto-reply to without any checks
    private function pong($args = '')
    {
        global $irc, $socket;
        
        // IGNORE THE TTL HERE
        $irc->_write($socket, ($args !== '') ? "PONG :$args" : 'PONG');
        $this->lastPingTime = time(); // update the last time we got sent a PING
    }
    
    // Called if we don't regieve a PING request within 1 minute of time
    private function ping()
    {
        global $irc, $socket;
        
        $this->lastPingTime = time();
        
        $irc->_write($socket, "PING :LAG$this->lastPingTime");
    }
    
    // Grab modes out of the initial WHO responses we get or if we request a WHO
    private function who($msg)
    {
        global $irc;
        
        $users = explode(' ', $msg);
                
        // Now sort OPS
        foreach ($users as $user)
        {
            if ($user[0] == '@')
            {
                // Once again, it is really easy to set and unset VIA key
                $user = trim($user, '@');
                $this->operators = array_merge($this->operators, array($user => false));
                $irc->_log_action("Adding $user as an OP");
            }
        }
        
        if (!$this->isTwitch)
        {
            reset($users);     
            
            // Check for voice since we disable regulars here.
            foreach ($users as $user)
            {
                if ($user[0] == '+')
                {
                    // Once again, it is really easy to set and unset VIA key
                    $user = trim($user, '+');
                    $this->regulars = array_merge($this->regulars, array($user => false));
                    $irc->_log_action("Adding $user as a regular");
                }
            }
        }
    }
    
    private function mode($nick, $mode)
    {
        if (!$this->isTwitch)
        {
            // grab voice check as well
            if ($mode == '+v')
            {
                $this->regulars[$nick] = null;
            }
        }
        
        if ($mode == '+o')
        {
            $this->regulars[$nick] = null;
        }
    }
    
    // Reads the message we recieved and adds any message triggers we need to.  Also triggers a command to be added
    /**
     * @todo Complete all read checks for the cycle
     */
    public function _read()
    {
        global $irc, $socket, $chan, $reminders, $twitch, $moderation, $currency, $rainwave, $lastFm, $spotify;
        
        $message = $irc->_read($socket);
        if (strlen($message) <= 0)
        {
            return;
        }
        $messageArr = $irc->checkRawMessage($message);
        
        if (isset($messageArr['type']))
        {
            // Process system messages
            if ($messageArr['type'] == 'system')
            {
                // System message, handle this
                // The structure of the system message is actually pretty complex, making this a little more direct with how we check it
                
                // PING
                if (isset($messageArr['isPing']))
                {
                    $this->pong($msg = (isset($messageArr['message']) ? $messageArr['message'] : ''));
                }
                
                // AUTH
                if (isset($messageArr['isAuth']))
                {
                    if ($messageArr['message'] == '*** Checking Ident')
                    {
                        $this->auth();
                    }
                }
                
                // MODE
                if (isset($messageArr['mode']))
                {
                    // Add OP
                    if ($messageArr['mode'] == '+o')
                    {
                        $this->operators = array_merge($this->operators, array($messageArr['user'] => false));
                        $user = $messageArr['user'];
                        $irc->_log_action("Adding $user as an OP");
                    }
                    
                    // Remove OP
                    if ($messageArr['mode'] == '-o')
                    {
                        unset($this->operators[$messageArr['user']]);
                        $user = $messageArr['user'];
                        $irc->_log_action("Removing $user from OP");
                    }
                    
                    // Voice (Not on Twitch)
                    if (!$this->isTwitch)
                    {
                        // Add a regular
                        if ($messageArr['mode'] == '+v')
                        {
                            $this->regulars = array_merge($this->regulars, array($messageArr['user'] => false));
                            $user = $messageArr['user'];
                            $irc->_log_action("Adding $user as a regular");
                        }
                        
                        // Remove a regular
                        if ($messageArr['mode'] == '-v')
                        {
                             unset($this->regulars[$messageArr['user']]);
                             $user = $messageArr['user'];
                            $irc->_log_action("Removing $user from regulars");
                        }
                    }
                }
                
                // We have a numbered service ID instead at this point.  We only handle a few of these and will drop the rest
                if (isset($messageArr['service_id']))
                {
                    // What service ID's are we looking for here?
                    if ($messageArr['service_id'] == '375')
                    {
                        // We are going to quickly read through the MOTD as fast as we can, no need to have a limiter here
                        while($messageArr['service_id'] != '376')
                        {
                            $message = $irc->_read($socket);
                            $messageArr = $irc->checkRawMessage($message);
                        }
                    }
                    
                    // We have a default mode, now we may JOIN
                    if ($messageArr['service_id'] == '221')
                    {
                        $irc->_joinChannel($socket, $chan);
                    }
                    
                    // We gained a WHO from a channel join
                    if ($messageArr['service_id'] == '353')
                    {
                        $this->who($messageArr['message']);
                    }
                }
                
                return;
            } else {
                // Private message, check for the existance of a command and process the command
                $words = explode(' ', $messageArr['message']);
                $sender = $messageArr['nick'];
                $command = $words[0];
                
                // Quickly reconstruct the rest of the message
                array_shift($words);
                $msg = implode(' ', $words);
                
                // Do we have a command delimeter?
                if (isset($command) && ($command != ''))
                {
                    if ($command[0] == $this->commandDelimeter)
                    {
                        // Okay, itterate through all of our known commands and see if it is registered
                        foreach (($this->loadedCommands + $this->userCommands) as $trigger => $info)
                        {
                            if ($trigger == trim($command, $this->commandDelimeter))
                            {
                                // Now pass the data to the command, we are done here
                                switch ($info[0])
                                {
                                    // CHECK THIS FIRST
                                    // We don't add anthing to info, so this case WILL break later checks
                                    case 'user':
                                        // Run this command in the burnbot module.  Specifically the user defined command handler
                                        $this->burnbot_userCommand($sender, $trigger);
                                        
                                        break;
                                    
                                    case 'burnbot':
                                        // Run this command in the burnbot module
                                        $this->{$info[1]}($sender, $msg, $command);
                                        
                                        break;
                                        
                                    default:
                                        // The command isn't defined properly, drop an error into the log and pass out to the chat as well
                                        $irc->_log_error("Error attempting to run command $trigger.  No information array provided");
                                        $this->addMessageToQue("Error attempting to run command $trigger.  No information array provided");
                                        break;
                                }
                                
                                break; // We are done here, no need to continue
                            }
                        }
                    }                    
                }
                
                return;
            }
        }
        
        // If we reached here the message wasn't handled.  We aren't going to return anything here becayuse the read function runs in a loop
        // and a return is unneeded calculations.  Still good to note that anything that wasn't handled is dropped entirely though.
    }

    public function addMessageToQue($message, $args = array())
    {
        return (array_push($this->messageQue, array($message, $args)) != 0);
    }
    
    // Processes our TTL stack and removes any and all messages that have expired
    public function processTTL()
    {
        // Check our TTL stack and see if any of our messages have finally expired as of this session (We count the tick start time here)
        foreach ($this->messageTTL as $key => $value)
        {
            // Currently the value isn't used, but it makes little difference to store it in case I need it later
            if ($key < $this->tickStartTime)
            {
                // It is also really easy to unset an array value by the key.  Very light too
                unset($this->messageTTL[$key]);
            }
        }
        
        // In case something needs the count of the TTLStack
        return count($this->messageTTL);
    }
    
    // Process the current que of messages and sent the one at the top of the list
    // Called after the TTL has been checked
    public function processQue()
    {
        global $irc, $socket;
        
        // Do we have messages in the que?
        if (count($this->messageQue) > 0)
        {
            $this->processTTL();
            
            // Check and process the TTL
            if (count($this->messageTTL) < 20)
            {
                $message = $this->messageQue[0][0];
                $args = $this->messageQue[0][1];
                
                array_shift($this->messageQue); // Move the stack up
                
                if (!empty($args))
                {
                    // The first value will be the type, always.  This is currently the only use for the $args array
                    switch($args[0])
                    {
                        case 'action':
                            
                            $irc->_sendAction($socket, $message, $this->chan);
                            break;
                            
                        // Default is handled like a PM to the channel
                        case 'pm':
                        default:

                            $irc->_sendPrivateMessage($socket, $message, $this->chan);
                            break;
                    }
                } else {
                   $irc->_sendPrivateMessage($socket, $message, $this->chan); 
                }

                // Lastly, add our message onto the TTL stack
                $this->messageTTL = array_merge($this->messageTTL, array(microtime(true) => $message));
            }
        }
    }
    
    // The loop we run for the bot
    public function tick()
    {
        // First get the time that this cycle started
        $this->tickStartTime = microtime(true);
        
        $this->_read();
        $this->processQue();
        
        // Okay, now check to see if we finished before the maximum time limit.
        if (($this->tickStartTime + $this->tickLimiter) > ($this->tickCurrentTime = microtime(true)))
        {
            // We were faster than the limit, sleep for the rest of the cycle
            usleep((($this->tickStartTime + $this->tickLimiter) - $this->tickCurrentTime) * 1000000);
        }
    }
    
    // call_user_func()
    // Callback functions for commands
    private function burnbot_version($sender, $msg = '')
    {
        $this->addMessageToQue("@$sender: Current version is $this->version");
    }
    
    private function burnbot_contact($sender, $msg = '')
    {
        $this->addMessageToQue("@$sender: If you have any questions or concerns, please contact IBurn36360 on Twitch.");
    }
    
    private function burnbot_slap($sender, $msg = '')
    {
        if ($msg == '')
        {
            $this->addMessageToQue("Slaps $sender", array('action'));
        } else {
            $this->addMessageToQue("Slaps $msg", array('action'));
        }
    }
    
    private function burnbot_addcom($sender, $msg = '')
    {
        if (array_key_exists($sender, $this->operators))
        {
            // We have the permission to add a command
            $split = explode(' ', $msg);
            
            $trigger = $split[0];
            array_shift($split);
            $output = implode(' ', $split);
            
            $this->insertCommand($trigger, $output);
        }
    }
    
    private function burnbot_delcom($sender, $msg = '')
    {
        // And this is why ALL arrays use keys.  You can not search via value
        if (array_key_exists($sender, $this->operators))
        {
            $parts = explode(' ', $msg);
            
            $this->removeCommand($parts[0]);
        }
    }
    
    private function burnbot_nick($sender, $msg = '')
    {
        global $irc, $socket;
        
        $nick = $msg;
        
        if (array_key_exists($sender, $this->operators) || array_key_exists($sender, $this->regulars))
        {
            // Nick is handled by Edge, no need to stack the command
            $irc->_write($socket, "NICK $nick");
        }
    }
    
    // There is currently no override here.  Might have to add one later
    private function burnbot_quit($sender, $msg = '')
    {
        $split = explode(' ', $msg);
        $overrideKey = $split[0];
        
        // If we are on Twitch, only the channel owner can tell us to leave.  Easy check
        if ($this->isTwitch && ($sender = trim($this->chan)))
        {
            // Make sure we can not reconnect
            $this->reconnectCounter = 0;
            $this->exitHandler();
        } elseif (!$this->isTwitch && array_key_exists($sender, $this->operators)) {
            // Make sure we can not reconnect
            $this->reconnectCounter = 0;
            $this->exitHandler();
        } elseif ($overrideKey == $this->overrideKey) {
            // We have been given the override key.  Exit no matter who uses this
            $this->reconnectCounter = 0;
            $this->exitHandler();
        }
    }
    
    // Edit the command specified
    private function burnbot_editcom($sender, $msg = '')
    {
        // Required structure: 'opsOnly' 'regsOnly' 'subsOnly' 'Output string'
        if (array_key_exists($sender, $this->operators))
        {
            $split = explode(' ', $msg);
            $command = $split[0];
            $opsOnly = $split[1];
            $regsOnly = $split[2];
            $subsOnly = $split[3];
            array_shift($split);
            array_shift($split);
            array_shift($split);
            array_shift($split);
            $output = implode(' ', $split);
            
            // Convert our vars.  Why do I accept to many possible responses?  Who knows
            $opsOnly = (($opsOnly == 'true') || ($opsOnly == 't') || ($opsOnly == '1') || ($opsOnly == 'yes')) ? intval(true) : intval(false);
            $regsOnly = (($regsOnly == 'true') || ($regsOnly == 't') || ($regsOnly == '1') || ($regsOnly == 'yes')) ? intval(true) : intval(false);
            $subsOnly = (($subsOnly == 'true') || ($subsOnly == 't') || ($subsOnly == '1') || ($subsOnly == 'yes')) ? intval(true) : intval(false);
            
            if ($output != '')
            {
                // Okay, after all is said and done, we still have an output, This means at least we have something to feed back to the channel
                $commandArr = array('output' => $output, 'ops_only' => $opsOnly, 'regulars_only' => $regsOnly, 'subs_only' => $subsOnly);
                $this->editCommand($command, $commandArr);
            }
        }
    }
    
    /**
     * @todo Write this
     */ 
    private function burnbot_listcom($sender, $msg = '')
    {
        $commands = '';
        $OPCommands = '';
        $RegCommands = '';
        $SubCommands = '';
        
        foreach ($this->loadedCommands + $this->userCommands as $trigger => $arr)
        {
            $op = isset($arr[2]) ? $arr[2] : false;
            $reg = isset($arr[3]) ? $arr[3] : false;
            $sub = isset($arr[4]) ? $arr[4] : false;
            
            // Now build the message
            if ($op)
            {
                $OPCommands .= "@$trigger, ";
            } elseif ($reg) {
                $RegCommands .= "+$trigger, ";
            } elseif ($sub) {
                $SubCommands .= "$$trigger, ";
            } else {
                $commands .= "$trigger, ";
            }
        }
        // We will have at least one of these, safe to use this as a key
        $commands = rtrim(rtrim($commands, ' '), ',');
        
        $str = "Currently registered commands: $OPCommands$RegCommands$SubCommands$commands";
        $this->addMessageToQue($str);
    }
    
    private function burnbot_addreg($sender, $msg = '')
    {
        // This command allows multiple users to be specified by using spaces
        if (array_key_exists($sender, $this->operators))
        {
            $split = explode(' ', $msg);
            
            // Catch here
            if (!empty($split))
            {
                foreach ($split as $user)
                {
                    $this->addRegular($user);
                }
                
                $this->addMessageToQue("All users successfully added as regulars");
            } else {
                $this->addMessageToQue("ERROR: No users supplied to be added.  Please add usernames separated by spaces after the command triger.");
            }
        }
    }
    
    private function burnbot_delreg($sender, $msg = '')
    {
        // This command allows multiple users to be specified by using spaces
        if (array_key_exists($sender, $this->operators))
        {
            $split = explode(' ', $msg);
            
            // Catch here
            if (!empty($split))
            {
                foreach ($split as $user)
                {
                    $this->removeRegular($user);
                }
                
                $this->addMessageToQue("All users successfully removed as regulars");
            } else {
                $this->addMessageToQue("ERROR: No users supplied to be removed.  Please add usernames separated by spaces after the command triger.");
            }
        }       
    }
    
    private function burnbot_google($sender, $msg = '')
    {
        // Contruct the query
        $arr = explode(' ', $msg);
        $query = 'http://lmgtfy.com/?q=';
        foreach ($arr as $word)
        {
            $query .= $word . '+';
        }
        $query = rtrim($query, '+');
        
        $this->addMessageToQue("@$sender: $query");
    }
    
    private function burnbot_userCommand($sender, $trigger)
    {
        global $db, $twitch, $irc;
        
        $sql = 'SELECT output,ops_only,regulars_only,subs_only FROM ' . BURNBOT_COMMANDS . ' WHERE id=\'' . $db->sql_escape($this->sessionID) . '\' AND _trigger=\'' . $db->sql_escape($trigger) . '\';';
        $result = $db->sql_query($sql);
        $arr = $db->sql_fetchrow($result);
        $db->sql_freeresult($result);
        
        $sendMessage = false;
        $opsonly = false;
        $regsOnly = false;
        $subsOnly = false;
        
        // Now check to see if anything was returned
        if (is_array($arr) && !empty($arr))
        {
            // Do we have an output for this command at all?
            if (!isset($arr['output']) || empty($arr['output']))
            {
                $irc->_log_error("Command registered as a dud");
                
                // Command is a dud, for now, do nothing.
                return;
            }
            
            // We got data, do our checks
            if (isset($arr['ops_only']))
            {
                if ($arr['ops_only'] && array_key_exists($sender, $this->operators))
                {
                    $sendMessage = true;
                    $opsonly = true;
                }
            }
            
            if (isset($arr['regulars_only']))
            {
                if ($arr['regulars_only'] && (array_key_exists($sender, $this->operators) || array_key_exists($sender, $this->regulars)))
                {
                    $sendMessage = true;
                    $regsOnly = true;
                }
            }
            
            // Don't even bother with this is we aren't on Twitch
            if (isset($arr['subs_only']) && $this->isTwitch)
            {
                // We DONT count regulars here
                if ($arr['subs_only'] && (array_key_exists($sender, $twitch->subscribers)))
                {
                    $sendMessage = true;
                    $subsOnly = true;
                }
            }
            
            // Did we go through all cases and not find a permission?
            if (!$sendMessage && !$opsonly && !$regsOnly && !$subsOnly)
            {
                $sendMessage = true;
            }
        }
        
        // After all checks, it looks like we can add the output to the que
        if ($sendMessage)
        {
            $this->addMessageToQue($arr['output']);
        } else {
            $this->addMessageToQue("Command was unable to be processed");
        }
    }
    
    private function burnbot_listops($sender, $msg = '')
    {
        $operators = '';
        
        foreach ($this->operators as $op => $value)
        {
            $operators .= " $op";
        }
        
        $ops = 'Operators:' . $operators;
        
        $this->addMessageToQue($ops);
    }
    
    private function burnbot_listreg($sender, $msg = '')
    {
        $regulars = '';
        
        foreach ($this->regulars as $regular => $value)
        {
            $regulars .= " $regular";
        }
        
        $reg = 'Regulars:' . $regulars;
        
        $this->addMessageToQue($reg);        
    }
    
    private function burnbot_loadedModules($sender, $msg = '')
    {
        $modules = '';
        
        foreach ($this->loadedModules as $module)
        {
            $modules .= " $module,";
        }
        
        $modules = rtrim($modules, ',');
        
        $str = "Currently loaded modules: $modules";
        $this->addMessageToQue($str);
    }
}
?>
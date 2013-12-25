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
    var $tickLimiter = .1; // This sets the time each cycle is expected to take (in seconds).  Will be used in the sleep calculation
    var $tickStartTime = 0;
    var $tickCurrentTime = 0;
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
    var $socket = null;
    var $sessionID = 0;
    
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
        
        $this->channel = $chan;
        $this->host = $host;
        $this->port = $port;
        $this->nick = $nick;
        $this->pass = $pass;
        
        // Register BurnBot's base commands that provide feedback and perform no actions
        $this->registerCommads(array(
            'version' => array('burnbot', 'burnbot_version'),
            'contact' => array('burnbot', 'burnbot_contact'),
            'slap'    => array('burnbot', 'burnbot_slap'),
            'addcom'  => array('burnbot', 'burnbot_addcom'),
            'delcom'  => array('burnbot', 'burnbot_delcom')
        ));
        
        $irc->_log_action('Burnbot constructed and commands registered');
    }
    
    // Be sure to properly close the socket BEFORE the script dies for whatever reason
    function exitHandler()
    {
        global $irc;
        
        $irc->_write($this->socket, 'QUIT :Script was killed or exited');
        
        // Wait for the peer to get the command, if they do not disconnect us, we will close the socket forcibly
        usleep(500000);
        $irc->disconnect($this->socket);
        
        // This shouldn't matter, but set it anyway
        $this->socket = null;
    }
    
    // Store the socket as a class var we can use easily
    public function init()
    {
        global $db, $host, $chan, $socket, $irc;
        
        // Avoid overwriting a valid socket.  If we disconnect, we can set the socket to null
        $this->socket = ($this->socket === null) ? $socket : $this->socket;
        
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
        
        $irc->_log_action('Setting Session ID: ' . $this->sessionID);
        
        // Now grab the data for the channel
        $this->grabCommands();
        $this->getRegulars();
    }
    
    // Accessors to get var data
    public function getSock()
    {
        return $this->socket;
    }
    public function getCounter()
    {
        return $this->reconnectCounter;
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
        $this->userCommands = array_merge($this->loadedCommands, $commands);
    }
    
    public function registerModule($module = array())
    {
        $this->loadedModules = array_merge($this->loadedModules, $module);
    }

    public function auth()
    {
        global $irc;
        
        // Auth to the server when we get our trigger
        $irc->_write($this->socket,  "USER $this->nick i * $this->nick@$this->nick");
        $irc->_write($this->socket,  "NICK $this->nick");
        
        // If we have a password, now is the time to pass it to the server as well
        if ($this->pass != '')
        {
            $irc->_write($this->socket, "PASS $this->pass");
        }
    }
    
    public function grabCommands()
    {
        global $db;
        
        // Unset the main command array since we are about to grab a fresh batch
        $this->userCommands = array();
        $commands = array();
        
        $sql = 'SELECT * FROM ' . BURNBOT_COMMANDS . ' WHERE id=\'' . $db->sql_escape($this->sessionID) . '\';';
        $result = $db->sql_query($sql);
        $arr = $db->sql_fetchrow($result);
        $db->sql_freeresult($result);
        
        foreach ($arr as $row)
        {
            $commands = array_merge($commands, array($row['trigger'] => array('burnbot', 'userDefinedCommand', array('output' => $row['output'], 'allowed_modes' => $row['allowed_modes'], 'twitch_allow_subs' => $row['twitch_allow_subs'], 'allow_regulars' => $row['allow_regulars']))));
        }
        
        $this->registerUserCommads($commands);
    }
    
    public function insertCommand($trigger, $output, $modes = '', $subs = false, $regulars = false)
    {
        $sql = 'INSERT INTO ' . BURNBOT_COMMANDS . ' (id, trigger, output, allowed_modes, twitch_allow_subs, allow_regulars) VALUES (\'' . $db->sql_escape($this->sessionID) . '\', \'' . $db->sql_escape($trigger) . '\', \'' . $db->sql_escape($output) . '\', \'' . $db->sql_escape($modes)  . '\', \'' . $db->sql_escape($subs)  . '\', \'' . $db->sql_escape($regulars) .  '\');';
        $result = $db->sql_query($sql);
        $arr = $db->sql_fetchrow($result);
        $db->sql_freeresult($result);        
    }
    
    public function removeCommand($trigger)
    {
        $sql = 'DELETE * FROM ' . BURNBOT_COMMANDS . ' WHERE id=\'' . $db->sql_escape($this->sessionID) . '\' AND trigger=\'' . $db->sql_escape($trigger) . '\';';
        $result = $db->sql_query($sql);
        $arr = $db->sql_fetchrow($result);
        $db->sql_freeresult($result);
        
        // Now unregister it
        unset($this->userCommands[$trigger]);
    }
    
    public function addRegular()
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
        
        foreach ($arr as $row)
        {
            $this->regulars[] = $row['username'];
        }      
    }
     
    // Attempts to reconnect to a socket that we seem to have lost connection to
    public function reconnect()
    {
        // DO NOT TRY TO RECONNECT IF THE SOCKET IS STILL ALIVE!
        if ($this->socket !== null)
        {
            global $irc;
            
            // Init a new socket.  Read will handle everything from there
            $this->socket = $irc->connect($this->host, $this->port);
            
            if (is_resource($this->socket))
            {
                return true;
            }
        }
        
        // We were unable to create a valid socket for some reason.  Set the socket to null and return false.
        $this->socket = null;
        return false;
    }
    
    // This is the ONLY message we auto-reply to without any checks
    public function pong($args = '')
    {
        global $irc;
        
        // IGNORE THE TTL HERE
        $irc->_write($this->socket, ($args !== '') ? "PONG :$args" : 'PONG');
    }
    
    // Grab modes out of the initial WHO responses we get or if we request a WHO
    public function who($msg)
    {
        
    }
    
    // Reads the message we recieved and adds any message triggers we need to.  Also triggers a command to be added
    /**
     * @todo Complete all read checks for the cycle
     */
    public function _read()
    {
        global $irc;
        
        $messageArr = $irc->_read($this->socket);
        
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
                $this->auth();
            }
            
            // MODE
            if (isset($messageArr['mode']))
            {
                // Check the user and mode, do we need to set them in the OP array?
            }
            
            // We have a numbered service ID instead at this point.  We only handle a few of these and will drop the rest
            if (isset($messageArr['service_id']))
            {
                // What service ID's are we looking for here?
                
                
            }
        } else {
            // Private message, check for the existance of a command and process the command
            
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
        global $irc;
        
        // Do we have messages in the que?
        if (count($this->messageQue) > 0)
        {
            $this->processTTL();
            
            // Check and process the TTL
            if (count($this->messageTTL) < 20)
            {
                $message = $this->messageQue[0];
                array_shift($this->messageQue); // Move the stack up
                
                $irc->_sendPrivateMessage($this->socket, $message, $this->channel);
                
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
    private function burnbot_version($msg = '')
    {
        
    }
    
    private function burnbot_contact($msg = '')
    {
        
    }
    
    private function burnbot_slap($msg = '')
    {
        
    }
    
    private function burnbot_addcom()
    {
        
    }
    
    private function burnbot_delcom()
    {
        
    }
}
?>
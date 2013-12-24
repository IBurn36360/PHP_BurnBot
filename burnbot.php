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
    
    // Connection details (used in some commands and in reconnecting)
    var $host = '';
    var $channel = '';
    var $nick = '';
    var $pass = '';
    var $port = 6667; // Default just in case EVERYTHING else fails for some horrable reason
    
    // The socket for the IRC connection
    var $socket = null;
    
    // Arrays (Large storage)
    var $loadedCommands = array();
    var $loadedModules = array();
    var $operators = array();
    var $regulars = array();
    
    /**
     * Structure: UNKEYED ARRAY
     *  [0] => array('message Type', array('message Args'))
    */
    var $messageQue = array();
    
    // Limits message sends
    var $limitSends = false;
    var $messageTTL = array();
    var $TTL = 20; // The number of seconds that a message is kept alive for
    var $TTLStack = 10; // The limit of messages in the stack
    
    /** Registers commands from modules into the main array for checking.  Commands will have the following structure:
     * 
     * The remaining message is the full message stripped of the command
     * 
     * 'test' => array('callback function', 'msg')
     */
    public static function registerCommads($commands = array())
    {
        $this->loadedCommands = array_merge($this->loadedCommands, $commands);
    }
    
    public static function registerModule($module = array())
    {
        $this->loadedModules = array_merge($this->loadedModules, $module);
    }
    
    public static function init($socket)
    {
        // grab our info from startup
        global $chan, $host, $port, $nick, $pass;
        
        $this->socket = $socket;
        $this->channel = $chan;
        $this->host = $host;
        $this->port = $port;
        $this->nick = $nick;
        $this->pass = $pass;
        
        // Register BurnBot's base commands that provide feedback and perform no actions
        $this->registerCommads(array(
            'version' => array('burnbot_version'),
            'contact' => array('burnbot_contact')
        ));
    }
    
    public static function auth($nick)
    {
        
    }
    
    public static function grabCommands()
    {
        
    }
    
    public static function insertCommand()
    {
        
    }
    
    public static function removeCommand()
    {
        
    }
    
    public static function checkCommands()
    {
        
    }
    
    public static function addRegular()
    {
        
    }
    
    public static function removeRegular()
    {
        
    }
    
    public static function getRegulars()
    {
        
    }
    
    // Attempts to reconnect to a socket that we seem to have lost connection to
    public static function reconnect()
    {
        
    }
    
    // This is the ONLY message we auto-reply to without any checks
    public static function pong($args = '')
    {
        global $irc, $file;
        
        /** 
         * @todo Redo this if TTL includes PONG responses
         */
        
        // IGNORE THE TTL HERE
        $irc->_write($this->socket, ($args !== '') ? "PONG :$args" : 'PONG', $file);
    }
    
    // Reads the message we recieved and adds any message triggers we need to.  Also triggers a command to be added
    public static function _read()
    {
        global $irc, $file;
        
        $messageArr = $irc->_read($this->socket, $file);
    }
    
    public static function twitch_generatePassword($nick)
    {
        // Select the nick out of the DB and grab the code to generate a token for it
        global $twitch, $db;
        
        
        
        return $twitch->chat_generateToken(null, $code);
    }
    
    public static function addMessageToQue($message, $args = array())
    {
        return (array_push($this->messageQue, array($message, $args)) != 0);
    }
    
    // Process the current que of messages and sent the one at the top of the list
    // Called after the TTL has been checked
    public static function processQue()
    {
        global $irc, $file;
        
        if (count($this->messageQue) > 0)
        {
            $message = $this->messageQue[0];
            array_shift($this->messageQue); // Move the stack up
            
            $irc->_sendPrivateMessage($this->socket, $message, $this->channel, $file);
        }
    }
    
    // The loop we run for the bot
    public static function tick()
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
    private static function burnbot_version($msg = '')
    {
        
    }
    
    private static function burnbot_contact($msg = '')
    {
        
    }
}
?>
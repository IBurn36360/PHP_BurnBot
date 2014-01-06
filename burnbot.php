<?php
// Was this accessed directly?  If so, exit.
if (!defined('IN_IRC'))
{
	exit;
}

// defines all Burnbot logic
class burnbot
{
    var $readOnly = false; // This stops commands from being able to be registered EXCEPT quit
    
    var $version = '.5';
    var $overrideKey = '';
    var $tickLimiter = .10; // This sets the time each cycle is expected to take (in seconds).  Will be used in the sleep calculation
    var $tickLimiterPostJoin = .02; // This updates the limiter above when we finally JOIN
    var $tickStartTime = 0;
    var $tickCurrentTime = 0;
    var $lastPingTime = 0;
    var $lastPongTime = 0;
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
    var $hasJoined = false;
    
    // Arrays (Large storage)
    var $loadedCommands = array();
    var $userCommands = array();
    var $loadedModules = array();
    var $operators = array();
    var $regulars = array();
    var $subscribers = array();
    var $turboUsers = array();
    
    // Twitch hostnames and IP's (True indicates that it is active)
    var $twitchHosts = array(
        'irc.twitch.tv' => true,
        '199.9.253.199' => true,
        '199.9.250.229' => true,
        '199.9.253.210' => true,
        '199.9.250.239' => true
    );
    
    var $burnbotCommands = array(
        'contact' => array('core', 'burnbot_contact', false, false, false, false),
        'google'  => array('core', 'burnbot_google', false, true, false, false),
        'help'    => array('core', 'burnbot_help', false, true, false, false),
        'listcom' => array('core', 'burnbot_listcom', false, true, false, false),
        'listops' => array('core', 'burnbot_listops', true, false, false, false),
        'listreg' => array('core', 'burnbot_listreg', true, false, false, false),
        'modules' => array('core', 'burnbot_loadedModules', true, false, false, false),
        'module'  => array('core', 'burnbot_module', true, false, false, false),
        'version' => array('core', 'burnbot_version', false, false, false, false),
        'nick'    => array('core', 'burnbot_nick', false, true, false, false),
        'slap'    => array('core', 'burnbot_slap', false, true, false, false),
        'addcom'  => array('core', 'burnbot_addcom', true, false, false, false),
        'delcom'  => array('core', 'burnbot_delcom', true, false, false, false),
        'editcom' => array('core', 'burnbot_editcom', true, false, false, false),
        'quit'    => array('core', 'burnbot_quit', true, false, false, false),
        'addreg'  => array('core', 'burnbot_addreg', true, false, false, false),
        'delreg'  => array('core', 'burnbot_delreg', true, false, false, false),
        'memusage'=> array('core', 'burnbot_memusage', true, false, false, false),
        'limiters'=> array('core', 'burnbot_limiters', true, false, false, false)
    );
    
    var $messageQue = array();
    
    // Limits message sends
    var $limitSends = true; // Override this later if we don't want the limiter enabled
    var $messageTTL = array();
    var $TTL = 31; // The number of seconds that a message is kept alive for (31 seconds to allow for the message to die on our peers end as well)
    var $TTLStack = 20; // The limit of messages in the stack
    
    function __construct()
    {
        // grab our info from startup
        global $twitch, $chan, $host, $port, $nick, $pass, $irc, $db;
        
        // Force the hash symbol at the start of the channel no matter what
        $this->chan = ($chan[0] == '#') ? $chan : '#' . $chan;
        $this->host = $host;
        $this->port = $port;
        $this->nick = $nick;
        $this->pass = $pass;
        $this->lastPingTime = time(); // We assume we will be getting a ping on AUTH, so this is only here for a second at max
        
        // Twitch specific initialization
        if (array_key_exists($host, $this->twitchHosts))
        {
            $this->isTwitch = true;
            
            // Force lower case on all Twitch channels so we don't join an invalid channel
            $this->chan = strtolower($this->chan);
        }
        
        // Now grab the session ID we will be using for DB queries
        $sql = $db->sql_build_select(BURNBOT_CONNECTIONS, array(
            'id'
        ), array(
            'host' => $host,
            'channel' => $chan
        ));
        $result = $db->sql_query($sql);
        $this->sessionID = $db->sql_fetchrow($result)['id'];
        $db->sql_freeresult($result);
        
        if ($this->sessionID == '')
        {
            $irc->_log_action("Creating new entry for session");
            $host = ($this->isTwitch) ? 'irc.twitch.tv' : $host ;
            $sql = $db->sql_build_insert(BURNBOT_CONNECTIONS, array(
                'host' => $host,
                'channel' => $chan
            ));
            $result = $db->sql_query($sql);
            
            $sql = $db->sql_build_select(BURNBOT_CONNECTIONS, array(
                'id'
            ), array(
                'host' => $host,
                'channel' => $chan
            ));
            $result = $db->sql_query($sql);
            
            // Okay, now we have the ID, store it
            $this->sessionID = $db->sql_fetchrow($result)['id'];
            $db->sql_freeresult($result);
        }
            
        // Now set up to generate a password for twitch if we are in Twitch mode
        if ($this->isTwitch)
        {
            // Register the regulars
            $this->getRegulars();
        }
        
        $this->overrideKey = md5($this->version . time() . rand(0, 1000000));
        
        $irc->_log_action('Setting Session ID: ' . $this->sessionID);
        $irc->_log_action('Quit Override Key: ' . $this->overrideKey);
        
        // Register the base modules
        $this->registerModule(array("core" => true, "user" => true));
        
        $irc->_log_action('Burnbot environment constructed');
    }
    
    // Be sure to properly close the socket BEFORE the script dies for whatever reason
    private function exitHandler()
    {
        global $irc, $socket;
        
        $irc->_write($socket, 'QUIT :Script was killed or exited');
        
        // Wait for the peer to get the command, if they do not disconnect us, we will close the socket forcibly
        usleep(500000);
        $irc->disconnect($socket);
        $socket = null;
        
        // Update our Auth in case we want to reconnect from our external parent
        $this->hasAuthd = false;
        $this->hasJoined = false;
        
        // Reset timers as well
        $this->lastPingTime = 0;
        $this->lastPongTime = 0;
        
        // Hard stop the script here since we were passed here
        echo '<hr />Script was passed to exit handler.  Now killing script entirely<hr />';
        exit;
    }
    
    private function timeoutPeer()
    {
      global $socket;
        
        $irc->_write($socket, 'QUIT :Ping timeout (180 seconds)');
        
        usleep(500000);
        $irc->disconnect($socket);
        $socket = null;
        
        // Update our Auth in case we want to reconnect
        $this->hasAuthd = false;
        $this->hasJoined = false;
        
        // Reset timers as well
        $this->lastPingTime = 0;
        $this->lastPongTime = 0;
        
        // Reconnect to the socket
        while ($this->reconnectCounter != 0)
        {
            $this->reconnect();
        }
        
        if ($socket !== null)
        {
            $this->reconnectCounter = 5;
        }
    }
    
    // Store the socket as a class var we can use easily
    public function init()
    {
        global $twitch, $db;
        
        // Check to see if we are in our first init phase and if we need to gen a password
        if ($this->isTwitch && ($this->pass == ''))
        {
            $sql = $db->sql_build_select(BURNBOT_TWITCHLOGINS, array(
                'code',
                'token'
            ), array(
                'nick' => $this->nick
            ));
            $result = $db->sql_query($sql);
            $arr = $db->sql_fetchrow($result);
            $code = $arr['code'];
            $token = trim($arr['token'], 'oauth:');
            $db->sql_freeresult($result);
            
            if (isset($token) && ($token != ''))
            {
                $this->pass = $twitch->chat_generateToken($token, $code);
                
                // Update the token to reflect any changes to it (Validation issues)
                if ($this->pass != $token)
                {
                    $sql = $db->sql_build_update(BURNBOT_TWITCHLOGINS, array(
                        'token' => $this->pass
                    ), array(
                        'nick' => $this->nick
                    ));
                    $result = $db->sql_query($sql);
                    $db->sql_freeresult($result);                    
                }
            } else {
                $this->pass = $twitch->chat_generateToken(null, $code);
                
                // Update the token to reflect any changes to it (Validation issues)
                $sql = $db->sql_build_update(BURNBOT_TWITCHLOGINS, array(
                    'token' => $this->pass
                ), array(
                    'nick' => $this->nick
                ));
                $result = $db->sql_query($sql);
                $db->sql_freeresult($result);
            }
        }
        
        // First, flush all of our loaded comands
        $this->loadedCommands = array();
        $this->userCommands = array();
        
        $commands = $this->burnbotCommands;
        
        if ($this->isTwitch)
        {
            // Disable some commands while on Twitch specifically
            unset($commands['nick'], $commands['limiters'], $commands['listreg']);
        } else {
            // In standard channels, we disable regulars since we can rely on modes
            unset($commands['addreg'], $commands['delreg']);
        }
        
        // Are we in readOnly mode?
        if ($this->readOnly)
        {
            unset($commands['contact'], 
                  $commands['google'], 
                  $commands['help'], 
                  $commands['listops'], 
                  $commands['listreg'], 
                  $commands['modules'], 
                  $commands['version'], 
                  $commands['nick'], 
                  $commands['slap'], 
                  $commands['addcom'], 
                  $commands['delcom'], 
                  $commands['editcom'], 
                  $commands['addreg'], 
                  $commands['delreg'], 
                  $commands['memusage'], 
                  $commands['limiters'], 
                  $commands['listcom']
            );
        }
        
        // Register Core's commands
        $this->registerCommads($commands);
         
        // Now grab the user commands
        $this->grabCommands();
        
        // Load our module config and be sure we disable any modules the chan wants disabled on init
        $mod = array();
        $sql = $db->sql_build_select(BURNBOT_MODULES_CONFIG, array(
            'enabled',
            'module'
        ), array(
            'id' => $this->sessionID,
            
        ));
        $result = $db->sql_query($sql);
        $modules = $db->sql_fetchrowset($result);
        $db->sql_freeresult($result);
        
        if (!empty($modules))
        {
            foreach ($modules as $row)
            {
                $enabled = ($row['enabled'] == 1) ? true : false;
                
                $this->loadedModules[$row['module']] = $enabled;
            }
        }
        
        // Go through the init phases of every registered module
        foreach ($this->loadedModules as $module => $enabled)
        {
            // is this module actually enabled at this point in time?  If not, skip their init phase
            if ($enabled == true)
            {
                // Run every module's init code if they have any to run.  All commands need to be registered here
                switch($module)
                {
                    case 'twitch':
                        $twitch->init();
                        break;
                        
                    default:
                        break;
                }
            }
        }
        
        // Now that we have init completed, go to post-init and load the channel configs
        $this->postInit();
    }
    
    // This loads any configuread disabled or changes the channel has made to commands
    private function postInit()
    {
        global $db;
        
        $commands = array();
        
        // Grab the command config, since the module config can override any of these when modules are disabled
        $sql = $db->sql_build_select(BURNBOT_COMMANDS_CONFIG, array(
            '_trigger',
            'ops',
            'regs',
            'subs',
            'turbo',
            'enabled'
        ), array(
            'id' => $this->sessionID
        ));
        $result = $db->sql_query($sql);
        $rows = $db->sql_fetchrowset($result);
        $db->sql_freeresult($result);
        
        if (!empty($rows))
        {
            foreach ($rows as $row)
            {
                // Are we disabling the command?
                if (isset($row['enabled']) && ($row['enabled'] == 1))
                {
                    $mod = $this->loadedCommands[$row['_trigger']][0];
                    $func = $this->loadedCommands[$row['_trigger']][1];
                    $ops = ($row['ops'] == 1) ? true : false;
                    $regs = ($row['regs'] == 1) ? true : false;
                    $subs = ($row['subs'] == 1) ? true : false;
                    $turbo = ($row['turbo'] == 1) ? true : false;
                    
                    $commands[$row['_trigger']] = array($mod, $func, $ops, $regs, $subs, $turbo);
                } else {
                    unset($this->loadedCommands[$row['_trigger']]);
                }
            }       
        }
        
        // Now load the module config
        $sql = $db->sql_build_select(BURNBOT_MODULES_CONFIG, array(
            'module',
            'enabled'
        ), array(
            'id' => $this->sessionID
        ));
        $result = $db->sql_query($sql);
        $rows = $db->sql_fetchrowset($result);
        $db->sql_freeresult($result);
        
        if (!empty($rows))
        {
            foreach($rows as $row)
            {
                // Do nothing if the module is enabled
                if ($row['enabled'] == 0)
                {
                    $this->unregisterModule($row['module']);
                }
            }
        }
    }
    
    // Accessors to get var data
    public function getReconnectCounter()
    {
        return $this->reconnectCounter;
    }
    public function getOverrideKey()
    {
        return $this->overrideKey;
    }
    public function getIsTwitch()
    {
        return $this->isTwitch;
    }
    public function getChan()
    {
        return $this->chan;
    }
    public function getReadOnly()
    {
        return $this->readOnly;
    }
    public function getSessionID()
    {
        return $this->sessionID;
    }
    public function getCommandDelimeter()
    {
        return $this->commandDelimeter;
    }
    
    // Registers
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
    
    // This is here for VERY specific commands only
    public function unregisterCommands($command = '', $commands = array())
    {
        if (($command != '') && array_key_exists($command, $this->loadedCommands))
        {
            unset($this->loadedCommands[$command]);
            return true;
        }
        
        // We can also unset a list of commands in the case we are unregistering a module
        if (($commands != array()) && is_array($commands))
        {
            foreach ($commands as $command)
            {
                if (($command != '') && array_key_exists($command, $this->loadedCommands))
                {
                    unset($this->loadedCommands[$command]);
                }
            }

            return true;
        }

        return false;
    }
    
    public function unregisterModule($module = '')
    {
        
        if ($module == '')
        {
            // People weren't smart
            return false;
        }
        
        $commands = array();
        if (array_key_exists($module, $this->loadedModules))
        {
            foreach($this->loadedCommands as $trigger => $arr)
            {
                if ($arr[0] == $module)
                {
                    $commands[] = $trigger;
                }
            }
            
            $this->unregisterCommands('', $commands);
            $this->loadedModules[$module] = false;
        }
    }

    public function auth()
    {
        global $irc, $socket;
        
        if (!$this->hasAuthd)
        {
            if (!$this->isTwitch)
            {
                // Auth to the server when we get our trigger
                $irc->_write($socket, "USER $this->nick i * $this->nick@$this->nick");
                $irc->_write($socket, "NICK $this->nick");
                
                // If we have a password, now is the time to pass it to the server as well
                if ($this->pass != '')
                {
                    $irc->_write($socket, "PASS $this->pass");
                }
            } else {
                // For Twitch, we AUTH both first and differently
                $irc->_write($socket, "PASS $this->pass");
                $irc->_write($socket, "NICK $this->nick");
            }
            
            $this->hasAuthd = true;
        }
    }
    
    public function grabCommands()
    {
        global $db, $irc;
        
        // Unset the main command array since we are about to grab a fresh batch
        $this->userCommands = array();
        $commands = array();
        
        // Construct in this way to make adding a whole hell of a lot easier in the future
        $sql = $db->sql_build_select(BURNBOT_COMMANDS, array(
            '_trigger',
            'output',
            'ops',
            'regulars',
            'subs',
            'turbo'
        ), array(
            'id' => $this->sessionID,
        ));
        
        $result = $db->sql_query($sql);
        $arr = $db->sql_fetchrowset($result);
        $db->sql_freeresult($result);
        
        if (is_array($arr) && !empty($arr))
        {            
            foreach ($arr as $row)
            {
                $row['ops'] = (isset($row['ops']) && ($row['ops'] == 1)) ? true : false;
                $row['regulars'] = (isset($row['regulars']) && $row['regulars'] == 1) ? true : false;
                $row['subs'] = (isset($row['subs']) && $row['subs'] == 1) ? true : false;
                $row['turbo'] = (isset($row['turbo']) && $row['turbo'] == 1) ? true : false;
                
                $commands = array_merge($commands, array($row['_trigger'] => array('user', 'burnbot_userCommand', $row['ops'], $row['regulars'], $row['subs'], $row['turbo'], $row['output'])));
            }            
        }
        
        $this->registerUserCommands($commands);
    }
    
    public function insertCommand($trigger, $output, $ops = false, $regulars = false, $subs = false, $turbo = false)
    {
        global $db, $irc;
        
        $trigger = trim($trigger, $this->commandDelimeter);
        
        if (array_key_exists($trigger, $this->userCommands))
        {
            $this->addMessageToQue("Command $trigger already exists, please edit the command instead");
            return;
        }
        
        if (($trigger == 'enable') || ($trigger == 'disable'))
        {
            $this->addMessageToQue("The trigger $trigger is reserved, please choose another trigger");
            return;
        }
        
        $sql = $db->sql_build_insert(BURNBOT_COMMANDS, array(
            'id' => $this->sessionID,
            '_trigger' => $trigger,
            'output' => $output,
            'ops' => $ops,
            'regulars' => $regulars,
            'subs' => $subs,
            'turbo' => $turbo
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
        
        $sql = $db->sql_build_delete(BURNBOT_COMMANDS, array(
            'id' => $this->sessionID, 
            '_trigger' => $trigger
        ));
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
        
        $sql = $db->sql_build_update(BURNBOT_COMMANDS, $commandArr, array(
            'id' => $this->sessionID, 
            '_trigger' => $command
        ));
        
        $result = $db->sql_query($sql);
        $success = $db->sql_fetchrow($result);
        $db->sql_freeresult($result);
        
        $output = $commandArr['output'];
        
        // Anything other than false is considered a success
        if ($success !== false)
        {
            $this->addMessageToQue("Command $command has been updated: $output");
        } else {
            $this->addMessageToQue("Command $command was unable to be updated.  Either it does not exist or your syntax was incorrect.");
        }
        
        // Weather or not it succeeded, flush and grab all of our commands
        $this->grabCommands();
    }
    
    public function addRegular($username)
    {
        global $db, $irc;
        
        $sql = $db->sql_build_insert(BURNBOT_REGULARS, array(
            'id' => $this->sessionID,
            'username' => $username
        ));
        $result = $db->sql_query($sql);
        $arr = $db->sql_fetchrow($result);
        $db->sql_freeresult($result);
        
        $this->getRegulars();
    }
    
    public function removeRegular($username)
    {
        global $db;
        
        $sql = $db->sql_build_delete(BURNBOT_REGULARS, array(
            'id' => $this->sessionID,
            'username' => $username
        ));
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
        
        $sql = $db->sql_build_select(BURNBOT_REGULARS, array(
            'username'
        ), array(
            'id' => $this->sessionID
        ));
        $result = $db->sql_query($sql);
        $arr = $db->sql_fetchrowset($result);
        $db->sql_freeresult($result);
        
        if (is_array($arr))
        {
            foreach ($arr as $row)
            {
                $this->regulars[$row['username']] = false;
            }
        }
    }
     
    // Attempts to reconnect to a socket that we seem to have lost connection to
    public function reconnect()
    {
        global $socket;
        
        $this->reconnectCounter--;
        
        // DO NOT TRY TO RECONNECT IF THE SOCKET IS STILL ALIVE!
        if ($socket === null)
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
        
        // Update this since we have sent a PING to our peer
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
    
    public function checkCommandPerm($user, $trigger)
    {
        // Assign layers
        if (array_key_exists($trigger, $this->loadedCommands))
        {
            $op = $this->loadedCommands[$trigger][2];
            $reg = $this->loadedCommands[$trigger][3];
            $sub = $this->loadedCommands[$trigger][4];
            $turbo = $this->loadedCommands[$trigger][5];            
        } elseif (array_key_exists($trigger, $this->userCommands)) {
            $op = $this->userCommands[$trigger][2];
            $reg = $this->userCommands[$trigger][3];
            $sub = $this->userCommands[$trigger][4];
            $turbo = $this->userCommands[$trigger][5];                 
        } else {
            // The command doesn't exist.  We should never reach here, but this is a catch case either way
            return false;
        }
        
        // Are any layers assigned...if not, we will assume true
        if ($op || $reg || $sub || $turbo)
        {
            // Now go through the layers one at a time, high to low
            if ($op && array_key_exists($user, $this->operators))
            {
                return true;
            } elseif ($reg && (array_key_exists($user, $this->operators) || array_key_exists($user, $this->regulars))) {
                return true;
            } elseif ($sub && (array_key_exists($user, $this->operators) || array_key_exists($user, $this->subscribers))) {
                return true;
            } elseif ($turbo && (array_key_exists($user, $this->operators) || array_key_exists($user, $this->turboUsers))) {
                return true;
            }
        } else {
            return true;
        }
        
        // If we reached here, assume that checks failed
        return false;
    }
    
    // Reads the message we recieved and adds any message triggers we need to.  Also triggers a command to be added
    public function _read($message = null)
    {
        global $irc, $socket, $chan, $reminders, $twitch, $moderation, $currency, $rainwave, $lastFm, $spotify;
        
        // Was the function already supplied a message?  If so, we were ina  loop of limitless reads
        $message = ($message != null) ? $message : $irc->_read($socket);
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
                    
                    // Update this when WE recieve the message
                    $this->lastPongTime = time();
                }
                
                // PONG
                if (isset($messageArr['isPong']))
                {
                    // Update this when WE recieve the message, nothing more to do here
                    $this->lastPongTime = time();
                }                
                
                // AUTH
                if (isset($messageArr['isAuth']))
                {
                    if ($messageArr['message'] == '*** Checking Ident')
                    {
                        $this->auth();
                    }
                }
                
                // NICK
                if (isset($messageArr['isNick']))
                {
                    // Go through every array and be sure to transfer their layer to the new nick
                    if (array_key_exists($messageArr['oldNick'], $this->operators))
                    {
                        // For logging
                        $oldNick = $messageArr['oldNick'];
                        $newNick = $messageArr['newNick'];
                        
                        unset($this->operators[$oldNick]);
                        $this->operators = array_merge($this->operators, array($newNick => true));
                        $irc->_log_action("Operator for $oldNick has been treansfered to $newNick");
                    }
                    
                    if (array_key_exists($messageArr['oldNick'], $this->regulars))
                    {
                        // For logging
                        $oldNick = $messageArr['oldNick'];
                        $newNick = $messageArr['newNick'];
                        
                        unset($this->regulars[$oldNick]);
                        $this->regulars = array_merge($this->regulars, array($newNick => true));
                        $irc->_log_action("Operator for $oldNick has been treansfered to $newNick");
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
                
                if ((isset($messageArr['isJoin']) || isset($messageArr['isPart'])) && $this->isTwitch)
                {
                    // Twitch batches JOIN and PART messages, This means we can read through all of those without limits
                    while (isset($messageArr['isJoin']) || isset($messageArr['isPart']))
                    {
                        if (isset($messageArr['isPart']))
                        {
                            // Blind unset our arrays so that people don't stay in arrays when not in the channel
                            $nick = $messageArr['nick'];
                            $irc->_log_action("Removing $nick from OP and sub layers");
                            unset($this->operators[$nick], $this->subscribers[$nick], $this->turboUsers[$nick]);
                            
                            // Lastly, if we are not on Twitch, remove the nick from regular as well
                            if (!$this->isTwitch)
                            {
                                unset($this->regulars[$nick]);
                            }
                        }
                        
                        $message = $irc->_read($socket);
                        $messageArr = $irc->checkRawMessage($message);
                    }
                    
                    // At this point, we need to make sure the last message is actually processed
                    $this->_read($message);
                    return; // !IMPORTANT, Stop ANY other checks in read for this check.  The recursive call to this function will handle the message.
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
                        
                        // If we are on twitch, this is when we join a channel
                        if ($this->isTwitch && !$this->hasJoined)
                        {
                            $irc->_joinChannel($socket, $chan);
                            $irc->_write($socket, "TWITCHCLIENT 2");
                            $this->hasJoined = true;
                            
                            // Lastly, change our read limiter to where it should be
                            $this->tickLimiter = $this->tickLimiterPostJoin;
                        }
                    }
                    
                    // We have a default mode, now we may JOIN
                    if (($messageArr['service_id'] == '221') && !$this->hasJoined)
                    {
                        $irc->_joinChannel($socket, $chan);
                        $this->hasJoined = true;
                        
                        // Lastly, change our read limiter to where it should be
                        $this->tickLimiter = $this->tickLimiterPostJoin;
                    }
                    
                    // We gained a WHO from a channel join
                    if ($messageArr['service_id'] == '353')
                    {
                        // This could be very large, so we are going into limitless read again
                        while ($messageArr['service_id'] == '353')
                        {
                            $this->who($messageArr['message']);
                            $message = $irc->_read($socket);
                            $messageArr = $irc->checkRawMessage($message);
                        }
                        
                        // Make sure the next message is dealt with properly
                        $this->_read($message);
                        return;
                    }
                }
                
                return;
            } elseif ($messageArr['type'] == 'private') {
                // Private message, check for the existance of a command and process the command
                $words = explode(' ', $messageArr['message']);
                $sender = $messageArr['nick'];
                $command = strtolower($words[0]);
                
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
                                // Does the sender have the permission for the command?
                                if ($this->checkCommandPerm($sender, $trigger))
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
                                        
                                        case 'core':
                                            // Run this command in the burnbot module
                                            $this->{$info[1]}($sender, $msg);
                                            
                                            break;
                                            
                                        case 'twitch':
                                            $twitch->{$info[1]}($sender, $msg);
                                            
                                            break;
                                            
                                        default:
                                            // The command isn't defined properly, drop an error into the log and pass out to the chat as well
                                            $irc->_log_error("Error attempting to run command $trigger.  No information array provided");
                                            $this->addMessageToQue("Error attempting to run command $trigger.  No information array provided");
                                            break;
                                    }
                                }
                                
                                break; // We are done here, no need to continue
                            }
                        }
                    }
                }
            } elseif ($messageArr['type'] == 'twitch_message') {
                // TWITCHCLIENT message, handle
                
                // SpecialUser, used for Admin, Subscriber, Staff and Turbo,
                if ($messageArr['command'] == 'SPECIALUSER')
                {
                    $nick = $messageArr['nick'];
                    
                    // Add user to Turbo Arr
                    if ($messageArr['value'] == 'turbo')
                    {
                        $irc->_log_action("Adding $nick as a turbo user");
                        $this->turboUsers = array_merge($this->turboUsers, array($nick => true));
                    }
                    
                    // Add user to Subscriber Arr
                    if ($messageArr['value'] == 'subscriber')
                    {
                        $irc->_log_action("Adding $nick as a subscriber");
                        $this->subscribers = array_merge($this->subscribers, array($nick => true));
                    }
                }
                
                // USERCOLOR, defines the user's color assuming it is set.  Not used by the bot itself in any way
                if ($messageArr['command'] == 'USERCOLOR')
                {
                    
                }
                
                // EMOTESET, stores the array of all emote sets the user is allowed.  Not used by the bot itself in any way
                if ($messageArr['command'] == 'EMOTESET')
                {
                    
                }
                
                // CLEARCHAT event, this deletes messages for a user.  Not used by the bot itself in any way
                if ($messageArr['command'] == 'CLEARCHAT')
                {
                    
                }
            }
            
            // We have handled the message, STOP anything else from seeing it
            return;
        }
        
        // If we have our twitch module enabled and loaded, pass off to it in case we need to get some data out of it that the main library won't pick up
        if ($this->isTwitch)
        {
            
        }
        
        // If we reached here the message wasn't handled.  We aren't going to return anything here becayuse the read function runs in a loop
        // and a return is unneeded calculations.  Still good to note that anything that wasn't handled is dropped entirely though.
    }

    public function addMessageToQue($message, $args = array(), $time = 0)
    {
        if ($time == 0)
        {
            // Set time to the current timestamp, we can send these immediately
            $time = time();
        }
        
        $success = array_push($this->messageQue, array($message, $args, $time));
        $sortArr = array();
        
        // Sort the stack
        foreach ($this->messageQue as $key => $arr)
        {
            // Make the time the value of the slave key
            $sortArr[$key] = $arr[2];
        }
        
        $clone = array();
        asort($sortArr);
        
        // Now remerge the array
        foreach($sortArr as $key => $arr)
        {
            $clone[] = $this->messageQue[$key];
        }
        
        // Lastly, resave the array
        $this->messageQue = $clone;
        
        return ($success != 0);
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
            // Check and process the TTL
            if (count($this->messageTTL) < 20)
            {
                // Are we able to send this message?
                $time = $this->messageQue[0][2];
                $currTime = time();
                if ($this->messageQue[0][2] <= time())
                {
                    // Set message and args array
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
                        // We have no args, assume PM
                        $irc->_sendPrivateMessage($socket, $message, $this->chan); 
                        
                    }
    
                    // Lastly, add our message onto the TTL stack
                    $this->messageTTL = array_merge($this->messageTTL, array(microtime(true) => $message));                    
                }
            } elseif (!$this->limitSends) {
                // Are we able to send this message?
                if ($time <= time())
                {
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
                        // We have no args, assume PM
                        $irc->_sendPrivateMessage($socket, $message, $this->chan); 
                        
                    }
    
                    // Lastly, add our message onto the TTL stack
                    $this->messageTTL = array_merge($this->messageTTL, array(microtime(true) => $message));                    
                }
            }
        }
    }
    
    // The loop we run for the bot
    public function tick()
    {
        global $irc;
        
        // First get the time that this cycle started
        $this->tickStartTime = microtime(true);
        $ping = time();
        
        // For Twitch in particular, we AUTH before anything
        if (!$this->hasAuthd && $this->isTwitch)
        {
            $this->auth();
        }
        
        $this->_read();
        $this->processTTL();
        $this->processQue();
        
        // Are we going to time our peer out at this point?
        if ((($this->lastPongTime + 180) <= $ping) && ($this->lastPongTime !== 0))
        {
            // We might not have AUTH'd and JOIN'd yet, so don't disconnect in that case
            if ($this->hasJoined)
            {
                // Alright, run our timeout handler
                $this->timeoutPeer();
            }            
        }
        
        // Do we need to send a PING to our peer?
        // Check to see if lastPing has been sent and to see if we have Auth'd
        if ((($this->lastPingTime + 60) <= $ping) && ($this->lastPingTime !== 0))
        {
            // Do NOT send a PING is we havn't AUTH'd yet
            if ($this->hasJoined)
            {
                $this->ping();
            }
        }
        
        // Okay, now check to see if we finished before the minimum time limit.
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
        $this->addMessageToQue("@$sender: Current version is V$this->version.  Get it over at https://github.com/IBurn36360/PHP_BurnBot");
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
        // We have the permission to add a command
        $split = explode(' ', $msg);
        
        $trigger = strtolower($split[0]);
        array_shift($split);
        $output = implode(' ', $split);
        
        $this->insertCommand($trigger, $output);
    }
    
    private function burnbot_delcom($sender, $msg = '')
    {
        // And this is why ALL arrays use keys.  You can not search via value
        $parts = explode(' ', $msg);
        
        $this->removeCommand(strtolower($parts[0]));
    }
    
    private function burnbot_nick($sender, $msg = '')
    {
        global $irc, $socket;
        
        $nick = $msg;

        // Nick is handled by Edge, no need to stack the command
        $irc->_write($socket, "NICK $nick");
    }
    
    // There is currently no override here.  Might have to add one later
    private function burnbot_quit($sender, $msg = '')
    {
        $split = explode(' ', $msg);
        $overrideKey = $split[0];
        
        // If we are on Twitch, only the channel owner can tell us to leave.  Easy check
        if ($this->isTwitch && ($sender == trim($this->chan, '#')))
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
        global $db, $irc;
        
        $split = explode(' ', strtolower($msg));
        
        if (($split[0] == 'enable') || ($split[0] == 'disable'))
        {
            $state = $split[0];
            $trigger = (isset($split[1])) ? $split[1] : false ;
            
            // check for a protected command
            if (($trigger == 'quit') || ($trigger == 'editcom') || ($trigger == 'module'))
            {
                // Disallow this command to be enabled and disabled.  Quit will be disallowed from edit later on.
                $this->addMessageToQue("Command trigger $trigger is protected and can not be enabled or disabled");
                return;
            }
            
            // did command, toss some feedback at the user
            if (!$trigger)
            {
                $this->addMessageToQue('Please specify a command trigger to modify');
                return;
            }
            
            if ($state == 'disable')
            {
                unset($this->loadedCommands[$trigger]);
                
                // Now update the database
                $sql = $db->sql_build_select(BURNBOT_COMMANDS_CONFIG, array(
                    '*'
                ), array(
                    'id' => $this->sessionID,
                    '_trigger' => $trigger
                ));
                $result = $db->sql_query($sql);
                $arr = $db->sql_fetchrow($result);
                $db->sql_freeresult($result);
                
                if (empty($arr))
                {
                    // No data, insert time
                    $sql = $db->sql_build_insert(BURNBOT_COMMANDS_CONFIG, array(
                        '_trigger' => $trigger,
                        'id' => $this->sessionID,
                        'enabled' => false
                    ));
                    $result = $db->sql_query($sql);
                    $db->sql_freeresult($result);
                } else {
                    // Data is present, update it instead
                    $sql = $db->sql_build_update(BURNBOT_COMMANDS_CONFIG, array(
                        'enabled' => false
                    ), array(
                        'id' => $this->sessionID,
                        '_trigger' => $trigger
                    ));
                    $result = $db->sql_query($sql);
                    $db->sql_freeresult($result);
                }
                
                $this->addMessageToQue("Command $trigger has been disabled");
                
                // Now that the database is updated, Re-enable the command by re-running the init and post-init phase of the bot
                $this->init();
            } else {
                // Well, we need to update the DB and run through the command resheller (For modules)
                $sql = $db->sql_build_select(BURNBOT_COMMANDS_CONFIG, array(
                    '*'
                ), array(
                    'id' => $this->sessionID,
                    '_trigger' => $trigger
                ));
                $result = $db->sql_query($sql);
                $arr = $db->sql_fetchrow($result);
                $db->sql_freeresult($result);
                
                if (empty($arr))
                {
                    // No data, insert time
                    $sql = $db->sql_build_insert(BURNBOT_COMMANDS_CONFIG, array(
                        '_trigger' => $trigger,
                        'id' => $this->sessionID,
                        'enabled' => true
                    ));
                    $result = $db->sql_query($sql);
                    $db->sql_freeresult($result);
                } else {
                    // Data is present, update it instead
                    $sql = $db->sql_build_update(BURNBOT_COMMANDS_CONFIG, array(
                        'enabled' => true
                    ), array(
                        'id' => $this->sessionID,
                        '_trigger' => $trigger
                    ));
                    $result = $db->sql_query($sql);
                    $db->sql_freeresult($result);
                }
                
                $this->addMessageToQue("Command $trigger has been enabled");
                
                // Now that the database is updated, Re-enable the command by re-running the init and post-init phase of the bot
                $this->init();
            }
        } else {
            if (array_key_exists($split[0], $this->loadedCommands))
            {
                // We are editing a module command's permission
                $trigger = (isset($split[0])) ? strtolower($split[0]) : null;
                
                // Is this command protected?
                if (($trigger == 'quit'))
                {
                    // Command is protected, stop the edit
                    $this->addMessageToQue("Command trigger $trigger is protected from having permissions edited");
                    return;
                }
                
                $ops = (isset($split[1])) ? $split[1] : null;
                $regs = (isset($split[2])) ? $split[2] : null;
                $subs = (isset($split[3])) ? $split[3] : null;
                $turbo = (isset($split[4])) ? $split[4] : null;
                
                for ($i = 1; $i <= 5; $i++)
                {
                    array_shift($split);
                }                

                // Convert our vars.  Why do I accept to many possible responses?  Who knows
                $ops = (($ops == 'true') || ($ops == 't') || ($ops == '1') || ($ops == 'yes') || ($ops == 'y')) ? intval(true) : intval(false);
                $regs = (($regs == 'true') || ($regs == 't') || ($regs == '1') || ($regs == 'yes') || ($regs == 'y')) ? intval(true) : intval(false);
                $subs = (($subs == 'true') || ($subs == 't') || ($subs == '1') || ($subs == 'yes') || ($subs == 'y')) ? intval(true) : intval(false);
                $turbo = (($turbo == 'true') || ($turbo == 't') || ($turbo == '1') || ($turbo == 'yes') || ($turbo == 'y')) ? intval(true) : intval(false);                

                // Okay, after all is said and done, we still have an output, This means at least we have something to feed back to the channel
                $mod = $this->loadedCommands[$trigger][0];
                $function = $this->loadedCommands[$trigger][1];
                
                $this->registerCommads(array($trigger => array($mod, $function, $ops, $regs, $subs, $turbo)));
                $this->addMessageToQue("Updated command $trigger with new permissions");
            } else {
                // We are editing a user command
                $command = (isset($split[0])) ? strtolower($split[0]) : null;
                $ops = (isset($split[1])) ? $split[1] : null;
                $regs = (isset($split[2])) ? $split[2] : null;
                $subs = (isset($split[3])) ? $split[3] : null;
                $turbo = (isset($split[4])) ? $split[4] : null;
                
                for ($i = 1; $i <= 5; $i++)
                {
                    array_shift($split);
                }
                
                $output = implode(' ', $split);
                
                // Convert our vars.  Why do I accept to many possible responses?  Who knows
                $ops = (($ops == 'true') || ($ops == 't') || ($ops == '1') || ($ops == 'yes') || ($ops == 'y')) ? intval(true) : intval(false);
                $regs = (($regs == 'true') || ($regs == 't') || ($regs == '1') || ($regs == 'yes') || ($regs == 'y')) ? intval(true) : intval(false);
                $subs = (($subs == 'true') || ($subs == 't') || ($subs == '1') || ($subs == 'yes') || ($subs == 'y')) ? intval(true) : intval(false);
                $turbo = (($turbo == 'true') || ($turbo == 't') || ($turbo == '1') || ($turbo == 'yes') || ($turbo == 'y')) ? intval(true) : intval(false);
                
                if ($output != '')
                {
                    // Okay, after all is said and done, we still have an output, This means at least we have something to feed back to the channel
                    $commandArr = array('output' => $output, 'ops' => $ops, 'regulars' => $regs, 'subs' => $subs, 'turbo' => $turbo);
                    $this->editCommand($command, $commandArr);
                } else {
                    $this->addMessageToQue("Edit was unable to be performed because there were not enough parameters: " . $this->commandDelimeter . "editcom {trigger} {Ops} {Regulars} {Subs} {Turbo} {output}");
                }
            }
        }
    }
    
    private function burnbot_listcom($sender, $msg = '')
    {
        $module = strtolower($msg); // Supplied module if there is one
        $commands = '';
        $OPCommands = '';
        $regCommands = '';
        $subCommands = '';
        $turboCommands = '';
        $comArr = array();
        $OPArr = array();
        $regArr = array();
        $subArr = array();
        $turboArr = array();
        
        switch ($module)
        {
            case 'user':
            
                foreach ($this->userCommands as $trigger => $arr)
                {
                    $mod = isset($arr[0]) ? $arr[0] : '';
                    $op = isset($arr[2]) ? $arr[2] : false;
                    $reg = isset($arr[3]) ? $arr[3] : false;
                    $sub = isset($arr[4]) ? $arr[4] : false;
                    $turbo = isset($arr[5]) ? $arr[5] : false;
                                        
                    // This stops an improperly registered command from being passed into the array
                    if ($mod != $module)
                    {
                        continue;
                    }
                    
                    if ($op)
                    {
                        $OPArr[]  = $trigger;
                    } elseif ($reg) {
                        $regArr[] = $trigger;
                    } elseif ($sub) {
                        $subArr[] = $trigger;
                    } elseif ($turbo) {
                        $turboArr[] = $trigger;
                    } else {
                        $comArr[] = $trigger;
                    }
                }
                
                break;

            case !'':
            
                foreach ($this->loadedCommands as $trigger => $arr)
                {
                    $mod = isset($arr[0]) ? $arr[0] : '';
                    $op = isset($arr[2]) ? $arr[2] : false;
                    $reg = isset($arr[3]) ? $arr[3] : false;
                    $sub = isset($arr[4]) ? $arr[4] : false;
                    $turbo = isset($arr[5]) ? $arr[5] : false;
                                        
                    // This stops an improperly registered command from being passed into the array
                    if ($mod != $module)
                    {
                        continue;
                    }
                    
                    if ($op)
                    {
                        $OPArr[]  = $trigger;
                    } elseif ($reg) {
                        $regArr[] = $trigger;
                    } elseif ($sub) {
                        $subArr[] = $trigger;
                    } elseif ($turbo) {
                        $turboArr[] = $trigger;
                    } else {
                        $comArr[] = $trigger;
                    }
                }
                
                break;
            
            default:
                foreach ($this->loadedCommands + $this->userCommands as $trigger => $arr)
                {
                    $mod = isset($arr[0]) ? $arr[0] : '';
                    $op = isset($arr[2]) ? $arr[2] : false;
                    $reg = isset($arr[3]) ? $arr[3] : false;
                    $sub = isset($arr[4]) ? $arr[4] : false;
                    $turbo = isset($arr[5]) ? $arr[5] : false;
                    
                    if ($op)
                    {
                        $OPArr[]  = $trigger;
                    } elseif ($reg) {
                        $regArr[] = $trigger;
                    } elseif ($sub) {
                        $subArr[] = $trigger;
                    } elseif ($turbo) {
                        $turboArr[] = $trigger;
                    } else {
                        $comArr[] = $trigger;
                    }
                }
                
                break;
        }
        
        // Sort all arrays alphabetically
        sort($OPArr, SORT_STRING);
        sort($regArr, SORT_STRING);
        sort($subArr, SORT_STRING);
        sort($comArr, SORT_STRING);
        sort($turboArr, SORT_STRING);
        
        // Construct the strings
        foreach ($OPArr as $trigger)
        {
            $OPCommands .= "@$trigger, ";
        }
        foreach ($regArr as $trigger)
        {
            $regCommands .= "+$trigger, ";
        }
        foreach ($subArr as $trigger)
        {
            $subCommands .= "$$trigger, ";
        }
        foreach ($turboArr as $trigger)
        {
            $turboCommands .= "%$trigger, ";
        }
        foreach ($comArr as $trigger)
        {
            $commands .= "$trigger, ";
        }
        
        // We will have at least one of these, safe to use this as a key
        $commands = rtrim(rtrim($commands, ' '), ',');
        
        if (!empty($OPArr) || !empty($regArr) || !empty($subArr) || !empty($comArr))
        {
            $str = "Currently registered commands: " . rtrim("$OPCommands$regCommands$subCommands$turboCommands$commands", ', ');
            
            // Tack this onto the end if nothing was supplied module wise
            if ($module == '')
            {
                $str .= ". [You may also specify a module to list all commands for that module]";
            }
        } else {
            // Only happens if there is a module specified
            $str = "No commands currently registered to that module";
        }
        
        $this->addMessageToQue($str);
    }
    
    private function burnbot_addreg($sender, $msg = '')
    {
        // This command allows multiple users to be specified by using spaces
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
    
    private function burnbot_delreg($sender, $msg = '')
    {
        // This command allows multiple users to be specified by using spaces
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
        global $irc;
        
        $output = $this->userCommands[$trigger][6];
        
        $irc->_log_action("Adding message to que: $output");
        
        // Add the message to the que
        if ($output != '')
        {
            $this->addMessageToQue($output);
        } else {
            $this->addMessageToQue('Command was a dud');
        }
    }
    
    private function burnbot_listops($sender, $msg = '')
    {
        $operators = '';
        
        // Quickly sort the array
        ksort($this->operators);
        
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
        
        // Quickly sort the array
        ksort($this->regulars);
        
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
        
        foreach ($this->loadedModules as $module => $enabled)
        {
            if ($enabled == true)
            {
                $modules .= " $module,";
            }
        }
        
        $modules = rtrim($modules, ',');
        
        $str = "Currently loaded modules:$modules";
        $this->addMessageToQue($str);
    }
    
    private function burnbot_memusage($sender, $msg = '')
    {
        $raw = memory_get_usage();
        $mB = round(($raw / 1024.0) / 1024.0, 2);
        
        $this->addMessageToQue("Current memory usage: " . $mB . "Mb || RawBytes: $raw");
    }
    
    private function burnbot_limiters($sender, $msg = '')
    {
        $msg = strtolower($msg);
        
        // Check to see if we have a valid option
        if (($msg == 'true') || ($msg == 't') || ($msg == '1') || ($msg == 'yes') || ($msg == 'y') || ($msg == 'on'))
        {
            $this->limitSends = true;
            $this->addMessageToQue("Limiters enabled: Restricting commands to $this->TTLStack messages in $this->TTL seconds");
        } elseif (($msg == 'false') || ($msg == 'f') || ($msg == '0') || ($msg == 'no') || ($msg == 'n') || ($msg == 'off')) {
            $this->limitSends = false;
            $this->addMessageToQue("Limiters removed");                
        } else {
            $str = ($this->limitSends) ? "on" : "off";
            $this->addMessageToQue("No valid option given, limiters retained as: $str");
        }     
    }
    
    private function burnbot_module($sender, $msg = '')
    {
        global $db;
        
        $split = explode(' ', $msg);
        
        if (array_key_exists($split[0], $this->loadedModules))
        {
            $str = ($this->loadedModules[$split[0]]) ? "Module $split[0] is enabled" : "Module $split[0] is disabled";
            
            $this->addMessageToQue($str);
            
            return;
        } else {
            // We might be editing a module
            
            if (!isset($split[1]) || ($split[1] == ''))
            {
                $this->addMessageToQue("Please specify a module to change");
                return;
            }
            
            // Before we even run any of the code for disabling a module, be sure we are not trying to disable core or user
            if (($split[1] == 'core') || ($split[1] == 'user'))
            {
                $this->addMessageToQue("Module $split[1] is protected.  You may not change this module's state");
                return;
            }
            
            if (($split[0] == 'enable') || ($split[0] == 'disable'))
            {
                if ($split[0] == 'enable')
                {
                    // Run the SQL first
                    $sql = $db->sql_build_select(BURNBOT_MODULES_CONFIG, array(
                        'enabled'
                    ), array(
                        'id' => $this->sessionID,
                        'module' => $split[1],
                    ));
                    $result = $db->sql_query($sql);
                    $row = $db->sql_fetchrow($result);
                    $db->sql_freeresult($result);
                    
                    // Did we get data?
                    if (isset($row['enabled']))
                    {
                        // Update the row
                        $sql = $db->sql_build_update(BURNBOT_MODULES_CONFIG, array(
                            'enabled' => true
                        ), array(
                            'id' => $this->sessionID,
                            'module' => $split[1],                            
                        ));
                        $result = $db->sql_query($sql);
                        $db->sql_freeresult($result);
                        
                    } else {
                        // insert the new config
                        $sql = $db->sql_build_insert(BURNBOT_MODULES_CONFIG, array(
                            'enabled' => true,
                            'id' => $this->sessionID,
                            'module' => $split[1]
                        ));
                        $result = $db->sql_query($sql);
                        $db->sql_freeresult($result);
                    }
                    
                    // Enable the module
                    $this->loadedModules[$split[1]] = true;
                    $this->addMessageToQue("Module $split[1] has been enabled");
                    
                    // Reload its commands too, unfortunately this forces us to go through the init phase again
                    $this->init();
                } else {
                    $sql = $db->sql_build_select(BURNBOT_MODULES_CONFIG, array(
                        'enabled'
                    ), array(
                        'id' => $this->sessionID,
                        'module' => $split[1],
                    ));
                    $result = $db->sql_query($sql);
                    $row = $db->sql_fetchrow($result);
                    $db->sql_freeresult($result);
                    
                    // Did we get data?
                    if (isset($row['enabled']))
                    {
                        // Update the row
                        $sql = $db->sql_build_update(BURNBOT_MODULES_CONFIG, array(
                            'enabled' => false
                        ), array(
                            'id' => $this->sessionID,
                            'module' => $split[1],                            
                        ));
                        $result = $db->sql_query($sql);
                        $db->sql_freeresult($result);
                        
                    } else {
                        // insert the new config
                        $sql = $db->sql_build_insert(BURNBOT_MODULES_CONFIG, array(
                            'enabled' => false,
                            'id' => $this->sessionID,
                            'module' => $split[1]
                        ));
                        $result = $db->sql_query($sql);
                        $db->sql_freeresult($result);
                    }
                    
                    // Pass off to the unregister
                    $this->unregisterModule($split[1]);
                    $this->addMessageToQue("Module $split[1] has been disabled");
                }
                
                return;
            }
        }
        
        // If we reached here, syntax was bad
        $this->addMessageToQue("To check the status of a module, add the module name after the command trigger.  To edit the state of a module, please use the following syntax: " . $this->commandDelimeter . "module {enable/disable} {module}");
    }
    
    private function burnbot_help($sender, $msg = '')
    {
        global $twitch, $reminders;
        
        // Nothing was provided, provide the syntax
        if ($msg == '')
        {
            $this->addMessageToQue("@$sender: To get help for a module, please use the following syntax: " . $this->commandDelimeter . "help {module}.  To get help for a command, please use the following syntax: " . $this->commandDelimeter . 'help {module} {command Trigger}.');
            return;
        }
        
        $parts = explode(' ', $msg);
        $module = $parts[0];
        $trigger = isset($parts[1]) ? $parts[1] : false;
        
        // Switch through the modules
        switch ($module)
        {
            case 'user':
                $this->addMessageToQue('[Protected] This module is an extension of core and handles all user defined triggers.  This module can not be disabled');
                break;
            
            case 'core':
                if ($trigger != false)
                {
                    // Don't switch if the command is not registered
                    if (!array_key_exists($trigger, $this->loadedCommands))
                    {
                        $this->addMessageToQue('The specified command either is currently disabled or does not exist');
                        return;
                    }
                    
                    // Attempt to grab command info
                    switch ($trigger)
                    {
                        case 'addcom':
                            $this->addMessageToQue('Usage: ' . $this->commandDelimeter . 'addcom {Trigger} {Output}.  Adds a new command to the user module.  Output may be any number of characters up to 512');
                            break;
                            
                        case 'delcom':
                            $this->addMessageToQue('Usage: ' . $this->commandDelimeter . 'delcom {trigger}. Deletes a command trigger from the database and unregisters it from the bot');
                            break;
                            
                        case 'editcom':
                            $this->addMessageToQue('Usage: [module core] ' . $this->commandDelimeter . 'editcom {enable/disable} {Trigger}. Enables or disables a command outside of the user module. Usage: [module core] ' . $this->commandDelimeter . 'editcom {trigger} {Op} {Reg} {Sub} {Turbo}. Edits the permission layers of a command outside of the user module. Usage: [module user] ' . $this->commandDelimeter . 'editcom {trigger} {Op} {Regs} {Subs} {Turbo} {Output}. Edits the permission layers and output of a user command');
                            break;
                            
                        case 'addreg':
                            $this->addMessageToQue('Usage: ' . $this->commandDelimeter . 'addreg {username} {username} ...  Adds all listed usernames as regulars into the database.  All usernames are separated by spaces');
                            break;
                            
                        case 'delreg':
                            $this->addMessageToQue('Usage: ' . $this->commandDelimeter . 'delreg {username} {username} ...  Removes all listed usernames from being regulars in the database.  All usernames are separated by spaces');
                            break;
                            
                        case 'limiters':
                            $this->addMessageToQue('Usage: ' . $this->commandDelimeter . 'limiters {enable/disable}.  Enables or disables the TTL limitations on the bot.  Limits the rate at which messages can be sent');
                            break;
                            
                        case 'listops':
                            $this->addMessageToQue('Usage: ' . $this->commandDelimeter . 'listops.  Lists all currently recognized operators.');
                            break;
                            
                        case 'listreg':
                            $this->addMessageToQue('Usage: ' . $this->commandDelimeter . 'listops.  Lists all currently recognized regulars.');
                            break;
                            
                        case 'memusage':
                            $this->addMessageToQue('Usage: ' . $this->commandDelimeter . 'memusage.  Lists the current memory allocated to the bot.  Does not account for the HTTPD server');
                            break;
                            
                        case 'module':
                            $this->addMessageToQue('Usage: ' . $this->commandDelimeter . 'module {module}.  Lists the state of a module.  Usage: ' . $this->commandDelimeter . 'module {enable/disable} {module}.  Enables and re-registers all commands or disables and unregisters all commands');
                            break;
                            
                        case 'modules':
                            $this->addMessageToQue('Usage: ' . $this->commandDelimeter . 'modules.  Lists all currently enabled modules');
                            break;
                            
                        case 'nick':
                            $this->addMessageToQue('Usage: ' . $this->commandDelimeter . 'nick {nick}.  Changes the nickname of the bot');
                            break;
                            
                        case 'quit':
                            $this->addMessageToQue('Usage: ' . $this->commandDelimeter . 'quit {Override}.  Forces the bot to disconnect.  On Twitch, will only respond to the caster');
                            break;
                            
                        case 'google':
                            $this->addMessageToQue('Usage: ' . $this->commandDelimeter . 'google {query}.  Sets up a Let me Google That For You link');
                            break;
                            
                        case 'listcom':
                            $this->addMessageToQue('Usage: ' . $this->commandDelimeter . 'listcom {module}.  Lists the commands registered to a module.  If no module is specified, will list all commands');
                            break;
                            
                        case 'slap':
                            $this->addMessageToQue('Usage: ' . $this->commandDelimeter . 'slap {target} {string}.  Performs an action to slap the target with the provided string after if one is provided');
                            break;
                            
                        case 'contact':
                            $this->addMessageToQue('Usage: ' . $this->commandDelimeter . 'contact.  Displays the contact information of the current developer');
                            break;
                            
                        case 'version':
                            $this->addMessageToQue('usage: ' . $this->commandDelimeter . 'version.  Displays current version and links to the project page');
                            break;
                        
                        // The trigger isn't part of core, tell the user
                        default:
                            $this->addMessageToQue('The command specified is not part of module core');
                            break;
                    }                    
                } else {
                    // Parse out the help for the module
                    $this->addMessageToQue('[Protected] This module houses all core function of the bot.  This also include the user module.  This module can not be disabled');
                }

                break;
            
            case 'twitch':
                if (array_key_exists($module, $this->loadedModules) && $this->loadedModules[$module])
                {
                    $twitch->help($trigger);
                    break;                    
                }
            
            case 'reminders':
                if (array_key_exists($module, $this->loadedModules) && $this->loadedModules[$module])
                {
                    $reminders->help($trigger);
                    break;                    
                }
            
            default:
                $this->addMessageToQue('The specified module is not currently active or does not exist');
                break;
        }
    }
}
?>
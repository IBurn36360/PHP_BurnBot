<?php
// Was this accessed directly?  If so, exit.
if (!defined('IN_IRC'))
{
	exit;
}

// defines all Burnbot logic
class burnbot
{
    protected $readOnly = false; // This stops commands from being able to be registered EXCEPT quit
    
    protected $version = '1.0';
    protected $overrideKey = '';
    protected $topic = '';
    protected $tickLimiter = .10; // This sets the time each cycle is expected to take (in seconds).  Will be used in the sleep calculation
    protected $tickLimiterPostJoin = .02; // This updates the limiter above when we finally JOIN
    protected $tickStartTime = 0;
    protected $tickCurrentTime = 0;
    protected $lastPingTime = 0;
    protected $lastPongTime = 0;
    protected $isTwitch = false;
    protected $commandDelimeter = '!';
    protected $reconnect = true; // Default to allow reconnecting to the server
    protected $reconnectCounter = 5; // The maximum number of times a socket can be attempted to be recovered
    
    // Connection details (used in some commands and in reconnecting)
    protected $host = '';
    protected $chan = '';
    protected $nick = '';
    protected $pass = '';
    protected $port = 6667; // Default just in case EVERYTHING else fails for some horrable reason
    
    // The socket for the IRC connection
    protected $sessionID = 0;
    protected $hasAuthd = false;
    protected $hasJoined = false;
    
    // Arrays (Large storage)
    protected $blacklistedCommands = array();
    protected $loadedCommands = array();
    protected $userCommands = array();
    protected $loadedModules = array();
    protected $operators = array();
    protected $regulars = array();
    protected $subscribers = array();
    protected $turboUsers = array();
    
    // Holds all module objects
    protected $modules = null;
    
    // Twitch hostnames and IP's (True indicates that it is active)
    protected $twitchHosts = array(
        'irc.twitch.tv' => true,
        '199.9.253.199' => true,
        '199.9.250.229' => true,
        '199.9.253.210' => true,
        '199.9.250.239' => true
    );
    
    protected $knownBots = array(
        'nightbot' => false,
        'moobot' => false,
        'saucebot' => false,
        'ackbot' => false,
        'xanbot' => false
    );
    
    protected $burnbotCommands = array(
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
        'limiters'=> array('core', 'burnbot_limiters', true, false, false, false),
        'topic'   => array('core', 'burnbot_topic', false, true, false, false)
    );
    
    protected $messageQue = array();
    
    // Limits message sends
    protected $limitSends = true; // Override this later if we don't want the limiter enabled
    protected $messageTTL = array();
    protected $TTL = 31; // The number of seconds that a message is kept alive for (31 seconds to allow for the message to die on our peers end as well)
    protected $TTLStack = 20; // The limit of messages in the stack
    
    function __construct()
    {
        // grab our info from startup
        global $chan, $host, $port, $nick, $pass, $irc, $db, $readOnly;
        
        // Force the hash symbol at the start of the channel no matter what
        $this->chan = ($chan[0] == '#') ? $chan : '#' . $chan;
        $this->host = $host;
        $this->port = $port;
        $this->nick = $nick;
        $this->pass = $pass;
        $this->lastPingTime = time(); // We assume we will be getting a ping on AUTH, so this is only here for a second at max
        $this->readOnly = $readOnly; // Sets readonly state
        
        // Init the object for the modules
        $this->modules = new stdClass;
        
        // Twitch specific initialization
        if (array_key_exists($host, $this->twitchHosts))
        {
            $this->isTwitch = true;
            
            // Force lower case on all Twitch channels so we don't join an invalid channel
            $this->chan = strtolower($this->chan);   
                     
            // Add the channel owner as an OP before we join since they are always OP no matter what
            $this->operators[trim($this->chan, '#')] = null;
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
        
        // Include chan in here so any 2 instances of the bot that are started at the same time can not have the same key (assuming different channels)
        $this->overrideKey = md5($this->version . time() . rand(0, 1000000) . $this->chan);
        
        $irc->_log_action('Setting Session ID: ' . $this->sessionID);
        $irc->_log_action('Quit Override Key: ' . $this->overrideKey);
        
        // Register the base modules
        $this->registerModule(array(
            'core' => array('enabled' => true, 'class' => 'burnbot'), 
            'user' => array('enabled' => true, 'class' => 'user')));
        
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
        exit;
    }
    
    private function timeoutPeer()
    {
      global $socket, $irc;
        
        $irc->_write($socket, 'QUIT :Ping timeout (250 seconds)');
        
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
        global $db;
        
        // Load all module objects
        foreach ($this->loadedModules as $module => $arr)
        {
            // Module is loaded already
            if (($module == 'user') || ($module == 'core') || property_exists($this->modules, $module))
            {
                continue;
            }
            
            // We have an external module to load and keep
            $this->modules->{$module} = new $arr['class'](false);
        }
        
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
                $this->pass = $this->modules->twitch->chat_generateToken($token, $code);
                
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
                $this->pass = $this->modules->twitch->chat_generateToken(null, $code);
                
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
            unset($commands['nick'], $commands['limiters'], $commands['topic']);
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
                  $commands['listcom'],
                  $commands['topic']
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
                if (array_key_exists($row['module'], $this->loadedModules))
                {
                    $enabled = ($row['enabled'] == 1) ? true : false;
                    $class = $this->loadedModules[$row['module']]['class'];
                    
                    $this->loadedModules[$row['module']] = array('enabled' => $enabled, 'class' => $class);                    
                }
            }
        }
        
        // Go through the init phases of every registered module
        foreach ($this->loadedModules as $module => $arr)
        {
            // is this module actually enabled at this point in time?  If not, skip their init phase, also do not init if in read only
            if (($arr['enabled'] == true) && !$this->readOnly)
            {
                // Run every module's init code if they have any to run.  All commands need to be registered here
                switch($module)
                {
                    // We do not run init code for anything in core
                    case 'core':
                    case 'user':
                        break;
                    
                    default:
                        // Only attempt to run init code on modules we have registered
                        if (array_key_exists($module, $this->loadedModules))
                        {
                            $this->modules->{$module}->init();
                        }
                        
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
        global $db, $irc;
        
        // This does not matter in read-only
        if ($this->readOnly)
        {
            return;
        }
        
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
                // Are we editing the command?
                if (isset($row['enabled']) && ($row['enabled'] == 1))
                {
                    $mod = $this->loadedCommands[$row['_trigger']][0];
                    $func = $this->loadedCommands[$row['_trigger']][1];
                    $ops = ($row['ops'] == 1) ? true : false;
                    $regs = ($row['regs'] == 1) ? true : false;
                    $subs = ($row['subs'] == 1) ? true : false;
                    $turbo = ($row['turbo'] == 1) ? true : false;
                    
                    $this->registerCommads(array($row['_trigger'] => array($mod, $func, $ops, $regs, $subs, $turbo)));
                    $trigger = $row['_trigger'];
                    
                    // Construct our log string
                    $str = "Loaded configuration for trigger [$trigger] ";
                    $str .= ($ops)   ? '{Ops}(true) '  : '{Ops}(false) ';
                    $str .= ($regs)  ? '{regs}(true) ' : '{regs}(false) ';
                    $str .= ($subs)  ? '{Subs}(true) ' : '{Subs}(false) ';
                    $str .= ($turbo) ? '{Turbo}(true)' : '{Turbo}(false)';
                    $irc->_log_action($str);
                } else {
                    unset($this->loadedCommands[$row['_trigger']]);
                    
                    // Log that the command was disabled
                    $trigger = $row['_trigger'];
                    $irc->_log_action("Loaded configuration for trigger [$trigger] (Disabled)");
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
    
    // Accessors to get protected data
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
    public function getNick()
    {
        return $this->nick;
    }
    public function getHasJoined()
    {
        return $this->hasJoined;
    }
    
    // Layer array accessors
    public function getReg()
    {
        return $this->regulars;
    }
    public function getSubs()
    {
        return $this->subscribers;
    }
    public function getTurbo()
    {
        return $this->turboUsers;
    }
    public function getOperators()
    {
        return $this->operators;
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
            $this->loadedModules[$module] = array('enabled' => false, 'class' => $this->loadedModules[$module]['class']);
        }
    }
    
    private function checkBots()
    {
        $botPresent = false;
        
        foreach ($this->knownBots as $bot => $found)
        {
            if ($found)
            {
                $botPresent = true;
            }
        }
        
        if (!$botPresent)
        {
            $this->commandDelimeter = '!';
            $this->addMessageToQue("All recognized bots disconnected, command delimeter changed from ~ to !");
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
        global $db;
        
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
        global $db;
        
        $trigger = trim($trigger, $this->commandDelimeter);
        
        if (array_key_exists($trigger, $this->userCommands))
        {
            $this->addMessageToQue("Command $trigger already exists, please edit the command instead");
            return;
        }
        
        if ($trigger == '' || (preg_match('[\w]i', $trigger) == 0))
        {
            $this->addMessageToQue("Please specify a command trigger");
            return;
        }
        
        if ($output == '')
        {
            $this->addMessageToQue("Please specify a command output");
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
        
        if (!array_key_exists($trigger, $this->userCommands))
        {
            $this->addMessageToQue("Command $trigger is not registered as a user command or does not exist");
            return;
        }
        
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
        global $db;
        
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
        global $db;
        
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
                $this->operators = array_merge($this->operators, array($user => null));
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
                    $this->regulars = array_merge($this->regulars, array($user => null));
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
        // Don't check permission if the user is ourself.  Also do not check quit a it has its own permission set
        if (($user == $this->nick) || ($trigger == 'quit'))
        {
            return true;
        }
        
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
        global $irc, $socket, $chan;
        
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
                    if (($messageArr['message'] == '*** Checking Ident') || (preg_match('[Looking up your hostname]', $messageArr['message']) != 0))
                    {
                        $this->auth();
                    }
                }
                
                // QUIT
                if (isset($messageArr['isQuit']))
                {
                    // Blind unset our arrays so that people don't stay in arrays when not in the channel
                    $nick = $messageArr['nick'];
                    $irc->_log_action("Removing $nick from all permission layers");
                    
                    // Regulars is included here since QUIT only happens off of Twitch
                    unset($this->operators[$nick], $this->subscribers[$nick], $this->turboUsers[$nick], $this->regulars[$nick]);
                }
                
                // TOPIC
                if (isset($messageArr['isTopic']))
                {
                    $this->topic = $messageArr['topic'];
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
                        $this->operators = array_merge($this->operators, array($newNick => null));
                        $irc->_log_action("Operator for $oldNick has been treansfered to $newNick");
                    }
                    
                    if (array_key_exists($messageArr['oldNick'], $this->regulars))
                    {
                        // For logging
                        $oldNick = $messageArr['oldNick'];
                        $newNick = $messageArr['newNick'];
                        
                        unset($this->regulars[$oldNick]);
                        $this->regulars = array_merge($this->regulars, array($newNick => null));
                        $irc->_log_action("Operator for $oldNick has been treansfered to $newNick");
                    }
                }
                
                // MODE
                if (isset($messageArr['mode']))
                {
                    $mode = $messageArr['mode'];
                    $user = $messageArr['nick'];
                    
                    // Add OP
                    if ($mode == '+o')
                    {
                        if (array_key_exists($user, $this->knownBots) && ($this->commandDelimeter == '!'))
                        {
                            $alt = '~';
                            $this->addMessageToQue("Chat bot detected, command delimeter changed from $this->commandDelimeter to $alt");
                            $irc->_log_action("Chat bot detected, command delimeter changed from $this->commandDelimeter to $alt");
                            $this->commandDelimeter = $alt;
                            
                            $this->knownBots[$user] = true;
                        }
                        
                        // Don't add the op if they already exist in the array
                        if (!array_key_exists($user, $this->operators))
                        {
                            $this->operators = array_merge($this->operators, array($user => null));
                            $irc->_log_action("Adding $user as an OP");   
                        }
                    }
                    
                    // Remove OP
                    if ($mode == '-o')
                    {
                        unset($this->operators[$messageArr['user']]);
                        $irc->_log_action("Removing $user from OP");
                        
                        if (array_key_exists($user, $this->knownBots))
                        {
                            $this->knownBots[$user] = false;
                            $this->checkBots();
                        }
                    }
                    
                    // Voice (Not on Twitch)
                    if (!$this->isTwitch)
                    {
                        // Add a regular
                        if ($mode == '+v' && (!array_key_exists($user, $this->regulars)))
                        {
                            $this->regulars = array_merge($this->regulars, array($user => null));
                            $irc->_log_action("Adding $user as a regular");
                        }
                        
                        // Remove a regular
                        if ($mode == '-v')
                        {
                            unset($this->regulars[$user]);
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
                            $irc->_log_action("Removing $nick from all permission layers");
                            unset($this->operators[$nick], $this->subscribers[$nick], $this->turboUsers[$nick]);
                            
                            // Check for bots
                            if (array_key_exists($messageArr['nick'], $this->knownBots))
                            {
                                $this->knownBots[$messageArr['nick']] = false;
                                $this->checkBots();
                            }
                            
                            // Lastly, if we are not on Twitch, remove the nick from regular as well
                            if (!$this->isTwitch)
                            {
                                unset($this->regulars[$nick]);
                            }
                        }
                        
                        // Allow modules to see this if not on twitch!
                        if (!$this->isTwitch)
                        {
                            foreach ($this->loadedModules as $module => $arr)
                            {
                                // Skip these
                                if (($module == 'user') || ($module == 'core'))
                                {
                                    continue;
                                }
                                
                                // Do not pass the message on if the module is disabled
                                if ($arr['enabled'])
                                {
                                    $this->modules->{$module}->_read($messageArr);
                                }
                            }
                        }
                        
                        $message = $irc->_read($socket);
                        $messageArr = $irc->checkRawMessage($message);
                    }
                    
                    // At this point, we need to make sure the last message is actually processed
                    $this->_read($message);
                    return; // !IMPORTANT, Stop ANY other checks in read for this check.  The recursive call to this function will handle the message.
                }
                
                // We have an error to handle
                if (isset($messageArr['isError']))
                {
                    // Check what error
                    
                    // Link closed
                    if ($messageArr['detail'] == 'link_closed')
                    {
                        // Exit out, thiss will not be seen by any module
                        $this->exitHandler();
                    }
                }
                
                // We have a numbered service ID instead at this point.  We only handle a few of these and will drop the rest
                if (isset($messageArr['service_id']))
                {
                    // Grab the channel topic (Used for !topic)
                    if ($messageArr['service_id'] == '332')
                    {
                        $this->topic = $messageArr['message'];
                    }
                    
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
                            $irc->_write($socket, "TWITCHCLIENT 1");
                            $this->hasJoined = true;
                            
                            // Lastly, change our read limiter to where it should be
                            $this->tickLimiter = $this->tickLimiterPostJoin;
                        }
                        
                        // Do NOT pass this message or set of messages to any modules
                        return;
                    }
                    
                    // Was our nick already in use?
                    if ($messageArr['service_id'] == '433')
                    {
                        $this->nick = $this->nick . '_';
                        
                        // Once our nick has been changed, reauth to the server
                        $this->hasAuthd = false;
                        $this->auth();
                    }
                    
                    // We have a default mode, now we may JOIN
                    if (($messageArr['service_id'] == '221') && !$this->hasJoined)
                    {
                        $irc->_joinChannel($socket, $chan);
                        $this->hasJoined = true;
                        
                        // Lastly, change our read limiter to where it should be
                        $this->tickLimiter = $this->tickLimiterPostJoin;
                    }
                    
                    // In ircd, we join off of the 266
                    if ($messageArr['service_id'] == '266')
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
                    }
                    
                    if ($messageArr['service_id'] == '432')
                    {
                        $this->nick = $messageArr['oldNick'];
                        $msg = ($messageArr['newNick'] != '') ? 'Nickname ' . $messageArr['newNick'] . " was rejected by the server.  Retaining nick $this->nick" : "Nickname was rejected by the server.  Retaining nick $this->nick";
                        $this->addMessageToQue($msg);
                    }
                }
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
                    // Stop a query from being able to run commands
                    if ($messageArr['chan'] != $this->chan)
                    {
                        return;
                    }
                    
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
                                    $this->runCommand($sender, $trigger, $msg, $info[0], $info[1]);
                                }
                                
                                break; // We are done here, no need to continue
                            }
                        }
                    }
                }
                
                // Do this AFTER a command so we don't have commands skipped
                if (array_key_exists($messageArr['nick'], $this->knownBots) && ($this->commandDelimeter == '!'))
                {
                    $alt = '~';
                    $this->addMessageToQue("Chat bot detected, command delimeter changed from $this->commandDelimeter to $alt");
                    $irc->_log_action("Chat bot detected, command delimeter changed from $this->commandDelimeter to $alt");
                    $this->commandDelimeter = $alt;
                    
                    $this->knownBots[$messageArr['nick']] = true;
                }
            } elseif ($messageArr['type'] == 'twitch_message') {
                // TWITCHCLIENT message, handle
                
                // SpecialUser, used for Admin, Subscriber, Staff and Turbo,
                if ($messageArr['command'] == 'SPECIALUSER')
                {
                    $nick = $messageArr['nick'];
                    
                    // Add user to Turbo Arr
                    if (($messageArr['value'] == 'turbo') && !array_key_exists($nick, $this->turboUsers))
                    {
                        $irc->_log_action("Adding $nick as a turbo user");
                        $this->turboUsers = array_merge($this->turboUsers, array($nick => true));
                    }
                    
                    // Add user to Subscriber Arr
                    if (($messageArr['value'] == 'subscriber') && !array_key_exists($nick, $this->subscribers))
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
            
            // Allow any module to see the message, This does not include JOIN and PART messages on Twitch and MOTD
            foreach ($this->loadedModules as $module => $arr)
            {
                // Skip these
                if (($module == 'user') || ($module == 'core'))
                {
                    continue;
                }
                
                // Do not pass the message on if the module is disabled
                if ($arr['enabled'])
                {
                    $this->modules->{$module}->_read($messageArr);
                }
            }
            
            // We have handled the message, STOP anything else from seeing it
            return;
        }
        
        // If we reached here the message wasn't handled.  We aren't going to return anything here because the read function runs in a loop
        // and a return is unneeded calculations.  Still good to note that anything that wasn't handled is dropped entirely though.
    }
    
    // Pulled out of the read function to allow modules to run commands without a permission check
    public function runCommand($sender, $trigger, $msg, $module = null, $function = null)
    {
        // This only happens if the command is run outside of the read cycle
        if (($module == null) && ($function == null))
        {
            // Check the registered module and core commands
            if (array_key_exists($trigger, $this->loadedCommands))
            {
                $module = $this->loadedCommands[$trigger][0];
                $function = $this->loadedCommands[$trigger][1];
            }
            
            // Check the registered user commands
            if (array_key_exists($trigger, $this->userCommands))
            {
                $module = $this->userCommands[$trigger][0];
                $function = $this->userCommands[$trigger][1];
            }
        }
        
        // Check to make sure nothing will crash should we not be provided enough data
        if (($module == null) || ($function == null))
        {
            // Log the error and leave, if we meet this check, the function was run improperly
            $irc->_log_error("Command $trigger was requested be run but is either not registered or not enough data was provided");
            return;
        }
        
        switch ($module)
        {
            // CHECK THIS FIRST
            // We don't add anthing to info, so this case WILL break later checks
            case 'user':
                // Run this command in the burnbot module.  Specifically the user defined command handler
                $this->burnbot_userCommand($sender, $trigger);
                
                break;
                
            case 'core':
                $this->{$function}($sender, $msg);
            
                break;
            
            default:
                if (array_key_exists($module, $this->loadedModules))
                {
                    $this->modules->{$module}->{$function}($sender, $msg);
                } else {
                    // The command isn't defined properly, drop an error into the log and pass out to the chat as well
                    $err = "Error attempting to run command $trigger.  Module does not appear to be loaded!";
                    $irc->_log_error($err);
                    $this->addMessageToQue($err);
                }
                
                break;
        }
    }

    public function addMessageToQue($message, $args = array(), $time = 0)
    {
        if ($time == 0)
        {
            // Set time to the current timestamp, we can send these immediately
            $time = time();
        }
        
        // Check to see if the message is too long.  We will go with 400 chars here as a safety net
        if (strlen($message) >= 425)
        {
            // Split the message up into 400 char or less chunks and add them to the que independently
            $wrapped = wordwrap($message, 400, "\n");
            $chunks = explode("\n", $wrapped);
            
            // Send the chunks
            foreach ($chunks as $chunk)
            {
                $this->addMessageToQue($chunk, $args, $time);
                
                $time += 2;
            }
            
            // Make sure we don't try to add the message again
            return;
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
        // First get the time that this cycle started
        $this->tickStartTime = microtime(true);
        $ping = time();
        
        // For Twitch in particular, we AUTH before anything
        if (!$this->hasAuthd && $this->isTwitch)
        {
            $this->auth();
        }
        
        $this->_read();
        
        foreach($this->loadedModules as $module => $arr)
        {
            if (($module == 'core') || ($module == 'user'))
            {
                continue;
            }
            
            $this->modules->{$module}->tick();
        }
        
        $this->processTTL();
        $this->processQue();
        
        // Are we going to time our peer out at this point?
        if ((($this->lastPongTime + 250) <= $ping) && ($this->lastPongTime !== 0))
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
        
        // update our global
        $this->nick = $nick;

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
        global $db;
        
        $split = explode(' ', strtolower($msg));
        
        if (($split[0] == 'enable') || ($split[0] == 'disable'))
        {
            $state = $split[0];
            $trigger = (isset($split[1])) ? $split[1] : false ;
            
            if (!array_key_exists($trigger, $this->loadedCommands))
            {
                $this->addMessageToQue("Command trigger $trigger is not currently registered.  Please register the command trigger to edit it");
                return;
            }
            
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

                // Convert our vars.  Why do I accept to many possible responses?  Who knows
                $ops = (($ops == 'true') || ($ops == 't') || ($ops == '1') || ($ops == 'yes') || ($ops == 'y')) ? intval(true) : intval(false);
                $regs = (($regs == 'true') || ($regs == 't') || ($regs == '1') || ($regs == 'yes') || ($regs == 'y')) ? intval(true) : intval(false);
                $subs = (($subs == 'true') || ($subs == 't') || ($subs == '1') || ($subs == 'yes') || ($subs == 'y')) ? intval(true) : intval(false);
                $turbo = (($turbo == 'true') || ($turbo == 't') || ($turbo == '1') || ($turbo == 'yes') || ($turbo == 'y')) ? intval(true) : intval(false);
                $mod = $this->loadedCommands[$trigger][0];
                $function = $this->loadedCommands[$trigger][1];
                
                // Update the database
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
                        'ops' => $ops,
                        'regs' => $regs,
                        'subs' => $subs,
                        'turbo' => $turbo
                    ));
                    $result = $db->sql_query($sql);
                    $db->sql_freeresult($result);
                } else {
                    // Data is present, update it instead
                    $sql = $db->sql_build_update(BURNBOT_COMMANDS_CONFIG, array(
                        'ops' => $ops,
                        'regs' => $regs,
                        'subs' => $subs,
                        'turbo' => $turbo
                    ), array(
                        'id' => $this->sessionID,
                        '_trigger' => $trigger
                    ));
                    $result = $db->sql_query($sql);
                    $db->sql_freeresult($result);
                }
                
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
                    $commandArr = array('output' => $output, 'ops' => $ops, 'regulars' => $regs, 'subs' => $subs, 'turbo' => $turbo);
                    $this->editCommand($command, $commandArr);
                } else {
                    $commandArr = array('output' => $this->userCommands[$command][6], 'ops' => $ops, 'regulars' => $regs, 'subs' => $subs, 'turbo' => $turbo);
                    $this->editCommand($command, $commandArr);
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
                    
                    $permAssigned = false;
                    
                    if ($op)
                    {
                        $OPArr[]  = $trigger;
                        $permAssigned = true;
                    }
                    if ($reg) 
                    {
                        $regArr[] = $trigger;
                        $permAssigned = true;
                    }
                    if ($sub) 
                    {
                        $subArr[] = $trigger;
                        $permAssigned = true;
                    }
                    if ($turbo) 
                    {
                        $turboArr[] = $trigger;
                        $permAssigned = true;
                    } 
                    if (!$permAssigned)
                    {
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
                    
                    $permAssigned = false;
                    
                    if ($op)
                    {
                        $OPArr[]  = $trigger;
                        $permAssigned = true;
                    }
                    if ($reg) 
                    {
                        $regArr[] = $trigger;
                        $permAssigned = true;
                    }
                    if ($sub) 
                    {
                        $subArr[] = $trigger;
                        $permAssigned = true;
                    }
                    if ($turbo) 
                    {
                        $turboArr[] = $trigger;
                        $permAssigned = true;
                    } 
                    if (!$permAssigned)
                    {
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
                    
                    $permAssigned = false;
                    
                    if ($op)
                    {
                        $OPArr[]  = $trigger;
                        $permAssigned = true;
                    }
                    if ($reg) 
                    {
                        $regArr[] = $trigger;
                        $permAssigned = true;
                    }
                    if ($sub) 
                    {
                        $subArr[] = $trigger;
                        $permAssigned = true;
                    }
                    if ($turbo) 
                    {
                        $turboArr[] = $trigger;
                        $permAssigned = true;
                    } 
                    if (!$permAssigned)
                    {
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
            $OPCommands .= "$trigger, ";
        }
        foreach ($regArr as $trigger)
        {
            $regCommands .= "$trigger, ";
        }
        foreach ($subArr as $trigger)
        {
            $subCommands .= "$trigger, ";
        }
        foreach ($turboArr as $trigger)
        {
            $turboCommands .= "$trigger, ";
        }
        foreach ($comArr as $trigger)
        {
            $commands .= "$trigger, ";
        }
        
        if (!empty($OPArr) || !empty($regArr) || !empty($subArr) || !empty($comArr))
        {
            $op = ($OPCommands != '') ? "Operator[" . rtrim("$OPCommands", ', ') . ']' : false;
            $sub = ($subCommands != '') ?  "Subscriber[" . rtrim("$subCommands", ', ') . ']' : false;
            $turbo = ($turboCommands != '') ? "Turbo[" . rtrim("$turboCommands", ', ') . ']' : false;
            $reg = ($regCommands != '') ?  "Regular[" . rtrim("$regCommands", ', ') . ']' : false;
            $user = ($commands != '') ?  "User[" . rtrim("$commands", ', ') . ']' : false;
            
            // Tack this onto the end if nothing was supplied module wise
            if ($module == '')
            {
                $user .= ". You may also specify a module to list all commands for that module";
            }
            
            $time = time();
            
            if ($OPCommands != '') {$this->addMessageToQue($op, array(), $time); $time += 2;}
            if ($regCommands != '') {$this->addMessageToQue($reg, array(), $time); $time += 2;}
            if ($subCommands != '') {$this->addMessageToQue($sub, array(), $time); $time += 2;}
            if ($turboCommands != '') {$this->addMessageToQue($turbo, array(), $time); $time += 2;}
            if ($commands != '') {$this->addMessageToQue($user, array(), $time);}
            
            return;
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
            $regulars .= "$regular, ";
        }
        
        $reg = 'Regulars: ' . rtrim($regulars, ', ');
        
        $this->addMessageToQue($reg);
    }
    
    private function burnbot_loadedModules($sender, $msg = '')
    {
        $modules = '';
        $disabled = '';
        
        foreach ($this->loadedModules as $module => $arr)
        {
            if ($arr['enabled'] == true)
            {
                $modules .= " $module,";
            } else {
                $disabled .= " $module,";
            }
        }
        
        $modules = trim(rtrim($modules, ','), ' ');
        $disabled = trim(rtrim($disabled, ','), ' ');
        
        $str = "Currently loaded modules:[$modules]. ";
        $str = ($disabled != '') ? $str . "Disabled Modules:[$disabled]" : $str;
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
            $str = ($this->loadedModules[$split[0]]['enabled']) ? "Module $split[0] is enabled" : "Module $split[0] is disabled";
            
            $this->addMessageToQue($str);
            
            return;
        } else {
            // We might be editing a module
            
            if (!isset($split[1]) || ($split[1] == ''))
            {
                $this->addMessageToQue("Please specify a module to change");
                return;
            }
            
            $module = $split[1];
            
            // Before we even run any of the code for disabling a module, be sure we are not trying to disable core or user
            if (($module == 'core') || ($module == 'user'))
            {
                $this->addMessageToQue("Module $split[1] is protected.  You may not change this module's state");
                return;
            }
            
            if (($split[0] == 'enable') || ($split[0] == 'disable'))
            {
                if ($split[0] == 'enable')
                {
                    if (!array_key_exists($module, $this->loadedModules))
                    {
                        $this->addMessageToQue("The module $module does not exist!");
                        return;
                    }
                    
                    // Run the SQL first
                    $sql = $db->sql_build_select(BURNBOT_MODULES_CONFIG, array(
                        'enabled'
                    ), array(
                        'id' => $this->sessionID,
                        'module' => $module,
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
                            'module' => $module,                            
                        ));
                        $result = $db->sql_query($sql);
                        $db->sql_freeresult($result);
                        
                    } else {
                        // insert the new config
                        $sql = $db->sql_build_insert(BURNBOT_MODULES_CONFIG, array(
                            'enabled' => true,
                            'id' => $this->sessionID,
                            'module' => $module
                        ));
                        $result = $db->sql_query($sql);
                        $db->sql_freeresult($result);
                    }
                    
                    $this->loadedModules[$module] = array('enabled' => true, 'class' => $this->loadedModules[$module]['class']);
                    $this->addMessageToQue("Module $module has been enabled");
                    
                    // Reload its commands too, unfortunately this forces us to go through the init phase again
                    $this->init();
                } else {
                    if (!array_key_exists($split[1], $this->loadedModules))
                    {
                        $this->addMessageToQue("The module $split[1] does not exist!");
                        return;
                    }
                    
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
    
    private function burnbot_topic($sender, $msg = '')
    {
        $this->addMessageToQue($this->topic);
    }
    
    private function burnbot_help($sender, $msg = '')
    {
        // Nothing was provided, provide the syntax
        if ($msg == '')
        {
            $time = time();
            
            $this->addMessageToQue("@$sender: To get help for a module, please use the following syntax: " . $this->commandDelimeter . "help {module}.  To get help for a command, please use the following syntax: " . $this->commandDelimeter . 'help {module} {command Trigger}.', array(), $time);
            $this->addMessageToQue('Usage: ' . $this->commandDelimeter . 'listcom {module}.  Lists the commands registered to a module.  If no module is specified, will list all commands', array(), $time + 2);
            $this->addMessageToQue('Usage: ' . $this->commandDelimeter . 'modules.  Lists all currently enabled modules', array(), $time + 4);
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
                            $time = time();
                        
                            $this->addMessageToQue('Usage: [module core] ' . $this->commandDelimeter . 'editcom {enable/disable} {Trigger}. Enables or disables a command outside of the user module. Usage: [module core] ' . $this->commandDelimeter . 'editcom {trigger} {Op} {Reg} {Sub} {Turbo}. Edits the permission layers of a command outside of the user module.', array(), $time);
                            $this->addMessageToQue('Usage: [module user] ' . $this->commandDelimeter . 'editcom {trigger} {Op} {Reg} {Sub} {Turbo} {Output}. Edits the permission layers of a user command.  Can have a new output added as well, overrides the output completely.', array(), $time + 2);
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
                            $this->addMessageToQue('Usage: ' . $this->commandDelimeter . 'quit {Override}.  Forces the bot to disconnect.  On Twitch, will only respond to the caster, or to anyone who has access to the override key');
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
            
            default:
                if (array_key_exists($module, $this->loadedModules) && $this->loadedModules[$module]['enabled'])
                {
                    $this->modules->{$module}->help($trigger);
                } else {
                    $this->addMessageToQue('The specified module is not currently active or does not exist');
                }
                
                break;
        }
    }
}
?>
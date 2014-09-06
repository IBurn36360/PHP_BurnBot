<?php
// Was this accessed directly?  If so, exit.
if (!defined('IN_PHPBURNBOT'))
{
	exit;
}

/**
 * This is the core of Burnbot.
 * 
 * This class contains all logic and handling for all functions of the bot, including managing the peer connection,
 * decyphering data and processing requests from both the channel and modules.
 * 
 * THIS IS A FINAL CLASS AND CAN NOT BE EXTENDED
 */
final class burnbot
{
    // Core identification
    protected $version = '2.0';
    protected $name = 'core';
    protected $author = 'Anthony \'IBurn36360\' Diaz';
    protected $moduleDescription = 'The core functionality and base of the bot';
    protected $dependencies = array('irc', 'logger', 'db');
    
    // Core vars [Construction]
    protected $host;
    protected $port;
    protected $chan;
    protected $chanName;
    protected $nick;
    protected $pass;
    protected $preJoin;
    protected $postJoin;
    protected $readOnly;
    
    // Reference Objects
    protected $db;
    protected $irc;
    protected $logger;
    
    // Static variables
    protected $tickLimiterConnect   = .1;
    protected $tickLimiterPostAuth  = .01;
    protected $tickLimiterLimitless = 0;
    protected $reconnectCounter     = 5;
    protected $loggingLevel         = 504;
    
    // Changing bools
    protected $reconnect          = true;
    protected $hasAuthd           = false;
    protected $hasJoined          = false;
    protected $getLastSocketError = true;
    
    // Records
    protected $commandDelimeter      = '!';
    protected $overrideKey           = '';
    protected $topic                 = 'Topic message was not send on JOIN';
    protected $build                 = 185;
    protected $lastSendTime          = 0;
    protected $tickStartTime         = 0;
    protected $tickCurrentTime       = 0;
    protected $lastSentPingTime      = 0;
    protected $lastPingTime          = 0;
    protected $lastPongTime          = 0;
    protected $lastMemoryLogTime     = 0;
    protected $sessionID             = 0;
    protected $messageLimiter        = 0;
    protected $messageQueCounter     = 0;
    protected $pingTimeoutTime       = 185;
    protected $pingIntervalTime      = 60;
    protected $memoryLogTimeInterval = 300;
    protected $tickLimiter;
    
    // User layers
    protected $operators     = array();
    protected $overrideUsers = array();
    protected $regulars      = array();
    protected $userLayer1    = array();
    protected $userLayer2    = array();
    protected $userlist      = array();
    
    // Mode mask arrays
    protected $userLayer1Mask = array('v', '+');
    protected $userLayer2Mask = array('x');
    
    // User layer Names
    protected $userLayer1Name = 'Voice';
    protected $userLayer2Name = 'Hidden';
    protected $userLayerNames = array(
        'protected',
        'operator',
        'regular',
        'user'
    );
    
    // Commands
    protected $blacklistedCommands      = array();
    protected $disabledCommands         = array();
    protected $loadedCommands           = array();
    protected $loadedModules            = array('core' => array('class' => 'burnbot', 'enabled' => true));
    protected $modules                  = array();
    protected $permissionBypassCommands = array('quit', 'override');
    
    // Message Que
    protected $messageQue = array();
    protected $messageQueTypes = array('private', 'query', 'action', 'raw');
    
    // Commands
    protected $commands = array(
        // Protected commands
        'quit' => array(
            'function'     => 'core_quit',
            'module'       => 'core',
            'operator'     => false,
            'regular'      => false,
            'user_layer_1' => false,
            'user_layer_2' => false
        ),
        'override' => array(
            'function'     => 'core_override',
            'module'       => 'core',
            'operator'     => false,
            'regular'      => false,
            'user_layer_1' => false,
            'user_layer_2' => false
        ),
        
        // Operator commands
        'editcom' => array(
            'function'     => 'core_editcom',
            'module'       => 'core',
            'operator'     => true,
            'regular'      => false,
            'user_layer_1' => false,
            'user_layer_2' => false
        ),
        'module' => array(
            'function'     => 'core_module',
            'module'       => 'core',
            'operator'     => true,
            'regular'      => false,
            'user_layer_1' => false,
            'user_layer_2' => false
        ),
        'modules' => array(
            'function'     => 'core_modules',
            'module'       => 'core',
            'operator'     => true,
            'regular'      => false,
            'user_layer_1' => false,
            'user_layer_2' => false
        ),
        'enablemodule' => array(
            'function'     => 'core_enableModule',
            'module'       => 'core',
            'operator'     => true,
            'regular'      => false,
            'user_layer_1' => false,
            'user_layer_2' => false
        ),
        'disablemodule' => array(
            'function'     => 'core_disableModule',
            'module'       => 'core',
            'operator'     => true,
            'regular'      => false,
            'user_layer_1' => false,
            'user_layer_2' => false
        ),
        'nick' => array(
            'function'     => 'core_nick',
            'module'       => 'core',
            'operator'     => true,
            'regular'      => false,
            'user_layer_1' => false,
            'user_layer_2' => false
        ),
        'commanddelim' => array(
            'function'     => 'core_commandDelim',
            'module'       => 'core',
            'operator'     => true,
            'regular'      => false,
            'user_layer_1' => false,
            'user_layer_2' => false
        ),
        
        // User Layer 1 commands
        'version' => array(
            'function'     => 'core_version',
            'module'       => 'core',
            'operator'     => false,
            'regular'      => false,
            'user_layer_1' => true,
            'user_layer_2' => false
        ),
        'ping' => array(
            'function'     => 'core_ping',
            'module'       => 'core',
            'operator'     => false,
            'regular'      => false,
            'user_layer_1' => true,
            'user_layer_2' => false
        ),
        'memusage' => array(
            'function'     => 'core_memusage',
            'module'       => 'core',
            'operator'     => false,
            'regular'      => false,
            'user_layer_1' => true,
            'user_layer_2' => false
        ),
        'listcom' => array(
            'function'     => 'core_listcom',
            'module'       => 'core',
            'operator'     => false,
            'regular'      => false,
            'user_layer_1' => true,
            'user_layer_2' => false
        ),
        'help' => array(
            'function'     => 'core_help',
            'module'       => 'core',
            'operator'     => false,
            'regular'      => false,
            'user_layer_1' => true,
            'user_layer_2' => false
        ),
        'topic' => array(
            'function'     => 'core_topic',
            'module'       => 'core',
            'operator'     => false,
            'regular'      => false,
            'user_layer_1' => true,
            'user_layer_2' => false
        ),
        
        // User commands
        'source' => array(
            'function'     => 'core_source',
            'module'       => 'core',
            'operator'     => false,
            'regular'      => false,
            'user_layer_1' => false,
            'user_layer_2' => false
        )
    );
    
    protected $overridableCommands = array(
        'editcom',
        'listcom'
    );
    
    protected $acceptValues = array(
        'yes',
        'y',
        'true',
        't'
    );
    
    /**
     * Construction of the core environment
     * 
     * @param $host[string] - The host address or IP address of the peer network
     * @param $port[int] - The port we are connectin on for the peer network
     * @param $chan[string] - The channel we will be joining after connection
     * @param $nick[string] - The nickname we will attempt to register with.  Will be altered in case of conflictions
     * @param $pass[string] - The string password to supply for authentication
     * @param $preJoin[string] - A comma separated list of commands to pass to the peer before joining the channel
     * @param $postJoin[string] - A comma separated list of commands to pass to the peer after joining the channel
     * @param $readOnly[bool] - Sets the bot state into read-only, disabling the message que system after the channel is joined.  Still processes override and quit commands
     * @param $db[object] - The DB library class object
     * @param $irc[object] - The IRC library class object
     * @param $logger[object] - The logger library class object
     */
    public function __construct($host, $port, $chan, $nick, $pass, $preJoin, $postJoin, $readOnly, &$db, &$irc, &$logger)
    {
        // Init vars
        $this->host     = $host;
        $this->port     = $port;
        $this->chan     = $chan;
        $this->chanName = trim($this->chan, '#');
        $this->nick     = $nick;
        $this->pass     = $pass;
        $this->preJoin  = explode(',', $preJoin);
        $this->postJoin = explode(',', $postJoin);
        $this->readOnly = $readOnly;
        
        // Init the modules objet as well
        $this->modules = new stdClass;
        
        // Libraries
        $this->db = $db;
        $this->irc = $irc;
        $this->logger = $logger;
        
        // All remaining variable operations
        $this->userLayerNames = array_merge($this->userLayerNames, array(strtolower($this->userLayer1Name), strtolower($this->userLayer2Name)));
        
        // Set the initialized override key
        $this->overrideKey = sha1(time() . $this->host . $this->chan);
        $this->logger->logLine("Current logging level set as $this->loggingLevel", 'core');
        $this->logger->logLine("Session Starting override key set as: $this->overrideKey", 'core');
        
        // Grab the session ID
        $sql = $this->db->buildSelect(BURNBOT_CORE_CONNECTIONS, array(
            'id',
            'command_delim'
        ), array(
            'host' => $this->host,
            'channel' => $this->chan
        ));
        $result = $this->db->query($sql);
        
        if (isset($result->numRows) && $result->numRows)
        {
            $this->sessionID = $result->row['id'];
            $this->commandDelimeter = $result->row['command_delim'];
        } elseif ($result->error != '') {
            $this->logger->logError($result->error);
            
            exit("Session ID error, please check logs for the issue");
        } else {
            // Set a new session ID
            $sql = $this->db->buildInsert(BURNBOT_CORE_CONNECTIONS, array(
                'host' => $this->host,
                'channel' => $this->chan
            ));
            $result = $this->db->query($sql);
            
            $this->sessionID = $this->db->getLastId();
        }
        
        $this->logger("Session ID set as: $this->sessionID", 32, 'core');
        $this->logger("Command delimeter set as: $this->commandDelimeter", 32, 'core');
    }
    
    /**
     * Runs the init phase of the bot
     * 
     * This refreshes all command lists and reloads all modules by running their init phases after flushing all data from them
     */
    public function init()
    {
        $this->logger("Registering commands", 32, 'core');
        $this->registerCommands($this->commands);
        
        // Construct the local module objects for every module
        foreach ($this->loadedModules as $module => $arr)
        {
            if ($module != 'core')
            {
                $this->logger("Constructing module [$module]", 32, 'core');
                $this->modules->{$module} = new $arr['class'];
            }
        }
        
        // Now run the init phase of every module we have
        foreach (array_keys($this->loadedModules) as $module)
        {
            if (($module != 'core'))
            {
                // This is a required function of all modules, even ones that do not register commands
                $this->logger("Running init phase of module [$module]", 32, 'core');
                $this->modules->{$module}->init();
            }
        }
        
        // Now that everything is done and all Init phases are run, go to post-init
        $this->postInit();
    }
    
    /**
     * Runs the post-init phase of the bot
     * 
     * This overrides commands and module settings based on the configuration settings for this ID
     */
    protected function postInit()
    {
        $this->logger('Running post-init pase', 32, 'core');
        
        // This is where we will go through and apply the settings that the channel has
        // Start off with the commands
        $sql = $this->db->buildSelect(BURNBOT_CORE_COMMANDS, array(
            '_trigger',
            'operator',
            'regular',
            'user_layer_1',
            'user_layer_2',
            'enabled'
        ), array(
            'id' => $this->sessionID
        ));
        $result = $this->db->query($sql);
        
        if (isset($result->numRows) && $result->numRows)
        {
            foreach ($result->rows as $row)
            {
                if (array_key_exists($row['_trigger'], $this->loadedCommands))
                {
                    // Apply the permissions first
                    $this->loadedCommands[$row['_trigger']] = array(
                        'function'     => $this->loadedCommands[$row['_trigger']]['function'],
                        'module'       => $this->loadedCommands[$row['_trigger']]['module'],
                        'operator'     => $row['operator'],
                        'regular'      => $row['regular'],
                        'user_layer_1' => $row['user_layer_1'],
                        'user_layer_2' => $row['user_layer_2']
                    );
                    
                    // Now apply the disable state
                    if ($row['enabled'])
                    {
                        $this->disabledCommands = array_diff($this->disabledCommands, array($row['_trigger']));
                    } else {
                        $this->disabledCommands = array_merge($this->disabledCommands, array($row['_trigger']));
                    }
                    
                    // Now log
                    $this->logger('Command [' . $row['_trigger'] . '] Has been applied with settings from DB: ' . 
                        'Operator ' . (($row['operator']) ? '[true] ' : '[false] ') . 
                        'Regular ' . (($row['regular']) ? '[true] ' : '[false] ') . 
                        'User Layer 1 ' . (($row['user_layer_1']) ? '[true] ' : '[false] ') . 
                        'User Layer 2 ' . (($row['user_layer_2']) ? '[true] ' : '[false] ') . 
                        'Enabled ' . (($row['enabled']) ? '[true]' : '[false]'), 32, 'core');
                }
            }
        }
        
        // Now deal with the modules
        $sql = $this->db->buildSelect(BURNBOT_CORE_MODULES, array(
            'module',
            'enabled'
        ), array(
            'id' => $this->sessionID
        ));
        $result = $this->db->query($sql);
        
        if ($result->numRows)
        {
            foreach ($result->rows as $row)
            {
                if (array_key_exists($row['module'], $this->loadedModules))
                {
                    $this->loadedModules[$row['module']]['enabled'] = (($row['enabled'] == 1) ? true : false);
                    $this->logger('Module [' . $row['module'] . '] has been ' . (($row['enabled'] == 1) ? '[enabled]' : '[disabled]'), 32, 'core');
                } else {
                    $this->logger('Setting found for non-existant module [' . $row['module'] . '] Enabled: ' . (($row['enabled'] == 1) ? '[enabled]' : '[disabled]'), 32, 'core');
                }
            }
        }
    }
    
    // Accessors
    
    /**
     * Gets the core version
     * 
     * @return $version[string] - Core version
     */
    public function getVersion()
    {
        return $this->version;
    }
    
    /**
     * Gets the session hostname
     * 
     * @return $host[string] - Hostname for this session
     */
    public function getHost()
    {
        return $this->host;
    }
    
    /**
     * Gets the session Port
     * 
     * @return $port[int] - Port for this session
     */
    public function getPort()
    {
        return $this->port;
    }
    
    /**
     * Gets the session channel
     * 
     * @return $chan[string] - Channel for this session
     */
    public function getChan()
    {
        return $this->chan;
    }
    
    /**
     * Gets the Channel name for this session (Chan without the leading '#')
     * 
     * @return $chanName[string] - ChanName for this session
     */
    public function getChanName()
    {
        return $this->chanName;
    }
    
    /**
     * Gets the current command delimeter
     * 
     * @return $commandDelimeter[string] - Current command delimeter
     */
    public function getCommandDelimeter()
    {
        return $this->commandDelimeter;
    }
    
    /**
     * Gets the currently registered command triggers
     * 
     * @return $commandTriggers[array] - Unkeyes array of all command triggers
     */
     public function getCommandTriggers()
     {
        return array_keys($this->loadedCommands);
     }
    
    /**
     * Gets the session Nick
     * 
     * @return $nick[string] - Current nick for this session
     */
    public function getNick()
    {
        return $this->nick;
    }
    
    /**
     * Gets the last time a message was sent
     * 
     * @return $lastSendTime[int] - UNIX timestamp that the last message was sent on
     */
    public function getLastSendTime()
    {
        return $this->lastSendTime;
    }
    
    /**
     * Gets the last time a PING request was received
     * 
     * @return $lastPingTime[int] - UNIX timestamp that the last PING request was received
     */
    public function getLastPingTime()
    {
        return $this->lastPingTime;
    }
    
    /**
     * Gets the last time a PONG reply was received
     * 
     * @return $lastPongTime[int] - UNIX timestamp that the last PONG reply was received
     */
    public function getLastPongTime()
    {
        return $this->lastPongTime;
    }
    
    /**
     * Gets the session ID for the current session
     * 
     * @return $sessionID[int] - Session ID for this session
     */
    public function getSessionID()
    {
        return $this->sessionID;
    }
    
    /**
     * Gets the current list of recognized operators
     * 
     * @return $operators[array] - Current array of all operators
     */
    public function getOperators()
    {
        return $this->operators;
    }
    
    /**
     * Gets the current list of recognized Override users
     * 
     * @return $overrideUsers[array] - Current array of all Override users
     */
    public function getOverrideUsers()
    {
        return $this->overrideUsers;
    }
    
    /**
     * Gets the current list of recognized regulars
     * 
     * @return $regulars[array] - Current array of all regulars
     */
    public function getRegulars()
    {
        return $this->regulars;
    }
    
    /**
     * Gets the current list of recognized users inside of userLayer1
     * 
     * @return $userLayer1[array] - Current array of all recognized users inside of userLayer1
     */
    public function getUserLayer1()
    {
        return $this->userLayer1;
    }
    
    /**
     * Gets the current list of recognized users inside of userLayer2
     * 
     * @return $userLayer2[array] - Current array of all recognized users inside of userLayer2
     */
    public function getUserLayer2()
    {
        return $this->userLayer2;
    }
    
    /**
     * Gets the current message send limiter
     * 
     * @return $messageLimiter[int] - Current message send limit
     */
    public function getMessageLimiter()
    {
        return $this->messageLimiter;
    }
    
    /**
     * Gets the array of all current valid accept strings
     * 
     * @return $acceptValues[array] - Unkeyed array of all accept strings in lower case
     */
    public function getAcceptValues()
    {
        return $this->acceptValues;
    }
    
    /**
     * Gets the current state of having joined the channel
     * 
     * @return $hasJoined[bool] - The bool state of having joined the channel successfully
     */
    public function getHasJoined()
    {
        return $this->hasJoined();
    }
    
    /**
     * Gets the list of command triggers modules can override for their own handlers
     * 
     * @return $overridableCommands[array] - Unkeyed array of all command triggers that accept module overrides
     */
    public function getOverridableCommands()
    {
        return $this->overridableCommands;
    }
    
    // Setters
    /**
     * Sets the number of seconds required between sending messages
     * 
     * @param $limit[int] - Number of seconds between messages at minimum
     */
    public function setMessageLimiter($limit = -1)
    {
        if (is_int($limit))
        {
            $this->messageLimiter = $limit;
        }
    }
    
    // Checkers (Comparison functions that allow operations to be completed without exposing storage arrays)
    /**
     * Checks to see if a command is currently registered with core
     * 
     * @param $command[string] - Command trigger to check for
     * 
     * @return [bool] - Command is registered or false if the command does not exist or is not specified
     */
    public function commandIsRegistered($command = '')
    {
        if ($command)
        {
            return array_key_exists($command, $this->loadedCommands);
        }
        
        return false;
    }
    
    // Helpers
    /**
     * Implodes an associative array into a string, maintaining the key and value
     * On arrays or objects, will parse the type of the value instead
     * 
     * @param $arr[array] - Array data to be parsed
     * 
     * @return $str[string] - String formatted data for the array
     */
    public function smartImplode($glue = '', $arr = array())
    {
        $str = '';
        
        if (is_array($arr) && !empty($arr))
        {
            foreach ($arr as $k => $v)
            {
                $str .= (is_array($v) || is_object($v)) ? "$k=>" . gettype($v) . $glue : "$k=>$v, ";
            }
            
            $str = rtrim($str, $glue);
        }
        
        return $str;
    }
    
    // Registers
    /**
     * Registers a module with core, providing default state and module info
     * 
     * @param $modules[array] - Array of all modules being registered.  Must have the same structure as the example
     * 
     * @return [bool] - Registred or not.  False happens upon conflict or missing information
     */
    public function registerModule($modules = array())
    {
        if (is_array($modules) && !empty($modules))
        {
            foreach ($modules as $module => $data)
            {
                if (in_array($module, $this->loadedModules))
                {
                    $this->logger->logError("Module [$module] attempted to register itself while another instance of module name [$module] exists");
                    return false;
                }
                
                // Check to see if the module has all of the info we will need
                
            }
            
            // If we reach here, the module properly registered
            $this->loadedModules = array_merge($this->loadedModules, $modules);
            $this->logger('Module(s) [' . implode(', ', array_keys($modules)) . '] registered', 64, 'core');
            return true;
        }
        
        return false;
    }
    
    /**
     * Registers commands with core
     * 
     * @param $commands[array] - Array of all commands being registered along with all data supplied for the commands
     * 
     * @return [bool] - Registered or not.  False happens if the command is already registered by a module
     */
    public function registerCommands($commands = array())
    {
        if (is_array($commands) && !empty($commands))
        {
            foreach ($commands as $command => $arr)
            {
                if (array_key_exists($command, array_keys($this->loadedCommands)))
                {
                    $this->logger->logError("Command [$command] attempted to register itself while another module held the command trigger", 64, 'core');
                    return false;
                }
            }
            
            // If we reach here, the module properly registered
            $this->loadedCommands = array_merge($this->loadedCommands, $commands);
            $this->logger('Command(s) [' . implode(', ', array_keys($commands)) . '] registered', 64, 'core');
            return true;
        }
        
        return false;
    }
    
    /**
     * Registers commands to the blacklist.  Blacklisted commands can NOT be run by a module
     * 
     * @param $commands[array] - Unkeyed array of all commands to be blacklisted
     * 
     * @return [bool] - Registered or not.  False happens if any command has already been registered
     */
    public function registerBlacklistedCommands($commands = array())
    {
        if (is_array($commands) && !empty($commands))
        {
            // If we reach here, the module properly registered
            if (array_intersect($commands, $this->blacklistedCommands))
            {
                $this->logger('Command(s) [' . implode(', ', $commands) . '] Were already in blacklist', 64, 'core');
                return false;
            }
            
            $this->blacklistedCommands = array_merge($this->blacklistedCommands, $commands);
            $this->logger('Command(s) [' . implode(', ', $commands) . '] registered in blacklist', 64, 'core');
            return true;
        }
        
        return false;
    }
    
    // Unregisters
    
    /**
     * Unregisters an array of commands fom the bot.
     * 
     * @param $commands[array] - Unketed array of all command triggers to unregister
     */
    public function unregisterCommands($commands = array())
    {
        if (is_array($commands) && !empty($commands))
        {
            foreach ($commands as $command)
            {
                unset($this->loadedCommands[$command], $this->disabledCommands[$command]);
            }
            
            $this->logger('Unregistered Commands: [' . implode(', ', $commands) . ']', 64, 'core');
        }
    }
    
    // Updaters (Used to update an already existing property)
    public function updateCommand($trigger = '', $arr = array())
    {
        if ($trigger && !empty($arr))
        {
            $this->loadedCommands = array_merge($this->loadedCommands, array($trigger => $arr));
        }
    }
    
    // Logging
    
    // WHAT IS IGNORED HERE
    //  - Construction phase
    //  - Shutdown
    //  - Errors [Anything that hits the exception or error handlers]
    
    /* BITMASK (additive)
    1   => Ping          [Incoming/Outgoing]
    2   => Pong          [Incoming/Outgoing]
    4   => Memusage      [Automatic memusage logging, command ignored]
    8   => Incoming      [All]
    16  => Outgoing      [All]
    32  => Core cricual  [Includes Userlevel/permLayer assignments and changes and init phase output]
    64  => Core output   [General output on command triggers]
    128 => Core Userlist [All userlist additions/subtractions]
    256 => Module output [All module output]
    */
    
    /* General values
    Production => 0   (None except erros)
    System     => 7   (Ping/Pong/memusage)
    Light      => 24  (Incoming and Outgoing only)
    Core       => 224 (ALL)
    Module     => 256 (ALL)
    Internal   => 480 (All core and module output)
    Debugging  => 511 (ALL)
    */
    
    /**
     * This passes a logging string to the logging handler from a module
     * 
     * @param $str[string] - String line to write to the log
     * @param $module[string] - The name of the module that originated the logging attempt.  If left blank, line will not be logged
     */
    public function log($str, $module = '')
    {
        // Is the module didn't identify itself, we won't bother
        if ($module)
        {
            $this->logger($str, 256, $module);
        }
    }
    
    /**
     * Performs a logging action for the bot, checking the logging level against the level in core
     * 
     * @param $str[string] - String line to write to the log
     * @param $level[int] - Logging level to use when checking against the core logging level
     * @param $module[string] - The name of the module that originated the logging attempt
     */
    protected function logger($str, $level = 0, $module)
    {
        // Don't check if the logging level ot the log level of the message is 0
        if (($level != 0) & ($this->loggingLevel != 0))
        {
            // Compare the 2 maps
            $map1 = array_reverse(str_split(decbin($level), 1), false);
            $map2 = array_reverse(str_split(decbin($this->loggingLevel), 1), false);
            
            if (count($map1) <= count($map2))
            {
                foreach ($map1 as $k => $v)
                {
                    if (($v == 1) && ($map2[$k] == 1))
                    {
                        $this->logger->logLine($str, $module);
                        return;
                    }
                }
            } else {
                foreach ($map2 as $k => $v)
                {
                    if (($v == 1) && ($map1[$k] == 1))
                    {
                        $this->logger->logLine($str, $module);
                        return;
                    }
                }
            }
        }
    }
    
    // IRC interaction
    /**
     * Performs an RFC compliant authentication
     */
    protected function auth()
    {
        if (!$this->hasAuthd)
        {
            // AUTH
            if ($this->pass)
            {
                $this->irc->_write("PASS $this->pass");
                $this->logger("PASS $this->pass", 16, 'outgoing');
            }
            
            $this->irc->write("NICK $this->nick");
            $this->logger("NICK $this->nick", 16, 'outgoing');
            
            $this->irc->write("USER $this->nick i * BurnbotV$this->version.$this->build@$this->nick");
            $this->logger("USER $this->nick i * BurnbotV$this->version.$this->build@$this->nick", 16, 'outgoing');
        }
    }
    
    /**
     * Attempts to join a channel after passing all of the pre-join commands
     */
    protected function join()
    {
        if ($this->preJoin)
        {
            foreach ($this->preJoin as $command)
            {
                $this->irc->write($command);
                $this->logger("Sending message to peer: [$command]", 32, 'core');
            }
            
            usleep(1000000);
        }
        
        $this->logger("Joining channel $this->chan", 16, 'core');
        $this->irc->joinChannel($this->chan);
    }
    
    /**
     * Quits the current IRC session with the given reason.  This disables all reconnect code
     * 
     * @param $reason[string] - String reason to pass to the QUIT command
     */
    protected function quit($reason = 'internal shutdown')
    {
        // Leave the channel with a QUIT and hit the exit handler
        $this->logger('Sending QUIT notice to peer and shutting down', 32, 'core');
        $this->irc->write("QUIT :$reason");
        
        // Wait a second so our QUIT actually goes through.  We can actualy close the socket faster than the message can write
        usleep(1000000);
        
        $this->reconnect = false;
        $this->messageQue = array();
        $this->irc->disconnect();
        $this->getLastSocketError = false;
    }
    
    /**
     * Sends a PONG response to a peer after receiving a PING
     * 
     * @param $data[string] - String data to reply to the PING request with
     */
    protected function ping($data)
    {
        $this->lastPingTime = time();
        $this->logger("PONG :$data", 2, 'pong');
        $this->irc->write("PONG :$data");
    }
    
    /**
     * Send a PING request to a peer
     */
    protected function sendPing()
    {
        $l = time();
        $this->lastSentPingTime = $l;
        $this->logger("PING :LAG$l", 1, 'ping');
        $this->irc->write("PING :LAG$l");
    }    
    
    /**
     * Handles a PONG reply
     */
    protected function pong()
    {
        $this->lastPongTime = time();
    }
    
    /**
     * Reconnects to a peer, decrementing the counter
     */
    protected function reconnectToPeer()
    {
        // Completely empty all permission and userlist arrays (Except regulars due to the manual nature of the array)
        $this->userlist      = array();
        $this->overrideUsers = array();
        $this->operators     = array();
        $this->userLayer1    = array();
        $this->userLayer2    = array();
        
        // Some other arrays need to be reset as well
        $this->messageQue    = array();
        
        // Finally, reset state vars as well
        $this->hasAuthd  = false;
        $this->hasJoined = false;
        
        // This period is here to allow some socket connections to close.  We have had a habbit of acting too fast in the past
        usleep(15000000);
        
        $this->irc->create();
        $this->irc->setBlocking();
        
        usleep(1000000);
        
        $this->logger("Reconnecting.  Tries left: $this->reconnectCounter", 32, 'core');
        $this->irc->connect();
        $this->irc->setNonBlocking();
        $this->reconnectCounter--; 
    }
    
    /**
     * Disconnects from a peer after a PING timeout
     */
    protected function timeoutPeer()
    {
        $str = "QUIT :Ping timeout ($this->pingTimeoutTime seconds)";
        $this->logger($str, 16, 'outgoing');
        $this->irc->write($str);
        
        usleep(1000000);
        
        $this->irc->disconnect();
    }
    
    /**
     * Processes a NAMES list
     * 
     * @param $list[string] - Raw list from NAMES response
     */
    protected function names($list)
    {
        $parts = explode(' ', $list);
        
        foreach ($parts as $user)
        {
            switch ($user[0])
            {
                case '@';
                case '%':
                    $user = trim($user, $user[0]);
                    $this->logger("User(s) [$user] added into operator layer", 32, 'core');
                    $this->operators = array_merge($this->operators, array($user));
                
                    break;
                
                case '+':
                    $user = trim($user, '+');
                
                    if (in_array('+', $this->userLayer1Mask))
                    {
                        $this->addToUserLayer1(array($user));
                    } elseif (in_array('+', $this->userLayer2Mask)) {
                        $this->addToUserLayer2(array($user));
                    }
                
                    break;
                
                default:
                
                    break;
            }
            
            // After all is checked for for permissions, add them to the userlist
            $this->addToUserlist(array($user));
        }
    }
    
    /**
     * Processes a nick change of a user within our current channel, swapping all permissions to the new nick
     * 
     * @param $oldNick[string] - Old nick from the name change
     * @param $newNick[string] - New nick from the name change
     */
    protected function nickChange($oldNick, $newNick)
    {
        if ($oldNick == $this->nick)
        {
            $this->nick = $newNick;
        } else {
            // Check all of the permission layers, remove the user from them and add their new nick so triggers work properly
            $this->overrideUsers = (in_array($oldNick, $this->overrideUsers)) ? array_merge(array_diff($this->overrideUsers, array($oldNick)), array($newNick)) : $this->overrideUsers;
            $this->operators     = (in_array($oldNick, $this->operators))     ? array_merge(array_diff($this->operators, array($oldNick)), array($newNick)) : $this->operators;
            $this->regulars      = (in_array($oldNick, $this->regulars))      ? array_merge(array_diff($this->regulars, array($oldNick)), array($newNick)) : $this->regulars;
            $this->userLayer1    = (in_array($oldNick, $this->userLayer1))    ? array_merge(array_diff($this->userLayer1, array($oldNick)), array($newNick)) : $this->userLayer1;
            $this->userLayer2    = (in_array($oldNick, $this->userLayer2))    ? array_merge(array_diff($this->userLayer2, array($oldNick)), array($newNick)) : $this->userLayer2;
            $this->logger("All permission layers migrated from nick [$oldNick] to nick [$newNick]", 32, 'core');
        }
    }
    
    /**
     * Handles a cycle of reading data from the peer buffer
     * 
     * This passes all lines to the read() function of all modules
     */
    protected function read()
    {
        if (($message = $this->irc->read()) == '')
        {
            return;
        }
        
        $messageArr = $this->irc->checkRawMessage($message);
        
        if (isset($messageArr['type']))
        {
            // Start checking the message
            if ($messageArr['type'] == 'private')
            {
                // Log the raw first so we can have a better understanding within the logs
                $this->logger($messageArr['raw'], 8, 'incoming');
                
                if ($messageArr['target'] == $this->chan)
                {
                    // This is a channel PM
                    if ($messageArr['message'][0] == $this->commandDelimeter)
                    {
                        // We have a command attempt, check it
                        $args = explode(' ', $messageArr['message']);
                        $command = trim(strtolower($args[0]), $this->commandDelimeter);
                        array_shift($args);
                        
                        if ($this->checkPermission(strtolower($command), $messageArr['nick']))
                        {
                            $this->runInternalCommand($messageArr['nick'], $command, $args, $this->loadedCommands[$command]['module'], $this->loadedCommands[$command]['function']);
                        }
                    }
                    
                    if ($messageArr['target'] == $this->nick) 
                    {
                        // At this point, allow modules to see the message
                        foreach ($this->loadedModules as $module => $arr)
                        {
                            if (($module != 'core') && $arr['enabled'])
                            {
                                $this->modules->{$module}->read($messageArr);
                            }
                        }
                    }                     
                    
                    // Silently ignore this.  It is neither directed at ur nor the channel we are in
                } else {
                    // This is a query.  Only handle the override command here
                    if (stristr($messageArr['message'], '!override'))
                    {
                        // We have our override command.  We will not be allowing modules to see this
                        $args = explode(' ', $messageArr['message']);
                        $command = trim(strtolower($args[0]), $this->commandDelimeter);
                        array_shift($args);
                        
                        if ($this->loadedModules[$this->loadedCommands[$command]['module']]['enabled'] && $this->checkPermission($command, $messageArr['nick']))
                        {
                            $this->runInternalCommand($messageArr['nick'], $command, $args, $this->loadedCommands[$command]['module'], $this->loadedCommands[$command]['function']);
                        }
                        
                        return;
                    } else {
                        // At this point, allow modules to see the message
                        foreach ($this->loadedModules as $module => $arr)
                        {
                            if (($module != 'core') && $arr['enabled'])
                            {
                                $this->modules->{$module}->read($messageArr);
                            }
                        }                        
                    }
                }
            } elseif ($messageArr['type'] == 'system') {
                if (isset($messageArr['is_ping']))
                {
                    // Handle a PING request
                    $this->logger($messageArr['raw'], 1, 'ping');
                    $this->ping($messageArr['message']);
                }
                
                if (isset($messageArr['is_invite']))
                {
                    $this->logger($messageArr['raw'], 8, 'incoming');
                }
                
                if (isset($messageArr['is_pong']))
                {
                    // Handle a PONG reply
                    $this->logger($messageArr['raw'], 2, 'pong');
                    $this->pong();
                }
                
                if (isset($messageArr['is_join']))
                {
                    // Handle a JOIN event
                    $this->logger($messageArr['raw'], 8, 'incoming');
                    $this->addToUserlist(array($messageArr['nick']));
                    $this->logger('User [' . $messageArr['nick'] . '] has joined and has been added to userlist', 128, 'core');
                }
                
                if (isset($messageArr['is_part']) || isset($messageArr['is_quit']))
                {
                    $this->logger($messageArr['raw'], 8, 'incoming');
                    $this->removeFromAll(array($messageArr['nick']));
                }
                
                if (isset($messageArr['is_kick']))
                {
                    $this->logger($messageArr['raw'], 8, 'incoming');
                    $this->removeFromAll(array($messageArr['target']));
                }
                
                if (isset($messageArr['is_notice']))
                {
                    // Handle notices
                    $this->logger($messageArr['raw'], 8, 'incoming');
                    
                    if (stristr($messageArr['message'], '*** checking ident'))
                    {
                        $this->auth();
                    }
                }
                
                if (isset($messageArr['is_nick']))
                {
                    $this->logger($messageArr['raw'], 8, 'incoming');
                    $this->nickChange($messageArr['old_nick'], $messageArr['new_nick']);
                }
                
                if (isset($messageArr['is_mode']))
                {
                    $this->logger($messageArr['raw'], 8, 'incoming');
                    
                    // Handle modesets
                    foreach ($messageArr['modes'] as $mode)
                    {
                        switch ($mode['mode'])
                        {
                            // Operator
                            case '+o':
                            case '+h':
                                if (!in_array($mode['nick'], $this->operators))
                                {
                                    $this->logger('adding user(s) [' . $mode['nick'] . '] to operator layer', 32, 'core');
                                    $this->operators = array_merge($this->operators, array($mode['nick']));
                                    sort($this->operators);
                                }
                                
                                break;
                                
                            case '-h':
                            case '-o':
                                // Blind remove operator since it is faster than checking then removing
                                $this->operators = array_diff($this->operators, array($mode['nick']));
                                $this->logger('removing user(s) [' . $mode['nick'] . '] from operator layer', 32, 'core');
                                
                                break;
                                
                            default:
                                break;
                        }
                        
                        // If we reached here, try our user layers
                        if (in_array($mode['mode'][1], $this->userLayer1Mask))
                        {
                            if ($mode['mode'][0] == '+')
                            {
                                $this->addToUserLayer1(array($mode['nick']));
                            } else {
                                $this->removeFromUserLayer1(array($mode['nick']));
                            }
                        }
                        
                        if (in_array($mode['mode'][1], $this->userLayer2Mask))
                        {
                            if ($mode['mode'][0] == '+')
                            {
                                $this->addToUserLayer2(array($mode['nick']));
                            } else {
                                $this->removeFromUserLayer2(array($mode['nick']));
                            }
                        }
                    }
                }
                
                if (isset($messageArr['is_error']))
                {
                    $this->logger($messageArr['raw'], 8, 'incoming');
                    
                    // Handle the error
                    if (stristr($messageArr['message'], 'Closing Link'))
                    {
                        $this->irc->disconnect();
                        $this->getLastSocketError = false;
                        $this->logger('Remote peer closed socket connection', 32, 'core');
                    }
                }
                
                if (isset($messageArr['service_id']))
                {
                    $this->logger($messageArr['raw'], 8, 'incoming');
                    
                    // Switch the service ID and do our tasks
                    switch($messageArr['service_id'])
                    {
                        // Welcome message, move to our read/write speed and succeed authentication
                        case '001':
                            $this->tickLimiter = $this->tickLimiterLimitless;
                            $this->logger('Read/Write speed restriction removed', 32, 'core');
                            $this->hasAuthd = true;
                        
                            break;
                            
                        case '331':
                            $this->topic = 'No topic has been set for this channel';
                            $this->logger("Topic set as: $this->topic", 32, 'core');
                            $this->hasJoined = true;
                        
                            break;
                            
                        case '332':
                            $this->topic = $messageArr['message'];
                            $this->logger("Topic set as: $this->topic", 32, 'core');
                            $this->hasJoined = true;
                        
                            break;
                            
                        case '353':
                            $this->tickLimiter = $this->tickLimiterLimitless;
                            $this->names($messageArr['message']);
                        
                            break;
                            
                        case '366':
                            $this->tickLimiter = $this->tickLimiterPostAuth;
                            $this->hasJoined = true;
                            
                            break;
                            
                        // MOTD is done, bring us back to our read/write speed
                        case '376':
                            $this->tickLimiter = $this->tickLimiterPostAuth;
                            $this->logger('Read/Write speed changed to post-authentication speed', 32, 'core');
                            $this->join();
                            
                            break;
                            
                        // Nick registry and hange errors
                        case '431':
                        case '432':
                        case '433':
                        case '436':
                            if (!$this->hasAuthd)
                            {
                                // Feedback that the requested nick isn't available
                                $this->addMessageToQue("Nick was rejected by server: " . $messageArr['message']);
                            } else {
                                $this->nick .= '_';
                                $this->auth();
                            }
                        
                            break;
                        
                        default:
                        
                            break;
                    }
                }
                
                foreach ($this->loadedModules as $module => $arr)
                {
                    if (($module != 'core') && $arr['enabled'])
                    {
                        $this->modules->{$module}->read($messageArr);
                    }
                }
            } else {
                // Our message isn't decoded properly, log as an undecoded message
                $this->logger($messageArr['raw'], 8, 'undecoded message'); // This is probably the only time I will break the module last rule
            }
        }
    }
    
    // Message operations
    
    /**
     * Adds a message to the message que, splitting it if needed
     * 
     * @param $str[string] - String line to write
     * @param $type[string] - String type to apply to the message
     * @param $args[array] - Array of args to pass if the type requires them
     * @param $time[int] - Int timestamp that the mesage may be sent at, defaults to when the message was added in the que
     */
    public function addMessageToQue($str = '', $type = 'private', $args = array(), $time = 0)
    {
        if (!$str)
        {
            // Stop anything from trying to add blank messages to the que to clog it up
            return;
        }
        
        // Validate what we have
        $time = ($time) ? $time : null;
        $type = (in_array($type, $this->messageQueTypes)) ? $type : 'private';
        $args = (is_array($args)) ? $args : array();
        
        // Check to see if the message is too long.  We will go with 400 chars here as a safety net
        if (strlen($str) >= 425)
        {
            // Split the message up into 400 char or less chunks and add them to the que independently
            $wrapped = wordwrap($str, 400, "\n");
            $chunks = explode("\n", $wrapped);
            
            // Send the chunks
            foreach ($chunks as $counter => $chunk)
            {
                $this->addMessageToQue($chunk, $type, $args, ((is_null($time)) ? $time : ($time + $counter)));
            }
            
            // Make sure we don't try to add the message again
            return;
        }
        
        // If the time was not supplied, go with our counter value so we can send them all in order
        if (is_null($time))
        {
            $time = ($this->messageQueCounter += 1);
        }
        
        // Add it to the stack (Add at the begining since this will be a new )
        array_unshift($this->messageQue, array('message' => $str, 'type' => $type, 'args' => $args, 'send_time' => $time));
        
        // Now sort the que, arrange the messages by the time we can send them, then by the order we recieved them (Old to new)
        $sortArr = array();
        
        // Sort the stack
        foreach ($this->messageQue as $key => $arr)
        {
            // Make the time the value of the slave key
            $sortArr[$key] = $arr['send_time'];
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
        reset($this->messageQue);
    }
    
    /**
     * Processes the message que, passing a messge back to a target if we are allowed to
     */
    protected function processMessageQue()
    {
        if ((($time = time()) >= ($this->lastSendTime + $this->messageLimiter)) && !empty($this->messageQue))
        {
            // We can proceed to process the que, strip the message off of the top of the stack and send it to the writer
            $msgArr = array_shift($this->messageQue);
            
            // Is the message allowed to be sent?
            if ($time >= $msgArr['send_time'])
            {
                // Alright, Switch the type so we can handle the message
                switch ($msgArr['type'])
                {
                    case 'private':
                        
                        $this->logger($msgArr['message'], 16, 'outgoing');
                        $this->irc->sendPrivateMessage($msgArr['message'], $this->chan);
                        break;
                        
                    case 'action':
                    
                        $this->logger($msgArr['message'], 16, 'outgoing-action');
                        $this->irc->sendAction($msgArr['message'], $this->chan);
                        break;
                        
                    case 'query':
                        if (isset($msgArr['args']['target']))
                        {
                            $this->logger($msgArr['message'], 16, 'outgoing-pm');
                            $this->irc->sendPrivateMessage($msgArr['message'], $msgArr['args']['target']); 
                        } else {
                            $this->logger->logError('No target for query supplied for message [' . $msgArr['message'] . ']');
                        }
                        
                        break;
                        
                    case 'raw':
                        $this->logger($msgArr['message'], 16, 'outgoing-raw');
                        $this->irc->write($msgArr['message']);
                        
                        break;
                    
                    // Type isn't supported, toss an error and move on
                    default:
                    
                        $this->logger->logError('Invalid type [' . $msgArr['type'] . '] specified for message [' . $msgArr['message'] . '].  Timestamp: [' . $msgArr['send_time'] . ']. Args: [' . $this->smartImplode($msgArr['args']) . ']');
                        break;
                }
            }
            
            $this->lastSendTime = $time;
        }
    }
    
    // Command handlers
    
    /**
     * Handles a command run from inside of core (A command that passes the command check in read())
     * 
     * @param $sender[string] - The nick of the user sending the command
     * @param $trigger[string] - The command trigger that was sent
     * @param $args[array] - Array of words provided with the command
     * @param $module[string] - String module name for the command
     * @param $function[string] - String function name for the command
     */
    protected function runInternalCommand($sender, $trigger, $args, $module, $function)
    {
        if ($module == 'core')
        {
            $this->{$function}($sender, $args);
        } elseif (array_key_exists($module, $this->loadedModules) && $this->loadedModules[$module]['enabled']) {
            // Pass off to the command handler
            $this->modules->{$module}->{$function}($sender, $args, $trigger);
        }
    }
    
    /**
     * Processes a command attempt from outside of core.  This respects the command blacklist
     * 
     * @param $sender[string] - The nick of the user sending the command
     * @param $trigger[string] - The command trigger that was sent
     * @param $args[array] - Array of words provided with the command
     */
    public function runCommand($sender, $trigger, $args)
    {
        if (!in_array($trigger, $this->blacklistedCommands) && $this->checkPermission($trigger, $sender))
        {
            $this->runInternalCommand($sender, $trigger, $args, $this->loadedCommands[$trigger]['module'], $this->loadedCommands[$trigger]['function']);
        }
    }
    
    // Userlist and userlayer operations
    /**
     * Adds an array of users into the userlist
     * 
     * @param $users[array] - Unkeyed array of all nicks to be added
     */
    public function addToUserlist($users = array())
    {
        $this->userlist = array_merge($this->userlist, $users);
    }
    
    /**
     * Removes an array of users from the userlist
     * 
     * @param $users[array] - Unkeyed array of all nicks to remove
     */
    public function removeFromUserlist($users = array())
    {
        $this->userlist = array_diff($this->userlist, $users);
    }
    
    /**
     * Adds an array of users into User Layer 1
     * 
     * @param $users[array] - Unkeyed array of all nicks to be added
     */
    public function addToUserLayer1($users = array())
    {
        $this->userLayer1 = array_merge($this->userLayer1, $users);
        sort($this->userLayer1);
        $this->logger('User(s) [' . implode(', ', $users) . '] added to user layer 1', 32, 'core');
    }
    
    /**
     * Removes an array of users from User Layer 1
     * 
     * @param $users[array] - Unkeyed array of all nicks to remove
     */
    public function removeFromUserLayer1($users = array())
    {
        $this->userLayer1 = array_diff($this->userLayer1, $users);
    }
    
    /**
     * Adds an array of users into User Layer 2
     * 
     * @param $users[array] - Unkeyed array of all nicks to be added
     */
    public function addToUserLayer2($users = array())
    {
        $this->userLayer2 = array_merge($this->userLayer2, $users);
        sort($this->userLayer2);
        $this->logger('User(s) [' . implode(', ', $users) . '] added to user layer 2', 32, 'core');
    }
    
    /**
     * Removes an array of users from User Layer 2
     * 
     * @param $users[array] - Unkeyed array of all nicks to remove
     */
    public function removeFromUserLayer2($users = array())
    {
        $this->userLayer2 = array_diff($this->userLayer2, $users);
    }
    
    /**
     * Checks to see if a user has the permission to run a command
     * 
     * @param $trigger[string] - The command trigger to check against
     * @param $nick[string] - The nick to search the permission layers for
     */
    protected function checkPermission($command, $nick)
    {
        // Make sure the command is registered and is not disabled
        if (array_key_exists($command, $this->loadedCommands) && !in_array($command, $this->disabledCommands))
        {
            // Check our bypasses before anything
            if ((($nick == $this->nick) && !in_array($command, $this->blacklistedCommands)) || in_array($command, $this->permissionBypassCommands) || in_array($nick, $this->overrideUsers) || (in_array($nick, $this->operators)))
            {
                return true;
            }
            
            // Check layers now
            $commandArr = $this->loadedCommands[$command];
            if (($commandArr['regular'] && in_array($nick, $this->regulars)) || ($commandArr['user_layer_1'] && in_array($nick, $this->userLayer1)) || ($commandArr['user_layer_2'] && in_array($nick, $this->userLayer2)) || (!$commandArr['operator'] && !$commandArr['regular'] && !$commandArr['user_layer_1'] && !$commandArr['user_layer_2']))
            {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Removes an array of users from all permission layers and the userlist
     * 
     * @param $users[array] - Unkeyed array of all nicks to remove from all layers and the userlist
     */
    protected function removeFromAll($users = array())
    {
        $this->logger('Removing user(s) [' . implode(', ', $users) . '] from all layers', 32, 'core');
        
        $this->userlist      = array_diff($this->userlist, $users);
        $this->overrideUsers = array_diff($this->overrideUsers, $users);
        $this->operators     = array_diff($this->operators, $users);
        $this->userLayer1    = array_diff($this->userLayer1, $users);
        $this->userLayer2    = array_diff($this->userLayer2, $users);
    }
    
    // Primary tick handlers
    
    /**
     * Primary loop of the bot, runs all tasks while the connection is alive (Incuding all reconnects)
     * 
     * @param $start[bool] - Sets the initial socket creation.  Used ONLY when the bot is first started
     */
    public function tick($start = false)
    {
        if ($start && $this->irc->connect())
        {
            usleep(1000000);
            
            if ($this->irc->setNonBlocking())
            {
                $this->logger('Socket connected properly', 32, 'core');
            }
            
            $this->tickLimiter = $this->tickLimiterConnect;
        }  
        
        while ($this->irc->isConnected())
        {
            $this->tickStartTime = microtime(true);
            $tickTime = time();
            
            // Run ticking operations
            $this->read();
            $this->processMessageQue();
            
            // Now tick every module
            if ($this->hasAuthd && $this->hasJoined)
            {
                foreach ($this->loadedModules as $module => $arr)
                {
                    if (($module != 'core') && $arr['enabled'])
                    {
                        $this->modules->{$module}->tick();
                    }
                }
            }
            
            // Check to see if we need to send a PING to our peer
            if ($this->hasAuthd && $this->hasJoined && ($this->lastPingTime <= ($tickTime - $this->pingIntervalTime)) && ($this->lastPongTime <= ($tickTime - $this->pingIntervalTime)) && ($this->lastSentPingTime <= ($tickTime - $this->pingIntervalTime)))
            {
                $this->sendPing();
            }
            
            if ($this->hasAuthd && $this->hasJoined && ($this->lastPingTime <= ($tickTime - $this->pingTimeoutTime)) && (($this->lastSentPingTime != 0) && ($this->lastPongTime <= ($tickTime - $this->pingTimeoutTime))))
            {
                $this->timeoutPeer();
            }
            
            if ($this->hasAuthd && $this->hasJoined && ($this->lastMemoryLogTime <= ($tickTime - $this->memoryLogTimeInterval)))
            {
                $raw = memory_get_usage();
                $mB = round(($raw / 1024.0) / 1024.0, 2);
                
                $this->logger("Memory usage: [$mB" . "MB], [$raw] bytes", 4, 'core');
                $this->lastMemoryLogTime = $tickTime;
            }
            
            // If we are not connected anymore, should we attempt to reconnect?
            if ((!$this->irc->isConnected() && $this->reconnect && ($this->reconnectCounter > 0)))
            {
                $this->logger->logError($this->irc->getLastError() . ':' . $this->irc->getLastErrorStr());
                $this->reconnectToPeer();
            }
            
            // Finally, put the limiter into effect, sleep for the remainder of the tick time
            if (($this->tickStartTime + $this->tickLimiter) > ($this->tickCurrentTime = microtime(true)))
            {
                usleep((($this->tickStartTime + $this->tickLimiter) - $this->tickCurrentTime) * 1000000);
            }
        }
        
        $this->logger->logError("Socket connection closed");
        if ($this->getLastSocketError)
        {
            $this->logger->logError($this->irc->getLastError() . ':' . $this->irc->getLastErrorStr());
        }
    }
    
    // Core commands
    
    /**
     * Processes a quit request, can accept an override key to bypass permission requirements
     */
    protected function core_quit($sender, $args = array())
    {
        if ((isset($args[0]) && ($args[0] == $this->overrideKey)) || in_array($sender, $this->overrideUsers) || in_array($sender, $this->operators))
        {
            // Run the exit handler
            $this->quit('Override kill used');
        }
    }
    
    /**
     * Processes an override request, adding a nick to the override layer if the proper key is supplied and generates a fresh key
     */
    protected function core_override($sender, $args = array())
    {
        if (isset($args[0]) && ($args[0] == $this->overrideKey))
        {
            // Assign them into the override layer and regenerate the key
            $this->logger("User(s) [$sender] added as an override user", 32, 'core');
            $this->overrideUsers = array_merge($this->overrideUsers, array($sender));
            $this->overrideKey = sha1(time() . $this->host . $this->chan . rand(1, 100000));
            $this->logger->logLine("Override key used and changed to: $this->overrideKey", 'core');
            
            $this->addMessageToQue('You were successfully added as an override user.', 'query', array('target' => $sender));
        }
    }
    
    /**
     * Passes the current version, including build, of core to the channel
     */
    protected function core_version($sender, $args = array())
    {
        $this->addMessageToQue("Currently running version $this->version.$this->build");
    }
    
    /**
     * Passes the last PING and PONG times that the read handler received to the channel
     */
    protected function core_ping($sender, $args = array())
    {
        $this->addMessageToQue('Last received PING: [' . (($this->lastPingTime != 0) ? date('H:i:s T', $this->lastPingTime) : 'None received') . '].  Last received PONG: [' . (($this->lastPongTime != 0) ? date('H:i:s', $this->lastPongTime) : 'None received') . ']');
    }
    
    /**
     * Passes the current allocated memory in Bytes and MegaBytes to the channel
     */
    protected function core_memusage($sender, $args = array())
    {
        $raw = memory_get_usage();
        $mB = round(($raw / 1024.0) / 1024.0, 2);
        
        $this->addMessageToQue("Memory usage: [$mB" . "MB], [$raw bytes]");
    }
    
    /**
     * Passes the core source and all standard module sources to the channel
     */
    protected function core_source($sender, $args = array())
    {
        $this->addMessageToQue('You can get the source for this bot at https://github.com/IBurn36360/PHP_BurnBot');
    }
    
    /**
     * Processes an edit command request.  May pass off to a module handler if the module for that trigger has its own
     */
    protected function core_editcom($sender, $args = array())
    {
        // The first arg will be a trigger, use this
        $trigger = (isset($args[0])) ? $args[0] : null;
        
        // No trigger, no editcom.  Simple as that
        if (($trigger === null) || (!array_key_exists($trigger, $this->loadedCommands)))
        {
            return;
        }

        // Command is registered.  Check to see if it is a module command and if the module has their own handler
        if (($this->loadedCommands[$trigger]['module'] != 'core') && method_exists($this->modules->{$this->loadedCommands[$trigger]['module']}, $this->loadedCommands[$trigger]['module'] . '_editcom'))
        {
            $this->modules->{$this->loadedCommands[$trigger]['module']}->{strtolower($this->loadedCommands[$trigger]['module']) . '_editcom'}($sender, $args, $trigger);
            return;
        }
        
        // At this point, no handler is present or the trigger is our own.  Check to see if we can edit it and if so, do so
        if (!in_array($trigger, $this->permissionBypassCommands))
        {
            // Break down the command we were given and validate it
            $state = (stristr($args[1], 'enable') || stristr($args[1], 'disable')) ? true : false;
            
            if ($state)
            {
                // We are enabling or disabling a command.  Process this as such
                if (stristr($args[1], 'enable'))
                {
                    // Enable the command, inserting the setting if it didn't exist
                    $sql = $this->db->buildSelect(BURNBOT_CORE_COMMANDS, array(
                        1
                    ), array(
                        'id' => $this->sessionID,
                        '_trigger' => $trigger
                        
                    ));
                    $result = $this->db->query($sql);
                    
                    // Does the setting exist?
                    if ($result->numRows)
                    {
                        // update
                        $sql = $this->db->buildUpdate(BURNBOT_CORE_COMMANDS, array(
                            'operator' => $this->loadedCommands[$trigger]['operator'],
                            'regular' => $this->loadedCommands[$trigger]['regular'],
                            'user_layer_1' => $this->loadedCommands[$trigger]['user_layer_1'],
                            'user_layer_2' => $this->loadedCommands[$trigger]['user_layer_2'],
                            'enabled' => true
                        ), array(
                            'id' => $this->sessionID,
                            '_trigger' => $trigger
                            
                        ));
                        $result = $this->db->query($sql);
                        
                        // Check for success
                        if ($result)
                        {
                            // DB was updated.  Take a moment to update the loaded command array
                            $this->disabledCommands = array_diff($this->disabledCommands, array($trigger));
                            $this->addMessageToQue("Command [$trigger] has been successfully enabled.");
                        } else {
                            // Log the error and add a message to the que.  DO NOT EDIT THE COMMAND
                            $this->addMessageToQue("Command [$trigger] was unable to be enabled, please see logs for details.");
                        }
                    } else {
                        // The setting did not exist, insert
                        $sql = $this->db->buildInsert(BURNBOT_CORE_COMMANDS, array(
                            'id' => $this->sessionID,
                            '_trigger' => $trigger,
                            'operator' => $this->loadedCommands[$trigger]['operator'],
                            'regular' => $this->loadedCommands[$trigger]['regular'],
                            'user_layer_1' => $this->loadedCommands[$trigger]['user_layer_1'],
                            'user_layer_2' => $this->loadedCommands[$trigger]['user_layer_2'],
                            'enabled' => true
                            ));
                        $result = $this->db->query($sql);
                        
                        // Check for success
                        if ($result)
                        {
                            // DB was updated.  Take a moment to update the loaded command array
                            $this->disabledCommands = array_diff($this->disabledCommands, array($trigger));
                            $this->addMessageToQue("Command [$trigger] has been successfully enabled.");
                        } else {
                            // Log the error and add a message to the que.  DO NOT EDIT THE COMMAND
                            $this->addMessageToQue("Command [$trigger] was unable to be enabled, please see logs for details.");
                        }
                    }
                } else {
                    // Disable the command, inserting it if it didn't exist
                    $sql = $this->db->buildSelect(BURNBOT_CORE_COMMANDS, array(
                        1
                    ), array(
                        'id' => $this->sessionID,
                        '_trigger' => $trigger
                        
                    ));
                    $result = $this->db->query($sql);
                    
                    // Does the setting exist?
                    if ($result->numRows)
                    {
                        // update
                        $sql = $this->db->buildUpdate(BURNBOT_CORE_COMMANDS, array(
                            'operator' => $this->loadedCommands[$trigger]['operator'],
                            'regular' => $this->loadedCommands[$trigger]['regular'],
                            'user_layer_1' => $this->loadedCommands[$trigger]['user_layer_1'],
                            'user_layer_2' => $this->loadedCommands[$trigger]['user_layer_2'],
                            'enabled' => false
                        ), array(
                            'id' => $this->sessionID,
                            '_trigger' => $trigger
                            
                        ));
                        $result = $this->db->query($sql);
                        
                        // Check for success
                        if ($result)
                        {
                            // DB was updated.  Take a moment to update the loaded command array
                            $this->disabledCommands = array_merge($this->disabledCommands, array($trigger));
                            $this->addMessageToQue("Command [$trigger] has been successfully disabled.");
                        } else {
                            // Log the error and add a message to the que.  DO NOT EDIT THE COMMAND
                            $this->addMessageToQue("Command [$trigger] was unable to be disabled, please see logs for details.");
                        }
                    } else {
                        // The setting did not exist, insert
                        $sql = $this->db->buildInsert(BURNBOT_CORE_COMMANDS, array(
                            'id' => $this->sessionID,
                            '_trigger' => $trigger,
                            'operator' => $this->loadedCommands[$trigger]['operator'],
                            'regular' => $this->loadedCommands[$trigger]['regular'],
                            'user_layer_1' => $this->loadedCommands[$trigger]['user_layer_1'],
                            'user_layer_2' => $this->loadedCommands[$trigger]['user_layer_2'],
                            'enabled' => false
                            ));
                        $result = $this->db->query($sql);
                        
                        // Check for success
                        if ($result)
                        {
                            // DB was updated.  Take a moment to update the loaded command array
                            $this->disabledCommands = array_merge($this->disabledCommands, array($trigger));
                            $this->addMessageToQue("Command [$trigger] has been successfully disabled.");
                        } else {
                            // Log the error and add a message to the que.  DO NOT EDIT THE COMMAND
                            $this->addMessageToQue("Command [$trigger] was unable to be disabled, please see logs for details.");
                        }
                    }
                }
            } else {
                // We are editing the permission layers of a command.
                $operator = (isset($args[1])) ? in_array($args[1], $this->acceptValues) : null;
                $regular  = (isset($args[2])) ? in_array($args[2], $this->acceptValues) : null;
                $ul1      = (isset($args[3])) ? in_array($args[3], $this->acceptValues) : null;
                $ul2      = (isset($args[4])) ? in_array($args[4], $this->acceptValues) : null;
                
                // Make sure we got all of that info
                if (is_null($operator) || is_null($regular) || is_null($ul1) || is_null($ul2))
                {
                    return;
                }
                
                // Build the query using the same structure as above
                $sql = $this->db->buildSelect(BURNBOT_CORE_COMMANDS, array(
                    1
                ), array(
                    'id' => $this->sessionID,
                    '_trigger' => $trigger
                    
                ));
                $result = $this->db->query($sql);
                
                // Does the setting exist?
                if ($result->numRows)
                {
                    // update
                    $sql = $this->db->buildUpdate(BURNBOT_CORE_COMMANDS, array(
                        'operator' => $operator,
                        'regular' => $regular,
                        'user_layer_1' => $ul1,
                        'user_layer_2' => $ul2,
                        'enabled' => ((in_array($trigger, $this->disabledCommands)) ? true : false)
                    ), array(
                        'id' => $this->sessionID,
                        '_trigger' => $trigger
                        
                    ));
                    $result = $this->db->query($sql);
                    
                    // Check for success
                    if ($result)
                    {
                        // DB was updated.  Take a moment to update the loaded command array
                        $this->loadedCommands[$trigger] = array(
                            'operator' => $operator,
                            'regular' => $regular,
                            'user_layer_1' => $ul1,
                            'user_layer_2' => $ul2,
                            'enabled' => ((in_array($trigger, $this->disabledCommands)) ? true : false)
                        );
                        
                        $this->addMessageToQue("Command [$trigger] has been successfully edited.");
                    } else {
                        // Log the error and add a message to the que.  DO NOT EDIT THE COMMAND
                        $this->addMessageToQue("Command [$trigger] was unable to be edited, please see logs for details.");
                    }
                } else {
                    // The setting did not exist, insert
                    $sql = $this->db->buildInsert(BURNBOT_CORE_COMMANDS, array(
                        'id' => $this->sessionID,
                        '_trigger' => $trigger,
                        'operator' => $operator,
                        'regular' => $regular,
                        'user_layer_1' => $ul1,
                        'user_layer_2' => $ul2,
                        'enabled' => ((in_array($trigger, $this->disabledCommands)) ? true : false)
                        ));
                    $result = $this->db->query($sql);
                    
                    // Check for success
                    if ($result)
                    {
                        // DB was updated.  Take a moment to update the loaded command array
                        $this->loadedCommands[$trigger] = array(
                            'module' => $this->loadedCommands[$trigger]['module'],
                            'function' => $this->loadedCommands[$trigger]['function'],
                            'operator' => $operator,
                            'regular' => $regular,
                            'user_layer_1' => $ul1,
                            'user_layer_2' => $ul2
                        );
                        
                        $this->addMessageToQue("Command [$trigger] has been successfully edited.");
                    } else {
                        // Log the error and add a message to the que.  DO NOT EDIT THE COMMAND
                        $this->addMessageToQue("Command [$trigger] was unable to be edited, please see logs for details.");
                    }
                }
            }
        } else {
            $this->addMessageToQue("The command [$trigger] is protected from being edited.");
        }
    }
    
    /**
     * Processes a list command request
     */
    protected function core_listcom($sender, $args = array())
    {
        $module = '';
        $layer  = '';
        
        // Define the layer arrays
        $protected = array();
        $op        = array();
        $reg       = array();
        $ul1       = array();
        $ul2       = array();
        $user      = array();
        
        // Alright, time to check the param for a user layer
        if (isset($args[0]) && in_array($args[0], $this->userLayerNames))
        {
            $layer = strtolower($args[0]);
            
            foreach ($this->loadedCommands as $command => $arr)
            {
                if (in_array($command, $this->disabledCommands) || ($module && ($arr['module'] != $module)))
                {
                    continue;
                }
                
                // We will list the protected commands separately since they actually don't use the permission system at all
                if (in_array($command, $this->permissionBypassCommands) && ($layer == 'protected'))
                {
                    array_push($protected, $command);
                    continue;
                }
                
                $hasLayer = false;
                
                if ($arr['operator'])
                {
                    if ($layer == 'operator')
                    {
                        array_push($op, $command);
                    }
                    
                    $hasLayer = true;
                }
                
                if ($arr['regular'])
                {
                    if ($layer == 'regular')
                    {
                        array_push($reg, $command);
                    }
                    
                    $hasLayer = true;
                }
                
                if ($arr['user_layer_1'])
                {
                    if ($layer == strtolower($this->userLayer1Name))
                    {
                        array_push($ul1, $command);
                    }
                    
                    $hasLayer = true;
                }
                
                if ($arr['user_layer_2'])
                {
                    if ($layer == strtolower($this->userLayer2Name))
                    {
                        array_push($ul2, $command);
                    }
                    
                    $hasLayer = true;
                }
                
                if (!$hasLayer && ($layer == 'user'))
                {
                    array_push($user, $command);
                }
            }
        } else {
            $module = (isset($args[0])) ? strtolower($args[0]) : false;
            
            foreach ($this->loadedCommands as $command => $arr)
            {
                if (in_array($command, $this->disabledCommands) || ($module && (strtolower($arr['module']) != $module)))
                {
                    continue;
                }
                
                // We will list the protected commands separately since they actually don't use the permission system at all
                if (in_array($command, $this->permissionBypassCommands))
                {
                    array_push($protected, $command);
                    continue;
                }
                
                $hasLayer = false;
                
                if ($arr['operator'])
                {
                    array_push($op, $command);
                    $hasLayer = true;
                }
                
                if ($arr['regular'])
                {
                    array_push($reg, $command);
                    $hasLayer = true;
                }
                
                if ($arr['user_layer_1'])
                {
                    array_push($ul1, $command);
                    $hasLayer = true;
                }
                
                if ($arr['user_layer_2'])
                {
                    array_push($ul2, $command);
                    $hasLayer = true;
                }
                
                if (!$hasLayer)
                {
                    array_push($user, $command);
                }
            }
        }
        
        // Sort the arrays
        sort($protected);
        sort($op);
        sort($reg);
        sort($ul1);
        sort($ul2);
        sort($user);
        
        // Now print out all of the messages if the arrays are not empty
        if (!empty($protected))
        {
            $this->addMessageToQue('Protected: [' . implode(', ', $protected) . ']');
        }
        
        if (!empty($op))
        {
            $this->addMessageToQue('Operator: [' . implode(', ', $op) . ']');
        }
        
        if (!empty($reg))
        {
            $this->addMessageToQue('Regular: [' . implode(', ', $reg) . ']');
        }
        
        if (!empty($ul1))
        {
            $this->addMessageToQue("$this->userLayer1Name: [" . implode(', ', $ul1) . ']');
        }
        
        if (!empty($ul2))
        {
            $this->addMessageToQue("$this->userLayer2Name: [" . implode(', ', $ul2) . ']');
        }
        
        if (!empty($user))
        {
            $this->addMessageToQue('User: [' . implode(', ', $user) . ']');
        }
        
        // Print out that a module can be specified as well
        if (!$module && !$layer)
        {
            $this->addMessageToQue('A module can also be provided to list all commands for that module.');
        }
    }
    
    /**
     * Prints out requested information of a module
     */
    protected function core_module($sender, $args = array())
    {
        if (isset($args[0]) && array_key_exists($args[0], $this->loadedModules) && $this->loadedModules[$args[0]]['enabled'])
        {
            $module = $args[0];
            array_shift($args);
            $arr = array();
            
            // Alright, time to check the second param
            if (!isset($args[0]) || ($args[0] == 'all'))
            {
                // Request all avilable information from the module
                if ($module == 'core')
                {
                    $arr = array(
                        'version' => "$this->version.$this->build",
                        'name' => $this->name,
                        'author' => $this->author,
                        'description' => $this->moduleDescription,
                        'dependencies' => $this->dependencies
                    );
                } else {
                    if (method_exists($this->loadedModules[$module]['class'], 'moduleInfo'))
                    {
                        $arr = $this->modules->{$module}->moduleInfo(array(
                            'version', 
                            'core_version', 
                            'name', 
                            'author', 
                            'description', 
                            'dependencies'
                        ));                        
                    } else {
                        $this->logger->logError("Module [$module] failed to have the required function to grab information.");
                        $this->addMessageToQue("An error occurred while trying to get info for module [$module].  Please see logs for details.");
                        return;
                    }

                }
            } else {
                // Request specific info from the module
                if ($module == 'core')
                {
                    // Switch through the requests
                    foreach ($args as $request)
                    {
                        switch ($request)
                        {
                            case 'version':
                                $arr = array_merge($arr, array('version' => "$this->version.$this->build"));
                                
                                break;
                                
                            case 'name':
                                $arr = array_merge($arr, array('version' => $this->name));
                                
                                break;
                                
                            case 'author':
                                $arr = array_merge($arr, array('version' => $this->author));
                                
                                break;
                                
                            case 'description':
                                $arr = array_merge($arr, array('version' => $this->moduleDescription));
                                
                                break;
                            
                            case 'dependencies':
                                $arr = array_merge($arr, array('version' => $this->dependencies));
                                
                                break;
                    
                            default:
                            
                                break;
                        }
                    }
                } else {
                    if (method_exists($this->loadedModules[$module]['class'], 'moduleInfo'))
                    {
                        $arr = $this->modules->{$module}->moduleInfo($args);                        
                    } else {
                        $this->logger->logError("Module [$module] failed to have the required function to grab information.");
                        $this->addMessageToQue("An error occurred while trying to get info for module [$module].  Please see logs for details.");
                        return;
                    }
                }
            }
            
            // Now that we are here, pass the info out as a formatted message
            $str = 'Module [' . $module . ']: ';
            $str .= ((isset($arr['version'])) ? 'Version: [' . $arr['version'] . '], ' : '');
            $str .= ((isset($arr['core_version'])) ? 'Required core version: [' . $arr['core_version'] . '], ' : '');
            $str .= ((isset($arr['name'])) ? 'Registered name: [' . $arr['name'] . '], ' : '');
            $str .= ((isset($arr['author'])) ? 'Author: [' . $arr['author'] . '], ' : '');
            $str .= ((isset($arr['description'])) ? 'Description: [' . $arr['description'] . '], ' : '');
            $str .= ((isset($arr['dependencies'])) ? 'Dependencies: [' . ((!empty($arr['dependencies'])) ? implode(', ', $arr['dependencies']) : 'no listed dependencies') . '], ' : '');
            $str = rtrim($str, ', ') . '.';
            if (empty($arr))
            {
                $str .= 'The module failed to supply the requested information or the requested information was invalid.';
            }
            
            $this->addMessageToQue($str);
        }
    }
    
    /**
     * Lists all modules by the state (Enabled or not)
     */
    protected function core_modules($sender, $args = array())
    {
        $enabled = array();
        $disabled = array();
         
        foreach ($this->loadedModules as $module => $arr)
        {
            if ($arr['enabled'])
            {
                array_push($enabled, $module);
            } else {
                array_push($disabled, $module);
            }
        }
        
        sort($enabled);
        sort($disabled);
        
        $this->addMessageToQue('Enabled modules: [' . ((empty($enabled)) ? 'No enabled modules' : implode(', ', $enabled)) . '] Disabled modules: [' . ((empty($disabled)) ? 'No disabled modules' : implode(', ', $disabled)) . ']');
    }
    
    /**
     * Attempts to enable a module
     */
    protected function core_enableModule($sender, $args = array())
    {
        if (isset($args[0]) && array_key_exists(($module = $args[0]), $this->loadedModules))
        {
            if ($this->loadedModules[$module]['enabled'])
            {
                $this->addMessageToQue("The module [$module] is already enabled.");
            } else {
                $sql = $this->db->buildSelect(BURNBOT_CORE_MODULES, array(
                    'enabled'
                ), array(
                    'id' => $this->sessionID,
                    'module' => $module
                ));
                $result = $this->db->query($sql);
                
                if ($result->numRows)
                {
                    //Update the rule, it exists
                    $sql = $this->db->buildUpdate(BURNBOT_CORE_MODULES, array(
                        'enabled' => true
                    ), array(
                        'id' => $this->sessionID,
                        'module' => $module
                    ));
                    $result = $this->db->query($sql);
                    
                    if ($result)
                    {
                        $this->loadedModules[$module]['enabled'] = true;
                        
                        $this->addMessageToQue("The module [$module] has been enabled.");
                    } else {
                        $this->addMessageToQue("There was an error when trying to enable module [$module].  Please see logs for details.");
                    }
                } else {
                    // Insert the new rule
                    $sql = $this->db->buildInsert(BURNBOT_CORE_MODULES, array(
                        'id' => $this->sessionID,
                        'module' => $module,
                        'enabled' => true
                    ));
                    $result = $this->db->query($sql);
                    
                    if ($result)
                    {
                        $this->loadedModules[$module]['enabled'] = true;
                        
                        $this->addMessageToQue("The module [$module] has been enabled.");
                    } else {
                        $this->addMessageToQue("There was an error when trying to enable module [$module].  Please see logs for details.");
                    }
                }
            }
        }
    }
    
    /**
     * Attempts to diable a module
     */
    protected function core_disableModule($sender, $args = array())
    {
        if (isset($args[0]) && array_key_exists(($module = $args[0]), $this->loadedModules))
        {
            if ($this->loadedModules[$module]['enabled'])
            {
                $sql = $this->db->buildSelect(BURNBOT_CORE_MODULES, array(
                    'enabled'
                ), array(
                    'module' => $module,
                    'id' => $this->sessionID
                ));
                $result = $this->db->query($sql);
                
                if ($result->numRows)
                {
                    //Update the rule, it exists
                    $sql = $this->db->buildUpdate(BURNBOT_CORE_MODULES, array(
                        'enabled' => false
                    ), array(
                        'id' => $this->sessionID,
                        'module' => $module
                    ));
                    $result = $this->db->query($sql);
                    
                    if ($result)
                    {
                        $this->loadedModules[$module]['enabled'] = false;
                        
                        $this->addMessageToQue("The module [$module] has been disabled.");
                    } else {
                        $this->addMessageToQue("There was an error when trying to disable module [$module].  Please see logs for details.");
                    }
                } else {
                    // Insert the new rule
                    $sql = $this->db->buildInsert(BURNBOT_CORE_MODULES, array(
                        'id' => $this->sessionID,
                        'module' => $module,
                        'enabled' => false
                    ));
                    $result = $this->db->query($sql);
                    
                    if ($result)
                    {
                        $this->loadedModules[$module]['enabled'] = false;
                        
                        $this->addMessageToQue("The module [$module] has been disabled.");
                    } else {
                        $this->addMessageToQue("There was an error when trying to disable module [$module].  Please see logs for details.");
                    }
                }
            } else {
                $this->addMessageToQue("The module [$module] is already disabled.");
            }
        }
    }
    
    /**
     * Attempts a nick change, read will detect if we succeed
     */
    protected function core_nick($sender, $args = array())
    {
        if (isset($args[0]))
        {
            $nick = $args[0];
            
            if ($nick != $this->nick)
            {
                $this->addMessageToQue("NICK $nick", 'raw');
            }
        }
    }
    
    /**
     * Changes the key character used to trigger commands
     */
    protected function core_commandDelim($sender, $args = array())
    {
        if (!empty($args))
        {
            $char = (isset($args[0]) && strlen($args[0] > 0)) ? $args[0][0] : false;
            
            if ($char !== false)
            {
                $sql = $this->db->buildUpdate(BURNBOT_CORE_CONNECTIONS, array(
                    'command_delim' => $char
                ), array(
                    'id' => $this->sessionID
                ));
                $result = $this->db->query($sql);
                
                if ($result)
                {
                    $this->commandDelimeter = $char;
                    $this->addMessageToQue("Command delimeter changed to [$char]");
                } else {
                    $this->addMessageToQue('An error occured while trying to update the command delimeter, please see logs for details.');
                }
            }
        }
    }
    
    /**
     * Passes the channel topic to the channel if there was one
     */
    protected function core_topic($sender, $args = array())
    {
        $this->addMessageToQue($this->topic);
    }
    
    /**
     * Runs the help handler or passes off to a module's help handler
     */
    protected function core_help($sender, $args = array())
    {
        if (empty($args) || (($trigger = (isset($args[0])) ? $args[0] : false)) == 'help')
        {
            $this->addMessageToQue('To get information on a command, use [' . $this->commandDelimeter . 'help {trigger}]');
            $this->addMessageToQue('If you need a list of commands, you can use [' . $this->commandDelimeter . 'listcom] to list all commands or use [' . $this->commandDelimeter . 'listcom {module}] to list all commands for a module.');
            return;
        }
        
        if ($trigger)
        {
            if (array_key_exists($trigger, $this->loadedCommands) && $this->loadedCommands[$trigger]['module'] == 'core') 
            {
                switch ($trigger)
                {
                    case 'quit':
                        $this->addMessageToQue('Usage: [' . $this->commandDelimeter . 'quit {override key}].  This causes the bot to quit from IRC and disable all reconnect code.  This will only respond to operators or override users.  If the correct override key is supplied, the override layer permission is used instead.');
                        
                        break;
                        
                    case 'override':
                        $this->addMessageToQue('Usage: [' . $this->commandDelimeter . 'override {override key}].  This adds the sender to the override layer if the key is correct, regenerating the key after the user was added.  This  is the only core command that currently responds to private messages.');
                    
                        break;
                        
                    case 'version':
                        $this->addMessageToQue('Usage: [' . $this->commandDelimeter . 'version].  Sends the current version of the bot to the chat.');
                    
                        break;
                        
                    case 'ping':
                        $this->addMessageToQue('Usage: [' . $this->commandDelimeter . 'ping].  Sends the last PING reply and the last PONG reply that the bot has processed to the chat.');
                    
                        break;
                        
                    case 'memusage':
                        $this->addMessageToQue('Usage: [' . $this->commandDelimeter . 'memusage].  Send the current memory usage of the bot to the chat.');
                    
                        break;  
                    
                    case 'listcom':
                        $this->addMessageToQue('Usage: [' . $this->commandDelimeter . 'listcom].  Lists all currently registered commands that are not disabled to the chat.');
                        $this->addMessageToQue('Usage: [' . $this->commandDelimeter . 'listcom {module}].  Lists all currently registered commands that are not disabled and in that module to the chat.');
                        $this->addMessageToQue('Usage: [' . $this->commandDelimeter . 'listcom {permission layer}].  Lists all currently registered commands that are not disabled and in that permission layer to the chat.');
                    
                        break;
                        
                    case 'editcom':
                        $this->addMessageToQue('Usage: [' . $this->commandDelimeter . 'editcom {trigger} {enable/disable}] Enables or disables a registered command trigger.  This is a core level enable or disable.');
                        $this->addMessageToQue('Usage: [' . $this->commandDelimeter . 'editcom {trigger} {operator layer} {regular layer} {user layer 1} {user layer 2}].  Sets the permission layers of a command to those specified.  If no permission layer is specified, all users may use the command.  If any one is specified, that layer and all operators may use that command.  Override users are classified as operators.');
                        $this->addMessageToQue('This command may have a handler specified by a module.  If you believe that a module handles this function on its own, use the module help for details on how the handler works.');
                        
                        break;
                        
                    case 'source':
                        $this->addMessageToQue('Usage: [' . $this->commandDelimeter . 'source].  Sends the link to the currently available bot source to the chat.');
                    
                        break;
                        
                    case 'module':
                        $this->addMessageToQue('Usage: [' . $this->commandDelimeter . 'module {module} {options}].  Requests specified information from either core or a registered module and writes it to the chat.  Valid options are a space separated list of the following: [version, core_version, name, author, description, dependencies] and [all] for all information.');
                    
                        break;
                        
                    case 'modules':
                        $this->addMessageToQue('Usage: [' . $this->commandDelimeter . 'modules].  Lists all modules sorted by if they are enabled or not.');
                    
                        break;
                        
                    case 'enablemodule':
                        $this->addMessageToQue('Usage: [' . $this->commandDelimeter . 'enablemodule].  Enabled a module if it was previously disabled.');
                    
                        break;
                        
                    case 'disablemodule':
                        $this->addMessageToQue('Usage: [' . $this->commandDelimeter . 'disablemodule].  Disables a module if it was previously enabled.');
                    
                        break;
                        
                    case 'nick':
                        $this->addMessageToQue('Usage: [' . $this->commandDelimeter . 'nick {nick}].  Attempts to have the bot obtain a new nickname.');
                    
                        break;
                    
                    case 'commanddelim':
                        $this->addMessageToQue('Usage: [' . $this->commandDelimeter . 'commanddelim {character}].  Assigns a new key character for commands.');
                    
                        break;
                    
                    default:
                        $this->addMessageToQue("The command [$trigger] apppears to be registered with core but has no help associated with it.");
                        
                        break;
                }
            } elseif (array_key_exists(($module = $trigger), $this->loadedModules) &&  $this->loadedModules[$module]['enabled']) {
                $this->modules->{$module}->{strtolower($module) . '_help'}($sender, (isset($args[1]) ? $args[1] : ''));
            }
        } else {
            $this->addMessageToQue('To get information on a command, use [' . $this->commandDelimeter . 'help {trigger}].');
            $this->addMessageToQue('If you need a list of commands, you can use ' . $this->commandDelimeter . 'listcom to list all commands or use [' . $this->commandDelimeter . 'listcom {module}] to list all commands for a module.');
            return;
        }
    }
}
?>
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
    var $tickLimiter = 100000; // This sets the time each cycle is expected to take.  Will be used in the sleep calculation
    
    // Arrays (Large storage)
    var $loadedCommands = array();
    var $operators = array();
    var $regulars = array();
    var $messageQue = array();
    
    // Limits message sends
    var $limitSends = false;
    var $messageTTL = array();
    
    /** Registers commands from modules into the main array for checking.  Commands will have the following structure:
     * 
     * array(
     *   'trigger' => '!test',                  // Sets the trigger to the command
     *   'allowed_modes' => '+o',               // Sets the defined flags that are allowed to use the command
     *   'regulars_allowed' => false,           // Are defined regulars allowed to use the command?
     *   'subs_allowed' => false,               // Are subscribers on Twitch allowed to use the command?
     *   'output' => 'This is a test command!', // The actual command output
     *   'twitch_caster' => true                // Sets the command to work for the caster despite any of the other restrictions (Will default true)
     * );
     */
    public static function registerCommads($commands = array())
    {
        $this->loadedCommands = array_merge($this->loadedCommands, $commands);
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
    
    public static function reconnect()
    {
        
    }
    
    public static function _read()
    {
        
    }
    
    public static function twitch_generatePassword($nick)
    {
        // Select the nick out of the DB and grab the code to generate a token for it
        global $twitch, $db;
        
        
        
        return $twitch->chat_generateToken(null, $code);
    }
    
    public static function addMessageToQue()
    {
        
    }
    
    public static function checkTTL()
    {
        
    }
}
?>
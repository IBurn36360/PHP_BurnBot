<?php
// defines all Burnbot logic
class burnbot
{
    var $version = '.5';
    
    // Arrays (Large storage)
    var $loadedCommands = array();
    var $operators = array();
    var $regulars = array();
    var $messageQue = array();
    
    // Limits message sends
    var $limitSends = false;
    var $messageTTL = array();
    
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
<?php
// Was this accessed directly?  If so, exit.
if (!defined('IN_IRC'))
{
	exit;
}

// A fun module requested by a user to scare people on webclients.  Would only run if enabled, so is disabled by default.
class izl
{
    function __construct($register = false)
    {
        global $burnBot, $irc;
        
        if ($register)
        {
            // Register the module
            $burnBot->registerModule(array('izl' => array('enabled' => false, 'class' => 'izl')));
        } else {
            // Construct environment data here.  This is your time to run any SQL you need to
            $irc->_log_action("Izlsnizzt module environment constructed");
        }
    }
    
    public function init()
    {
        
    }
    
    public function _read($messageArr)
    {
        global $burnBot, $irc;
        
        if ($messageArr['type'] == 'system')
        {
            if (isset($messageArr['isJoin']))
            {
                $host = $messageArr['host'];
                
                if (strtolower($messageArr['client']) == 'webchat')
                {
                    $nick = $messageArr['nick'];
                    
                    $burnBot->addMessageToQue("5WARNING Webchat User Detected: IP Logged $host 5WARNING");
                }
            }
        }
    }
    
    public function tick()
    {
        
    }
}
?>
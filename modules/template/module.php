<?php
// Was this accessed directly?  If so, exit.
if (!defined('IN_IRC'))
{
	exit;
}

class module
{
    protected $commands = array(
    //  {trigger} => array({Module name}, {Function name}, {Op permission}, {Reg permission}, {Sub permission}, {Turbo permission})
    //  'testcommad' => array('module', 'module_functionName', false, false, false, false),
    );
    
    function __construct($register = false)
    {
        global $burnBot;
        
        if ($register)
        {
            // Register the module
            $burnBot->registerModule(array('module' => array('enabled' => true, 'class' => 'module')));
        } else {
            // Construct environment data here.  This is your time to run any SQL you need to
            
        }
    }
    
    public function init()
    {
        global $burnBot;
        
        
        
        // Register commands
        $burnBot->registerCommads($this->commands);
    }
    
    public function _read($messageArr)
    {
        
    }
    
    public function tick()
    {
        
    }
}
?>
<?php
// Was this accessed directly?  If so, exit.
if (!defined('IN_PHPBURNBOT'))
{
	exit;
}

/**
 * Module Izl
 * 
 * @author Anthony 'IBurn36360' Diaz
 * @final
 * @name Module Izl
 * @version 1.0.0
 * 
 * Custom module for irc://irc.quakenet.org/#izlsnizzt
 */
final class izl
{
    // Module vars
    protected $version = '1.0.0';
    protected $requiredCoreVersion = '2.0';
    protected $name = 'Izl';
    protected $author = 'Anthony \'IBurn36360\' Diaz';
    protected $moduleDescription = 'Custom module for irc://irc.quakenet.org/#izlsnizzt';
    protected $dependencies = array();
    
    protected $burnBot;
    
    function __construct($register = false)
    {
        global $burnBot;
        
        if ($register)
        {
            $burnBot->registerModule(array('Izl' => array(
                'class' => 'izl',
                'enabled' => true
            )));
        } else {
            $this->burnBot = &$burnBot;
        }
    }
    
    public function init()
    {
        
    }
    
    /**
     * Processes a module information request from core
     * 
     * @param $list[array] - Array of all fields to be returned
     * 
     * @return $return[array] - All requested data that exists
     */
    public function moduleInfo($list = array())
    {
        $return = array();
        
        if (is_array($list) && !empty($list))
        {
            foreach ($list as $request)
            {
                switch ($request)
                {
                    case 'version':
                        $return = array_merge($return, array('version' => $this->version));
                        continue;
                        
                        break;
                        
                    case 'core_version':
                        $return = array_merge($return, array('core_version' => $this->requiredCoreVersion));
                        continue;
                        
                        break;
                        
                    case 'name':
                        $return = array_merge($return, array('name' => $this->name));
                        continue;
                        
                        break;
                        
                    case 'author':
                        $return = array_merge($return, array('author' => $this->author));
                        continue;
                        
                        break;
                        
                        
                    case 'description':
                        $return = array_merge($return, array('description' => $this->moduleDescription));
                        continue;
                        
                        break;
                    
                    case 'dependencies':
                        $return = array_merge($return, array('dependencies' => $this->dependencies));
                        continue;
                        
                        break;
                }
            }
        }
        
        return $return;
    }
    
    public function read($messageArr = array())
    {
        if ($messageArr['type'] == 'system')
        {
            if (isset($messageArr['is_join']))
            {
                if (stristr($messageArr['ident'], 'webchat') || stristr($messageArr['hostname'], 'webchat'))
                {
                    $this->burnBot->addMessageToQue("5Webchat User Detected");
                }
            }
        }
    }
    
    public function tick()
    {
        
    }
    
    public function help()
    {
        
    }
}
?>
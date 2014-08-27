<?php
// Was this accessed directly?  If so, exit.
if (!defined('IN_PHPBURNBOT'))
{
	exit;
}

// A fun module requested by a user to scare people on webclients.  Would only run if enabled, so is disabled by default.
class nickprotect
{
    // Module vars
    protected $version = '1.0.7';
    protected $requiredCoreVersion = '2.0';
    protected $name = 'NickProtect';
    protected $author = 'Anthony \'IBurn36360\' Diaz';
    protected $moduleDescription = 'Handles grabbing nicknames in the event that the user PARTs or QUITs from the channel';
    protected $dependencies = array();
    
    protected $burnBot;
    protected $db;
    
    protected $sessionID;
    
    protected $commands = array(
        'addnick' => array(
            'function'     => 'nickprotect_addnick',
            'module'       => 'NickProtect',
            'operator'     => true,
            'regular'      => false,
            'user_layer_1' => false,
            'user_layer_2' => false
        ),
        'delnick' => array(
            'function'     => 'nickprotect_delnick',
            'module'       => 'NickProtect',
            'operator'     => true,
            'regular'      => false,
            'user_layer_1' => false,
            'user_layer_2' => false
        ),
        'listnick' => array(
            'function'     => 'nickprotect_listnick',
            'module'       => 'NickProtect',
            'operator'     => true,
            'regular'      => false,
            'user_layer_1' => false,
            'user_layer_2' => false
        )
    );
    
    protected $protectedNicks = array();
    
    function __construct($register = false)
    {
        global $burnBot, $db;
        
        if ($register)
        {
            $burnBot->registerModule(array('NickProtect' => array(
                'class' => 'nickprotect',
                'enabled' => false
            )));
        } else {
            $this->burnBot = &$burnBot;
            $this->db = &$db;
            
            $this->sessionID = $this->burnBot->getSessionID();
            
            // Now grab our nick list
            $sql = $this->db->buildSelect(BURNBOT_MODULE_NICKPROTECT_NICKS, array(
                'nick'
            ), array(
                'id' => $this->sessionID
            ));
            $result = $this->db->query($sql);
            
            if ($result)
            {
                foreach ($result->rows as $row)
                {
                    $this->protectedNicks = array_merge($this->protectedNicks, array($row['nick']));
                }
            }
        }
    }
    
    public function init()
    {
        $this->burnBot->registerCommands($this->commands);
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
        if (($messageArr['type'] === 'service') && (isset($messageArr['is_part']) || (isset($messageArr['is_quit']) && !strstr($messageArr['message'], 'NETSPLIT'))))
        {
            // Are we protecting a nick and have not already reserved one?
            if (in_array($messageArr['nick'], $this->protectedNicks) && !in_array(($this->burnBot->getNick()), $this->protectedNicks))
            {
                // We wait 2 seconds so we don't get hammered for netrider
                $this->burnBot->addMessageToQue('NICK ' . $messageArr['nick'], 'raw', array(), time() + 2);
            }
        }
    }
    
    public function tick()
    {
        
    }
    
    public function nickprotect_addnick($sender, $args = array(), $trigger = '')
    {
        if (isset($args[0]))
        {
            $nick = strtolower($args[0]);
            
            if (in_array($nick, $this->protectedNicks))
            {
                $this->burnBot->addMessageToQue("The nickname [$nick] is already being protected.");
            } else {
                $sql = $this->db->buildInsert(BURNBOT_MODULE_NICKPROTECT_NICKS, array(
                    'id' => $this->sessionID,
                    'nick' => $nick
                ));
                $result = $this->db->query($sql);
                
                if ($result)
                {
                    $this->protectedNicks = array_merge($this->protectedNicks, array($nick));
                    $this->burnBot->addMessageToQue("The nick [$nick] is now protected in case of disconnects or parts.");
                } else {
                    $this->burnBot->addMessageToQue("There was an error adding the nick [$nick] for protection.  Please see logs for details.");
                }
            }
        } else {
            $this->burnBot->addMessageToQue('A nickname was not provided.  Please provide a nick to be added to the protection list.');
        }
    }
    
    public function nickprotect_delnick($sender, $args = array(), $trigger = '')
    {
        if (isset($args[0]))
        {
            $nick = strtolower($args[0]);
            
            if (in_array($nick, $this->protectedNicks))
            {
                $sql = $this->db->buildInsert(BURNBOT_MODULE_NICKPROTECT_NICKS, array(
                    'id' => $this->sessionID,
                    'nick' => $nick
                ));
                $result = $this->db->query($sql);
                
                if ($result)
                {
                    $this->protectedNicks = array_diff($this->protectedNicks, array($nick));
                    $this->burnBot->addMessageToQue("The nick [$nick] is no longer protected in case of disconnects or parts.");
                } else {
                    $this->burnBot->addMessageToQue("There was an error removing the nick [$nick] for protection.  Please see logs for details.");
                }
            } else {
                $this->burnBot->addMessageToQue("The nickname [$nick] was not being protected.");
            }
        } else {
            $this->burnBot->addMessageToQue('A nickname was not provided.  Please provide a nick to be removed from the protection list.');
        }
    }
    
    public function nickprotect_listnick($sender, $args = array(), $trigger = '')
    {
        if (!empty($this->protectedNicks))
        {
            $this->burnBot->addMessageToQue('Protected nicknames: [' . implode(', ', $this->protectedNicks) . ']');
        } else {
            $this->burnBot->addMessageToQue('There are currently no protected nicknames.');
        }
    }
    
    public function help($sender, $trigger = '')
    {
        if (array_key_exists($trigger, $this->commands) || in_array($trigger, $this->burnBot->getOverridableCommands()))
        {
            switch ($trigger)
            {
                case 'addnick':
                    $this->burnBot->addMessageToQue('Usage: [' . $this->burnBot->getCommandDelimeter() . 'addnick {nick}].  Adds a new protected nick.');
                
                    break;
                    
                case 'delnick':
                    $this->burnBot->addMessageToQue('Usage: [' . $this->burnBot->getCommandDelimeter() . 'delnick {nick}].  Removes a protected nick.');
                
                    break;
                    
                case 'listnick':
                    $this->burnBot->addMessageToQue('Usage: [' . $this->burnBot->getCommandDelimeter() . 'listnick].  Lists all protected nicks.');
                
                    break;
                
                default:
                    $this->burnBot->addMessageToQue("The command [$trigger] is registered to module [NickProtect], but is not a NickProtect command or has no help associated with it.");
                
                    break;
            }
        } else {
            $this->burnBot->addMessageToQue("The command [$trigger] is not registerted to module [NickProtect].");
        }
    }
}
?>
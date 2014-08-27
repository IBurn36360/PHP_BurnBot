<?php
// Was this accessed directly?  If so, exit.
if (!defined('IN_PHPBURNBOT'))
{
	exit;
}

class reminders
{
    // Module vars
    protected $version = '1.0.8';
    protected $requiredCoreVersion = '2.0';
    protected $name = 'Reminders';
    protected $author = 'Anthony \'IBurn36360\' Diaz';
    protected $moduleDescription = 'Handles recurring messages or commands (Events that happen on a timer)';
    protected $dependencies = array();
    
    protected $burnBot;
    protected $db;
    
    protected $sessionID;
    
    protected $lastReminderTime    = 0;
    protected $lastMessageTime     = 0;
    protected $enabled             = false;
    protected $requireChatActivity = true;
    protected $reminderDelayPeriod = 120;
    protected $defaultTTL          = 300;
    protected $chatActivityDelay   = 600;
    
    protected $remindersQue = array();
    protected $reminders    = array();
    
    protected $validReminderTypes = array(
        'normal',
        'command',
        'action'
    );
    
    protected $commands = array(
        'addrem' => array(
            'function'     => 'reminders_addrem',
            'module'       => 'Reminders',
            'operator'     => true,
            'regular'      => false,
            'user_layer_1' => false,
            'user_layer_2' => false
        ),
        'delrem' => array(
            'function'     => 'reminders_delrem',
            'module'       => 'Reminders',
            'operator'     => true,
            'regular'      => false,
            'user_layer_1' => false,
            'user_layer_2' => false
        ),
        'editrem' => array(
            'function'     => 'reminders_editrem',
            'module'       => 'Reminders',
            'operator'     => true,
            'regular'      => false,
            'user_layer_1' => false,
            'user_layer_2' => false
        ),
        'listrem' => array(
            'function'     => 'reminders_listrem',
            'module'       => 'Reminders',
            'operator'     => true,
            'regular'      => false,
            'user_layer_1' => false,
            'user_layer_2' => false
        ),
        'reminders' => array(
            'function'     => 'reminders_reminders',
            'module'       => 'Reminders',
            'operator'     => true,
            'regular'      => false,
            'user_layer_1' => false,
            'user_layer_2' => false
        )
    );
    
    public function __construct($register = false)
    {
        global $burnBot, $db;
        
        if ($register)
        {
            $burnBot->registerModule(array('Reminders' => array(
                'class' => 'reminders',
                'enabled' => false
            )));
        } else {
            $this->burnBot = &$burnBot;
            $this->db = &$db;
            
            $this->sessionID = $this->burnBot->getSessionID();
            
            // Now grab all reminders and their settings
            $sql = $this->db->buildSelect(BURNBOT_MODULE_REMINDERS_CONFIG, array(
                'enabled',
                'require_chat_activity',
                'reminder_delay',
                'chat_activity_delay'
            ), array(
                'id' => $this->sessionID
            ));
            $result = $this->db->query($sql);
            
            if ($result && ($result->numRows != 0))
            {
                // Load our stored config
                $this->enabled = ($result->row['enabled'] == 1) ? true : false;
                $this->requireChatActivity = ($result->row['require_chat_activity'] == 1) ? true : false;
                $this->reminderDelayPeriod = intval($result->row['reminder_delay']);
                $this->chatActivityDelay = intval($result->row['chat_activity_delay']);
                
                $this->burnBot->log('Config loaded from DB', 'reminders');
                $this->burnBot->log('Enabled [' . (($this->enabled) ? 'true' : 'false') . ']', 'reminders');
                $this->burnBot->log('Require chat activity [' . (($this->requireChatActivity) ? 'true' : 'false') . ']', 'reminders');
                $this->burnBot->log('Reminder delay period [' . intval($this->reminderDelayPeriod) . ']', 'reminders');
                $this->burnBot->log('Chat activity delay [' . intval($this->chatActivityDelay) . ']', 'reminders');
            } elseif ($result && ($result->numRows == 0)) {
                // Create a new line for our config settings
                $sql = $this->db->buildInsert(BURNBOT_MODULE_REMINDERS_CONFIG, array(
                    'enabled' => $this->enabled,
                    'require_chat_activity' => $this->requireChatActivity,
                    'reminder_delay' => $this->reminderDelayPeriod,
                    'chat_activity_delay' => $this->chatActivityDelay,
                    'id' => $this->sessionID
                ));
                
                $result = $this->db->query($sql);
                
                if ($result)
                {
                    $this->burnBot->log('New configuration created in database', 'reminders');
                } else {
                    $this->burnBot->log($result->error, 'reminders');
                }
            } else {
                $this->burnBot->log($result->error, 'reminders');
            }
            
            // Now load the reminders and construct the reminders stack
            $sql = $this->db->buildSelect(BURNBOT_MODULE_REMINDERS_REMINDERS, array(
                'name',
                'args',
                'ttl',
                'type'
            ), array(
                'id' => $this->sessionID
            ));
            $result = $this->db->query($sql);
            
            if ($result && ($result->numRows != 0))
            {
                foreach ($result->rows as $row)
                {
                    $this->reminders[$row['name']] = array(
                        'args' => explode(',', $row['args']),
                        'ttl'  => intval($row['ttl']),
                        'type' => $row['type']
                    );
                    
                    $this->burnBot->log('Reminder loaded [' . $row['name'] . '] Args: [' . $row['args'] . '] TTL: [' . intval($row['ttl']) . '] Type: [' . $row['type'] . ']', 'reminders');
                }
            } elseif ($result->error != '') {
                $this->burnBot->log($result->error, 'reminders');
            } else {
                $this->burnBot->log('No reminders loaded from database', 'reminders');
            }
            
            $this->buildReminders();
        }
    }
    
    /**
     * Constructs the initial array of reminders on startup
     */
    protected function buildReminders()
    {
        $time = time();
        
        // We are going to do our first process based on the order the reminders were loaded (and by extension, added)
        foreach ($this->reminders as $name => $data)
        {
            $this->remindersQue[$name] = array(
                'type' => $data['type'],
                'ttl' => $data['ttl'],
                'args' => $data['args'],
                'time' => $time
            );
            
            $time++;
        }
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
    
    public function init()
    {
        $this->burnBot->registerCommands($this->commands);
    }
    
    public function read($messageArr)
    {
        if ($messageArr['type'] == 'private')
        {
            // Update the last time we had a message in the channel
            $this->lastMessageTime = time();
        }
    }
    
    public function tick()
    {
        // We do this on its own because it can make the check very cheap if reminders are disabled
        if ($this->enabled)
        {
            $time = time();
            
            if (($time >= ($this->lastReminderTime + $this->reminderDelayPeriod)) && (!$this->requireChatActivity || ($this->requireChatActivity && ($this->lastMessageTime >= ($time = $this->chatActivityDelay)))))
            {
                // Check the reminders stack to see if there is a reminder waiting to be processed
                $reminder = reset($this->remindersQue);
                $name = key($this->remindersQue);
                
                if (isset($reminder['time']) && ($reminder['time'] <= $time))
                {
                    array_shift($this->remindersQue);
                    
                    $args = $reminder['args'];
                    
                    // Process the stack
                    switch($reminder['type'])
                    {
                        case 'normal':
                            $this->burnBot->addMessageToQue(implode(' ', $args));
                        
                            break;
                            
                        case 'action':
                            $this->burnBot->addMessageToQue(implode(' ', $args), 'action');
                        
                            break;
                            
                        case 'command':
                            $trigger = array_shift($args);
                        
                            $this->burnBot->runCommand($this->burnBot->getNick(), $trigger, $args);
                        
                            break;
                        
                        default:
                            $this->burnBot->log('Reminder attempted to use invalid type [' . $reminder['type'] . ']', 'reminders');
                        
                            break;
                    }
                    
                    // Now that the command is processed, calculate its next valid send time and reque it
                    $nextValidTime = $time + $reminder['ttl'];
                    
                    $this->remindersQue[$name] = array(
                        'type' => $reminder['type'],
                        'ttl' => $reminder['ttl'],
                        'args' => $reminder['args'],
                        'time' => $nextValidTime
                    );
                    
                    $this->lastReminderTime = $time;
                }
            }
        }
    }
    
    public function reminders_addrem($sender, $args = array(), $trigger = '')
    {
        if (!empty($args))
        {
            $name = (isset($args[0])) ? $args[0] : false;
            array_shift($args);
            $arguments = $args;
            
            if (($name === false) || empty($arguments))
            {
                $this->burnBot->addMessageToQue('No name or arguments were provided for the reminder.  Please provide a name and at least one argument for the reminder.');
            } elseif (!array_key_exists($name, $this->reminders)) {
                $sql = $this->db->buildInsert(BURNBOT_MODULE_REMINDERS_REMINDERS, array(
                    'id' => $this->sessionID,
                    'name' => $name,
                    'args' => implode(',', $arguments)
                ));
                $result = $this->db->query($sql);
                
                if ($result)
                {
                    // Add the reminder to both the registry and the que
                    $this->reminders = array_merge($this->reminders, array($name => array(
                        'args' => $arguments,
                        'type' => 'normal',
                        'ttl'  => $this->defaultTTL
                    )));
                    $this->remindersQue = array_merge($this->remindersQue, array($name => array(
                        'args' => $arguments,
                        'type' => 'normal',
                        'ttl'  => $this->defaultTTL,
                        'time' => time()
                    )));
                    
                    $this->burnBot->addMessageToQue("The reminder [$name] has been successfully added.");
                } else {
                    $this->burnBot->addMessageToQue("There was an error adding reminder [$name].  Please see logs for details.");
                }
            } else {
                $this->burnBot->addMessageToQue("The reminder [$name] already exists.  Please choose another name for your reminder.");
            }
        } else {
            $this->burnBot->addMessageToQue('No name or arguments were provided for the reminder.  Please provide a name and at least one argument for the reminder.');
        }
    }
    
    public function reminders_delrem($sender, $args = array(), $trigger = '')
    {
        if (isset($args[0]))
        {
            if (array_key_exists($args[0], $this->reminders))
            {
                $sql = $this->db->buildDelete(BURNBOT_MODULE_REMINDERS_REMINDERS, array(
                    'id' => $this->sessionID,
                    'name' => $args[0]
                ));
                $result = $this->db->query($sql);
                
                if ($result)
                {
                    // Remove the reminder from the que and the registry
                    unset($this->reminders[$args[0]], $this->remindersQue[$args[0]]);
                    
                    $this->burnBot->addMessageToQue('The reminder [' . $args[0] . '] has been successfully deteled.');
                } else {
                    $this->burnBot->addMessageToQue('There was an error deleting reminder [' . $args[0] . '].  Please see logs for details.');
                }
            } else {
                $this->burnBot->addMessageToQue('The reminder [' . $args[0] . '] is not registered, please choose another one.');
            }
        }
    }
    
    public function reminders_editrem($sender, $args = array(), $trigger = '')
    {
        $name = (isset($args[0])) ? $args[0] : false;
        
        if ($name === false)
        {
            $this->burnBot->addMessageToQue('You must provide the name of the reminder you wish to edit.');
            return;
        } elseif (!array_key_exists($name, $this->reminders)) {
            $this->burnBot->addMessageToQue("The reminder [$name] is not registered, please choose a valid reminder.");
            return;
        }
        
        // Well...start grabbing our params
        $ttl = (isset($args[1])) ? intval($args[1]) : $this->reminders[$name]['ttl'];
        $type = (isset($args[2])) ? $args[2] : $this->reminders[$name]['type'];
        for ($i = 0; $i <= 2; $i++)
        {
            array_shift($args);
        }
        $arguments = (empty($args)) ? $this->reminders[$name]['args'] : $args;
        
        $sql = $this->db->buildUpdate(BURNBOT_MODULE_REMINDERS_REMINDERS, array(
            'args' => implode(',', $arguments),
            'type' => $type,
            'ttl' => $ttl
        ), array(
            'id' => $this->sessionID,
            'name' => $name
        ));
        $result = $this->db->query($sql);
        
        if ($result)
        {
            // Update the arrays
            $this->reminders = array_merge($this->reminders, array($name => array(
                'args' => $arguments,
                'type' => $type,
                'ttl'  => $ttl
            )));
            $this->remindersQue = array_merge($this->remindersQue, array($name => array(
                'args' => $arguments,
                'type' => $type,
                'ttl'  => $ttl,
                'time' => $this->remindersQue[$name]['time']
            )));
            
            $this->burnBot->log("Reminder updated [$name] Args: [" . $row['args'] . "] TTL: [$ttl] Type: [$type]'", 'reminders');
            $this->burnBot->addMessageToQue("The reminder [$name] has been successfully updated");
        } else {
            $this->burnBot->addMessageToQue("There was an error updating reminder [$name].  Please see logs for details.");
        }
    }
    
    public function reminders_listrem($sender, $args = array(), $trigger = '')
    {
        if (isset($args[0]))
        {
            if ((($reminder = $args[0]) != '') && array_key_exists($reminder, $this->reminders))
            {
                $this->burnBot->addMessageToQue("Reminder [$reminder] Type: [" . $this->reminders[$reminder]['type'] . '], Args: [' .  implode(', ', $this->reminders[$reminder]['args']) . '], TTL: [' . $this->reminders[$reminder]['ttl'] . ']');
            } else {
                $this->burnBot->addMessageToQue("The reminder [$reminder] is not registered, please choose another one for information on that reminder.");
            }
        } else {
            $this->burnBot->addMessageToQue('Current registered reminders: [' . implode(', ', array_keys($this->reminders)) . ']');
        }
    }
    
    public function reminders_reminders($sender, $args = array(), $trigger = '')
    {
        if (empty($args))
        {
            // Simply print out the current configuration
            $this->burnBot->addMessageToQue('Current reminders configuration: Enabled [' . (($this->enabled) ? 'true' : 'false') . '], Require chat activity [' . (($this->requireChatActivity) ? 'true' : 'false') . '], Reminder delay period [' . intval($this->reminderDelayPeriod) . '], Chat activity delay [' . intval($this->chatActivityDelay) . ']');
        } else {
            // People can be cheeky here if they know how the system works
            $enabled = (isset($args[0])) ? in_array(strtolower($args[0]), $this->burnBot->getAcceptValues()) : $this->enabled;
            $requireActivity = (isset($args[1])) ? in_array(strtolower($args[1]), $this->burnBot->getAcceptValues()) : $this->requireChatActivity;
            $reminderDelay = (isset($args[2])) ? intval($args[2]) : $this->reminderDelayPeriod;
            $chatActivityDelay = (isset($args[3])) ? intval($args[3]) : $this->chatActivityDelay;
            
            $sql = $this->db->buildUpdate(BURNBOT_MODULE_REMINDERS_CONFIG, array(
                'enabled' => $enabled,
                'require_chat_activity' => $requireActivity,
                'reminder_delay' => $reminderDelay,
                'chat_activity_delay' => $chatActivityDelay
            ), array(
                'id' => $this->sessionID
            ));
            $result = $this->db->query($sql);
            
            if ($result)
            {
                $this->enabled = $enabled;
                $this->requireChatActivity = $requireActivity;
                $this->reminderDelayPeriod = $reminderDelay;
                $this->chatActivityDelay = $chatActivityDelay;
                
                $this->burnBot->log('Config updated', 'reminders');
                $this->burnBot->log('Enabled [' . (($this->enabled) ? 'true' : 'false') . ']', 'reminders');
                $this->burnBot->log('Require chat activity [' . (($this->requireChatActivity) ? 'true' : 'false') . ']', 'reminders');
                $this->burnBot->log('Reminder delay period [' . intval($this->reminderDelayPeriod) . ']', 'reminders');
                $this->burnBot->log('Chat activity delay [' . intval($this->chatActivityDelay) . ']', 'reminders');
                
                $this->burnBot->addMessageToQue('Reminders configuration has been successfully updated');
            } else {
                $this->burnBot->addMessageToQue('There was an error when trying to update the reminders configuration.  Please see logs for details');
            }
        }
    }
    
    public function reminders_help($sender, $trigger = '')
    {
        if (array_key_exists($trigger, $this->commands) || in_array($trigger, $this->burnBot->getOverridableCommands()))
        {
            switch ($trigger)
            {
                case 'addrem':
                    $this->burnBot->addMessageToQue('Usage: [' . $this->burnBot->getCommandDelimeter() . 'addrem {name} {arguments or output}].  Adds a new reminder with the specified name and either the output provided or the list of arguments to be passed to a command.');
                    $this->burnBot->addMessageToQue('When attempting to use a reminder with an existing command, the first argument is the command trigger you wish to execute.');
                
                    break;
                    
                case 'delrem':
                    $this->burnBot->addMessageToQue('Usage: [' . $this->burnBot->getCommandDelimeter() . 'delrem {name}].  Deletes the specified reminder by name.');
                
                    break;
                    
                case 'editrem':
                    $this->burnBot->addMessageToQue('Usage: [' . $this->burnBot->getCommandDelimeter() . 'editrem {name} {time between this event in seconds} {type} {arguments or output}].  Edits the specified reminder with a new provided type or new arguments/output.  If no arguments/output are provided, the current arguments/output is retained.');
                    $this->burnBot->addMessageToQue('Current valid reminder types: [' . implode(', ', $this->validReminderTypes) . ']');
                    $this->burnBot->addMessageToQue('When attempting to use a reminder with an existing command, the first argument is the command trigger you wish to execute.');
                
                    break;
                    
                case 'listrem':
                    $this->burnBot->addMessageToQue('Usage: [' . $this->burnBot->getCommandDelimeter() . 'listrem].  Lists all currently registered reminders by name.');
                    $this->burnBot->addMessageToQue('Usage: [' . $this->burnBot->getCommandDelimeter() . 'listrem {name}].  Provides the information about the reminder specified if it exists.');
                
                    break;
                    
                case 'reminders':
                    $this->burnBot->addMessageToQue('Usage: [' . $this->burnBot->getCommandDelimeter() . 'reminders {enabled} {require chat activity} {minimum delay between reminders (in seconds)} {time before lack of chat activity disables reminders (in seconds)}].  Changes the behavior of the reminders module.  Any skipped parameters will be filled in with their current values.');
                    $this->burnBot->addMessageToQue('Usage: [' . $this->burnBot->getCommandDelimeter() . 'reminders]. Lists all current settings for the reminders module.');
                
                    break;
                
                default:
                    $this->burnBot->addMessageToQue("The command [$trigger] is registered to module [Reminders], but is not a Reminders command or has no help associated with it.");
                
                    break;
            }
        } else {
            $this->burnBot->addMessageToQue("The command [$trigger] is not registerted to module [Reminders].");
        }
    }
}
?>
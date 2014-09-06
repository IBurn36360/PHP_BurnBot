<?php
// Was this accessed directly?  If so, exit.
if (!defined('IN_PHPBURNBOT'))
{
	exit;
}

/**
 * Module channel
 * 
 * @author Anthony 'IBurn36360' Diaz
 * @name Module Channel
 * @version 1.0.20
 * 
 * Handles channel added commands
 */
final class channel
{
    // Module vars
    protected $version = '1.0.43';
    protected $requiredCoreVersion = '2.0';
    protected $name = 'channel';
    protected $author = 'Anthony \'IBurn36360\' Diaz';
    protected $moduleDescription = 'Handles channel added commands';
    protected $dependencies = array();
    
    // Core and DB objects
    protected $burnBot;
    protected $db;
    
    // Core vars
    protected $sessionID;
    protected $acceptValues;
    
    // Flood protection
    protected $commandUseInterval = 15;
    
    protected $commands = array(
        'addcom' => array(
            'function'     => 'channel_addcom',
            'module'       => 'Channel',
            'operator'     => true,
            'regular'      => false,
            'user_layer_1' => false,
            'user_layer_2' => false
        ),
        'delcom' => array(
            'function'     => 'channel_delcom',
            'module'       => 'Channel',
            'operator'     => true,
            'regular'      => false,
            'user_layer_1' => false,
            'user_layer_2' => false
        ),
        'editcomoptions' => array(
            'function'     => 'channel_editCommandOptions',
            'module'       => 'Channel',
            'operator'     => true,
            'regular'      => false,
            'user_layer_1' => false,
            'user_layer_2' => false
        )
    );
    protected $commandOutputs    = array();
    protected $commandTypes      = array(); 
    protected $usedCommands      = array();
    protected $validCommandTypes = array('normal', 'action');
    
    public function __construct($register = false)
    {
        global $burnBot, $db;
        
        if ($register)
        {
            $burnBot->registerModule(array('Channel' => array(
                'class' => 'channel',
                'enabled' => true
            )));
        } else {
            // Construct the environment for module channel
            $this->burnBot = &$burnBot;
            $this->db = &$db;
            
            // Synch up with core
            $this->sessionID = $this->burnBot->getSessionID();
            $this->acceptValues = $this->burnBot->getAcceptValues();
            
            // Get all of the channel commands
            $sql = $this->db->buildSelect(BURNBOT_MODULE_CHANNEL_COMMANDS, array(
                '_trigger',
                'output',
                'operator',
                'regular',
                'user_layer_1',
                'user_layer_2',
                'type'
            ), array(
                'id' => $this->sessionID
            ));
            $result = $this->db->query($sql);
            
            if (isset($result->numRows) && $result->numRows)
            {
                // Load the commands and their outputs into the arrays
                foreach ($result->rows as $row)
                {
                    $this->commands = array_merge($this->commands, array($row['_trigger'] => array(
                        'function'     => 'channel_command',
                        'module'       => 'Channel',
                        'operator'     => $row['operator'],
                        'regular'      => $row['regular'],
                        'user_layer_1' => $row['user_layer_1'],
                        'user_layer_2' => $row['user_layer_2']
                    )));
                    $this->commandOutputs = array_merge($this->commandOutputs, array($row['_trigger'] => $row['output']));
                    $this->commandTypes   = array_merge($this->commandTypes, array($row['_trigger'] => ((in_array($row['type'], $this->validCommandTypes) ? $row['type'] : 'normal'))));
                    
                    // Now log (This WILL thrash disks, but that is why it is able to be turned off)
                    $this->burnBot->log('Channel command registered [' . $row['_trigger'] . '] Operator Layer: [' . (($row['operator']) ? 'true' : 'false') . '] Regular layer: [' . (($row['regular']) ? 'true' : 'false') . '] User layer 1: [' . (($row['user_layer_1']) ? 'true' : 'false') . '] User layer 2: [' . (($row['user_layer_2']) ? 'true' : 'false') . '] Output: [' . $row['output'] . '] Type: [' . $row['type'] . ']', 'channel');
                }
            }
            
            $this->burnBot->log('Total commands loaded: [' . count($this->commandOutputs) . ']', 'channel');
        }
    }
    
    public function init()
    {
        $this->burnBot->registerCommands($this->commands);
    }
    
    public function read($messageArr = array())
    {
        
    }
    
    public function tick()
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
    
    public function channel_command($sender, $args = array(), $trigger = '')
    {
        $time = time();
        
        if ($trigger && array_key_exists($trigger, $this->commandOutputs) && ((!array_key_exists($trigger, $this->usedCommands)) || ($this->usedCommands[$trigger] <= (($time - $this->commandUseInterval)))))
        {
            $this->checkMutation($sender, $args, $trigger);
            $this->usedCommands[$trigger] = $time;
        }
    }
    
    protected function checkMutation($sender, $args = array(), $trigger = '')
    {
        $mutated = $this->commandOutputs[$trigger];
        
        // Do mutation checks and change
        $mutated = str_replace('_SELF_', $sender, $mutated);
        
        // Allows commands to have a target
        if (strstr($mutated, '_TARGET_'))
        {
            if (empty($args))
            {
                $this->burnBot->addMessageToQue("The command [$trigger] requires arguments, none were supplied");
                return;
            }
            
            $mutated = str_replace('_TARGET_', $args[0], $mutated);
            array_shift($args);
        }
        
        // _ALL_ can safely be empty for quite a few cases where it might not be needed
        $mutated = str_replace('_ALL_', implode(' ', $args), $mutated);
        if (strstr($mutated, '_HTTPQUERY_'))
        {
            if (empty($args))
            {
                $this->burnBot->addMessageToQue("The command [$trigger] requires arguments, none were supplied");
                return;
            }
            
            $mutated = str_replace('_HTTPQUERY_', http_build_query($args), $mutated);
        }
        
        
        if (strstr($mutated, '_SEARCHQUERY_'))
        {
            if (empty($args))
            {
                $this->burnBot->addMessageToQue("The command [$trigger] requires arguments, none were supplied");
                return;
            }
            
            $mutated = $this->addSearchQuery($mutated, $args);
        }
        
        if (strstr($mutated, '_ARG'))
        {
            if (empty($args))
            {
                $this->burnBot->addMessageToQue("The command [$trigger] requires arguments, none were supplied");
                return;
            }
            
            $mutated = $this->addSpecifiedParams($mutated, $args, $trigger);
        }
        
        if (array_key_exists($trigger, $this->commandTypes))
        {
            switch ($this->commandTypes[$trigger])
            {
                case 'normal':
                    $this->burnBot->addMessageToQue($mutated);
                
                    break;
                
                case 'action':
                    $this->burnBot->addMessageToQue($mutated, 'action');
                
                    break;
                
                default:
                    $this->burnBot->log("Command trigger [$trigger] is not using a supported command type [" . $this->commandTypes[$trigger] . ']', 'channel');
                
                    break;
            }
        } else {
            $this->burnBot->addMessageToQue($mutated);
        }
    }
    
    protected function addSearchQuery($mutated, $args = array())
    {
        $cleaned = array();
        
        foreach ($args as $arg)
        {
            $cleaned[] = urlencode($arg);
        }
        
        return str_replace('_SEARCHQUERY_', implode('+', $cleaned), $mutated);
    }
    
    protected function addSpecifiedParams($mutated, $args = array(), $trigger = '')
    {
        $c = 0;
        
        while (strstr($mutated, '_ARG' . $c . '_') != '')
        {
            if (isset($args[$c]))
            {
                $mutated = str_replace('_ARG' . $c . '_', $args[$c], $mutated);
            } else {
                $this->burnBot->addMessageToQue("The command [$trigger] requires more parameters.  $c were provided");
                return;
            }
            
            $c++;
        }
        
        return $mutated;
    }
    
    public function channel_addcom($sender, $args = array(), $trigger = '')
    {
        $trigger = isset($args[0]) ? strtolower(trim($args[0], $this->burnBot->getCommandDelimeter())) : false;
        
        if (!$trigger)
        {
            // Dead attempt, ignore
            return;
        } elseif (array_key_exists($trigger, $this->commands)) {
            $this->burnBot->addMessageToQue("The command trigger [$trigger] already exists as a channel command.  Please choose a new trigger");
        } elseif ($this->burnBot->commandIsRegistered($trigger)) {
            $this->burnBot->addMessageToQue("The command trigger [$trigger] is currently registered with core and can not be used.  Please choose a new trigger.");
        }
        
        array_shift($args);
        $output = implode(' ', $args);
        
        if (!$output)
        {
            $this->burnBot->addMessageToQue('No command output was specified, please specify an output');
        }
        
        // Alright, time to deal with adding the command into DB
        $sql = $this->db->buildSelect(BURNBOT_MODULE_CHANNEL_COMMANDS, array(
            '1'
        ), array(
            'id'       => $this->sessionID,
            '_trigger' => $trigger
        ));
        $result = $this->db->query($sql);
        
        if (!$result->numRows)
        {
            $sql = $this->db->buildInsert(BURNBOT_MODULE_CHANNEL_COMMANDS, array(
                'id'       => $this->sessionID,
                '_trigger' => $trigger,
                'output'   => $output
            ));
            $result = $this->db->query($sql);
            
            if ($result)
            {
                // We succeeded, add the command and output now
                $this->commands = array_merge($this->commands, array($trigger=> array(
                    'function'     => 'channel_command',
                    'module'       => 'Channel',
                    'operator'     => false,
                    'regular'      => false,
                    'user_layer_1' => false,
                    'user_layer_2' => false
                )));
                $this->commandOutputs = array_merge($this->commandOutputs, array($trigger => $output));
                
                $this->burnBot->registerCommands(array($trigger => array(
                    'function'     => 'channel_command',
                    'module'       => 'Channel',
                    'operator'     => false,
                    'regular'      => false,
                    'user_layer_1' => false,
                    'user_layer_2' => false
                )));
                $this->burnBot->addMessageToQue("The command [$trigger] has been successfully added");
                
            } else {
                $this->burnBot->addMessageToQue("There was an error adding the command [$trigger].  Please see logs for details");
            }
        } else {
            // This really should not happen, but you never know.  Be safe then sorry
            $this->burnBot->addMessageToQue("The command trigger [$trigger] exists in database.  Please delete the old command or specify a new trigger");
        }
    }
    
    public function channel_delcom($sender, $args = array(), $trigger = '')
    {
        $trigger = isset($args[0]) ? strtolower($args[0]) : null;
        
        if (is_null($trigger))
        {
            // Dead attempt, ignore
            return;
        } elseif (!array_key_exists($trigger, $this->commands)) {
            $this->burnBot->addMessageToQue("The command trigger [$trigger] does not exist as a channel command.  Please choose another trigger");
        }
        
        // Prepare the delete
        $sql = $this->db->buildDelete(BURNBOT_MODULE_CHANNEL_COMMANDS, array(
            'id'       => $this->sessionID,
            '_trigger' => $trigger
        ));
        $result = $this->db->query($sql);
        
        if ($result)
        {
            // Unregister the command in all arrays now
            $this->burnBot->unregisterCommands(array($trigger));
            unset($this->commands[$trigger], $this->commandOutputs[$trigger]);
            
            $this->burnBot->addMessageToQue("The command [$trigger] has been successfully deleted");
        } else {
            $this->burnBot->addMessageToQue("There was an error trying to delete the command [$trigger].  Please see logs for details");
        }
    }
    
    // Core override handlers
    
    public function channel_editcom($sender, $args = array(), $trigger = '')
    {
        $trigger = isset($args[0]) ? strtolower($args[0]) : false;
        $op      = isset($args[1]) ? $args[1] : false;
        $reg     = isset($args[2]) ? $args[2] : false;
        $ul1     = isset($args[3]) ? $args[3] : false;
        $ul2     = isset($args[4]) ? $args[4] : false;
        
        if (!$trigger || !$op || !$reg || !$ul1 || !$ul2)
        {
            // Invalid request, ignore
            return;
        } elseif (!array_key_exists($trigger, $this->commands)) {
            $this->burnBot->addMessageToQue("The command trigger [$trigger] does not exist as a channel command.  Please choose another trigger");
        }
        
        for ($i = 0; $i <= 4; $i++)
        {
            array_shift($args);
        }
        
        $op  = in_array($op, $this->acceptValues);
        $reg = in_array($reg, $this->acceptValues);
        $ul1 = in_array($ul1, $this->acceptValues);
        $ul2 = in_array($ul2, $this->acceptValues);
        $output = implode(' ', $args);
        if (!$output)
        {
            $output = $this->commandOutputs[$trigger];
        }
        
        // Alright, time to update the command
        $sql = $this->db->buildUpdate(BURNBOT_MODULE_CHANNEL_COMMANDS, array(
            'output'       => $output,
            'operator'     => $op,
            'regular'      => $reg,
            'user_layer_1' => $ul1,
            'user_layer_2' => $ul2
        ), array(
            'id'       => $this->sessionID,
            '_trigger' => $trigger
        ));
        $result = $this->db->query($sql);
        
        if ($result)
        {
            // Update core and local
            $arr = array(
                'function'     => 'channel_command',
                'module'       => 'channel',
                'operator'     => $op,
                'regular'      => $reg,
                'user_layer_1' => $ul1,
                'user_layer_2' => $ul2
            );
            
            $this->commands[$trigger] = $arr;
            $this->commandOutputs[$trigger] = $output;
            $this->burnBot->updateCommand($trigger, $arr);
            
            $this->burnBot->addMessageToQue("Command [$trigger] has been updated");
        } else {
            $this->burnBot->addMessageToQue("There was an error trying to update the command [$trigger].  Please see logs for details");
        }
    }
    
    public function channel_editCommandOptions($sender, $args = array(), $trigger = '')
    {
        $trigger = isset($args[0]) ? strtolower($args[0]) : false;
        $type    = isset($args[1]) ? $args[1] : false;
        
        if (!$trigger)
        {
            // Invalid request, ignore
            return;
        } elseif (!array_key_exists($trigger, $this->commands)) {
            $this->burnBot->addMessageToQue("The command trigger [$trigger] does not exist as a channel command.  Please choose another trigger");
        }
        
        $type = ($type && in_array($type, $this->validCommandTypes)) ? $type : 'default';
        
        $sql = $this->db->buildUpdate(BURNBOT_MODULE_CHANNEL_COMMANDS, array(
            'type' => $type
        ), array(
            'id'       => $this->sessionID,
            '_trigger' => $trigger
        ));
        $result = $this->db->query($sql);
        
        if ($result)
        {
            $this->commandTypes[$trigger] = $type;
            
            $this->burnBot->addMessageToQue("Command [$trigger] has been updated");
        } else {
            $this->burnBot->addMessageToQue("There was an error trying to update the command [$trigger].  Please see logs for details");
        }
    }
    
    // Help function
    public function channel_help($sender, $trigger = '')
    {
        if (array_key_exists($trigger, $this->commands) || in_array($trigger, $this->burnBot->getOverridableCommands()))
        {
            switch ($trigger)
            {
                case 'addcom':
                    $this->burnBot->addMessageToQue('Usage: [' . $this->burnBot->getCommandDelimeter() . 'addcom {trigger} {output}].  Adds a new channel added command with a custom output.  Defaults as a user command.');
                    $this->burnBot->addMessageToQue('Channel commands can accept several replacement strings to allow channel commands to accept arguments.');
                    $this->burnBot->addMessageToQue('[_SELF_]: Replaced with the nick of the user who ran the command.');
                    $this->burnBot->addMessageToQue('[_TARGET_]: Replaced with the first argument after the trigger.  This removes the first argument from all remaning replacements.');
                    $this->burnBot->addMessageToQue('[_ALL_]: Replaced with all remaining arguments with spaces in between.');
                    $this->burnBot->addMessageToQue('[_HTTPQUERY_]: Replaced with a HTTP query built from all of the remaining arguments.');
                    $this->burnBot->addMessageToQue('[_SEARCHQUERY_]: Replaced with a sanitized search query built from all of the remaining arguments.');
                    $this->burnBot->addMessageToQue('[_ARG#_]: Replaced with the specified argument number.  If numbers are skipped, all arguments after the skipped one are ignored and if not enough arguments are provided, the command will fail.');
                
                    break;
                
                case 'delcom':
                    $this->burnBot->addMessageToQue('Usage: [' . $this->burnBot->getCommandDelimeter() . 'delcom {trigger}].  Deletes a channel command and unregisters it completely.');
                
                    break;
                    
                case 'editcom':
                    $this->burnBot->addMessageToQue('This command overrides the core command.');
                    $this->burnBot->addMessageToQue('Usage: [' . $this->burnBot->getCommandDelimeter() . 'editcom {trigger} {operator layer} {regular layer} {user layer 1} {user layer 2} {output}].  Edits the command with new permission layers and a new output if supplied.  Is no output is supplied, the current one will be retained.');
                
                    break;
                    
                case 'editcomoptions':
                    $this->burnBot->addMessageToQue('Usage: [' . $this->burnBot->getCommandDelimeter() . 'editcomoptions {trigger} {type}].  Edits the command with new options that change the behavior of the command in specific ways.');
                    $this->burnBot->addMessageToQue('[Type]: Changes how the command is sent.  Currently accepts [' . implode(', ', $this->validCommandTypes) . '].  Action sends an action to the channel (\'/me in IRC clients\')');
                
                    break;
                
                // This will catch any channel added commands, allowing the raw to be seen
                default:
                    if (array_key_exists($trigger, $this->commandOutputs))
                    {
                        $this->burnBot->addMessageToQue("Channel added command [$trigger]: Output: [" . $this->commandOutputs[$trigger] . ']');
                    } else {
                        $this->burnBot->addMessageToQue("The command [$trigger] is registered to module [Channel], but is not a Channel added command and has no help associated with it.");
                    }
                
                    break;
            }
        } else {
            $this->burnBot->addMessageToQue("The command [$trigger] is not registerted to module [Channel].");
        }
    }
}
?>
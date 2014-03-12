<?php
// Was this accessed directly?  If so, exit.
if (!defined('IN_IRC'))
{
	exit;
}

class reminder
{
    protected $sessionID = 0;
    protected $commandDelimeter = '';
    protected $nick = '';
    
    protected $lastReminderTime = 0;
    protected $lastmessageTime = 0;
    protected $enabled = false;
    protected $waitForActivity = true;
    protected $defaultTTL = 300;
    
    // The minimum ammount of time between reminders.  Although they will have their own limits, This stops reminders from being sent on top of each other
    protected $reminderDelayPeriod = 120;
    protected $messageDelayPeriod  = 600;
    
    protected $commands = array(
        'addreminder'           => array('reminders', 'reminders_addreminder', true, false, false, false),
        'delreminder'           => array('reminders', 'reminders_delreminder', true, false, false, false),
        'editreminder'          => array('reminders', 'reminders_editreminder', true, false, false, false),
        'reminders'             => array('reminders', 'reminders_reminders', true, false, false, false),
        'reminders_addcommand'  => array('reminders', 'reminders_addcommand', true, false, false, false),
        'reminders_delcommand'  => array('reminders', 'reminders_delcommand', true, false, false, false),
        'reminders_editcommand' => array('reminders', 'reminders_editcommand', true, false, false, false),
        'reminders_commands'    => array('reminders', 'reminders_commands', true, false, false, false)
    );
    
    protected $remindersStack = array();
    protected $reminders = array();
    
    protected $commandsStack = array();
    
    function __construct($register = false)
    {
        global $burnBot, $irc, $db;
        
        if ($register)
        {
            // Register the module
            $burnBot->registerModule(array('reminders' => array('enabled' => true, 'class' => 'reminder')));            
        } else {
            // Synch up with core
            $this->sessionID = $burnBot->getSessionID();
            $this->commandDelimeter = $burnBot->getCommandDelimeter();
            $this->nick = $burnBot->getNick();
            
            $this->lastReminderTime = time();
            $this->lastmessageTime  = time();
            $this->lastLogTime      = time();
            
            // Grab base config
            $sql = $db->sql_build_select(BURNBOT_REMINDERSCONFIG, array(
                'enabled',
                'activity'
            ), array(
                'id' => $this->sessionID
            ));
            $result = $db->sql_query($sql);
            $rows = $db->sql_fetchrow($result);
            $db->sql_freeresult($result);
            
            if (!empty($rows))
            {
                $this->enabled = ($rows['enabled'] == '1') ? true : false;
                $this->waitForActivity = ($rows['activity'] == '1') ? true : false;
                
                $str = '';
                $str .= ($this->enabled) ? 'Enabled(true) ' : 'Enabled(false) ';
                $str .= ($this->waitForActivity) ? 'Require Activity(true)' : 'Require Activity(false)';
                
                $irc->_log_action("Loaded configuration: $str", 'reminders');
            } else {
                // Create
                $sql = $db->sql_build_insert(BURNBOT_REMINDERSCONFIG, array(
                    'id' => $this->sessionID
                ));
                $result = $db->sql_query($sql);
                $db->sql_freeresult($result);
            }
            
            $irc->_log_action('Reminders module environment constructed');
        }
    }
    
    public function init()
    {
        global $burnBot, $db, $irc;
        
        $time = time();
        $counter = 0;
        
        // SQL time, grab our reminders and construct the array
        $sql = $db->sql_build_select(BURNBOT_REMINDERS, array(
            'name',
            'output',
            'ttl'
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
                $ttl = intval($row['ttl']);
                $this->reminders[$row['name']] = array('output' => $row['output'], 'ttl' => $row['ttl']);
            }
        }
        
        // Now grab recurring commmands
        $sql = $db->sql_build_select(BURNBOT_REMINDERSCOMMANDS, array(
            'name',
            '_trigger',
            'args',
            'ttl'
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
                $ttl = intval($row['ttl']);
                $this->commandsStack[$row['name']] = array('ttl' => $row['ttl'], 'args' => $row['args'], 'trigger' => $row['_trigger']);
            }
        }
        
        // Build the initial stack of reminders
        if (!empty($this->reminders))
        {
            foreach($this->reminders as $name => $arr)
            {
                // Start the stack, key is the time that the reminder can be sent at
                $this->remindersStack[($time + $counter)] = array('name' => $name, 'output' => $arr['output'], 'ttl' => $arr['ttl']);
                $counter++;
            }            
        }
        
        if (!empty($this->commandsStack))
        {
            foreach($this->commandsStack as $name => $arr)
            {
                $this->remindersStack[($time + $counter)] = array('name' => $name, 'trigger' => $arr['trigger'], 'ttl' => $arr['ttl'], 'args' => $arr['args']);
                $counter++;
            }            
        }
        
        $burnBot->registerCommads($this->commands);
        
        $irc->_log_action('Reminder module initialized', 'init');
    }
    
    public function _read($messageArr)
    {
        if ($messageArr['type'] == 'private')
        {
            // Update the last time we had a message in the channel
            $this->lastmessageTime = time();
        }
    }
    
    // Checks the reminders que and processes it (DOES NOT STOP RUNNING EVEN IF DISABLED)
    public function tick()
    {
        global $burnBot, $irc;
        
        $tickStartTime = time();
        
        // Do we have everything we need to start sending reminders?
        if ((!$burnBot->getHasJoined()) || empty($this->remindersStack))
        {
            // No need to even tick yet or there are no events to run.  Just leave this now
            return;
        }
        
        // Is there a requirement for chat activity?
        if (($this->waitForActivity) && ($tickStartTime >= ($this->lastmessageTime + $this->messageDelayPeriod)))
        {
            return;
        }
        
        if ($tickStartTime > $this->lastReminderTime)
        {
            // Reset this in case the pointer is somewhere other than the top
            reset($this->remindersStack);
            
            $current = key($this->remindersStack);
            
            // Are we allowed to run a recurring event?
            if ($tickStartTime >= $this->lastReminderTime)
            {
                // Yes, check the event
                $reminder = $this->remindersStack[$current];
                $name = $reminder['name'];
                
                $irc->_log_action("Processing key $current", "reminders");
                
                // Are we running a command?
                if ($this->enabled)
                {
                    if (array_key_exists('trigger', $reminder))
                    {
                        $this->runCommand($reminder['trigger'], $reminder['args']);
                        $update = $tickStartTime + $this->reminderDelayPeriod;
                        $irc->_log_action("Reminder TTL updated to $update", "reminders");
                        $this->lastReminderTime = $update;
                    } else {
                        // Assume that it is an output
                        $output = (array_key_exists('output', $reminder)) ? $reminder['output'] : false;
                        
                        if ($output === false)
                        {
                            $irc->_log_error("Reminder or command $name not in proper format");
                        } else {
                            $burnBot->addMessageToQue($output);
                            $update = $tickStartTime + $this->reminderDelayPeriod;
                            $irc->_log_action("Reminder TTL updated to $update", "reminders");
                            $this->lastReminderTime = $update;
                        }
                    }
                }
                
                // Even if we failed or if we are disabled right now, proccess the stack and shift it
                $next = $current + $reminder['ttl'];
                
                // Do not overwrite another reminder
                while (array_key_exists($next, $this->remindersStack))
                {
                    $next++;
                }
                
                unset($this->remindersStack[$current]);
                $this->remindersStack[$next] = $reminder;
                
                ksort($this->remindersStack, SORT_NUMERIC);
            }
        }
    }
    
    private function runCommand($trigger, $msg = '')
    {
        global $burnBot;
        
        $burnBot->runCommand($this->nick, $trigger, $msg);
    }
    
    public function reminders_addreminder($sender, $msg = '')
    {
        global $burnBot, $db;
        
        // Synch up with the command delimeter
        $this->commandDelimeter = $burnBot->getCommandDelimeter();
        
        if ($msg == '')
        {
            $burnBot->addMessageToQue('Not enough data provided to add reminder.  Usage: ' . $this->commandDelimeter . 'addreminder {name} {output}');
             return;
        }
        
        $split = explode(' ', $msg);
        $name = strtolower($split[0]);
        array_shift($split);
        $output = implode(' ', $split);
        
        // Do not allow people to try and add a reminder if the name is taken already
        if (array_key_exists($name, $this->commandsStack) || array_key_exists($name, $this->reminders))
        {
            $burnBot->addMessageToQue("The name $name is reserved by another reminder or a command, please select another name");
            return;
        }
        
        // No output, do not allow dud reminders
        if ($output == '')
        {
            $burnBot->addMessageToQue('Not enough data provided to add reminder.  Usage: ' . $this->commandDelimeter . 'addreminder {name} {output}');
            return;
        }
        
        // Build the SQL
        $sql = $db->sql_build_insert(BURNBOT_REMINDERS, array(
            'id' => $this->sessionID,
            'name' => $name,
            'output' => $output,
            'ttl' => $this->defaultTTL
        ));
        $result = $db->sql_query($sql);
        if ($result !== false)
        {
            $burnBot->addMessageToQue("Reminder $name has been sucessfully added");
            
            // Update the registered reminders and stack here
            $this->reminders = array_merge($this->reminders, array($name => array('output' => $output, 'ttl' => $this->defaultTTL)));
            
            // pass it into the stack
            $time = time() + $this->reminderDelayPeriod;
            while (array_key_exists($time, $this->remindersStack))
            {
                $time++;
            }
            
            $this->remindersStack[$time] = array('name' => $name, 'ttl' => $this->defaultTTL, 'output' => $output);
        } else {
            $burnBot->addMessageToQue("Reminder $name has not been added.  Please check logs");
        }
        $db->sql_freeresult($result);
    }
    
    public function reminders_delreminder($sender, $msg = '')
    {
        global $burnBot, $db;
        
        // Synch up with the command delimeter
        $this->commandDelimeter = $burnBot->getCommandDelimeter();
        
        if ($msg == '')
        {
            $burnBot->addMessageToQue('No reminder supplied for deletion, please specify a reminder to delete');
            return;
        }
        
        $split = explode(' ', $msg);
        $name = strtolower($split[0]);
        
        if (!array_key_exists($name, $this->reminders))
        {
            $burnBot->addMessageToQue("The provided reminder $name does not exist, please use the command " . $this->commandDelimeter . 'reminders to get the list of registered reminders');
            return;            
        }
        
        $sql = $db->sql_build_delete(BURNBOT_REMINDERS, array(
            'id' => $this->sessionID,
            'name' => $name
        ));
        $result = $db->sql_query($sql);
        if ($result !== false)
        {
            $burnBot->addMessageToQue("Reminder $name has been sucessfully deleted");
            
            // Update the registered reminders
            unset($this->reminders[$name]);
        } else {
            $burnBot->addMessageToQue("Reminder $name has not been deleted.  Please check logs");
        }
        $db->sql_freeresult($result);
        
        // Now remove the reminder from both the register and the stack
        unset($this->reminders[$name]);
        
        // The stack
        {
            foreach($this->remindersStack as $time => $arr)
            {
                if ($arr['name'] == $name)
                {
                    unset($this->remindersStack[$time]);
                    break;
                }
            }
        }
    }
    
    public function reminders_editreminder($sender, $msg = '')
    {
        global $burnBot, $db;
        
        // Synch up with the command delimeter
        $this->commandDelimeter = $burnBot->getCommandDelimeter();
        
        // Do we have data?
        if ($msg == '')
        {
            $this->help('editreminder');
            return;
        }
        
        $split = explode(' ', $msg);
        $name = strtolower($split[0]);
        $ttl = isset($split[1]) ? intval($split[1]) : false;
        array_shift($split);
        array_shift($split);
        $output = implode(' ', $split);
        
        if (!array_key_exists($name, $this->reminders))
        {
            $burnBot->addMessageToQue("Reminder $name does not exist.  Please use command " . $this->commandDelimeter . "reminders to get a list of currently registered reminders");
            return;
        }
        
        if ($ttl == false)
        {
            $this->help('editreminder');
            return;
        }
        
        // Make sure we can update the TTL independant of the output
        if ($output == '')
        {
            $output = $this->reminders[$name]['output'];
        }
        
        $sql = $db->sql_build_update(BURNBOT_REMINDERS, array(
            'ttl' => $ttl,
            'output' => $output
        ), array(
            'id' => $this->sessionID,
            'name' => $name
        ));
        $result = $db->sql_query($sql);
        if ($result !== false)
        {
            $burnBot->addMessageToQue("Reminder $name has been sucessfully updated");
            
            // Update the register and and stack
            $this->reminders = array_merge($this->reminders, array($name => array('output' => $output, 'ttl' => $ttl)));
            
            foreach ($this->remindersStack as $time => $arr)
            {
                if ($arr['name'] == $name)
                {
                    $this->remindersStack[$time] = array('name' => $name, 'output' => $output, 'ttl' => $ttl);
                    break;
                }
            }
        } else {
            $burnBot->addMessageToQue("Reminder $name has not been updated.  Please check logs");
        }
        $db->sql_freeresult($result);
    }
    
    public function reminders_reminders($sender, $msg = '')
    {
        global $burnBot, $db;
        
        if ($msg == '')
        {
            $reminders = '';
            
            foreach ($this->reminders as $name => $arr)
            {
                $reminders .= "$name, ";
            }
            
            $reminders = rtrim($reminders, ', ');
            
            $burnBot->addMessageToQue("Currently registered reminders: $reminders");
            
            return;
        } else {
            // make sure anything else is dropped
            $split = explode(' ', $msg);
            if (($split[0] == 'enable') || ($split[0] == 'disable'))
            {
                $chatActivity = (isset($split[1])) ? strtolower($split[1]) : false;
                if ($chatActivity !== false)
                {
                    $chatActivity = (($chatActivity == 'true') || ($chatActivity == 't') || ($chatActivity == 'yes') || ($chatActivity == 'y') || ($chatActivity == 'enabled')) ? true : false;
                } else {
                    $chatActivity = $this->waitForActivity;
                }
                
                if ($split[0] == 'enable')
                {
                    $sql = $db->sql_build_update(BURNBOT_REMINDERSCONFIG, array(
                        'enabled' => true,
                        'activity' => $chatActivity
                    ), array(
                        'id' => $this->sessionID
                    ));
                    $result = $db->sql_query($sql);
                    if ($result !== false)
                    {
                        $str = ($chatActivity) ? 'Reminders are enabled with chat activity required' : 'Reminders are enabled with chat activity not required';
                        $burnBot->addMessageToQue($str);
                        $db->sql_freeresult($result);
                        
                        $this->enabled = true;
                        $this->waitForActivity = $chatActivity;
                    } else {
                        $burnBot->addMessageToQue("An error occured while trying to enable reminders.  Please see log");
                        $db->sql_freeresult($result);
                    }
                } else {
                    $sql = $db->sql_build_update(BURNBOT_REMINDERSCONFIG, array(
                        'enabled' => false,
                        'activity' => $chatActivity
                    ), array(
                        'id' => $this->sessionID
                    ));
                    $result = $db->sql_query($sql);
                    if ($result !== false)
                    {
                        $str = ($chatActivity) ? 'Reminders are disabled with chat activity required' : 'Reminders are disabled with chat activity not required';
                        $burnBot->addMessageToQue($str);
                        $db->sql_freeresult($result);
                        
                        $this->enabled = false;
                        $this->waitForActivity = $chatActivity;
                    } else {
                        $burnBot->addMessageToQue("An error occured while trying to disable reminders.  Please see log");
                        $db->sql_freeresult($result);
                    }
                }
            } else {
                $name = strtolower($split[0]);
                
                if (array_key_exists($name, $this->reminders))
                {
                    $message = "$name: TTL[" . $this->reminders[$name]['ttl'] . '], Output[' . $this->reminders[$name]['output'] . ']';
                    $burnBot->addMessageToQue($message);
                } else {
                    $burnBot->addMessageToQue("There is no reminder with the name: $name");
                }
                
                return;
            }
        }
    }
    
    public function reminders_addcommand($sender, $msg = '')
    {
        global $burnBot, $db;
        
        // Synch up with the command delimeter
        $this->commandDelimeter = $burnBot->getCommandDelimeter();
        
        if ($msg == '')
        {
            $burnBot->addMessageToQue('Not enough data provided to add command.  Usage: ' . $this->commandDelimeter . 'reminders_addcommand {name} {output}');
             return;
        }
        
        $split = explode(' ', $msg);
        $name = strtolower($split[0]);
        $trigger = $split[1];
        array_shift($split);array_shift($split);
        $args = implode(' ', $split);
        
        // Do not allow people to try and add a reminder if the name is taken already
        if (array_key_exists($name, $this->commandsStack) || array_key_exists($name, $this->reminders))
        {
            $burnBot->addMessageToQue("The name $name is reserved by another reminder or a command, please select another name");
            return;
        }
        
        $sql = $db->sql_build_insert(BURNBOT_REMINDERSCOMMANDS, array(
            'id' => $this->sessionID,
            'name' => $name,
            'args' => $args,
            '_trigger' => $trigger,
            'ttl' => $this->defaultTTL
        ));
        $result = $db->sql_query($sql);
        if ($result !== false)
        {
            $burnBot->addMessageToQue("Recurring command $name has been successfully added");
            
            // Update the registered reminders and stack here
            $this->commandsStack = array_merge($this->commandsStack, array($name => array('args' => $args, 'ttl' => $this->defaultTTL, 'trigger' => $trigger, 'name' => $name)));
            
            // pass it into the stack
            $time = time() + $this->defaultTTL;
            while (array_key_exists($time, $this->remindersStack))
            {
                $time++;
            }
            
            $this->remindersStack = array_merge($this->remindersStack, array($time => array('args' => $args, 'ttl' => $this->defaultTTL, 'trigger' => $trigger, 'name' => $name)));
        } else {
            $burnBot->addMessageToQue("Recurring command $name has not been successfully added.  Please check logs");
        }
        $db->sql_freeresult($result);
    }
    
    public function reminders_delcommand($sender, $msg = '')
    {
        global $burnBot, $db;
        
        // Synch up with the command delimeter
        $this->commandDelimeter = $burnBot->getCommandDelimeter();
        
        if ($msg == '')
        {
            $burnBot->addMessageToQue('No recurring command supplied for deletion, please specify a reminder to delete');
            return;
        }
        
        $split = explode(' ', $msg);
        $name = strtolower($split[0]);
        
        if (!array_key_exists($name, $this->commandsStack))
        {
            $burnBot->addMessageToQue('The provided recurring command $name does not exist, please use the command ' . $this->commandDelimeter . 'reminders_commands to get the list of registered recurring commands');
            return;            
        }
        
        $sql = $db->sql_build_delete(BURNBOT_REMINDERSCOMMANDS, array(
            'id' => $this->sessionID,
            'name' => $name
        ));
        $result = $db->sql_query($sql);
        if ($result !== false)
        {
            $burnBot->addMessageToQue("Recurring command $name has been sucessfully deleted");
            
            // Update the registered reminders
            unset($this->commandsStack[$name]);
        } else {
            $burnBot->addMessageToQue("Recurring command $name has not been deleted.  Please check logs");
            return;
        }
        $db->sql_freeresult($result);
        
        // The stack
        {
            foreach($this->remindersStack as $time => $arr)
            {
                if ($arr['name'] == $name)
                {
                    unset($this->remindersStack[$time]);
                    break;
                }
            }
        }
    }
    
    public function reminders_editcommand($sender, $msg = '')
    {
        global $db, $burnBot;
        
        // Synch up with the command delimeter
        $this->commandDelimeter = $burnBot->getCommandDelimeter();
        
        // Stop if there is no data
        if ($msg == '')
        {
            $this->help('reminders_editcommand');
            return;
        }
        
        $split = explode(' ', $msg);
        $name = strtolower($split[0]);
        $ttl = (isset($split[1])) ? intval($split[1]) : false;
        $trigger = (isset($split[2])) ? $split[2] : false;
        array_shift($split);
        array_shift($split);
        array_shift($split);
        $args = implode(' ', $split);
        
        // Stop an edit when there is no TTL at least
        if ($ttl == false)
        {
            $burnBot->addMessageToQue("There was not enough data provided to edit the recurring command. Usage: " . $this->commandDelimeter . 'reminders_editcommand {name} {ttl} {trigger} {args}');
            return;
        }
        
        // Check to see if anything else is set and set to current if no update data is provided
        if ($trigger == false)
        {
            $trigger = $this->commandsStack[$name]['trigger'];
        }
        if ($args == '')
        {
            $args = $this->commandsStack[$name]['args'];
        }
        
        // Update the register and and stack
        $this->commandsStack = array_merge($this->commandsStack, array($name => array('name' => $name, 'trigger' => $trigger, 'ttl' => $ttl, 'args' => $args)));
        
        foreach ($this->remindersStack as $time => $arr)
        {
            if ($arr['name'] == $name)
            {
                $this->remindersStack = array_merge($this->remindersStack, array($time => array('name' => $name, 'trigger' => $trigger, 'ttl' => $ttl, 'args' => $args)));
                break;
            }
        }
        
        $sql = $db->sql_build_update(BURNBOT_REMINDERSCOMMANDS, array(
            'ttl' => $ttl,
            'trigger' => $trigger,
            'args' =>$args
        ), array(
            'id' => $this->sessionID,
            'name' => $name
        ));
        $result = $db->sql_query($sql);
        if ($result !== false)
        {
            $burnBot->addMessageToQue("Recurring command $name has been sucessfully updated");
            
            // Update the registered reminders
            unset($this->reminders[$name]);
        } else {
            $burnBot->addMessageToQue("Recurring command $name has not been updated.  Please check logs");
        }
        $db->sql_freeresult($result);
    }
    
    public function reminders_commands($sender, $msg = '')
    {
        global $burnBot;
        
        if ($msg == '')
        {
            $commands = '';
            
            foreach ($this->commandsStack as $name => $arr)
            {
                $commands .= "$name, ";
            }
            
            $commands = rtrim($commands, ', ');
            
            $burnBot->addMessageToQue("Currently registered recurring commands: $commands");
        } else {
            $name = strtolower($split[0]);
            
            if (array_key_exists($name, $this->commands))
            {
                $message = "$name: TTL[" . $this->commandsStack[$name]['ttl'] . '], Command[' . $this->commandsStack[$name]['command'] . '], Args[' . $this->commandsStack[$name]['args'] . ']';
                $burnBot->addMessageToQue($message);
            } else {
                $burnBot->addMessageToQue("There is no recurring command with the name: $name");
            }
        }
    }
    
    public function help($trigger)
    {
        global $burnBot;
        
        // Synch up with the command delimeter
        $this->commandDelimeter = $burnBot->getCommandDelimeter();
        
        if ($trigger != false)
        {
            switch ($trigger)
            {
                case 'delreminder':
                    $burnBot->addMessageToQue('Usage: ' . $this->commandDelimeter . 'delreminder {name}.  Deletes the specified reminder if it exists');
                    break;
                    
                case 'addreminder':
                    $burnBot->addMessageToQue('Usage: ' . $this->commandDelimeter . 'addreminder {name} {output}.  Adds the specified reminder with the output');
                    break;
                    
                case 'editreminder':
                    $burnBot->addMessageToQue('Usage: ' . $this->commandDelimeter . 'editreminder {name} {ttl} {output}.  Edits the specified reminder with new data.  TTL is the number of seconds between reminders, this does not override the 120 second minimum between reminders');
                    break;
                
                case 'reminders':
                    $burnBot->addMessageToQue('Usage: ' . $this->commandDelimeter . 'reminders.  Outputs the name of all currently registered commands.  Usage: ' . $this->commandDelimeter . 'reminders {name}. Retrieves information about the reminder specified');
                    $burnBot->addMessageToQue('Usage: ' . $this->commandDelimeter . 'reminders {enable/disable} {require activity}.  Enables or disables recurring messages.  The recurring activity parameter sets weather chat activity is needed for recurring messages to be processed');
                    break;
                    
                case 'reminders_addcommand':
                    $burnBot->addMessageToQue('Usage: ' . $this->commandDelimeter . 'reminders_addcommand {name} {output}.  Adds a new command to be run as a recurring event');
                    break;
                    
                case 'reminders_delcommand':
                    $burnBot->addMessageToQue('Usage: ' . $this->commandDelimeter . 'reminders_delcommand {name}.  Removes a recurring command');
                    break;
                
                case 'reminders_editcommand':
                    $burnBot->addMessageToQue('Usage: ' . $this->commandDelimeter . 'reminders_editcommand {name} {ttl} {trigger} {args}.  Edits a recurring command with the information provided');
                    break;
                
                case 'reminders_commands':
                    $burnBot->addMessageToQue('Usage: ' . $this->commandDelimeter . 'reminders_commands.  Lists all registered recurring commands. Usage: ' . $this->commandDelimeter . 'reminders_commands {name}. Displays information about the specified recurring command');
                    break;
                
                default:
                    $burnBot->addMessageToQue("The command specified is not part of module reminders");
                    break;
            }
        } else {
            $burnBot->addMessageToQue("This module handles all repeating tasks, such as sending messages or performing commands");
        }
    }
}
?>
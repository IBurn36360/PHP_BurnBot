<?php
// Was this accessed directly?  If so, exit.
if (!defined('IN_IRC'))
{
	exit;
}

class moderation
{
    protected $config = array();
    protected $inTesting = true;
    
    protected $constructed = false;
    
    protected $isTwitch = false;
    protected $sessionID = 0;
    
    protected $urlFools = array(
        '[ (dot) ]i',
        '[(dot)]i',
        '[ . ]'
    );
    
    // Populated by the database
    protected $urlRegex = array();
    protected $words = array();
    
    protected $commands = array(
        'filter'  => array('moderation', 'moderation_filter', true, false, false, false),
        'filters' => array('moderation', 'moderation_filters', true, false, false, false)
    );
    
    function __construct($register = false)
    {
        global $irc, $burnBot, $db;
        
        if ($register)
        {
            // Register the module
            $burnBot->registerModule(array('moderation' => array('enabled' => true, 'class' => 'moderation')));
        } else {
            // Synch up with core
            $this->sessionID = $burnBot->getSessionID();
            $this->isTwitch  = $burnBot->getIsTwitch();
            
            // Grab the config
            $sql = $db->sql_build_select(BURNBOT_MODERATION_CONFIG, array(
                'url',
                'url_allow_reg',
                'url_allow_turbo',
                'url_allow_sub',
                'chrspam',
                'chrspam_allow_reg',
                'chrspam_allow_turbo',
                'chrspam_allow_sub',
                'cpsspam',
                'cpsspam_allow_reg',
                'cpsspam_allow_turbo',
                'cpsspam_allow_sub',
                'ascii',
                'ascii_allow_reg',
                'ascii_allow_turbo',
                'ascii_allow_sub',
                'ip',
                'ip_allow_reg',
                'ip_allow_turbo',
                'ip_allow_sub'
            ), array(
                'id' => $this->sessionID
            ));
            $result = $db->sql_query($sql);
            $rows = $db->sql_fetchrow($result);
            $db->sql_freeresult($result);
            
            if (!empty($rows))
            {
                $this->config = array(
                    'url' => ($rows['url'] == '1') ? true : false,
                    'url_allow_reg' => ($rows['url_allow_reg'] == '1') ? true : false,
                    'url_allow_turbo' => ($rows['url_allow_turbo'] == '1') ? true : false,
                    'url_allow_sub' => ($rows['url_allow_sub'] == '1') ? true : false,
                    'chrspam' => ($rows['chrspam'] == '1') ? true : false,
                    'chrspam_allow_reg' => ($rows['chrspam_allow_reg'] == '1') ? true : false,
                    'chrspam_allow_turbo' => ($rows['chrspam_allow_turbo'] == '1') ? true : false,
                    'chrspam_allow_sub' => ($rows['chrspam_allow_sub'] == '1') ? true : false,
                    'cpsspam' => ($rows['cpsspam'] == '1') ? true : false,
                    'cpsspam_allow_reg' => ($rows['cpsspam_allow_reg'] == '1') ? true : false,
                    'cpsspam_allow_turbo' => ($rows['cpsspam_allow_turbo'] == '1') ? true : false,
                    'cpsspam_allow_sub' => ($rows['cpsspam_allow_sub'] == '1') ? true : false,
                    'ascii' => ($rows['ascii'] == '1') ? true : false,
                    'ascii_allow_reg' => ($rows['ascii_allow_reg'] == '1') ? true : false,
                    'ascii_allow_turbo' => ($rows['ascii_allow_turbo'] == '1') ? true : false,
                    'ascii_allow_sub' => ($rows['ascii_allow_sub'] == '1') ? true : false,
                    'ip' => ($rows['ip'] == '1') ? true : false,
                    'ip_allow_reg' => ($rows['ip_allow_reg'] == '1') ? true : false,
                    'ip_allow_turbo' => ($rows['ip_allow_turbo'] == '1') ? true : false,
                    'ip_allow_sub' => ($rows['ip_allow_sub'] == '1') ? true : false
                );
            } else {
                // Create our DB entry so we have a reliable config to grab from
                $sql = $db->sql_build_insert(BURNBOT_MODERATION_CONFIG, array(
                    'id' => $this->sessionID
                ));
                $result = $db->sql_query($sql);
                $db->sql_freeresult($result);
            }
            
            $irc->_log_action("Moderation environment constructed");
        }
    }
    
    private function refreshFilterSets()
    {
        global $db;
        
        $this->urlRegex = array();
        $enders = '';
        
        $sql = $db->sql_build_select(BURNBOT_MODERATION_ENDERS, array(
            'ender'
        ));
        $result = $db->sql_query($sql);
        $rows = $db->sql_fetchrowset($result);
        $db->sql_freeresult($result);
        
        if (is_array($rows) && !empty($rows))
        {
            foreach ($rows as $row)
            {
                $enders = (isset($row['ender'])) ? $enders . $row['ender'] . '|' : $enders;
            }
            
            $enders = rtrim($enders, '|');
        }
        
        $this->urlRegex[] = "([\w]+.($enders))";
    }

    public function init()
    {
        global $burnBot;
        
        $this->refreshFilterSets();
        
        $burnBot->registerCommads($this->commands);
    }
    
    public function _read($messageArr)
    {
        global $burnBot;
        
        $user = (isset($messageArr['nick'])) ? $messageArr['nick'] : false;
        $message = (isset($messageArr['message'])) ? $messageArr['message'] : false;
        
        if (!$user)
        {
            return;
        }
        
        // Skip jtv if we are on twitch, this is an automated user
        if ($this->isTwitch && ($user == 'jtv'))
        {
            return;
        }
        
        // Are we filtering?  If not, don't synch up with core's arrays for now and don't run any more code
        if ($this->config['url'] || $this->config['chrspam'] || $this->config['cpsspam'] || $this->config['ascii'] || $this->config['ip'])
        {
            // Synch up with core
            $turboUsers  = $burnBot->getTurbo();
            $operators   = $burnBot->getOperators();
            $regulars    = $burnBot->getReg();
            $subscribers = $burnBot->getSubs();
            
            // Filter URL's
            if ($this->config['url'])
            {
                // Skip the permission layers the user is in
                if (!array_key_exists($user, $operators) && (!$this->config['url_allow_reg'] || !array_key_exists($user, $regulars)) && (!$this->config['url_allow_sub'] || !array_key_exists($user, $subscribers)) && (!$this->config['url_allow_turbo'] || !array_key_exists($user, $turboUsers)))
                {
                    // Store the message
                    $check = $message;
                    $found = false;
                    
                    // First off, replace any smartass attempts to fool the regex
                    foreach ($this->urlFools as $fool)
                    {
                        $check = preg_replace($fool, '.', $check);
                    }
                    
                    // Second check (Uses the array of regex matches in cases where a custom is needed or if a URL slips through and needs to be handled)
                    if (!$found)
                    {
                        foreach ($this->urlRegex as $regex)
                        {
                            if (preg_match($regex, $check) != 0)
                            {
                                $found = true;
                            }
                        }
                    }
                    
                    // Do we need to act?
                    if ($found)
                    {
                        $this->moderate($user, 'Posting a link');
                        $irc->_log_action("Moderation action triggered on link", "moderation");
                        return;
                    }
                }
            }
            
            // Filter IP addresses
            if ($this->config['ip'])
            {
                // Skip the permission layers the user is in (always ignore OPs)
                if (!array_key_exists($user, $operators) && (!$this->config['ip_allow_reg'] || !array_key_exists($user, $regulars)) && (!$this->config['ip_allow_sub'] || !array_key_exists($user, $subscribers)) && (!$this->config['ip_allow_turbo'] || !array_key_exists($user, $turboUsers)))
                {
                    $check = $message;
                    
                    // First off, replace any smartass attempts to fool the regex
                    foreach ($this->urlFools as $fool)
                    {
                        $check = preg_replace($fool, '.', $check);
                    }
                    
                    // Is there an IP in the set?
                    if (preg_match("(\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b)", $check) != 0)
                    {
                        $this->moderate($user, 'Posting an IP');
                        return;
                    }
                }
            }
            
            // Filter Char spam
            if ($this->config['chrspam'])
            {
                // Skip the permission layers the user is in (always ignore OPs)
                if (!array_key_exists($user, $operators) && (!$this->config['chrspam_allow_reg'] || !array_key_exists($user, $regulars)) && (!$this->config['chrspam_allow_sub'] || !array_key_exists($user, $subscribers)) && (!$this->config['chrspam_allow_turbo'] || !array_key_exists($user, $turboUsers)))
                {
                    
                }
            }
            
            // Filter Caps spam
            if ($this->config['cpsspam'])
            {
                // Skip the permission layers the user is in (always ignore OPs)
                if (!array_key_exists($user, $operators) && (!$this->config['cpsspam_allow_reg'] || !array_key_exists($user, $regulars)) && (!$this->config['cpsspam_allow_sub'] || !array_key_exists($user, $subscribers)) && (!$this->config['cpsspam_allow_turbo'] || !array_key_exists($user, $turboUsers)))
                {
                    
                }
            }
            
            // Filter ASCII characters
            if ($this->config['ascii'])
            {
                // Skip the permission layers the user is in (always ignore OPs)
                if (!array_key_exists($user, $operators) && (!$this->config['ascii_allow_reg'] || !array_key_exists($user, $regulars)) && (!$this->config['ascii_allow_sub'] || !array_key_exists($user, $subscribers)) && (!$this->config['ascii_allow_turbo'] || !array_key_exists($user, $turboUsers)))
                {
                    
                }
            }
            
            // Filter defined words
            if ($this->config['words'])
            {
                // Skip the permission layers the user is in (always ignore OPs)
                if (!array_key_exists($user, $operators) && (!$this->config['words_allow_reg'] || !array_key_exists($user, $regulars)) && (!$this->config['words_allow_sub'] || !array_key_exists($user, $subscribers)) && (!$this->config['words_allow_turbo'] || !array_key_exists($user, $turboUsers)))
                {
                    
                }
            }
        }
    }
    
    public function tick()
    {
        
    }
    
    private function moderate($user, $type = '')
    {
        global $burnBot;
        
        if ($this->inTesting)
        {
            // Just tell the chat something was detected
            $burnBot->addMessageToQue('Moderation action taken');
        } else {
            
        }
    }
    
    public function moderation_filter($sender, $msg = '')
    {
        
    }
    
    public function moderation_filters($sender, $msg = '')
    {
        
    }
}
?>
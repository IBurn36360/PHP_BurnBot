<?php
// Was this accessed directly?  If so, exit.
if (!defined('IN_IRC'))
{
	exit;
}

class moderation
{
    protected $config = array();
    protected $emotes = array();
    protected $moderatedUsers = array();
    protected $permittedUsers = array();
    protected $responses = array();
    protected $words = array();
    
    // The steps in punishment on Twitch.  On IRC, we just go by number of kicks before ban
    // Int values are timeouts, b stands for a ban at that point
    protected $steps = array(
        '1',
        '5',
        '30',
        '300',
        '600',
        'b'
    );
    
    protected $filters = array(
        'url'  => 'url',
        'caps' => 'cpsspam',
        'char' => 'chrspam',
        'ascii' => 'ascii',
        'ip' => 'ip',
        'words' => 'words',
        'emoteSpam' => 'twitch_emotespam',
        'singleEmotes' => 'twitch_singleemotes',
        'twitchGlobal' => 'twitch_global_url'
    );
    
    protected $inTesting = false;
    protected $constructed = false;
    protected $emotesgrabbed = false;
    protected $permitTime = 180; // Time in seconds that a user is permitted for
    protected $baseTime = 946684800; // Part of my way around timestamps going off of timezone, this is default to JAN 1'st 2000 0:00:00
    
    // Core
    protected $isTwitch = false;
    protected $sessionID = 0;
    protected $commandDelimeter = '';
    protected $chan = '';
    
    protected $urlFools = array(
        '(([ ]+)?(dot|\(dot\))([ ]+)?)',
        '([ ]+.([ ]+)?)'  // Stop people using spaces from defeating the checks
    );
    
    // Populated by the database
    protected $urlRegex = array();
    
    protected $commands = array(
        'filter'  => array('moderation', 'moderation_filter', true, false, false, false),
        'filters' => array('moderation', 'moderation_filters', true, false, false, false),
        'permit'  => array('moderation', 'moderation_permit', true, false, false, false),
        'pardon'  => array('moderation', 'moderation_pardon', true, false, false, false),
        'moderation_responses'   => array('moderation', 'moderation_responses', true, false, false, false),
        'moderation_addresponse' => array('moderation', 'moderation_addresponse', true, false, false, false),
        'moderation_delresponse' => array('moderation', 'moderation_delresponse', true, false, false, false),
        'moderation_words'       => array('moderation', 'moderation_words', true, false, false, false),
        'moderation_addword'     => array('moderation', 'moderation_addword', true, false, false, false),
        'moderation_delword'     => array('moderation', 'moderation_delword', true, false, false, false),
        'moderation_steps'       => array('moderation', 'moderation_steps', true, false, false, false),
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
            $this->chan      = $burnBot->getChan();
            
            // Adjust our base time so we have an easy calculation for formatting it
            $arr = getdate($this->baseTime);
            if ($arr['year'] != '2000') // We are below our ideal timestamp
            {
                // Scale up to our timestamp
                $reset = (24 - intval($arr['hours'])) * 60 * 60;
                $this->baseTime += $reset;
            } else {
                // Are we above our ideal timestamp?
                if ($arr['hours'] != '0')
                {
                    $reset = intval($arr['hours']) * 60 * 60;
                    $this->baseTime -= $reset;
                }
            }
            
            // Grab the config
            $sql = $db->sql_build_select(BURNBOT_MODERATION_CONFIG, array(
                'url'
            ), array(
                'id' => $this->sessionID
            ));
            $result = $db->sql_query($sql);
            $rows = $db->sql_fetchrow($result);
            $db->sql_freeresult($result);
            
            if (empty($rows))
            {
                // Create our DB entry so we have a reliable config to grab from
                $sql = $db->sql_build_insert(BURNBOT_MODERATION_CONFIG, array(
                    'id' => $this->sessionID
                ));
                $result = $db->sql_query($sql);
                $db->sql_freeresult($result);
            }
            
            // Assuming we have 
            $sql = $db->sql_build_select(BURNBOT_MODERATION_CONFIG, array(
                'url',
                'url_allow_reg',
                'url_allow_turbo',
                'url_allow_sub',
                'chrspam',
                'chrspam_allow_reg',
                'chrspam_allow_turbo',
                'chrspam_allow_sub',
                'chrspam_threshold',
                'cpsspam',
                'cpsspam_allow_reg',
                'cpsspam_allow_turbo',
                'cpsspam_allow_sub',
                'cpsspam_threshold',
                'ascii',
                'ascii_allow_reg',
                'ascii_allow_turbo',
                'ascii_allow_sub',
                'ip',
                'ip_allow_reg',
                'ip_allow_turbo',
                'ip_allow_sub',
                'words',
                'words_allow_reg',
                'words_allow_turbo',
                'words_allow_sub',
                'twitch_emotespam',
                'twitch_emotespam_allow_reg',
                'twitch_emotespam_allow_turbo',
                'twitch_emotespam_allow_sub',
                'twitch_singleemotes',
                'twitch_singleemotes_allow_reg',
                'twitch_singleemotes_allow_turbo',
                'twitch_singleemotes_allow_sub',
                'twitch_global_url',
                'steps'
            ), array(
                'id' => $this->sessionID
            ));
            $result = $db->sql_query($sql);
            $rows = $db->sql_fetchrow($result);
            $db->sql_freeresult($result);
            
            $this->config = array(
                'url' => ($rows['url'] == '1') ? true : false,
                'url_allow_reg' => ($rows['url_allow_reg'] == '1') ? true : false,
                'url_allow_turbo' => ($rows['url_allow_turbo'] == '1') ? true : false,
                'url_allow_sub' => ($rows['url_allow_sub'] == '1') ? true : false,
                'chrspam' => ($rows['chrspam'] == '1') ? true : false,
                'chrspam_allow_reg' => ($rows['chrspam_allow_reg'] == '1') ? true : false,
                'chrspam_allow_turbo' => ($rows['chrspam_allow_turbo'] == '1') ? true : false,
                'chrspam_allow_sub' => ($rows['chrspam_allow_sub'] == '1') ? true : false,
                'chrspam_threshold' => intval($rows['chrspam_threshold']),
                'cpsspam' => ($rows['cpsspam'] == '1') ? true : false,
                'cpsspam_allow_reg' => ($rows['cpsspam_allow_reg'] == '1') ? true : false,
                'cpsspam_allow_turbo' => ($rows['cpsspam_allow_turbo'] == '1') ? true : false,
                'cpsspam_allow_sub' => ($rows['cpsspam_allow_sub'] == '1') ? true : false,
                'cpsspam_threshold' => intval($rows['cpsspam_threshold']),
                'ascii' => ($rows['ascii'] == '1') ? true : false,
                'ascii_allow_reg' => ($rows['ascii_allow_reg'] == '1') ? true : false,
                'ascii_allow_turbo' => ($rows['ascii_allow_turbo'] == '1') ? true : false,
                'ascii_allow_sub' => ($rows['ascii_allow_sub'] == '1') ? true : false,
                'ip' => ($rows['ip'] == '1') ? true : false,
                'ip_allow_reg' => ($rows['ip_allow_reg'] == '1') ? true : false,
                'ip_allow_turbo' => ($rows['ip_allow_turbo'] == '1') ? true : false,
                'ip_allow_sub' => ($rows['ip_allow_sub'] == '1') ? true : false,
                'words' => ($rows['words'] == '1') ? true : false,
                'words_allow_reg' => ($rows['words_allow_reg'] == '1') ? true : false,
                'words_allow_turbo' => ($rows['words_allow_turbo'] == '1') ? true : false,
                'words_allow_sub' => ($rows['words_allow_sub'] == '1') ? true : false,
                'twitch_emotespam' => ($rows['twitch_emotespam'] == '1') ? true : false,
                'twitch_emotespam_allow_reg' => ($rows['twitch_emotespam_allow_reg'] == '1') ? true : false,
                'twitch_emotespam_allow_turbo' => ($rows['twitch_emotespam_allow_turbo'] == '1') ? true : false,
                'twitch_emotespam_allow_sub' => ($rows['twitch_emotespam_allow_sub'] == '1') ? true : false,
                'twitch_singleemotes' => ($rows['twitch_singleemotes'] == '1') ? true : false,
                'twitch_singleemotes_allow_reg' => ($rows['twitch_singleemotes_allow_reg'] == '1') ? true : false,
                'twitch_singleemotes_allow_turbo' => ($rows['twitch_singleemotes_allow_turbo'] == '1') ? true : false,
                'twitch_singleemotes_allow_sub' => ($rows['twitch_singleemotes_allow_sub'] == '1') ? true : false,
                'twitch_global_url' => ($rows['twitch_global_url'] == '1') ? true : false
            );
            
            // Do was have a non-default set of steps?
            if ($rows['steps'] != '')
            {
                $unpacked = explode(',', $rows['steps']);
                $this->steps = $unpacked;
            }
            
            // Now load any words/phrases that are to be filtered
            $sql = $db->sql_build_select(BURNBOT_MODERATION_WORDS, array(
                'word'
            ), array(
                'id' => $this->sessionID
            ));
            $result = $db->sql_query($sql);
            $rows = $db->sql_fetchrowset($result);
            $db->sql_freeresult($result);
            
            if (is_array($rows) && !empty($rows))
            {
                foreach ($rows as $row)
                {
                    $this->words = array_merge($this->words, array($row['word']));
                }
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
        
        $this->urlRegex[] = "(([\w]+.($enders))[ /?&%#])";
    }
    
    private function grabEmotes()
    {
        global $irc;
        
        // Do we have the interface?
        if (class_exists('twitch'))
        {
            $twitch = new twitch;
            
            // Grab all emotes from twitch
            $emotes = $twitch->chat_getEmoticonsGlobal();
            
            if (is_array($emotes) && !empty($emotes))
            {
                // Only grab the regex.  Nothing else should be needed here
                foreach ($emotes as $regex => $arr)
                {
                    $this->emotes[$regex] = null;
                }
            } else {
                $irc->_log_error("No emote set grabbed from twitch!", 'Moderation');
                return;
            }
            
            $irc->_log_action('Emote set grabbed from twitch', 'moderation');
            
            $this->emotesgrabbed = true;
        } else {
            $irc->_log_error("Dependency twitch not available, emote filters disabled!");
        }
    }

    public function init()
    {
        global $burnBot;
        
        $this->refreshFilterSets();
        
        // Grab emotes if we are on twitch (Will not happenon re-init)
        if ($this->isTwitch && !$this->emotesgrabbed)
        {
            $this->grabEmotes();
        }
        
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
            
            // Don't attempt to moderate OP's
            if (array_key_exists($user, $operators))
            {
                return;
            }
            
            // Filter URL's
            if ($this->config['url'])
            {
                // Skip the permission layers the user is in
                if ((!$this->config['url_allow_reg'] || !array_key_exists($user, $regulars)) && (!$this->config['url_allow_sub'] || !array_key_exists($user, $subscribers)) && (!$this->config['url_allow_turbo'] || !array_key_exists($user, $turboUsers)))
                {
                    // Store the message
                    $check = $message;
                    $found = false;
                    
                    // First off, replace any smartass attempts to fool the regex
                    foreach ($this->urlFools as $fool)
                    {
                        $check = preg_replace($fool, '.', $check);
                    }

                    foreach ($this->urlRegex as $regex)
                    {
                        if (preg_match($regex, $check) != 0)
                        {
                            // Is this user permitted to post a link?
                            if (array_key_exists($user, $this->permittedUsers))
                            {
                                // They have posted their link, delete them from the permit
                                unset($this->permittedUsers[$user]);
                                return;
                            } else {
                                $this->moderate($user, 'Posting a link');
                                $this->sort();
                                
                                return;
                            }
                        }
                    }
                }
            }
            
            // Filter IP addresses
            if ($this->config['ip'])
            {
                // Skip the permission layers the user is in (always ignore OPs)
                if ((!$this->config['ip_allow_reg'] || !array_key_exists($user, $regulars)) && (!$this->config['ip_allow_sub'] || !array_key_exists($user, $subscribers)) && (!$this->config['ip_allow_turbo'] || !array_key_exists($user, $turboUsers)))
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
                        if (array_key_exists($user, $this->permittedUsers))
                        {
                            // A user may also post an IP under a permit.  Both cases will expire the permit however
                            unset($this->permittedUsers[$user]);
                            return;
                        } else {
                            $this->moderate($user, 'Posting an IP');
                            $this->sort();
                            
                            return;                            
                        }
                    }
                }
            }

            // Filter ASCII characters
            if ($this->config['ascii'])
            {
                // Skip the permission layers the user is in (always ignore OPs)
                if ((!$this->config['ascii_allow_reg'] || !array_key_exists($user, $regulars)) && (!$this->config['ascii_allow_sub'] || !array_key_exists($user, $subscribers)) && (!$this->config['ascii_allow_turbo'] || !array_key_exists($user, $turboUsers)))
                {
                    // Check for non-American ASCII chars
                    if (preg_match('([^\x20-\x73])', $message) != '')
                    {
                        $this->moderate($user, 'Using banned characters');
                        $this->sort();
                        
                        return;
                    }
                }
            }
            
            // Filter defined words
            if ($this->config['words'])
            {
                // Skip the permission layers the user is in (always ignore OPs)
                if ((!$this->config['words_allow_reg'] || !array_key_exists($user, $regulars)) && (!$this->config['words_allow_sub'] || !array_key_exists($user, $subscribers)) && (!$this->config['words_allow_turbo'] || !array_key_exists($user, $turboUsers)))
                {
                    if (!empty($this->words))
                    {
                        foreach ($this->words as $word)
                        {
                            if (strstr($message, $word) != '')
                            {
                                $this->moderate($user, "Using a banned word");
                                $this->sort();
                                
                                return;
                            }
                        }
                    }
                }
            }
            
            // Filter Char spam
            if ($this->config['chrspam'])
            {
                // Skip the permission layers the user is in (always ignore OPs)
                if ((!$this->config['chrspam_allow_reg'] || !array_key_exists($user, $regulars)) && (!$this->config['chrspam_allow_sub'] || !array_key_exists($user, $subscribers)) && (!$this->config['chrspam_allow_turbo'] || !array_key_exists($user, $turboUsers)))
                {
                    // Check to see if there are no spaces
                    if (strlen($message) >= $this->config['chrspam_threshold'])
                    {
                        if (strstr($message, ' ') == '')
                        {
                            $this->moderate($user, 'Spamming characters');
                            $this->sort();
                            
                            return;
                        }
                        
                        // Check for repeated characters
                        $chars = explode('', strtolower($message));
                        $frequency = current(reset(array_count_values($chars)));
                        
                        if ((strlen($message) >= $this->config['chrspam_threshold']) && (($frequency / strlen($message)) >= .5))
                        {
                            $this->moderate($user, 'Spamming characters');
                            $this->sort();
                            
                            return;
                        }
                    }
                }
            }
            
            // Filter Caps spam
            if ($this->config['cpsspam'])
            {
                // Skip the permission layers the user is in (always ignore OPs)
                if ((!$this->config['cpsspam_allow_reg'] || !array_key_exists($user, $regulars)) && (!$this->config['cpsspam_allow_sub'] || !array_key_exists($user, $subscribers)) && (!$this->config['cpsspam_allow_turbo'] || !array_key_exists($user, $turboUsers)))
                {
                    if (strlen($message) >= $this->config['cpsspam_threshold'])
                    {
                        // Simple check, is it all caps?
                        if (strtoupper($message) == $message)
                        {
                            $this->moderate($user, 'Spamming caps');
                            $this->sort();
                            
                            return;
                        }
                        
                        // More difficult check.  We are going to count 80% as the threshold for spam here
                        $upper = preg_replace('[ ]', '', strtoupper($message));
                        $store = preg_replace('[ ]', '', $message);
                        $matches = 0;
                        
                        foreach ($store as $key => $char)
                        {
                            if ($upper[$key] == $char)
                            {
                                $matches ++;
                            }
                        }
                        
                        if (($matches / strlen($store)) >= .8)
                        {
                            $this->moderate($user, 'Spamming caps');
                            $this->sort();
                            
                            return;
                        }
                    }
                }
            }
            
            // Filter emotes from Twitch (Emote spam)
            if ($this->isTwitch && $this->config['twitch_emotespam'])
            {
                if ((!$this->config['twitch_emotespam_allow_reg'] || !array_key_exists($user, $regulars)) && (!$this->config['twitch_emotespam_allow_sub'] || !array_key_exists($user, $subscribers)) && (!$this->config['twitch_emotespam_allow_turbo'] || !array_key_exists($user, $turboUsers)))
                {
                    // Don't bother if we don't have an emote list to match against
                    if ($this->emotesgrabbed)
                    {
                        $words = explode(' ', $message);
                        $found = true;
                        
                        // We have a special check for this case.  Don't check if there is only 1 word in the set
                        if (count($words) != 1)
                        {
                            foreach ($words as $word)
                            {
                                $check = false;
                                
                                foreach ($this->emotes as $regex => $n)
                                {
                                    if (preg_match($regex, $word) != 0)
                                    {
                                        $check = true;
                                        break;
                                    }
                                }
                                
                                // We failed to match an emote.  This user is clean
                                if (!$check)
                                {
                                    $found = false;
                                    break;
                                }
                            }
                        }
                        
                        // All words were emotes, count that as spamming
                        if ($found)
                        {
                            $this->moderate($user, 'Spamming emotes');
                            
                            $this->sort();
                            
                            return;
                        }
                    }
                }
            }
            
            if ($this->isTwitch && $this->config['twitch_singleemotes'])
            {
                if ((!$this->config['twitch_emotespam_allow_reg'] || !array_key_exists($user, $regulars)) && (!$this->config['twitch_emotespam_allow_sub'] || !array_key_exists($user, $subscribers)) && (!$this->config['twitch_emotespam_allow_turbo'] || !array_key_exists($user, $turboUsers)))
                {
                    // Don't bother if we don't have an emote list to match against
                    if ($this->emotesgrabbed)
                    {
                        $words = explode(' ', $message);
                        
                        // Do we only have 1 word?
                        if (count($words) == 1)
                        {
                            foreach ($this->emotes as $regex => $n)
                            {
                                if (preg_match($regex, $message) != 0)
                                {
                                    $this->moderate($user, 'Using single emotes');
                                    
                                    $this->sort();
                                    
                                    return;
                                }
                            }
                        }
                    }
                }
            }
            
            // On twitch we have a set of URL's that are strictly banned due to them being spammed or used by bots.
            if ($this->isTwitch && $this->config['twitch_global_url'])
            {
                // This check doe NOT have a permission layer associated with it.  Tt does not matter if the user is turbo or whatever, it is a global filter
                foreach ($this->twitchGlobals as $globalURL)
                {
                    if (strstr($globalURL, $message))
                    {
                        $this->moderate($user, "Using a spammed/scam URL", true);
                        
                        return;
                    }
                }
            }
        }
    }
    
    public function tick()
    {
        if (!empty($this->moderatedUsers))
        {
            reset($this->moderatedUsers);
            $user = key($this->moderatedUsers);
            
            // Sort the array
            if ($this->moderatedUsers[$user]['ttl'] <= time())
            {
                array_shift($this->moderatedUsers);
            }
        }
    }
    
    private function sort()
    {
        // Storage array for the sorting
        $sortArr = array();
        $clone = array();
        
        foreach ($this->moderatedUsers as $user => $arr)
        {
            $sortArr[$user] = $arr['ttl'];
        }
        
        // Sort the arrays, maintain keys
        asort($sortArr);
        
        // Copy to a clone
        foreach ($sortArr as $user => $ttl)
        {
            $clone[$user] = $this->moderatedUsers[$user];
        }
        
        // Reset the moderated users
        $this->moderatedUsers = array();
        $this->moderatedUsers = $clone;
    }
    
    private function moderate($user, $type = 'Default reason', $global = false)
    {
        global $burnBot, $irc, $socket;
        
        // Used for debugging, DO NOT MODERATE, output to the chat
        if ($this->inTesting)
        {
            // Just tell the chat something was detected
            $burnBot->addMessageToQue("Moderation action taken [$type]");
        } else {
            // Only time this can happen is Twitch, ban the user instantly
            if ($global)
            {
                $burnBot->addMessageToQue("/ban $user");
                $burnBot->addMessageToQue($this->buildFeedback($user, $type) . "[Banned]");
                return;
            }
            
            $time = time();
            
            // Check to see if the user was moderated within the time period (Increasing with number of strikes)
            if (array_key_exists($this->moderatedUsers))
            {
                // They have been moderated within the time period.  Not a good thing for them
                $times = $this->moderatedUsers[$user]['times'];
                
                // Is the user off of the scale at this point?
                if (!array_key_exists($times, $this->steps))
                {
                    // Yes, the user is off of our moderation scale, meaning that the the end of the scale is not a ban
                    // Scale the ttl on a moderated users linearly (For now)
                    $TTL = $time + (3600 * $times);
                    
                    // Use merge because it properly overwrites the new data
                    $this->moderatedUsers = array_merge($this->moderatedUsers, array($user => array('times' => $times++, 'ttl' => $TTL)));
                                    
                    // Now choose our action to take based on where we are
                    if ($this->isTwitch)
                    {
                        // Choose the last key now and time the user out for the time
                        $timeOut = $this->steps[count($this->steps) - 1];
                        
                        $burnBot->addMessageToQue("/timeout $user $timeout");
                        $burnBot->addMessageToQue($this->buildFeedback($user, $type) . "[$timeOut second timeout]");
                    } else {
                        $irc->_write($socket, "KICK $user $type");
                        $burnBot->addMessageToQue($this->buildFeedback($user, $type) . "[Kick]");
                    }
                    
                    return;
                } elseif ($this->steps[$times] == 'b') { 
                    // ban the user
                    
                    // First, clear them from the scale.  A ban resets your moderation cound since, at this point, an OP needs to unban you
                    unset($this->moderatedUsers[$user]);
                    
                    // What are we on?
                    if ($this->isTwitch)
                    {
                        $burnBot->addMessageToQue("/ban $user");
                        $burnBot->addMessageToQue($this->buildFeedback($user, $type) . "[Banned]");
                    } else {
                        $irc->_write($socket, "MODE $this->chan -b $user");
                        $burnBot->addMessageToQue($this->buildFeedback($user, $type) . "[Banned]");
                    }
                    
                    return;
                } elseif ($this->steps[$times] != 0) {
                    // Do not ban the user
                    // Scale the ttl on a moderated users linearly (For now)
                    $TTL = $time + (3600 * $times);
                    
                    // Use merge because it properly overwrites the new data
                    $this->moderatedUsers = array_merge($this->moderatedUsers, array($user => array('times' => $times++, 'ttl' => $TTL)));
                                    
                    // Now choose our action to take based on where we are
                    if ($this->isTwitch)
                    {
                        // Choose the last key now and time the user out for the time
                        $timeOut = $this->steps[$times];
                        
                        $burnBot->addMessageToQue("/timeout $user $timeout");
                        $burnBot->addMessageToQue($this->buildFeedback($user, $type));
                    } else {
                        $irc->_write($socket, "KICK $user $type");
                        $burnBot->addMessageToQue($this->buildFeedback($user, $type));
                    }
                    
                    return;
                } else { // Just warn the user based on the moderation type, take no action
                    
                }
            } else {
                // The only step is to ban
                if ($this->steps[0] == 'b')
                {
                    if ($this->isTwitch)
                    {
                        $burnBot->addMessageToQue("/ban $user");
                        $burnBot->addMessageToQue($this->buildFeedback($user, $type) . "[Banned]");
                    } else {
                        $irc->_write($socket, "MODE $this->chan -b $user");
                        $burnBot->addMessageToQue($this->buildFeedback($user, $type) . "[Banned]");
                    }
                } elseif ($this->steps[0] != 0) { // This is a valid option technically, so we will warn the user here without moderating them
                    // Scale the ttl on a moderated users linearly (For now)
                    $TTL = $time + 3600;
                    
                    // Use merge because it properly overwrites the new data
                    $this->moderatedUsers = array_merge($this->moderatedUsers, array($user => array('times' => $times++, 'ttl' => $TTL)));
                                    
                    // Now choose our action to take based on where we are
                    if ($this->isTwitch)
                    {
                        // Choose the last key now and time the user out for the time
                        $timeOut = $this->steps[count($this->steps) - 1];
                        
                        $burnBot->addMessageToQue("/timeout $user $timeout");
                        $burnBot->addMessageToQue($this->buildFeedback($user, $type) . "[$timeOut second timeout]");
                    } else {
                        $irc->_write($socket, "KICK $user $type");
                        $burnBot->addMessageToQue($this->buildFeedback($user, $type) . "[Kick]");
                    }
                    
                    return;
                } else { // moderate the user based on the steps
                    // Scale the ttl on a moderated users linearly (For now)
                    $TTL = $time + 3600;
                    
                    // Use merge because it properly overwrites the new data
                    $this->moderatedUsers = array_merge($this->moderatedUsers, array($user => array('times' => $times++, 'ttl' => $TTL)));
                                    
                    // Now choose our action to take based on where we are
                    if ($this->isTwitch)
                    {
                        // Choose the last key now and time the user out for the time
                        $timeOut = $this->steps[count($this->steps) - 1];
                        
                        $burnBot->addMessageToQue($this->buildFeedback($user, $type));
                    } else {
                        $burnBot->addMessageToQue($this->buildFeedback($user, $type));
                    }
                    
                    return;
                }
            }
        }
    }
    
    // Used to build the user feedback on what they have done, will be modular later
    private function buildFeedback($user, $type)
    {
        switch ($type)
        {
            case 'Posting a link':
                return "$user, please do not post links without permission";
                break;
            
            case 'Posting an IP':
                return "$user, please do not post IPs in here without permission";
                break;
            
            case 'Using banned characters':
                return "$user, please do not use ASCII symbols or foreign characters";
                break;
                
            case 'Using a banned word':
                return "$user, please watch what you say (Using a banned word)";
                break;
                
            case 'Spamming characters':
                return "$user, please do not spam characters";
                break;
                
            case 'Spamming caps':
                return "&user, please do not spam caps";
                break;
                
            case 'Spamming emotes':
                return "$user, please do not spam emotes";
                break;
                
            case 'Using single emotes':
                return "$user, please do not use single emotes (Emotes with no context)";
                break;
            
            default:
                return "$user, you are warned (Error processing moderation type [$type])";
                break;
        }
    }
    
    public function moderation_filter($sender, $msg = '')
    {
        global $burnBot, $db;
        
        // Synch up with core
        $this->commandDelimeter = $burnBot->getCommandDelimeter();
        
        $split = explode(' ', $msg);
        
        if (count($split) == 0)
        {
            $this->help('filter');
            return;
        }
        
        // Start checking for data
        $filter    = (isset($split[0])) ? $split[0] : false;
        $enabled   = (isset($split[1])) ? $split[1] : false;
        $reg       = (isset($split[2])) ? $split[2] : false;
        $sub       = (isset($split[3])) ? $split[3] : false;
        $turbo     = (isset($split[4])) ? $split[4] : false;
        $threshold = (isset($split[5])) ? $split[5] : false;
        
        // Type strict since a double space could break this
        if (($filter === false) || (!array_key_exists($filter, $this->filters)))
        {
            $burnBot->addMessageToQue("The provided filter appears to not exist. Please use the command " . $this->commandDelimeter . "filters to see a list.");
            $this->help('filters');
            return;
        }
        
        // Stop filters that are twitch specific from being used or edited while on standard IRC
        if ($this->isTwitch && ($filter == 'twitchGlobal') || ($filter == 'emoteSpam') || ($filter == 'singleEmotes'))
        {
            $burnBot->addMessageToQue("The filter $filter is not allowed to be edited or enabled while not on Twitch");
            return;
        }
        
        // Are we modifying the global filter?
        if ($filter == 'twitchGlobal')
        {
            // Type strict, important to check for this stictly since it will be a string otherwise
            if ($enabled !== false)
            {
                $enabled = (($enabled == 'true') || ($enabled == 't') || ($enabled == 'yes') || ($enabled == 'y') || ($enabled == 'on') || ($enabled == 'enabled') || ($enabled == 'enable')) ? true : false;
                
                // What are we doing after that check?
                $sql = $db->sql_build_update(BURNBOT_MODERATION_CONFIG, array(
                    'twitch_global_url' => $enabled
                ), array(
                    'id' => $this->sessionID
                ));
                $result = $db->sql_query($sql);
                
                if ($result != false)
                {
                    $str = ($enabled) ? "Global Banned filter has been enabled" : "Global Banned filter has been disabled";
                    $burnBot->addMessageToQue("Global Banned filter has been enabled");
                    $this->config = array_merge($this->config, array('twitch_global_url' => true));
                } else {
                    $burnBot->addMessageToQue("An error occured while trying to modify the filter.  Please see logs for more information.");
                }
                
                $db->sql_freeresult($result);
                return;
            } else {
                $burnBot->addMessageToQue("Please specify state of the filter: " . $this->commandDelimeter . "filter twitchGlobal {Enabled}");
                return;
            }
        } elseif (($filter == 'caps') || ($filter == 'char')) { // Filters that have thresholds?
            // Type strict, important to check for this stictly since it will be a string otherwise
            if ($enabled !== false)
            {
                // Set the base for our checks and our SQL queries
                $base = $this->filters[$filter];
                
                // Check and determine what the values need to be
                $enabled = (($enabled == 'true') || ($enabled == 't') || ($enabled == 'yes') || ($enabled == 'y') || ($enabled == 'on') || ($enabled == 'enabled') || ($enabled == 'enable')) ? true : false;
                $reg = ((($reg === false) && ($this->config[$base . '_allow_reg'])) || (($reg !== false) && (($reg == 'true') || ($reg == 't') || ($reg == 'yes') || ($reg == 'y') || ($reg == 'allow') || ($reg == 'bypass') || ($reg == 'bypassed')))) ? true : false;
                $sub = ((($sub === false) && ($this->config[$base . '_allow_sub'])) || (($sub !== false) && (($sub == 'true') || ($sub == 't') || ($sub == 'yes') || ($sub == 'y') || ($sub == 'allow') || ($sub == 'bypass') || ($sub == 'bypassed')))) ? true : false;
                $turbo = ((($turbo === false) && ($this->config[$base . '_allow_turbo'])) || (($turbo !== false) && (($turbo == 'true') || ($turbo == 't') || ($turbo == 'yes') || ($turbo == 'y') || ($turbo == 'allow') || ($turbo == 'bypass') || ($turbo == 'bypassed')))) ? true : false;
                $threshold = ($threshold !== false) ? intval($threshold) : $this->config[$base . '_threshold'];
                
                // Now perform the SQL query
                $sql = $db->sql_build_update(BURNBOT_MODERATION_CONFIG, array(
                    $base => $enabled,
                    $base . '_allow_reg' => $reg,
                    $base . '_allow_sub' => $sub,
                    $base . '_allow_turbo' => $turbo,
                    $base . '_threshold' => $threshold
                ), array(
                    'id' => $this->sessionID
                ));
                $result = $db->sql_query($sql);
                if ($result != false)
                {
                    $burnBot->addMessageToQue("Filter $filter updated successfully");
                    
                    // Update the config
                    $this->config = array_merge($this->config, array(
                        $base => $enabled,
                        $base . '_allow_reg' => $reg,
                        $base . '_allow_sub' => $sub,
                        $base . '_allow_turbo' => $turbo,
                        $base . '_threshold' => $threshold
                    ));
                } else {
                    $burnBot->addMessageToQue("An error occured while trying to modify the filter.  Please see logs for more information.");
                }
                $db->sql_freeresult($result);
                return;
            } else {
                $burnBot->addMessageToQue("Please specify at least the state of the filter: " . $this->commandDelimeter . "filter {char/caps} {Enabled} {Bypass regulars} {Bypass subscribers} {Bypass turbo users} {character threshold}");
                return;
            }
        } else { // Everything else
            // Type strict, important to check for this stictly since it will be a string otherwise
            if ($enabled !== false)
            {
                // Set the base for our checks and our SQL queries
                $base = $this->filters[$filter];
                
                // Check and determine what the values need to be
                $enabled = (($enabled == 'true') || ($enabled == 't') || ($enabled == 'yes') || ($enabled == 'y') || ($enabled == 'on') || ($enabled == 'enabled') || ($enabled == 'enable')) ? true : false;
                $reg = ((($reg === false) && ($this->config[$base . '_allow_reg'])) || (($reg !== false) && (($reg == 'true') || ($reg == 't') || ($reg == 'yes') || ($reg == 'y') || ($reg == 'allow') || ($reg == 'bypass') || ($reg == 'bypassed')))) ? true : false;
                $sub = ((($sub === false) && ($this->config[$base . '_allow_sub'])) || (($sub !== false) && (($sub == 'true') || ($sub == 't') || ($sub == 'yes') || ($sub == 'y') || ($sub == 'allow') || ($sub == 'bypass') || ($sub == 'bypassed')))) ? true : false;
                $turbo = ((($turbo === false) && ($this->config[$base . '_allow_turbo'])) || (($turbo !== false) && (($turbo == 'true') || ($turbo == 't') || ($turbo == 'yes') || ($turbo == 'y') || ($turbo == 'allow') || ($turbo == 'bypass') || ($turbo == 'bypassed')))) ? true : false;
                
                // Now perform the SQL query
                $sql = $db->sql_build_update(BURNBOT_MODERATION_CONFIG, array(
                    $base => $enabled,
                    $base . '_allow_reg' => $reg,
                    $base . '_allow_sub' => $sub,
                    $base . '_allow_turbo' => $turbo
                ), array(
                    'id' => $this->sessionID
                ));
                $result = $db->sql_query($sql);
                if ($result != false)
                {
                    $burnBot->addMessageToQue("Filter $filter updated successfully");
                    
                    // Update the config
                    $this->config = array_merge($this->config, array(
                        $base => $enabled,
                        $base . '_allow_reg' => $reg,
                        $base . '_allow_sub' => $sub,
                        $base . '_allow_turbo' => $turbo
                    ));
                } else {
                    $burnBot->addMessageToQue("An error occured while trying to modify the filter.  Please see logs for more information.");
                }
                $db->sql_freeresult($result);
                return;
            } else {
                $burnBot->addMessageToQue("Please specify at least the state of the filter: " . $this->commandDelimeter . "filter {char/caps} {Enabled} {Bypass regulars} {Bypass subscribers} {Bypass turbo users} {character threshold}");
                return;
            }
        }
    }
    
    public function moderation_filters($sender, $msg = '')
    {
        global $burnBot;
        
        if ($msg == '')
        {
            // Return the list of the current filters
            $filters = '';
            
            foreach ($this->filters as $filter => $value)
            {
                $filters .= "$filter, ";
            }
            $filters = rtrim($filters, ', ');
            
            $burnBot->addMessageToQue("Currently registered filters: $filters");
            return;
        } else {
            // Grab data for the requested filter
            $split = explode(' ', $msg);
            $filter = $split[0];
            
            if (!array_key_exists($filter, $this->filters) || ($filter == ''))
            {
                $burnBot->addMessageToQue("The filter [$filter] does is not registered, please specify a registered filter");
                return;
            }
            
            // Set the filter base
            $base = $this->filters[$filter];
            $enabled = $this->filters[$filter];
            $regKey = $this->filters[$filter] . '_allow_reg';
            $subKey = $this->filters[$filter] . '_allow_sub';
            $turboKey = $this->filters[$filter] . '_allow_turbo';
            $thresholdKey = $this->filters[$filter] . '_threshold';
            $reg = (array_key_exists($regKey, $this->config)) ? true : false;
            $sub = (array_key_exists($subKey, $this->config)) ? true : false;
            $turbo = (array_key_exists($turboKey, $this->config)) ? true : false;
            $threshold = (array_key_exists($thresholdKey, $this->config)) ? true : false;
            
            $str = "$filter ";
            $str .= ($enabled) ? '{enabled} ' : '{disabled} ';
            
            if ($reg)
            {
                $str .= (($this->config[$regKey]) == true) ? '{Regulars bypassed} ' : '{Regulars filtered} ';
            }
            
            if ($sub)
            {
                $str .= ($this->config[$subKey]) ? '{Subscribers bypassed} ' : '{Subscribers filtered} ';
            }
            
            if ($turbo)
            {
                $str .= ($this->config[$turboKey]) ? '{Turbo users bypassed} ' : '{Turbo users filtered} ';
            }
            
            if ($threshold)
            {
                $str .= ($this->config[$thresholdKey] == 1) ? '{' . $this->config[$thresholdKey] . ' character}' : '{' . $this->config[$thresholdKey] . ' characters}';
            }
            
            $burnBot->addMessageToQue($str);
            return;
        }
    }
    
    public function moderation_permit($sender, $msg = '')
    {
        global $burnBot;
        
        $users = explode(' ', $msg);
        
        // We need a user or set of users to permit
        if (empty($users))
        {
            $this->help('permit');
            return;
        }
        
        // This is the time people may be permitted for
        $time = time() + $this->permitTime;
        $format = getdate($this->baseTime + $this->permitTime);
        $strTime = '';
        
        // Format the string now
        if (intval($format['hours']) != 0)
        {
            if ($format['hours'] == '1')
            {
                $strTime .= $format['hours'] . " Hour, ";
            } else {
                $strTime .= $format['hours'] . " Hours, ";
            }
        }
        
        if (intval($format['minutes']) != 0)
        {
            if ($format['minutes'] == '1')
            {
                $strTime .= $format['minutes'] . " Minute, ";
            } else {
                $strTime .= $format['minutes'] . " Minutes, ";
            }
        }
        
        if (intval($format['seconds']) != 0)
        {
            if ($format[2] == '1')
            {
                $strTime .= $format['seconds'] . " Second";
            } else {
                $strTime .= $format['seconds'] . " Seconds";
            }
        }
        
        $strTime = rtrim($strTime, ', ');
        
        // Add all users to the array
        foreach ($users as $user)
        {
            $this->permittedUsers[$user] = $time;
        }
        
        if (count($users) == 1)
        {
            $burnBot->addMessageToQue("User [$user] has $strTime to post a link");
        } else {
            $usr = '';
            
            foreach ($users as $user)
            {
                $usr .= "$user, ";
            }
            
            $usr = rtrim($usr, ', ');
            $burnBot->addMessageToQue("Users [$usr] have $strTime to post a link");
        }
    }
    
    public function moderation_pardon($sender, $msg = '')
    {
        global $burnBot, $irc, $socket;
        
        $users = explode(' ', $msg);
        
        // We need a user or set of users to pardon
        if (empty($users))
        {
            $this->help('pardon');
            return;
        }
        
        foreach ($users as $user)
        {
            unset($this->moderatedUsers[$user]);
            
            if ($this->isTwitch)
            {
                $burnBot->addMessageToQue("/unban $user");
            } else {
                $irc->_write($socket, "MODE $this->chan -b $user");
            }
        }
        
        if (count($users) == 1)
        {
            $burnBot->addMessageToQue("User $user has been pardoned");
        } else {
            $usr = '';
            foreach ($users as $user)
            {
                $usr .= "$user, ";
            }
            $usr = rtrim($usr, ', ');
            
            $burnBot->addMessageToQue("Users [$usr] have been pardoned");
        }
    }
    
    public function moderation_responses($sender, $msg = '')
    {
        global $burnBot;
        
        $burnBot->addMessageToQue("This command is currently not implemented, but is registered.");
    }
    
    public function moderation_addresponse($sender, $msg = '')
    {
        global $burnBot;
        
        $burnBot->addMessageToQue("This command is currently not implemented, but is registered.");
    }
    
    public function moderation_delresponse($sender, $msg = '')
    {
        global $burnBot;
        
        $burnBot->addMessageToQue("This command is currently not implemented, but is registered.");
    }
    
    public function moderation_words($sender, $msg = '')
    {
        global $burnBot;
        
        if (!empty($this->words))
        {
            $words = '';
            
            foreach ($this->words as $word)
            {
                $words .= "[$word], ";
            }
            
            $words = rtrim($words, ', ');
            
            $burnBot->addMessageToQue("Currently filtered words/phrases: $words");
            return;
        } else {
            $burnBot->addMessageToQue('There are no currently defined words or phrases to be filtered');
            return;
        }
    }
    
    public function moderation_addword($sender, $msg = '')
    {
        global $burnBot, $db;
        
        if ($msg == '')
        {
            $burnBot->addMessageToQue("Please provide a word or phrase to add");
            return;
        }
        
        foreach ($this->words as $word)
        {
            if ($msg == $word)
            {
                $burnBot->addMessageToQue("[$word] is already being filtered");
                return;
            }
        }
        
        // At this point, we know we are adding a new word or phrase, build the SQL for it
        $sql = $db->sql_build_insert(BURNBOT_MODERATION_WORDS, array(
            'id' => $this->sessionID,
            'word' => $msg
        ));
        $result = $db->sql_query($sql);
        
        if ($result !== false)
        {
            $this->words = array_merge($this->words, array($msg));
            
            $burnBot->addMessageToQue("[$msg] is now bring filtered");
        } else {
            $burnBot->addMessageToQue("There was an error adding [$msg].  Please check logs for more information");
        }
        $db->sql_freeresult($result);
    }
    
    public function moderation_delword($sender, $msg = '')
    {
        global $burnBot, $db;
        
        if ($msg == '')
        {
            $burnBot->addMessageToQue("Please provide a word or phrase to remove");
            return;
        }
        
        // Check to see if it exists
        foreach ($this->words as $key => $word)
        {
            // Does the filter exist?
            if ($msg == $word)
            {
                $sql = $db->sql_build_delete(BURNBOT_MODERATION_WORDS, array(
                    'id' => $this->sessionID,
                    'word' => $msg
                ));
                $result = $db->sql_query($sql);
                
                if ($result !== false)
                {
                    // Output and remove the word from the filter
                    $burnBot->addMessageToQue("[$msg] removed as a filter");
                    
                    unset($this->words[$key]);
                } else {
                    $burnBot->addMessageToQue("There was an error removing [$msg].  Please check logs for more information");
                }
                $db->sql_freeresult($result);
                
                return;
            }
        }
        
        // At this point, the word or phrase was not being filtered
        $burnBot->addMessageToQue('[$msg] is not an existing filter');
    }
    
    public function moderation_steps($sender, $msg = '')
    {
        global $burnBot, $db;
        
        // Display the current moderation steps
        if ($msg == '')
        {
            $str = 'Current moderation steps: [';
            
            foreach ($this->steps as $step)
            {
                if ($step != 'b')
                {
                    if ($this->isTwitch)
                    {
                        $str .= "$step timeout";
                    } else {
                        $str .= "kick, ";
                    }
                } else {
                    $str .= "ban, ";
                }
            }
            $str = rtrim($str, ', ') . ']';
            
            $burnBot->addMessageToQue($str);
            return;
        }
        
        // Split the steps for verification
        $steps  = explode(' ', strtolower($msg));
        
        foreach ($steps as $step)
        {
            if (preg_match('([^b0-9])', $step) != 0)
            {
                $burnBot->addMessageToQue("[$step] is not a valid option for a moderation step. Please use numbers to represent kicks or timeouts and the letter 'b' to represent a ban.");
                return;
            }
        }
        
        // At this point, we will be packaging the steps for the database and updating the global as well
        $packaged = implode(',', $steps);
        $sql = $db->sql_build_update(BURNBOT_MODERATION_CONFIG, array(
            'steps' => $packaged
        ), array(
            'id' => $this->sessionID
        ));
        $result = $db->sql_query($sql);
        if ($result !== false)
        {
            $burnBot->addMessageToQue("Moderation steps updated successfully");
            
            // Update the config for steps as well
            $this->steps = $steps;
        } else {
            $burnBot->addMessageToQue('There was an error updating moderation steps.  Please see logs for more information');
        }
        $db->sql_freeresult($result);
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
                case 'filter':
                    $burnBot->addMessageToQue('Usage: ' . $this->commandDelimeter . 'filter {filterName} {enable/disable} {Bypass regulars} {Bypass subscribers} {Bypass turbo users} {character threshold}. Changes the settings on a filter based on what is provided.  Will not update anything if it is not provided.  Requires the enable/disable parameter to perform any action.');
                    break;
                
                case 'filters':
                    $burnBot->addMessageToQue('Usage: ' . $this->commandDelimeter . 'filters {filter}. If no filter is supplied, all registered filter names are displayed.  If a filter is provided, the complete configuration of that filter is displayed.');
                    break;
                
                case 'permit':
                    $burnBot->addMessageToQue('Usage: ' . $this->commandDelimeter . 'permit {User} {User}. Permits a user or set of users to bypass the link or IP filters until they are posted in chat or time expires.');
                    break;
                    
                case 'pardon':
                    $burnBot->addMessageToQue('Usage: ' . $this->commandDelimeter . 'pardon {User} {User}. Pardons a user or set of users, removing them from all moderation steps and unbanning them.');
                    break;
                    
                case 'moderation_responses':
                    $burnBot->addMessageToQue("This command is currently not implemented, but is registered.");
                    break;
                
                case 'moderation_addresponse':
                    $burnBot->addMessageToQue("This command is currently not implemented, but is registered.");
                    break;
                    
                case 'moderation_delresponse':
                    $burnBot->addMessageToQue("This command is currently not implemented, but is registered.");
                    break;
                
                case 'moderation_words':
                    $burnBot->addMessageToQue('Usage: ' . $this->commandDelimeter . 'moderation_words. Lists all defined words or phrases.');
                    break;
                    
                case 'moderation_addword':
                    $burnBot->addMessageToQue('Usage: ' . $this->commandDelimeter . 'moderation_addword {word/phrase}. Adds the provided word or phrase as a new filter.');
                    break;
                
                case 'moderation_delword':
                    $burnBot->addMessageToQue('Usage: ' . $this->commandDelimeter . 'moderation_delword {word/phrase}. Removes the provided word or phrase from being filtered.');
                    break;
                    
                case 'moderation_steps':
                    $burnBot->addMessageToQue('Usage: ' . $this->commandDelimeter . 'moderation_steps {Step} {Step} etc. Changes how the bot steps its moderation. A number represents a kick on standard IRC and a timeout on Twitch. The letter b represents a ban.');
                    break;
                
                default:
                    $burnBot->addMessageToQue("The command specified is not part of module moderation");
                    break;
            }
        } else {
            $burnBot->addMessageToQue("This module handles moderation of chat.  Is able to moderate standard IRC and Twitch.tv chat");
        }
    }
}
?>
<?php
// Was this accessed directly?  If so, exit.
if (!defined('IN_IRC'))
{
	exit;
}

class rainwave
{
    protected $commandDelimeter = '';
    protected $sessionID = 0;
    protected $modules = array();
    
    protected $commands = array(
        'song'                => array('rainwave', 'rainwave_song', false, true, false, false),
        'rainwave_setchannel' => array('rainwave', 'rainwave_setchannel', true, false, false, false)
    );
    
    protected $channels = array(
        'covers' => null,
        'game' => null,
        'ocremix' => null,
        'chiptunes' => null,
        'all' => null
    );
    
    protected $radioModules = array(
        'lastFm',
        'spotify',
        'rainwave'
    );
    
    protected $channel = 'ocremix';
    
    function __construct($register = false)
    {
        global $irc, $burnBot, $db;
        if ($register)
        {
            $burnBot->registerModule(array('rainwave' => array('enabled' => false, 'class' => 'rainwave')));
        } else {
            $this->sessionID = $burnBot->getSessionID();
            $this->commandDelimeter = $burnBot->getCommandDelimeter();
            
            // Grab the config
            $sql = $db->sql_build_select(BURNBOT_RAINWAVE_CONFIG, array(
                'channel'
            ), array(
                'id' => $this->sessionID
            ));
            $result = $db->sql_query($sql);
            $row = $db->sql_fetchrow($result);
            $db->sql_freeresult($result);
            if (!empty($row))
            {
                $this->channel = strval($row['channel']);
                $irc->_log_action("channel updated to: $this->channel", 'rainwave');
            } else {
                $sql = $db->sql_build_insert(BURNBOT_RAINWAVE_CONFIG, array(
                    'channel' => $this->channel,
                    'id' => $this->sessionID
                ));
                $result = $db->sql_query($sql);
                $db->sql_freeresult($result);
            }
            
            $irc->_log_action("Rainwave environment constructed");
        }
    }
    
    public function init()
    {
        global $burnBot, $irc;
        
        // Update this
        $this->modules = $burnBot->getLoadedModules();
        $permitted = true;
        
        foreach ($this->modules as $module => $info)
        {
            // Is another radio module enabled on init?
            if (array_key_exists($module, $this->radioModules) && ($info['enabled'] == true))
            {
                $permitted = false;
                break;
            }
        }
        
        // Can we register our commands?
        if ($permitted)
        {
            $burnBot->registerCommads($this->commands);
        } else { // Another radio module is enabled, we will not register our song command and will pass a message to logs
            $commands = $this->commands;
            unset($commands['song']);
            $burnBot->registerCommads($commands);
        }
        
        $irc->_log_action('Rainwave module initialized', 'init');
    }
    
    public function _read()
    {
        
    } 
    
    public function tick()
    {
        
    }
    
    public function rainwave_song($sender, $msg = '')
    {
        global $burnBot, $irc;
        
        if (class_exists('song_cURL'))
        {
            $cURL = new song_cURL;
            
            switch ($this->channel)
            {
                case 'all':
                    $sid = '5';
                    break;
                    
                case 'game':
                    $sid = '1';
                    break;
                    
                case 'ocremix':
                    $$sid = '2';
                    break;
                    
                case 'covers':
                    $sid = '3';
                    break;
                    
                case 'chiptunes':
                    $sid = '4';
                    break;
                    
                default:
                    $burnBot->addMessageToQue("An unrecognized channel is currently set, please run rainwave_setchannel to set the channel");
                    return;
                    break;
            }
            
            $post = array(
                'sid' => $sid
            );
            
            // Perform the call
            $result = json_decode($cURL->cURL_post('http://rainwave.cc/api4/info', $post), true);
            if (is_array($result) && !empty($result))
            {
                // build the info for the current song
                $current = $result['sched_current']['songs'][0];
                $thisSong = $current['title'] . ' - ';
                $thisArtists = '';
                
                foreach ($current['artists'] as $artist)
                {
                    $thisArtists .= $artist['name'] . ', ';
                }
                $thisArtists = rtrim($thisArtists, ', ');
                $thisRating = $current['rating'];
                $thisAlbum = $current['albums'][0]['name'];
                
                // Build the song prior too (In case people wanted the last song)
                $prior = $result['sched_history'][0]['songs'][0];
                $priorSong = $prior['title'] . ' - ';
                $priorArtists = '';
                
                foreach ($prior['artists'] as $artist)
                {
                    $priorArtists .= $artist['name'] . ', ';
                }
                $priorArtists = rtrim($priorArtists, ', ');
                $priorRating = $prior['rating'];
                $priorAlbum = $prior['albums'][0]['name'];
                
                // Build and send the string.
                $burnBot->addMessageToQue("Current song: [$thisSong$thisArtists ($thisAlbum) " . '{' . "$thisRating" . '}' . "], Last song: [$priorSong$priorArtists ($priorAlbum) " . '{' . "$priorRating" . '}' . "]");                
            } else {
                $burnBot->addMessageToQue("An error occurred when attempting to grab song, please see logs for details");
                $irc->_log_error("Call failed");
            }
        } else {
            $burnBot->addMessageToQue("An error occurred when attempting to grab song, please see logs for details");
            $irc->_log_error("Missing dependency 'song_cURL'.  Can not perform calls");
        }
    }
    
    public function rainwave_setchannel($sender, $msg = '')
    {
        global $burnBot, $db;
        
        $split = explode(' ', $msg);
        $channel = (isset($split[0])) ? strtolower($split[0]) : false;
        
        if ($channel === false)
        {
            $this->help('rainwave_setchannel');
            return;
        }
        
        if (array_key_exists($channel, $this->channels))
        {
            $sql = $db->sql_build_update(BURNBOT_RAINWAVE_CONFIG, array(
                'channel' => $channel
            ), array(
                'id' => $this->sessionID
            ));
            $result = $db->sql_query($sql);
            
            if ($result !== false)
            {
                $this->channel = $channel;
                $burnBot->addMessageToQue("Channel successfully update to: $channel");
            } else {
                $burnBot->addMessageToQue("An error occurred while trying to update the channel.  Please see logs");
            }
            
            $db->sql_freeresult($result);
        } else {
            $burnBot->addMessageToQue("The channel $channel is not recognized, please specify one of the following channels to pull from: [" . implode(', ', array_keys($this->channels)) . "] Note that some channels are not available from the API and are not supported");
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
                case 'song':
                    $burnBot->addMessageToQue('Usage: ' . $this->commandDelimeter . 'song.  Grabs information about the currently playing song.  Will display the Title, Artists, Album/Game and the Average Rating');
                    break;
                
                case 'rainwave_setchannel':
                    $burnBot->addMessageToQue('Usage: ' . $this->commandDelimeter . 'rainwave_setchannel {channel}. Sets the song command to the specified channel.  Accepts the following channels: [' . implode(', ', array_keys($this->channels)) . ']');
                    break;
                
                default:
                    $burnBot->addMessageToQue("The command specified is not part of module rainwave");
                    break;
            }
        } else {
            $burnBot->addMessageToQue("This module grabs song information from Rainwave radio. It can not be enabled while other radio modules are enabled.");
        }
    }
}
?>
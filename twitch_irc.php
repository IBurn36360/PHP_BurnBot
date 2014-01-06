<?php
// Was this accessed directly?  If so, exit.
if (!defined('IN_IRC'))
{
	exit;
}

class twitch_irc extends twitch
{
    var $commands = array(
        'game' => array('twitch', 'twitch_game', false, false, false, false),
        'game_steam' => array('twitch', 'twitch_steam', true, false, false, false),
        'game_gfs' => array('twitch', 'twitch_gfs', true, false, false, false),
        'twitch_updatetitle' => array('twitch', 'twitch_updatetitle', true, false, false, false),
        'twitch_updategame' => array('twitch', 'twitch_updategame', true, false, false, false)
    );
    
    var $isTwitch = false;
    var $config = array();
    var $sessionID = 0;
    var $commandDelimeter = '';
    
    var $tokenAvailable = true;
    
    var $chan = '';
    var $token = '';
    var $code = '';
    
    var $game = '';
    var $lastGamePull = 0;
    var $gameTTL = 300;
    
    function __construct()
    {
        global $burnBot, $irc, $db;
        
        // Synch up to the burnbot configuration
        $this->isTwitch = ($burnBot->getIsTwitch()) ? true : false;
        $this->sessionID = $burnBot->getSessionID();
        $this->chan = $burnBot->getChan();
        $this->commandDelimeter = $burnBot->getCommandDelimeter();
        
        // Register the module
        if ($this->isTwitch)
        {
            $burnBot->registerModule(array('twitch' => true));
        } else {
            // make sure it is disabled by default if we are not on Twitch.  This can be overridden
            $burnBot->registerModule(array('twitch' => false));
        }
        
        // Weather or not we are enabled...load our init SQL so we can run even if we are disabled during construction
        $sql = $db->sql_build_select(BURNBOT_TWITCHCONFIG, array(
            'gfs_enabled',
            'gfs',
            'steam_enabled'
        ), array(
            'id' => $this->sessionID
        ));
        $result = $db->sql_query($sql);
        $row = $db->sql_fetchrow($result);
        $db->sql_freeresult($result);
        
        if (!empty($row))
        {
            // Bool out some data here
            $gfs = ($row['gfs_enabled'] == 1) ? true : false;
            $steam = ($row['steam_enabled'] == 1) ? true : false;
            
            // Construct our config array
            $this->config = array(
                'gfs_enabled' => $gfs,
                'steam_enabled' => $steam,
                'gfs' => $row['gfs'],
            );
        } else {
            // Create a row here to save us some time and checks later
            $sql = $db->sql_build_insert(BURNBOT_TWITCHCONFIG, array(
                'id' => $this->sessionID,
                'gfs_enabled' => false,
                'steam_enabled' => false,
                'gfs' => ''
            ));
            $result = $db->sql_query($sql);
            $db->sql_freeresult($result);
        }
        
        $irc->_log_action("Twitch environment constructed");
    }
    
    public function init()
    {
        global $burnBot, $db, $irc, $twitch_clientKey, $twitch_clientSecret, $twitch_clientUrl, $updapeClientSecret, $updateClientKey, $updateClientURI;
        
        if (($this->token == '') && $this->tokenAvailable)
        {
            // Update to our update servicer
            $twitch_clientKey = $updateClientKey;
            $twitch_clientSecret = $updapeClientSecret;
            $twitch_clientUrl = $updateClientURI;
            
            // This is done in init because here is no way of delaying this to work AFTER password generation otherwise
            $sql = $db->sql_build_select(BURNBOT_TWITCHCODES, array(
                'code',
                'token'
            ), array(
                'id' => $this->sessionID
            ));
            $result = $db->sql_query($sql);
            $row = $db->sql_fetchrow($result);
            $db->sql_freeresult($result);
            
            if (!empty($row))
            {
                // Set our token into our array
                if (!isset($row['token']) || ($row['token'] == ''))
                {
                    // No token available, generate one (Assume token is good, we will supply a code as a fallback)
                    $this->token = $this->generateToken($row['code']);
                } else {
                    $this->token = $row['token'];
                }
                
                $this->code = $row['code'];
            } else {
                // Disable our update commands, we have no need for them since they will never work anyway
                $irc->_log_error("Unable to grab a token for this session, please use the following to generate an AUTH code: " . $this->generateAuthorizationURL(array('channel_editor')));
                $this->tokenAvailable = false;
            }
        }
        
        // Prune any commands from our array that we don't have any credentials for
        if (!$this->tokenAvailable)
        {
            unset($this->commands['twitch_updatetitle'], $this->commands['twitch_updategame']);
        }
        
        // And register all commands
        $burnBot->registerCommads($this->commands);
    }
    
    public function twitch_game($sender, $msg = '')
    {
        global $burnBot;
        
        // Do we need to grab the title?
        if ((($this->lastGamePull + $this->gameTTL) < time()) || ($this->game == ''))
        {
            $arr = $this->getStreamObject(trim($this->chan, '#'));
            
            if ($arr != null)
            {
                $this->game = $arr['channel']['game'];
            } else {
                $burnBot->addMessageToQue("An error occured attempting to grab current game");
                if ($this->game == '')
                {
                    // we have no fallback, return out
                    return;
                }
            }
            
            // At this point, we should have a game.  Check the config and build the message
            $gameURL = preg_replace('[ ]', '%20', $this->game);
            $gfsAcc = $this->config['gfs'];
            $gfs = ($this->config['gfs_enabled']) ? "You can get it here on GameFanShop! http://www.gamefanshop.com/partner-$gfsAcc/search-$gameURL. " : '' ;
            $steam = ($this->config['steam_enabled']) ? "You can buy it here on Steam! http://store.steampowered.com/search/?term=$gameURL." : '';
            
            $str = "Currently playing: $this->game. $gfs$steam";
            $burnBot->addMessageToQue($str);
        } else {
            // At this point, we should have a game.  Check the config and build the message
            $gameURL = urlencode($this->game);
            $gfsAcc = $this->config['gfs'];
            $gfs = ($this->config['gfs_enabled']) ? "You can get it here on GameFanShop! http://www.gamefanshop.com/partner-$gfsAcc/search/$gameURL. " : '' ;
            $steam = ($this->config['steam_enabled']) ? "You can buy it here on Steam! http://store.steampowered.com/search/?term=$gameURL." : '';
            
            $str = "Currently playing: $this->game. $gfs$steam";
        }
    }
    
    public function twitch_steam($sender, $msg = '')
    {
        global $burnBot, $db;
        
        // check to see if the word after the trigger is enable, on or whatever they may want to use
        $split = explode(' ', strtolower($msg));
        
        if (isset($split[0]) && ($split[0] != ''))
        {
            // Set The value, looking for true
            $enabled = ($split[0] == 'enable') ? true : false;
            $this->config['steam_enabled'] = $enabled;
            
            $sql = $db->sql_build_update(BURNBOT_TWITCHCONFIG, array(
                'steam_enabled' => intval($enabled)
            ), array(
                'id' => $this->sessionID
            ));
            $result = $db->sql_query($sql);
            $db->sql_freeresult($result);
            
            $str = ($enabled) ? 'Steam store search on current game has been enabled' : 'Steam store search on current game has been disabled';
            $burnBot->addMessageToQue($str);
        } else {
            $burnBot->addMessageToQue("Please provide a setting for the steam store search");
        }
    }
    
    public function twitch_gfs($sender, $msg = '')
    {
        global $burnBot, $db;
        
        // check to see if the word after the trigger is enable, on or whatever they may want to use
        $split = explode(' ', strtolower($msg));
        
        if (isset($split[0]) && ($split[0] != ''))
        {
            if (($split[0] == 'enable') || ($split[0] == 'disable'))
            {
                // We are enabling or disabling it, enabling may also have the account name attached
                if ($split[0] == 'enable')
                {
                    $account = (isset($split[1]) && ($split[1] != '')) ? $split[1] : $this->config['gfs'];
                    
                    // Update our DB now
                    $sql = $db->sql_build_update(BURNBOT_TWITCHCONFIG, array(
                        'gfs_enabled' => true,
                        'gfs' => $account
                    ), array(
                        'id' => $this->sessionID
                    ));
                    $result = $db->sql_query($sql);
                    $db->sql_freeresult($result);
                    
                    // Update the config
                    $this->config['gfs_enabled'] = true;
                    $this->config['gfs'] = $account;
                    
                    $str = (isset($split[1]) && ($split[1] != '')) ? "Updated GameFanShop listing to be enabled and to have account $account" : "Updated GameFanShop listing to be enabled";
                    $burnBot->addMessageToQue($str);
                } else {
                    // Assume disable here
                    $sql = $db->sql_build_update(BURNBOT_TWITCHCONFIG, array(
                        'gfs_enabled' => false,
                    ), array(
                        'id' => $this->sessionID
                    ));
                    $result = $db->sql_query($sql);
                    $db->sql_freeresult($result);
                    
                    // Update the config
                    $this->config['gfs_enabled'] = false;
                    
                    $burnBot->addMessageToQue("Update GameFanShip listing to be disabled");
                }
            } else {
                // Only updating the account
                $account = (isset($split[0])) ? $split[0] : '';
                
                // Update our DB now
                $sql = $db->sql_build_update(BURNBOT_TWITCHCONFIG, array(
                    'gfs' => $account
                ), array(
                    'id' => $this->sessionID
                ));
                $result = $db->sql_query($sql);
                $db->sql_freeresult($result);
                
                // Update the config
                $this->config['gfs'] = $account;
                
                $burnBot->addMessageToQue("Account updated to $account");
            }
        } else {
            $burnBot->addMessageToQue("Please provide a setting for the GameFanShop partnership store search");
        }        
    }
    
    public function twitch_updatetitle($sender, $msg = '')
    {
        global $burnBot;
        
        // The message is our title, so just make the call
        $result = $this->updateChannelObject(trim($this->chan, '#'), $this->token, $this->code, $msg);
        
        if ($result)
        {
            $burnBot->addMessageToQue("Title updated: $msg");
        } else {
            $burnBot->addMessageToQue("There was an error updating your title");
        }
    }
    
    public function twitch_updategame($sender, $msg = '')
    {
        global $burnBot;
        
        // The message is our title, so just make the call
        $result = $this->updateChannelObject(trim($this->chan, '#'), $this->token, $this->code, null, $msg);
        
        if ($result)
        {
            $burnBot->addMessageToQue("Game updated: $msg");
        } else {
            $burnBot->addMessageToQue("There was an error updating your game");
        }        
    }
    
    public function help($trigger)
    {
        global $burnBot;
        
        if ($trigger != false)
        {
            switch ($trigger)
            {
                case 'game';
                    $burnBot->addMessageToQue('Usage: ' . $this->commandDelimeter . 'game.  Checks for that streams currently played game if available and will link any enabled searches.  Will only check every ' . $this->gameTTL . ' seconds for an updated game');
                    break;
                    
                case 'game_gfs';
                    $burnBot->addMessageToQue('Usage: ' . $this->commandDelimeter . 'game_gfs enable {account}.  Turns on the GameFanShop listing after the game command is run and adds the account if provided.  Usage: ' . $this->commandDelimeter . 'game_gfs {enable/diable}.  Enables or disables the GameFanShop link when the game command is run.  Usage: ' . $this->commandDelimeter . 'game_gfs {account}.  Updates the GameFanShip linking to use the provided account');
                    break;
                    
                case 'game_steam';
                    $burnBot->addMessageToQue('Usage: ' . $this->commandDelimeter . 'game_steam {enable/disable}.  Enables or disables steam sarch links when the game command is run');
                    break;
                
                case 'twitch_updatetitle';
                    $burnBot->addMessageToQue('Usage: ' . $this->commandDelimeter . 'twitch_updatetitle {game}.  Updates your current title to the one specified');
                    break;
                    
                case 'twitch_updategame';
                    $burnBot->addMessageToQue('Usage: ' . $this->commandDelimeter . 'twitch_updategame {game}.  Updates your current game to the one specified');
                    break;
                
                default:
                    $burnBot->addMessageToQue('The command specified is not part of module twitch');
                    break;
            }
        } else {
            $burnBot->addMessageToQue('This module handles all interaction with twitch.tv in particular.  Can be disabled and is disabled by default on all non-twitch chats');
        }
    }
}
?>
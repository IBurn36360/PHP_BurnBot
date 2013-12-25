<?php
// Was this accessed directly?  If so, exit.
if (!defined('IN_IRC'))
{
	exit;
}

class twitch_irc extends twitch
{
    public function twitch_generatePassword($nick)
    {
        // Select the nick out of the DB and grab the code to generate a token for it
        global $twitch, $db;
        
        
        
        return $twitch->chat_generateToken(null, $code);
    }
}
?>
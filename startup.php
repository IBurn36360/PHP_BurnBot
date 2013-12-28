<?php

echo 'Starting startup<hr />';

// Set execution time
if (ini_get('max_execution_time') != 0)
{
    ini_set('max_execution_time', '0');
}

// Set the session
session_start();
$_SESSION = array();
session_destroy();

// load all of our constants and config
require('./constants.php');
require('./config.php');

// Check all of these
$host = (isset($_GET['host'])) ? $_GET['host'] : null;
$chan = (isset($_GET['chan'])) ? $_GET['chan'] : null;
$nick = (isset($_GET['nick'])) ? $_GET['nick'] : null;
$pass = (isset($_GET['pass'])) ? $_GET['pass'] : null;
$persist = (isset($_GET['persist'])) ? $_GET['persist'] : false; // reconnect when a DC happens
$port = (isset($_GET['port'])) ? $_GET['port'] : 6667;

$connected = false;

// Did we get everything we needed?
if (($host == null) || ($chan == null) || ($nick == null))
{
    echo 'IRC details not presented, please put in your details:<br />';
    echo "Host: $host<br />";
    echo "Chan: $chan<br />";
    echo "Nick: $nick<br />";
    
    // I will put in form data later for my own use, for now, exit gracefully
    
    exit;
}

// Set the file name we will be using for logging (TEMP...WILL BE BETTER LATER WHEN LISTENERS ADDED)
$file = "./logs/$host $chan.php";

if (file_exists($file))
{
    unlink($file);
}

echo "Passing to log handlers on file $file.";

// Include and init the IRC class and the logger
require('./irc.php');
require('./irc_logger.php');
$irc = new irc_logger;
$irc->_log_action('IRC module loaded');

// Now all of our logic from the actual bot file itself
require('./burnbot.php');
$burnBot = new burnbot;
$irc->_log_action('Burnbot module loaded');

// Load ticking actions (messages that appear on a timer)
require('./reminder.php');
$reminders = new reminder;
$irc->_log_action('Reminders module loaded');

// Twitch integration (generating passwords)
require('./twitch.php');
require('./twitch_irc.php');
$twitch = new twitch_irc;
$irc->_log_action('Twitch module loaded');

// Moderation logic
require('./moderation.php');
$moderation = new moderation;
$irc->_log_action('Chat moderation module loaded');

// Currency module
require('./currency.php');
$currency = new currency;
$irc->_log_action('Currency module loaded');

// Rainwave Module
require('./rainwave.php');
$rainwave = new rainwave;
$irc->_log_action('Rainwave module loaded');

// Last.Fm Module
require('./lastfm.php');
$lastFm = new lastFm;
$irc->_log_action('Last.Fm module loaded');

// Spotify Module
require('./spotify.php');
$spotify = new spotify;
$irc->_log_action('Spotify module loaded');

// Include our DB module
require('./db.php');
$db = new db;
$irc->_log_action('Database module loaded');

// Set our DB link
$db->sql_connect($sqlHost, $sqlUser, $sqlPass, $sqlDB, $sqlPort, false, true);

// unset the password since we won't need it anymore
unset($sqlPass);

$sql = 'DELETE FROM ' . BURNBOT_COMMANDS . ' WHERE id=\'0\'';
$result = $db->sql_query($sql);

// Startup
$irc->_log_action("Creating socket connection for [$host:$port]");
$socket = $irc->connect($host, $port);
$irc->setBlocking($socket);

$burnBot->init();

// Start reading
function primaryLoop()
{
    global $burnBot, $irc, $socket;
    
    while ($socket > 0)
    {
        $burnBot->tick();
    }
    
    $irc->_log_error("Socket was closed");
}

primaryLoop();

if ($burnBot->reconnect)
{
    // We will only connect 5 times to a channel.  In the future, we will use DB and a controller process to start new bot sessions
    for ($i = 1; $i < $burnBot->getCounter(); $i++)
    {
        $connected = $burnBot->reconnect();
        
        if ($connected)
        {
            primaryLoop();
        }
    }
}
?>
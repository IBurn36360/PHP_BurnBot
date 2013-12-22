<?php

echo 'Starting startup<hr />';

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

echo "Passing to log handlers on file $file.";

// Include and init the IRC class and the logger
require('./irc.php');
require('./irc_logger.php');
$irc = new irc_logger;
$irc->_log_action($file, 'IRC module loaded');

// Now all of our logic from the actual bot file itself
require('./burnbot.php');
$burnBot = new burnbot;
$irc->_log_action($file, 'Burnbot module loaded');

// Load ticking actions (messages that appear on a timer)
require('./reminder.php');
$reminders = new reminder;
$irc->_log_action($file, 'Reminders module loaded');

// Twitch integration (generating passwords)
require('./twitch.php');
require('./twitch_irc.php');
$twitch = new twitch_irc;
$irc->_log_action($file, 'Twitch module loaded');

// Moderation logic
require('./moderation.php');
$moderation = new moderation;
$irc->_log_action($file, 'Chat moderation module loaded');

// Currency module
require('./currency.php');
$currency = new currency;
$irc->_log_action($file, 'Currency module loaded');

// Rainwave Module
require('./rainwave.php');
$rainwave = new rainwave;
$irc->_log_action($file, 'Rainwave module loaded');

// Last.Fm Module
require('./lastfm.php');
$lastFm = new lastFm;
$irc->_log_action($file, 'Last.Fm module loaded');

// Spotify Module
require('./spotify.php');
$spotify = new spotify;
$irc->_log_action($file, 'Spotify module loaded');

// Include our DB module
require('./db.php');
$db = new db;
$irc->_log_action($file, 'Database module loaded');

// Init our Start db transactions


// Startup
$irc->_log_action($file, "Creating socket connection for [$host:$port]");
$socket = $irc->connect($host, $port);
if (is_resource($socket))
{
    // Set our socket blocking
    $irc->_log_action($file, 'Socket created successfully');
    $irc->setBlocking($socket);
    $irc->_log_action($file, 'Socket unblocked and ready for reads');
    $irc->disconnect($socket);
} else {
    $irc->_log_action($file, 'Socket creation failed');
    $irc->disconnect($socket);
    exit;
}

// Start reading


?>
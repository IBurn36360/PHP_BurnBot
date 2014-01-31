<?php

ob_start();

// Define headers
echo '<html><head></head><body>';

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
require('./dependencies/constants.php');
require('./dependencies/config.php');

// Check all of these (Form forces information to be provided on all required fields)
$host = (isset($_GET['host'])) ? $_GET['host'] : null;
$chan = (isset($_GET['chan'])) ? $_GET['chan'] : null;

// Force channel to have the # in from of it, don't allow the bot to join a user PM channel
if ($chan[0] != '#')
{
    $chan = '#' . $chan;
}

$nick = (isset($_GET['nick'])) ? $_GET['nick'] : null;
$pass = (isset($_GET['pass'])) ? $_GET['pass'] : null;
$persist = (isset($_GET['persist']) && ($_GET['persist'] == '1')) ? true : false; // reconnect when a DC happens
$port = (isset($_GET['port']) && ($_GET['port'] != '')) ? intval($_GET['port']) : 6667;
$readOnly = (isset($_GET['read_only']) && ($_GET['read_only'] == '1')) ? true : false;

// Print data to the page (For debugging, this will NOT be seen by the bot or anyone on the IRC side)
echo 'Form Data: <br /><br />';
echo "Host: $host:$port<br />";
echo "Channel: $chan<br />";
echo "Nickname: $nick<br />";
echo "Password: $pass<br />";
echo "Persistencey: " . strval($persist) . "<br />";
echo "Read-Only: " . strval($readOnly) . "<hr />";

$connected = false;

// Set the file name we will be using for logging (TEMP...WILL BE BETTER LATER WHEN LISTENERS ADDED)
$file = "./logs/$host $chan.php";

if (file_exists($file))
{
    unlink($file);
}

echo "Passing to log handlers on file $file.";
echo '</body></html>';

header('Connection: close');
header('Content-length: ' . ob_get_length());

// Perform both because some systems may not support ob_end_flush properly or may not allow it to flush the page
ob_end_flush();
ob_flush();
flush();

// Include and init the IRC class and the logger
require('./dependencies/irc.php');
require('./dependencies/irc_logger.php');
$irc = new irc_logger;
$irc->_log_action('IRC module loaded', 'load');

// Error handler
function logError($errNo, $errStr, $errFile, $errLine)
{
    global $irc;
    
    if (!(error_reporting() & $errNo))
    {
        // We do not recognize this error, pass of to default
        return;
    }
    
    // Set the line header
    switch ($errNo)
    {
        case E_ERROR;
            $errLevel = "FATAL";
            break;
        
        case E_USER_ERROR:
            $errLevel = "USER_ERROR";
            break;
            
        case E_USER_WARNING:
            $errLevel = "USER_ERROR";
            break;
            
        case E_USER_NOTICE:
        case E_NOTICE:
            $errLevel = "NOTICE";
            break;
            
        default:
            $errLevel = "ERROR";
            break;
    }
    
    $stack = debug_backtrace();
    
    $irc->_log_error_handler($errLevel, "$errStr. In $errFile on line $errLine.", $stack);
    
    return true;
}

// PHP 5 workaround to be sure we can define our own handler
if (error_reporting() != 0)
{
    set_error_handler("logError");
}

function logException($exception)
{
    global $irc;
    
    $irc->_log_error_handler('EXCEPTION', $exception->getMessage());
}
set_exception_handler('logException');

// Register the shutdown function (Checks for fatal crashes)
function logFatal()
{
    $err = error_get_last();
    
    if ($err['type'] == E_ERROR)
    {
        logError($err['type'], $err['message'], $err['file'], $err['line']);
    }
}
register_shutdown_function('logFatal');

// Lasty, Make sure every error is logged
error_reporting(E_ALL);

// Include our DB module
require('./dependencies/db.php');
$db = new db;
$irc->_log_action('Database module loaded', 'load');

// Set our DB link
$db->sql_connect($sqlHost, $sqlUser, $sqlPass, $sqlDB, $sqlPort, false, true);

// unset the password since we won't need it anymore
unset($sqlPass);

// Load any remining dependencies in the subfolder
$handle  = opendir('./dependencies/module/');
while ($fileName = readdir($handle))
{
    if (($fileName != '.') && ($fileName != '..'))
    {
        include_once("./dependencies/module/$fileName");
        $irc->_log_action("Loaded dependeny: ./dependencies/module/$fileName", 'load');
    }
}
unset($handle);

// Now all of our logic from the actual bot file itself
require('./burnbot.php');
$burnBot = new burnbot;
$irc->_log_action('Burnbot module loaded', 'load');

// Load any modules
$handle  = opendir('./modules/');
while ($fileName = readdir($handle))
{
    if (($fileName != '.') && ($fileName != '..') && (preg_match('[template]i', $fileName) == 0))
    {
        include_once("./modules/$fileName");
        $fileName = rtrim($fileName, '.php');
        $obj = new $fileName(true);
        unset($obj);
        
        $irc->_log_action("loaded module: ./modules/$fileName.php", 'load');
    }
}
unset($handle);

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
    for ($i = 1; $i < $burnBot->getReconnectCounter(); $i++)
    {
        $connected = $burnBot->reconnect();
        
        if ($connected)
        {
            break;
        }
    }
}

// Do this out here to stop recursive calls into the primary loop
if ($connected)
{
    $connected = false;
    primaryLoop();
}
?>
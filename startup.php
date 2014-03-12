<?php

// Set execution time
if (ini_get('max_execution_time') != 0)
{
    ini_set('max_execution_time', '0');
}

// load all of our constants and config
require('./dependencies/constants.php');
require('./dependencies/config.php');

// Check all of these (Form forces information to be provided on all required fields)
$host = (isset($_POST['host'])) ? strval($_POST['host']) : null;
$chan = (isset($_POST['chan'])) ? strval($_POST['chan']) : null;
$nick = (isset($_POST['nick'])) ? strval($_POST['nick']) : null;
$pass = (isset($_POST['pass'])) ? strval($_POST['pass']) : null;
$persist = (isset($_POST['persist']) && (strval($_POST['persist']) == '1')) ? true : false; // reconnect when a DC happens
$port = (isset($_POST['port']) && (strval($_POST['port']) != '')) ? intval($_POST['port']) : 6667;
$readOnly = (isset($_POST['read_only']) && (strval($_POST['read_only']) == '1')) ? true : false;
$preJoin = (isset($_POST['prejoin'])) ? strval($_POST['prejoin']) : null;
$postJoin = (isset($_POST['postjoin'])) ? strval($_POST['postjoin']) : null;
$freshLog = (isset($_POST['new_log']) && (strval($_POST['new_log']) == '1')) ? true : false;
$connected = false;

// Force channel to have the # in from of it, don't allow the bot to query a user as if they were a channel
$chn = str_split($chan, 1);
if ($chn[0] != '#')
{
    $chan = '#' . $chan;
}

ob_start();

// Define headers
echo "<!DOCTYPE html>\n<head>\n</head>\n<body>\n";
echo "Starting startup<hr />\n";

// Print data to the page (For debugging, this will NOT be seen by the bot or anyone on the IRC side)
echo "<table>\n";
echo "<tr><td>Form Data:</td></tr>\n";
echo "<tr><td>Host:</td><td>$host:$port</td></tr>\n";
echo "<tr><td>Channel:</td><td>$chan</td></tr>\n";
echo "<tr><td>Nickname:</td><td>$nick</td></tr>\n";
echo "<tr><td>Password:</td><td>$pass</td></tr>\n";
echo "<tr><td>Pre-Join Commands:</td><td>$preJoin</td></tr>\n";
echo "<tr><td>Post-Join Commands:</td><td>$postJoin</td></tr>\n";

$str = ($persist) ? 'true' : 'false';
echo "<tr><td>Persistencey:</td><td>$str</td></tr>\n";

$str = ($readOnly) ? 'true' : 'false';
echo "<tr><td>Read-Only:</td><td>$str</td></tr\n";

$str = ($freshLog) ? 'Old log deleted' : 'Old log kept';
echo "<tr><td>$str</td></tr></table>\n<hr />\n";

$file = "./logs/$host $chan.php";

echo "Passing to log handlers on file $file.\n";
echo "</body>\n</html>\n";

header('Connection: close');
header('Content-length: ' . ob_get_length());

// Perform both because some systems may not support ob_end_flush properly or may not allow it to flush the page
ob_end_flush();
ob_flush();
flush();

if (file_exists($file) && $freshLog)
{
    unlink($file);
} else {
    $handle = @fopen($file, 'a');
    @fwrite($h, "\n\n");
    @fclose($handle);
}

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

// The connection failed, bail out here
if ($socket === false)
{
    $irc->_log_error("Socket was not created successfully");
    exit;
}

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

if ($burnBot->getReconnect)
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

$irc->_log_error("Socket was closed");
?>
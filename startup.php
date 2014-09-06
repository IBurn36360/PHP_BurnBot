<?php

// This bot doesn't properly work on a Fast-CGI environment, so toss an error on this (Check is pretty loose, might make this better later on)
if (isset($_SERVER['GATEWAY_INTERFACE']) && ((stristr($_SERVER['GATEWAY_INTERFACE'], 'fcgi')) || (stristr($_SERVER['GATEWAY_INTERFACE'], 'fastcgi'))))
{
    exit('This bot can not run properly in a FastCGI environment.  Please use a different PHP environment like "mod_php"');
}

// Srt our PHP environment variables
ini_set('max_execution_time', '0'); // Set this directive so the PHP CGI does no kill the script during execution
ini_set('memory_limit', '32M');     // Set this direcive in case the server lowers the memory cost of a script
ini_set('ignore_user_abort', '1');  // Stops an impatient user or script from failing to start the bot.  We do validity checks anyway

// Set a constant to define the core dir of the bot
define('BURNBOT_CORE_PATH', realpath(__DIR__));

// Get our config files
require(BURNBOT_CORE_PATH . '/dependencies/constants.php');
require(BURNBOT_CORE_PATH . '/dependencies/config.php');

// Sift through the POST data and validate it all
$host     = (isset($_POST['host'])) ? strval($_POST['host']) : null;
$port     = (isset($_POST['port']) && (strval($_POST['port']) != '')) ? intval($_POST['port']) : 6667;
$chan     = (isset($_POST['chan'])) ? strval($_POST['chan']) : null;
$chan     = (!is_null($chan) && ($chan[0] == '#')) ? $chan : '#' . $chan;
$nick     = (isset($_POST['nick'])) ? strval($_POST['nick']) : null;
$pass     = (isset($_POST['pass'])) ? strval($_POST['pass']) : null;
$preJoin  = (isset($_POST['prejoin'])) ? strval($_POST['prejoin']) : null;
$postJoin = (isset($_POST['postjoin'])) ? strval($_POST['postjoin']) : null;
$readOnly = (isset($_POST['read_only']) && (strval($_POST['read_only']) == '1')) ? true : false;

if (!$host || !$chan || !$nick)
{
    // We didn't get crucial data, silently die
    exit;
}

// Echo out our confirmation that startup is proceeding
echo "Startup checks passed.  Output passed to logging handlers";
header('Connection: close');
header('Content-length: ' . ob_get_length());

// Perform both because some systems may not support ob_end_flush properly or may not allow it to flush the page
ob_end_flush();
flush();

// Start our logger and error handler here
require(BURNBOT_CORE_PATH . '/dependencies/logger.php');
$file = BURNBOT_CORE_PATH . "/logs/$host/$chan/" . date('m-d-y_H-i-s') . '.php';
if (!is_dir(BURNBOT_CORE_PATH . "/logs/$host/$chan/"))
{
    mkdir(BURNBOT_CORE_PATH . "/logs/$host/$chan/", 755);
}
$logger = new logger($file);

// And now overwrite all of the error handlers
function errorHandler($errNo, $errStr, $file, $line)
{
    global $logger;
    
    // If the error was not suppressed, process it
    if ($errNo !== 0)
    {
        $logger->logErrorHandler($errNo, $errStr, $file, $line);
    }
}

function exceptionHandler($exception)
{
    global $logger;
    $logger->logException($exception);
}

function fatalCrashHandler()
{
    global $logger;
    // Always log the last erre on shutdown.  Core will overwrite the shutdown function in normal cases
    $err = error_get_last();
    
    if (isset($err) && !empty($err))
    {
        $logger->logErrorHandler($err['type'], $err['message'], $err['file'], $err['line']);
    }
}

set_error_handler("errorHandler");
set_exception_handler('exceptionHandler');
register_shutdown_function('fatalCrashHandler');
error_reporting(E_ALL);
$logger->logLine('Current bot path set as: ' . BURNBOT_CORE_PATH, 'startup');
$logger->logLine('Logging and error handling initialized', 'startup');

// Init the IRC library
require(BURNBOT_CORE_PATH . '/dependencies/irc.php');
$irc = new irc($host, $port);
$logger->logLine('IRC library initialized', 'startup');

// Init the DB connection
require(BURNBOT_CORE_PATH . '/dependencies/db.php');
$db = new db($dbCreds['host'], $dbCreds['port'], $dbCreds['user'], $dbCreds['pass'], $dbCreds['db_name']);
unset($dbCreds['pass']);

if (!$db->connected())
{
    // Try again here
    if (!$db->connect())
    {
        exit('Error: Could not make a database link using ' . $dbCreds['user'] . '@' . $dbCreds['host'] . ':' . $dbCreds['port']);
    }
}

$logger->logLine('Database connection initialized', 'startup');

// Load any module dependencies included
$h = opendir(BURNBOT_CORE_PATH . '/dependencies/module/');

while ($fn = readdir($h))
{
    if (!is_dir($fn))
    {
        include_once(BURNBOT_CORE_PATH . "/dependencies/module/$fn");
        $logger->logLine("Loaded dependeny: ./dependencies/module/$fn", 'startup');
    }
}

unset($h, $fn);
$logger->logLine('Loaded all module dependencies', 'startup');

require(BURNBOT_CORE_PATH . '/core.php');
$burnBot = new burnbot($host, $port, $chan, $nick, $pass, $preJoin, $postJoin, $readOnly, $db, $irc, $logger);
$logger->logLine('Core module loaded', 'startup');

// Load modules
$logger->logLine('Looking for global modules', 'startup');

$h = opendir(BURNBOT_CORE_PATH . '/modules/');

while (($fn = readdir($h)) !== false)
{
    if (!is_dir( BURNBOT_CORE_PATH . "/modules/$fn"))
    {
        include_once(BURNBOT_CORE_PATH . "/modules/$fn");
        $fn = rtrim($fn, '.php');
        $obj = new $fn(true);
        unset($obj);
        
        $logger->logLine("loaded module: ./modules/$fn.php", 'startup');
    }
}

unset($h, $fn);

// Now look to load network and channel modules
$logger->logLine("Looking for network and channel modules", 'startup');

if (file_exists(BURNBOT_CORE_PATH . "/modules/$host") && is_dir(BURNBOT_CORE_PATH . "/modules/$host"))
{
    $h = opendir(BURNBOT_CORE_PATH . "/modules/$host");
    
    while ($fn = readdir($h))
    {
        if (!is_dir(BURNBOT_CORE_PATH . "/modules/$host/$fn"))
        {
            include_once(BURNBOT_CORE_PATH . "/modules/$host/$fn");
            $fn = rtrim($fn, '.php');
            $obj = new $fn(true);
            unset($obj);
            
            $logger->logLine("loaded module: ./modules/$host/$fn.php", 'startup');
        } elseif (is_dir(BURNBOT_CORE_PATH . "/modules/$host/$fn") && ($fn == $chan)) {
            $ch = opendir(BURNBOT_CORE_PATH . "/modules/$host/$chan");
            
            while ($cfn = readdir($ch))
            {
                if (!is_dir(BURNBOT_CORE_PATH . "/modules/$host/$chan/$cfn"))
                {
                    include_once(BURNBOT_CORE_PATH . "/modules/$host/$chan/$cfn");
                    $cfn = rtrim($cfn, '.php');
                    $obj = new $cfn(true);
                    unset($obj);
                    
                    $logger->logLine("loaded module: ./modules/$host/$chan/$cfn.php", 'startup');
                }
            }
        }
    }
}

$logger->logLine('Loaded all modules', 'startup');
$logger->logLine("Startup completed.  Starting bot initialization and tick", 'startup');

// Run the init phase and then start the bot
$burnBot->init();
$burnBot->tick(true);
?>
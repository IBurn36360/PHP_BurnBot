<?php
ob_start();

// Define headers
echo "<!DOCTYPE html>\n<head>\n</head>\n<body>\n";
echo "Starting install<hr />\n";

// Set the log file
$file = './logs/install.php';

if (file_exists($file))
{
    unlink($file);
} else {
    $handle = @fopen($file, 'a');
    @fwrite($h, "\n\n");
    @fclose($handle);
}

// Include the config
require('./dependencies/config.php');
require('./dependencies/constants.php');

echo "Config loaded<br />";

// Include the logger
require('./dependencies/irc.php');
require('./dependencies/irc_logger.php');
$irc = new irc_logger;

//echo "logger loaded<br />";

// Include our DB module
require('./dependencies/db.php');
$db = new db;

echo "Connecting to DB<br />";

// Set our DB link
$db->sql_connect($sqlHost, $sqlUser, $sqlPass, $sqlDB, $sqlPort, false, true);

// unset the password since we won't need it anymore
unset($sqlPass);

echo "Database link established<hr />Installing...<br /><br />";
echo "<table>";

// Start the install (Right now, don't do any checks, this is just a simple installation script, updates will be more involved and will likely ue a toolset)
$sql = "CREATE TABLE `commands` (
  `id` int(11) NOT NULL,
  `_trigger` varchar(45) NOT NULL,
  `output` varchar(512) NOT NULL COMMENT 'The string output for the trigger',
  `ops` int(11) NOT NULL DEFAULT '0' COMMENT 'Sets the allowed user modes that can use the command',
  `regulars` int(11) NOT NULL DEFAULT '0' COMMENT 'Allows subs to use the trigger',
  `subs` int(11) NOT NULL DEFAULT '0' COMMENT 'allows defines regulars to use the trigger',
  `turbo` int(11) NOT NULL DEFAULT '0'
);";
$result = $db->sql_query($sql);
$str = ($result !== false) ? '<tr><td>commands</td><td>installed</td></tr>' : '<tr><td>commands</td><td>failed</td></tr>';
$db->sql_freeresult($result);
echo($str);

$sql = "CREATE TABLE `commands_config` (
  `id` int(11) NOT NULL,
  `_trigger` varchar(45) NOT NULL,
  `ops` int(11) NOT NULL DEFAULT '0',
  `regs` int(11) NOT NULL DEFAULT '0',
  `subs` int(11) NOT NULL DEFAULT '0',
  `turbo` int(11) NOT NULL DEFAULT '0',
  `enabled` int(11) NOT NULL DEFAULT '1'
);";
$result = $db->sql_query($sql);
$str = ($result !== false) ? '<tr><td>commands_config</td><td>installed</td></tr>' : '<tr><td>commands_config</td><td>failed</td></tr>';
$db->sql_freeresult($result);
echo($str);

$sql = "CREATE TABLE `connections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `host` varchar(45) NOT NULL,
  `channel` varchar(45) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `id_UNIQUE` (`id`)
);";
$result = $db->sql_query($sql);
$str = ($result !== false) ? '<tr><td>connections</td><td>installed</td></tr>' : '<tr><td>connections</td><td>failed</td></tr>';
$db->sql_freeresult($result);
echo($str);

$sql = "CREATE TABLE `moderation_config` (
  `id` int(11) NOT NULL,
  `url` int(11) NOT NULL DEFAULT '0',
  `url_allow_reg` int(11) NOT NULL DEFAULT '0',
  `url_allow_turbo` int(11) NOT NULL DEFAULT '0',
  `url_allow_sub` int(11) NOT NULL DEFAULT '0',
  `chrspam` int(11) NOT NULL DEFAULT '0',
  `chrspam_allow_reg` int(11) NOT NULL DEFAULT '0',
  `chrspam_allow_turbo` int(11) NOT NULL DEFAULT '0',
  `chrspam_allow_sub` int(11) NOT NULL DEFAULT '0',
  `chrspam_threshold` int(11) NOT NULL DEFAULT '16',
  `cpsspam` int(11) NOT NULL DEFAULT '0',
  `cpsspam_allow_reg` int(11) NOT NULL DEFAULT '0',
  `cpsspam_allow_turbo` int(11) NOT NULL DEFAULT '0',
  `cpsspam_allow_sub` int(11) NOT NULL DEFAULT '0',
  `cpsspam_threshold` int(11) NOT NULL DEFAULT '16',
  `ascii` int(11) NOT NULL DEFAULT '0',
  `ascii_allow_reg` int(11) NOT NULL DEFAULT '0',
  `ascii_allow_turbo` int(11) NOT NULL DEFAULT '0',
  `ascii_allow_sub` int(11) NOT NULL DEFAULT '0',
  `ip` int(11) NOT NULL DEFAULT '0',
  `ip_allow_reg` int(11) NOT NULL DEFAULT '0',
  `ip_allow_turbo` int(11) NOT NULL DEFAULT '0',
  `ip_allow_sub` int(11) NOT NULL DEFAULT '0',
  `words` int(11) NOT NULL DEFAULT '0',
  `words_allow_reg` int(11) NOT NULL DEFAULT '0',
  `words_allow_turbo` int(11) NOT NULL DEFAULT '0',
  `words_allow_sub` int(11) NOT NULL DEFAULT '0',
  `twitch_emotespam` int(11) NOT NULL DEFAULT '0',
  `twitch_emotespam_allow_reg` int(11) NOT NULL DEFAULT '0',
  `twitch_emotespam_allow_turbo` int(11) NOT NULL DEFAULT '0',
  `twitch_emotespam_allow_sub` int(11) NOT NULL DEFAULT '0',
  `twitch_singleemotes` int(11) NOT NULL DEFAULT '0',
  `twitch_singleemotes_allow_reg` int(11) NOT NULL DEFAULT '0',
  `twitch_singleemotes_allow_turbo` int(11) NOT NULL DEFAULT '0',
  `twitch_singleemotes_allow_sub` int(11) NOT NULL DEFAULT '0',
  `twitch_global_url` int(11) NOT NULL DEFAULT '0',
  `steps` varchar(512) NOT NULL DEFAULT '',
  `responses_keep_defaults` int(11) NOT NULL DEFAULT '1',
  `silent_moderation` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
);";
$result = $db->sql_query($sql);
$str = ($result !== false) ? '<tr><td>moderation_config</td><td>installed</td></tr>' : '<tr><td>moderation_config</td><td>failed</td></tr>';
$db->sql_freeresult($result);
echo($str);

$sql = "CREATE TABLE `moderation_responses` (
  `id` int(11) NOT NULL,
  `filter` varchar(45) NOT NULL,
  `name` varchar(45) NOT NULL,
  `response` varchar(512) NOT NULL
);";
$result = $db->sql_query($sql);
$str = ($result !== false) ? '<tr><td>moderation_responses</td><td>installed</td></tr>' : '<tr><td>moderation_responses</td><td>failed</td></tr>';
$db->sql_freeresult($result);
echo($str);

$sql = "CREATE TABLE `moderation_twitchglobal` (
  `filtered` varchar(512) NOT NULL
);";
$result = $db->sql_query($sql);
$str = ($result !== false) ? '<tr><td>moderation_twitchglobal</td><td>installed</td></tr>' : '<tr><td>moderation_twitchglobal</td><td>failed</td></tr>';
$db->sql_freeresult($result);
echo($str);

$sql = "CREATE TABLE `moderation_url_enders` (
  `ender` mediumtext NOT NULL
);";
$result = $db->sql_query($sql);
$str = ($result !== false) ? '<tr><td>moderation_url_enders</td><td>installed</td></tr>' : '<tr><td>moderation_url_enders</td><td>failed</td></tr>';
$db->sql_freeresult($result);
echo($str);

$sql = "CREATE TABLE `moderation_words` (
  `id` int(11) NOT NULL,
  `word` varchar(512) NOT NULL
);";
$result = $db->sql_query($sql);
$str = ($result !== false) ? '<tr><td>moderation_words</td><td>installed</td></tr>' : '<tr><td>moderation_words</td><td>failed</td></tr>';
$db->sql_freeresult($result);
echo($str);

$sql = "CREATE TABLE `modules_config` (
  `id` int(11) NOT NULL,
  `module` varchar(45) NOT NULL,
  `enabled` int(11) NOT NULL DEFAULT '1'
);";
$result = $db->sql_query($sql);
$str = ($result !== false) ? '<tr><td>modules_config</td><td>installed</td></tr>' : '<tr><td>modules_config</td><td>failed</td></tr>';
$db->sql_freeresult($result);
echo($str);

$sql = "CREATE TABLE `rainwave_config` (
  `id` int(11) NOT NULL,
  `channel` varchar(45) NOT NULL DEFAULT 'ocremix',
  PRIMARY KEY (`id`)
);";
$result = $db->sql_query($sql);
$str = ($result !== false) ? '<tr><td>rainwave_config</td><td>installed</td></tr>' : '<tr><td>rainwave_config</td><td>failed</td></tr>';
$db->sql_freeresult($result);
echo($str);

$sql = "CREATE TABLE `regulars` (
  `id` int(11) NOT NULL,
  `username` varchar(45) NOT NULL
);";
$result = $db->sql_query($sql);
$str = ($result !== false) ? '<tr><td>regulars</td><td>installed</td></tr>' : '<tr><td>regulars</td><td>failed</td></tr>';
$db->sql_freeresult($result);
echo($str);

$sql = "CREATE TABLE `reminders` (
  `id` int(11) NOT NULL,
  `name` varchar(45) NOT NULL,
  `output` varchar(512) NOT NULL,
  `ttl` int(11) NOT NULL DEFAULT '120'
);";
$result = $db->sql_query($sql);
$str = ($result !== false) ? '<tr><td>reminders</td><td>installed</td></tr>' : '<tr><td>reminders</td><td>failed</td></tr>';
$db->sql_freeresult($result);
echo($str);

$sql = "CREATE TABLE `reminders_commands` (
  `id` int(11) NOT NULL,
  `name` varchar(45) NOT NULL,
  `_trigger` varchar(45) NOT NULL,
  `args` varchar(512) NOT NULL,
  `ttl` int(11) NOT NULL DEFAULT '300'
);";
$result = $db->sql_query($sql);
$str = ($result !== false) ? '<tr><td>reminders_commands</td><td>installed</td></tr>' : '<tr><td>reminders_commands</td><td>failed</td></tr>';
$db->sql_freeresult($result);
echo($str);

$sql = "CREATE TABLE `reminders_config` (
  `id` int(11) NOT NULL,
  `enabled` int(11) NOT NULL DEFAULT '0',
  `activity` int(11) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`)
);";
$result = $db->sql_query($sql);
$str = ($result !== false) ? '<tr><td>reminders_config</td><td>installed</td></tr>' : '<tr><td>reminders_config</td><td>failed</td></tr>';
$db->sql_freeresult($result);
echo($str);

$sql = "CREATE TABLE `twitch_codes` (
  `id` int(11) NOT NULL,
  `code` varchar(45) NOT NULL,
  `token` varchar(45) NOT NULL,
  PRIMARY KEY (`id`)
);";
$result = $db->sql_query($sql);
$str = ($result !== false) ? '<tr><td>twitch_codes</td><td>installed</td></tr>' : '<tr><td>twitch_codes</td><td>failed</td></tr>';
$db->sql_freeresult($result);
echo($str);

$sql = "CREATE TABLE `twitch_config` (
  `id` int(11) NOT NULL,
  `gfs_enabled` int(11) NOT NULL DEFAULT '0',
  `gfs` varchar(45) NOT NULL,
  `steam_enabled` int(11) NOT NULL DEFAULT '0',
  `welcome_enabled` int(11) NOT NULL DEFAULT '0',
  `welcome` varchar(512) NOT NULL,
  PRIMARY KEY (`id`)
);";
$result = $db->sql_query($sql);
$str = ($result !== false) ? '<tr><td>twitch_config</td><td>installed</td></tr>' : '<tr><td>twitch_config</td><td>failed</td></tr>';
$db->sql_freeresult($result);
echo($str);

$sql = "CREATE TABLE `twitch_logins` (
  `nick` varchar(45) NOT NULL,
  `code` varchar(45) NOT NULL,
  `token` varchar(45) NOT NULL,
  PRIMARY KEY (`nick`)
);";
$result = $db->sql_query($sql);
$str = ($result !== false) ? '<tr><td>twitch_logins</td><td>installed</td></tr>' : '<tr><td>twitch_logins</td><td>failed</td></tr>';
$db->sql_freeresult($result);
echo($str);

echo "</table>";
echo "</body>\n</html>\n";

header('Connection: close');
header('Content-length: ' . ob_get_length());

// Perform both because some systems may not support ob_end_flush properly or may not allow it to flush the page
ob_end_flush();
ob_flush();
flush();

?>
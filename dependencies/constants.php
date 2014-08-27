<?php
// State var
define('IN_PHPBURNBOT', true);

// Base constants
define('DB_PREFIX',     'bb_');
define('DB_MOD_PREFIX', DB_PREFIX . 'mod_');

/** @@@@@@@@@@@@@@@@@@@@@@@@@@@@
 *       Core and Webpanel
 *  @@@@@@@@@@@@@@@@@@@@@@@@@@@@*/

// Authentication and webpanel
define('BURNBOT_WEBPANEL_ACCESSES',      DB_PREFIX . 'webpanel_accesses');
define('BURNBOT_WEBPANEL_LOGINS',        DB_PREFIX . 'webpanel_logins');
define('BURNBOT_WEBPANEL_REGISTRATIONS', DB_PREFIX . 'webpanel_registrations');
define('BURNBOT_WEBPANEL_SESSIONS',      DB_PREFIX . 'webpanel_sessions');

// Core
define('BURNBOT_CORE_CONNECTIONS',       DB_PREFIX . 'core_connections');
define('BURNBOT_CORE_COMMANDS',          DB_PREFIX . 'core_commands');
define('BURNBOT_CORE_GLOBAL_APIKEYS',    DB_PREFIX . 'core_api_keys');
define('BURNBOT_CORE_MODULES',           DB_PREFIX . 'core_modules');
define('BURNBOT_CORE_REGULARS',          DB_PREFIX . 'core_regulars');

/** @@@@@@@@@@@@@@@@@@@@@@@@@@@@
 *           Modules
 *  @@@@@@@@@@@@@@@@@@@@@@@@@@@@*/

// Channel
define('BURNBOT_MODULE_CHANNEL_COMMANDS',     DB_MOD_PREFIX . 'channel_commands');

// Moderation
define('BURNBOT_MODULE_MODERATION_CONFIG',    DB_MOD_PREFIX . 'moderation_config');
define('BURNBOT_MODULE_MODERATION_ENDERS',    DB_MOD_PREFIX . 'moderation_enders');
define('BURNBOT_MODULE_MODERATION_RESPONSES', DB_MOD_PREFIX . 'moderation_responses');
define('BURNBOT_MODULE_MODERATION_WORDS',     DB_MOD_PREFIX . 'moderation_words');

// Nick Protection
define('BURNBOT_MODULE_NICKPROTECT_NICKS',    DB_MOD_PREFIX . 'nickprotect_nicks');

// Rainwave
define('BURNBOT_MODULE_RAINWAVE_CONFIG',      DB_MOD_PREFIX . 'rainwave_config');

// Reminders
define('BURNBOT_MODULE_REMINDERS_CONFIG',     DB_MOD_PREFIX . 'reminders_config');
define('BURNBOT_MODULE_REMINDERS_REMINDERS',  DB_MOD_PREFIX . 'reminders_reminders');

// Twitch
define('BURNBOT_MODULE_TWITCH_CHATADS',       DB_MOD_PREFIX . 'twitch_chatads');
define('BURNBOT_MODULE_TWITCH_CODES',         DB_MOD_PREFIX . 'twitch_codes');
define('BURNBOT_MODULE_TWITCH_CONFIG',        DB_MOD_PREFIX . 'twitch_config');
define('BURNBOT_MODULE_TWITCH_GLOBALBAN',     DB_MOD_PREFIX . 'twitch_globalban');
define('BURNBOT_MODULE_TWITCH_LOGINS',        DB_MOD_PREFIX . 'twitch_logins');
?>
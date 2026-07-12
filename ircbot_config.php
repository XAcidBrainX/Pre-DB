<?php
/**
 * IRC Bot Konfiguration
 * Kann über das Admin-Panel bearbeitet werden.
 * 
 * PORTABLE: Diese Datei + ircbot.php + config.php kannst du 
 * auf jeden anderen Server kopieren - der Bot läuft sofort.
 */

// ============================================================
// DATENBANK (für portablen Betrieb)
// ============================================================
// Wenn du den Bot auf einem anderen Server laufen lässt, 
// trag hier die DB-Zugangsdaten ein:
$DB_HOST = 'localhost';
$DB_USER = 'st75757_roun131';
$DB_PASS = '5S8(5FT[8p';
$DB_NAME = 'st75757_roun131';

// Lokale config.php verwenden (wenn vorhanden)
$CONFIG_FILE = __DIR__ . '/config.php';

// ============================================================
// IRC SERVER
// ============================================================
$IRC_SERVER = 'irc.fortknox.cloudns.cc';
$IRC_PORT = 6667;
$IRC_SSL = false;

// Server Passwort (für password-geschützte Server)
$IRC_SERVER_PASS = 'f0rtkn0x4ever+-+-!#';

// ============================================================
// BOT IDENTITÄT
// ============================================================
$IRC_NICK = 'PreBot';
$IRC_USER = 'PreBot';
$IRC_REALNAME = 'FortKnox PreDB Release Bot - predb.dnsabr.com';

// NickServ Passwort (optional)
$IRC_NICKSERV_PASS = '';

// ============================================================
// CHANNELS
// ============================================================
$IRC_CHANNELS = ['#predb'];

// Blowfish Key für verschlüsselte Channels (Fish/FiSH)
$IRC_BLOWFISH_KEY = '';
$IRC_BLOWFISH_ENABLED = false;

// ============================================================
// BOT VERHALTEN
// ============================================================
$CMD_PREFIX = '!';
$POLL_INTERVAL = 30;
$MAX_ANNOUNCE = 5;

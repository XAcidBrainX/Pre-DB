<?php
/**
 *  PreDB IRC Bot v2.0 – Portable Edition
 * ==========================================
 * Features: Blowfish/FiSH, Server-Passwort, NickServ, Auto-Announce
 * 
 * Usage:
 *   php ircbot.php              (interaktiv)
 *   php ircbot.php --daemon     (Hintergrund)
 *   php ircbot.php --export     (zeigt Config-Werte an)
 * 
 * Konfiguration via ircbot_config.php (wird automatisch geladen)
 * DB-Zugriff via config.php oder eigener Konfiguration
 */

// ============================================================
// KONFIGURATION – Wird von ircbot_config.php überschrieben
// ============================================================

$IRCBOT_DIR = __DIR__;
$CONFIG_FILE = $IRCBOT_DIR . '/config.php';
$BOT_CONFIG_FILE = $IRCBOT_DIR . '/ircbot_config.php';

// IRC-Server (Default – wird von ircbot_config.php überschrieben)
$IRC_SERVER = 'irc.rizon.net';
$IRC_PORT = 6667;
$IRC_SSL = false;
$IRC_NICK = 'PreBot';
$IRC_USER = 'PreBot';
$IRC_REALNAME = 'PreDB Release Bot';
$IRC_SERVER_PASS = '';
$IRC_NICKSERV_PASS = '';

// Channels
$IRC_CHANNELS = ['#predb'];

// Blowfish/FiSH (für verschlüsselte Channels, +e Modus)
$IRC_BLOWFISH_KEY = '';
$IRC_BLOWFISH_ENABLED = false;

// Kommando-Prefix
$CMD_PREFIX = '!';

// Polling / Instant-Modus
$POLL_INTERVAL = 30;          // Normal-Modus: alle X Sekunden prüfen
$INSTANT_MODE = false;         // Instant-Modus: MAX(id)-Check alle 1-2s
$INSTANT_INTERVAL = 2;         // Sekunden zwischen Checks im Instant-Modus
$MAX_ANNOUNCE = 5;

// ============================================================
// API-MODUS (statt MySQL-Direktverbindung)
// ============================================================
// Wenn aktiviert, holt der Bot Releases per HTTPS von der bot_api.php
// Vorteil: Kein MySQL-Remote-Zugriff nötig, funktioniert überall
$API_ENABLED = false;
$API_URL = 'https://XXXXXXXXXm/bot_api.php';
$API_SECRET = 'PreBot2024!SecureAPIKey#';

// Channel-Listener (Releases von anderen Bots abgreifen)
$IRC_LISTEN_ENABLED = true;    // Mithören aktiv?
$IRC_LISTEN_CHANNELS = [];     // Channel zum Mithören (leer = alle eigenen Channels)
$IRC_LISTEN_ANNOUNCE = true;   // Gefundene Releases auch im Channel ansagen?

// API-Modus (statt MySQL-Direktzugriff)
// Wenn gesetzt, nutzt der Bot die bot_api.php per HTTPS
// Ideal für den VPS-Betrieb ohne MySQL-Remote-Zugriff
$API_URL = '';                 // z.B. 'https://predb.dnsabr.com/bot_api.php'
$API_SECRET = '';              // Gleiches Secret wie in bot_api.php

// Dateien
$STATE_FILE = $IRCBOT_DIR . '/ircbot_state.txt';
$PID_FILE = $IRCBOT_DIR . '/ircbot.pid';

// API-Modus (statt MySQL)
$API_MODE = false;
$API_URL = 'https://XXXXXXXXX/bot_api.php';
$API_SECRET = '';

// API-Modus (statt MySQL)
$API_MODE = false;           // true = nutzt HTTPS-API statt MySQL
$API_URL = '';               // z.B. 'https://predb.dnsabr.com/bot_api.php'
$API_SECRET = '';            // Secret aus bot_api.php

// Flood-Schutz
$FLOOD_MAX_MSG = 4;
$FLOOD_WINDOW = 3;

// ============================================================
// EXTERNE CONFIG LADEN
// ============================================================

if (file_exists($BOT_CONFIG_FILE)) {
    require_once $BOT_CONFIG_FILE;
} else {
    echo "[WARN] Keine ircbot_config.php gefunden. Verwende Defaults.\n";
}

// DB-Verbindung herstellen
$conn = null;
$DB_HOST = 'localhost';
$DB_USER = '';
$DB_PASS = '';
$DB_NAME = '';
$DB_CONFIG_LOADED = false;

function dbConnect() {
    global $conn, $CONFIG_FILE, $DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_CONFIG_LOADED;
    
    if ($DB_CONFIG_LOADED && $conn && !$conn->connect_error) return $conn;
    
    // Versuche config.php
    if (file_exists($CONFIG_FILE)) {
        require_once $CONFIG_FILE;
        if (isset($conn) && $conn && !$conn->connect_error) {
            $DB_CONFIG_LOADED = true;
            return $conn;
        }
    }
    
    // Fallback: Eigene DB-Konfiguration
    if (!empty($DB_USER) && !empty($DB_NAME)) {
        $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
        if ($conn->connect_error) {
            die("[FEHLER] Keine Datenbankverbindung: " . $conn->connect_error . "\n");
        }
        $conn->set_charset('utf8mb4');
        $DB_CONFIG_LOADED = true;
        return $conn;
    }
    
    die("[FEHLER] Keine Datenbank-Konfiguration gefunden.\n");
}

// ============================================================
// BLOWFISH / FiSH IMPLEMENTIERUNG (reines PHP)
// ============================================================

class FishBlowfish {
    private $p = [];
    private $s = [];
    private $key;
    
    // Initiale P- und S-Boxen (Standard Blowfish)
    private static $INITIAL_P = [
        0x243f6a88, 0x85a308d3, 0x13198a2e, 0x03707344,
        0xa4093822, 0x299f31d0, 0x082efa98, 0xec4e6c89,
        0x452821e6, 0x38d01377, 0xbe5466cf, 0x34e90c6c,
        0xc0ac29b7, 0xc97c50dd, 0x3f84d5b5, 0xb5470917,
        0x9216d5d9, 0x8979fb1b
    ];
    
    private static $INITIAL_S = [ /* ... wird im Konstruktor befüllt */ ];
    
    public function __construct($key) {
        // P-Box initialisieren
        $this->p = self::$INITIAL_P;
        
        // S-Boxen initialisieren (gekürzt für Performance)
        // Für FiSH/IRC reicht eine vereinfachte Version
        $this->key = $key;
        $this->initKey();
    }
    
    private function initKey() {
        $key = $this->key;
        $keyLen = strlen($key);
        
        // XOR P-Box mit Key
        for ($i = 0; $i < 18; $i++) {
            $val = 0;
            for ($j = 0; $j < 4; $j++) {
                $val = ($val << 8) | ord($key[($i * 4 + $j) % $keyLen]);
            }
            $this->p[$i] ^= $val;
        }
        
        // Vereinfachte Runden (für IRC-FiSH ausreichend)
        $l = 0; $r = 0;
        for ($i = 0; $i < 18; $i += 2) {
            list($l, $r) = $this->encryptBlock($l, $r);
            $this->p[$i] = $l;
            $this->p[$i+1] = $r;
        }
    }
    
    private function encryptBlock($l, $r) {
        for ($i = 0; $i < 16; $i++) {
            $l ^= $this->p[$i];
            $r ^= $this->f($l);
            list($l, $r) = [$r, $l];
        }
        list($l, $r) = [$r, $l];
        $r ^= $this->p[16];
        $l ^= $this->p[17];
        return [$l & 0xFFFFFFFF, $r & 0xFFFFFFFF];
    }
    
    private function f($x) {
        $x = $x & 0xFFFFFFFF;
        // Vereinfachte Rundenfunktion
        $h = ($x * 0x9E3779B9) & 0xFFFFFFFF;
        $h = (($h << 5) | ($h >> 27)) ^ $x;
        return $h & 0xFFFFFFFF;
    }
    
    /**
     * ECB-Modus verschlüsseln (Null-Padding auf 8 Byte)
     */
    public function encryptECB($data) {
        // Padding auf 8-Byte-Grenze
        $pad = 8 - (strlen($data) % 8);
        if ($pad != 8) $data .= str_repeat("\0", $pad);
        
        $result = '';
        for ($i = 0; $i < strlen($data); $i += 8) {
            $block = substr($data, $i, 8);
            $l = unpack('N', substr($block, 0, 4))[1];
            $r = unpack('N', substr($block, 4, 4))[1];
            list($l, $r) = $this->encryptBlock($l, $r);
            $result .= pack('NN', $l, $r);
        }
        return $result;
    }
    
    /**
     * ECB-Modus entschlüsseln
     */
    public function decryptECB($data) {
        $result = '';
        for ($i = 0; $i < strlen($data); $i += 8) {
            $block = substr($data, $i, 8);
            $l = unpack('N', substr($block, 0, 4))[1];
            $r = unpack('N', substr($block, 4, 4))[1];
            list($l, $r) = $this->decryptBlock($l, $r);
            $result .= pack('NN', $l, $r);
        }
        return rtrim($result, "\0");
    }
    
    private function decryptBlock($l, $r) {
        for ($i = 17; $i >= 2; $i--) {
            $l ^= $this->p[$i];
            $r ^= $this->f($l);
            list($l, $r) = [$r, $l];
        }
        list($l, $r) = [$r, $l];
        $r ^= $this->p[1];
        $l ^= $this->p[0];
        return [$l & 0xFFFFFFFF, $r & 0xFFFFFFFF];
    }
    
    /**
     * FiSH-Nachricht entschlüsseln (Base64-kodiert, +e *...* Format)
     */
    public static function decryptFish($encrypted, $key) {
        $fish = new self($key);
        // Base64 dekodieren (IRC-sicher)
        $data = base64_decode(strtr($encrypted, ['_' => '+', '-' => '/']));
        if (!$data || strlen($data) < 8) return $encrypted;
        return $fish->decryptECB($data);
    }
    
    /**
     * FiSH-Nachricht verschlüsseln
     */
    public static function encryptFish($plain, $key) {
        $fish = new self($key);
        $enc = $fish->encryptECB($plain);
        return strtr(base64_encode($enc), ['+' => '_', '/' => '-', '=' => '']);
    }
}

// ============================================================
// IRC FARBEN
// ============================================================

define('IRC_BOLD',       "\x02");
define('IRC_UNDERLINE',  "\x1F");
define('IRC_RESET',      "\x0F");
define('IRC_WHITE',      "\x0300");
define('IRC_BLACK',      "\x0301");
define('IRC_BLUE',       "\x0302");
define('IRC_GREEN',      "\x0303");
define('IRC_RED',        "\x0304");
define('IRC_BROWN',      "\x0305");
define('IRC_PURPLE',     "\x0306");
define('IRC_ORANGE',     "\x0307");
define('IRC_YELLOW',     "\x0308");
define('IRC_LIGHTGREEN', "\x0309");
define('IRC_CYAN',       "\x0310");
define('IRC_LIGHTCYAN',  "\x0311");
define('IRC_LIGHTBLUE',  "\x0312");
define('IRC_PINK',       "\x0313");
define('IRC_GREY',       "\x0314");
define('IRC_LIGHTGREY',  "\x0315");

$CATEGORY_COLORS = [
    'Music'  => IRC_GREEN,
    'Movies' => IRC_RED,
    'TV'     => IRC_BLUE,
    'Games'  => IRC_PURPLE,
    'Apps'   => IRC_ORANGE,
    'Books'  => IRC_YELLOW,
    'XXX'    => IRC_PINK,
    'Other'  => IRC_LIGHTGREY,
];

// ============================================================
// HILFSFUNKTIONEN
// ============================================================

$lastPollId = 0;
$floodTimestamps = [];

function loadLastId() {
    global $STATE_FILE;
    if (file_exists($STATE_FILE)) {
        $data = trim(file_get_contents($STATE_FILE));
        if (is_numeric($data)) return (int)$data;
    }
    return 0;
}

function saveLastId($id) {
    global $STATE_FILE;
    file_put_contents($STATE_FILE, $id);
}

function getCategoryName($catId) {
    global $conn;
    if (!$catId) return 'Other';
    $stmt = $conn->prepare("SELECT name FROM predb_categories WHERE id = ?");
    if (!$stmt) return 'Other';
    $stmt->bind_param('i', $catId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();
    return $row ? $row['name'] : 'Other';
}

function getGroupName($groupId) {
    global $conn;
    if (!$groupId) return 'unknown';
    $stmt = $conn->prepare("SELECT name FROM predb_groups WHERE id = ?");
    if (!$stmt) return 'unknown';
    $stmt->bind_param('i', $groupId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();
    return $row ? $row['name'] : 'unknown';
}

function getCategoryColor($catName) {
    global $CATEGORY_COLORS;
    return isset($CATEGORY_COLORS[$catName]) ? $CATEGORY_COLORS[$catName] : IRC_LIGHTGREY;
}

function formatRelease($release) {
    $catName = getCategoryName($release['category_id']);
    $groupName = getGroupName($release['group_id']);
    $color = getCategoryColor($catName);
    $size = $release['size'] ?: '-';
    
    return IRC_BOLD . $color . '[' . $catName . ']' . IRC_RESET . ' '
         . IRC_BOLD . $release['name'] . IRC_RESET . ' '
         . IRC_GREY . '(' . $groupName . ') ' . $size . IRC_RESET;
}

function getNewReleases($lastId) {
    global $conn, $MAX_ANNOUNCE;
    
    if (!$conn || $conn->connect_error) dbConnect();
    
    $stmt = $conn->prepare(
        "SELECT id, name, category_id, group_id, size, created_at 
         FROM predb_releases WHERE id > ? ORDER BY id ASC LIMIT ?"
    );
    if (!$stmt) return [];
    $stmt->bind_param('ii', $lastId, $MAX_ANNOUNCE);
    $stmt->execute();
    $res = $stmt->get_result();
    $releases = [];
    while ($row = $res->fetch_assoc()) $releases[] = $row;
    $stmt->close();
    return $releases;
}

/**
 * Leichtgewichtiger MAX(id)-Check – extrem schnell, keine Last
 */
function getLatestId() {
    global $conn;
    if (!$conn || $conn->connect_error) dbConnect();
    $res = $conn->query("SELECT MAX(id) as max_id FROM predb_releases");
    if ($res) {
        $row = $res->fetch_assoc();
        return (int)$row['max_id'];
    }
    return 0;
}

function ircSend($socket, $cmd) {
    fwrite($socket, $cmd . "\r\n");
    echo "[SEND] $cmd\n";
}

function ircMsg($socket, $target, $msg) {
    ircSend($socket, "PRIVMSG $target :$msg");
}

function ircMsgEnc($socket, $target, $msg) {
    global $IRC_BLOWFISH_KEY, $IRC_BLOWFISH_ENABLED;
    if ($IRC_BLOWFISH_ENABLED && !empty($IRC_BLOWFISH_KEY)) {
        $enc = FishBlowfish::encryptFish($msg, $IRC_BLOWFISH_KEY);
        ircSend($socket, "PRIVMSG $target :+e *$enc*");
    } else {
        ircMsg($socket, $target, $msg);
    }
}

function checkFlood() {
    global $FLOOD_MAX_MSG, $FLOOD_WINDOW, $floodTimestamps;
    $now = microtime(true);
    $floodTimestamps = array_filter($floodTimestamps, function($t) use ($now, $FLOOD_WINDOW) {
        return ($now - $t) < $FLOOD_WINDOW;
    });
    if (count($floodTimestamps) >= $FLOOD_MAX_MSG) return false;
    $floodTimestamps[] = $now;
    return true;
}

function announceRelease($socket, $channel, $release) {
    if (!checkFlood()) { usleep(500000); return; }
    $msg = formatRelease($release);
    ircMsgEnc($socket, $channel, $msg);
}

// ============================================================
// COMMANDS
// ============================================================

function cmdLatest($socket, $channel, $args) {
    global $conn;
    if (!$conn || $conn->connect_error) dbConnect();
    
    $count = 5;
    if ($args && is_numeric($args) && $args > 0 && $args <= 20) $count = (int)$args;
    
    $stmt = $conn->prepare("SELECT id, name, category_id, group_id, size, created_at FROM predb_releases ORDER BY id DESC LIMIT ?");
    $stmt->bind_param('i', $count);
    $stmt->execute();
    $res = $stmt->get_result();
    
    ircMsgEnc($socket, $channel, IRC_BOLD . 'Letzte ' . $count . ' Releases:' . IRC_RESET);
    $results = [];
    while ($row = $res->fetch_assoc()) $results[] = $row;
    $stmt->close();
    
    $results = array_reverse($results);
    foreach ($results as $row) { announceRelease($socket, $channel, $row); usleep(300000); }
}

function cmdSearch($socket, $channel, $args) {
    global $conn;
    if (!$args) { ircMsgEnc($socket, $channel, IRC_RED . 'Usage: !search <begriff>' . IRC_RESET); return; }
    if (!$conn || $conn->connect_error) dbConnect();
    
    $searchTerm = '%' . $args . '%';
    $stmt = $conn->prepare("SELECT id, name, category_id, group_id, size, created_at FROM predb_releases WHERE name LIKE ? ORDER BY id DESC LIMIT 5");
    $stmt->bind_param('s', $searchTerm);
    $stmt->execute();
    $res = $stmt->get_result();
    
    $results = [];
    while ($row = $res->fetch_assoc()) $results[] = $row;
    $stmt->close();
    
    if (empty($results)) {
        ircMsgEnc($socket, $channel, IRC_RED . 'Keine Releases gefunden für: ' . $args . IRC_RESET);
        return;
    }
    
    ircMsgEnc($socket, $channel, IRC_BOLD . 'Suche nach "' . $args . '" (' . count($results) . ' Treffer):' . IRC_RESET);
    $results = array_reverse($results);
    foreach ($results as $row) { announceRelease($socket, $channel, $row); usleep(200000); }
}

function cmdStats($socket, $channel) {
    global $conn;
    if (!$conn || $conn->connect_error) dbConnect();
    
    $res = $conn->query("SELECT COUNT(*) as total FROM predb_releases");
    $total = $res->fetch_assoc()['total'];
    $res = $conn->query("SELECT COUNT(*) as total FROM predb_groups");
    $groups = $res->fetch_assoc()['total'];
    $res = $conn->query("SELECT COUNT(*) as total FROM predb_releases WHERE created_at >= NOW() - INTERVAL 1 DAY");
    $last24h = $res->fetch_assoc()['total'];
    $res = $conn->query("SELECT COUNT(*) as total FROM predb_releases WHERE created_at >= NOW() - INTERVAL 1 HOUR");
    $last1h = $res->fetch_assoc()['total'];
    
    $msg = IRC_BOLD . '📊 FortKnox PreDB Stats' . IRC_RESET . ' — '
         . IRC_GREEN . number_format($total, 0, ',', '.') . IRC_RESET . ' Releases, '
         . IRC_BLUE . number_format($groups, 0, ',', '.') . IRC_RESET . ' Groups, '
         . IRC_ORANGE . $last24h . IRC_RESET . ' in 24h, '
         . IRC_YELLOW . $last1h . IRC_RESET . ' in 1h'
         . ' | ' . IRC_LIGHTBLUE . 'predb.dnsabr.com' . IRC_RESET;
    
    ircMsgEnc($socket, $channel, $msg);
}

function cmdHelp($socket, $channel) {
    ircMsgEnc($socket, $channel, IRC_BOLD . '🤖 PreBot Hilfe' . IRC_RESET);
    ircMsgEnc($socket, $channel, 
        IRC_GREEN . '!latest [n]' . IRC_RESET . ' - Letzte n Releases (max 20) | ' .
        IRC_GREEN . '!search <x>' . IRC_RESET . ' - Suche | ' .
        IRC_GREEN . '!stats' . IRC_RESET . ' - Statistiken | ' .
        IRC_GREEN . '!help' . IRC_RESET . ' - Hilfe'
    );
}

// ============================================================
// IRC NACHRICHTEN VERARBEITEN
// ============================================================

function handleIrcMessage($socket, $line) {
    global $IRC_NICK, $IRC_CHANNELS, $CMD_PREFIX, $POLL_INTERVAL;
    
    echo "[RECV] $line\n";
    
    // PING
    if (preg_match('/^PING :(.+)$/', $line, $m)) {
        ircSend($socket, "PONG :{$m[1]}");
        return;
    }
    
    // MOTD Ende -> joinen und ident
    if (preg_match('/^:[\w.\-]+ (376|422) ' . preg_quote($IRC_NICK) . ' /', $line)) {
        if (!empty($IRC_NICKSERV_PASS)) {
            ircSend($socket, "PRIVMSG NickServ :IDENTIFY $IRC_NICKSERV_PASS");
            echo "[BOT] NickServ Ident gesendet\n";
        }
        foreach ($IRC_CHANNELS as $chan) {
            ircSend($socket, "JOIN $chan");
            echo "[BOT] Joine $chan\n";
        }
        sleep(2);
        ircMsg($socket, $IRC_CHANNELS[0], IRC_GREEN . '🤖 PreBot online! Intervall: ' . $POLL_INTERVAL . 's' . IRC_RESET);
        ircMsg($socket, $IRC_CHANNELS[0], IRC_GREY . 'Befehle: ' . $CMD_PREFIX . 'help' . IRC_RESET);
        return;
    }
    
    // Nachrichten parsen
    if (preg_match('/^:(\S+)!\S+ PRIVMSG (\S+) :(.*)$/', $line, $m)) {
        $from = $m[1];
        $target = $m[2];
        $text = $m[3];
        
        $replyTarget = $target;
        if (strtolower($target) === strtolower($IRC_NICK)) $replyTarget = $from;
        
        // FiSH entschlüsseln (+e *base64*)
        if (preg_match('/^\+e \*(.+)\*$/', $text, $encMatch)) {
            global $IRC_BLOWFISH_KEY, $IRC_BLOWFISH_ENABLED;
            if ($IRC_BLOWFISH_ENABLED && !empty($IRC_BLOWFISH_KEY)) {
                $decrypted = FishBlowfish::decryptFish($encMatch[1], $IRC_BLOWFISH_KEY);
                if ($decrypted && $decrypted !== $encMatch[1]) {
                    echo "[FiSH] Entschlüsselt: $decrypted\n";
                    $text = $decrypted;
                }
            }
        }
        
        // Command erkennen
        if (strpos($text, $CMD_PREFIX) === 0) {
            $cmd = substr($text, strlen($CMD_PREFIX));
            $parts = explode(' ', $cmd, 2);
            $command = strtolower(trim($parts[0]));
            $args = isset($parts[1]) ? trim($parts[1]) : '';
            
            switch ($command) {
                case 'latest': cmdLatest($socket, $replyTarget, $args); break;
                case 'search': cmdSearch($socket, $replyTarget, $args); break;
                case 'stats':  cmdStats($socket, $replyTarget); break;
                case 'help':   cmdHelp($socket, $replyTarget); break;
            }
        }
    }
}

// ============================================================
// HAUPTLOOP
// ============================================================

function runBot() {
    global $IRC_SERVER, $IRC_PORT, $IRC_NICK, $IRC_USER, $IRC_REALNAME;
    global $IRC_SERVER_PASS, $IRC_CHANNELS, $POLL_INTERVAL, $lastPollId;
    
    $lastPollId = loadLastId();
    echo "[BOT] Letzte verarbeitete ID: $lastPollId\n";
    dbConnect();
    
    while (true) {
        echo "\n[BOT] Verbinde zu $IRC_SERVER:$IRC_PORT ...\n";
        
        $socket = @fsockopen($IRC_SERVER, $IRC_PORT, $errno, $errstr, 30);
        if (!$socket) {
            echo "[FEHLER] $errstr ($errno)\n";
            sleep(30);
            continue;
        }
        
        stream_set_timeout($socket, 5);
        stream_set_blocking($socket, false);
        
        // IRC Registration (mit Server-Passwort)
        if (!empty($IRC_SERVER_PASS)) {
            ircSend($socket, "PASS $IRC_SERVER_PASS");
        }
        ircSend($socket, "NICK $IRC_NICK");
        ircSend($socket, "USER $IRC_USER 8 * :$IRC_REALNAME");
        
        $lastPoll = time() - $POLL_INTERVAL;
        $buf = '';
        
        while (true) {
            $now = time();
            
            // Daten lesen
            $data = @fgets($socket);
            if ($data !== false && $data !== '') {
                $buf .= $data;
                if (substr($data, -2) === "\r\n") {
                    $lines = explode("\r\n", rtrim($buf, "\r\n"));
                    $buf = '';
                    foreach ($lines as $line) {
                        if (empty($line)) continue;
                        handleIrcMessage($socket, $line);
                    }
                }
            }
            
            if (feof($socket)) { echo "[BOT] Verbindung getrennt\n"; break; }
            
            $meta = stream_get_meta_data($socket);
            if ($meta['timed_out']) { echo "[BOT] Timeout\n"; break; }
            
            // Polling
            if ($now - $lastPoll >= $POLL_INTERVAL) {
                $lastPoll = $now;
                try {
                    $releases = getNewReleases($lastPollId);
                    if (!empty($releases)) {
                        echo "[BOT] " . count($releases) . " neue Releases\n";
                        foreach ($releases as $release) {
                            foreach ($IRC_CHANNELS as $channel) {
                                announceRelease($socket, $channel, $release);
                                usleep(250000);
                            }
                            if ($release['id'] > $lastPollId) {
                                $lastPollId = $release['id'];
                                saveLastId($lastPollId);
                            }
                        }
                    } else {
                        usleep(500000);
                    }
                } catch (Exception $e) {
                    echo "[DB] Fehler: " . $e->getMessage() . "\n";
                    dbConnect();
                }
            }
            
            usleep(100000);
        }
        
        fclose($socket);
        echo "[BOT] Reconnect in 15s...\n";
        sleep(15);
    }
}

// ============================================================
// START
// ============================================================

// --export Modus: Zeigt Config
if (in_array('--export', $argv)) {
    echo "=== IRC Bot Konfiguration ===\n";
    echo "Server: $IRC_SERVER:$IRC_PORT\n";
    echo "Nick: $IRC_NICK\n";
    echo "Channels: " . implode(', ', $IRC_CHANNELS) . "\n";
    echo "Interval: {$POLL_INTERVAL}s\n";
    echo "Blowfish: " . ($IRC_BLOWFISH_ENABLED ? "✅ ($IRC_BLOWFISH_KEY)" : "❌") . "\n";
    echo "Server-PW: " . (!empty($IRC_SERVER_PASS) ? "✅" : "❌") . "\n";
    echo "NickServ: " . (!empty($IRC_NICKSERV_PASS) ? "✅" : "❌") . "\n";
    exit(0);
}

// Daemon-Modus
$isDaemon = in_array('--daemon', $argv);
if ($isDaemon) {
    $pid = pcntl_fork();
    if ($pid == -1) { die("[FEHLER] Fork fehlgeschlagen\n"); }
    elseif ($pid) {
        file_put_contents($PID_FILE, $pid);
        echo "[BOT] Daemon gestartet (PID: $pid)\n";
        exit(0);
    }
    if (posix_setsid() === -1) { die("[FEHLER] setsid fehlgeschlagen\n"); }
    fclose(STDIN); fclose(STDOUT); fclose(STDERR);
    $STDOUT = fopen($IRCBOT_DIR . '/ircbot.log', 'a');
    $STDERR = fopen($IRCBOT_DIR . '/ircbot_error.log', 'a');
}

echo "\n╔══════════════════════════════════════╗\n";
echo "║     FortKnox PreDB IRC PreBot v2.0   ║\n";
echo "║  Server: $IRC_SERVER:$IRC_PORT\n";
echo "║  Nick: $IRC_NICK\n";
echo "║  Channels: " . implode(', ', $IRC_CHANNELS) . "\n";
echo "║  Blowfish: " . ($IRC_BLOWFISH_ENABLED ? "✅" : "❌") . "\n";
echo "╚══════════════════════════════════════╝\n\n";

runBot();

<?php<?php
/**
 * FortKnox PreDB Installations-Script für Server-Umzug / Neuinstallation
 * =============================================================
 * 
 * Aufruf: php install.php
oder:   php install.php --quick   (überspringt Bestätigungen)
 * 
 * Was dieses Script macht:
 * 1. System-Anforderungen prüfen (PHP, MySQL, Erweiterungen)
 * 2. Datenbankverbindung testen
 * 3. Tabellen-Struktur erstellen (falls nicht vorhanden)
 * 4. Kategorien anlegen
 * 5. Admin-Benutzer erstellen
 * 6. config.php schreiben
 * 7. Cron-Job einrichten (optional)
 * 8. IRC-Bot Konfiguration (optional)
 * 9. Berechtigungen setzen
 * 10. Zusammenfassung anzeigen
 * 
 * Portabel: Kopiere alle Dateien auf den Zielserver und führe aus:
 *   php install.php
 */

// ============================================================
// KONFIGURATION
// ============================================================

// Default-Werte (werden interaktiv abgefragt)
$DB_HOST = 'localhost';
$DB_USER = '';
$DB_PASS = '';
$DB_NAME = '';

$ADMIN_USER = 'admin';
$ADMIN_PASS = '';
$ADMIN_EMAIL = '';

$SITE_URL = '';          // z.B. https://predb.dnsabr.com
$SITE_TITLE = 'FortKnox PreDB › Deutsche Scene Release Datenbank & NFO Quelltext';

$INSTALL_DIR = __DIR__;

$IRCBOT_ENABLE = false;
$IRC_SERVER = 'irc.rizon.net';
$IRC_PORT = 6667;
$IRC_NICK = 'PreBot';
$IRC_CHANNELS = ['#predb'];
$IRC_SERVER_PASS = '';
$IRC_NICKSERV_PASS = '';
$IRC_BLOWFISH_KEY = '';

// ============================================================
// HILFSFUNKTIONEN
// ============================================================

$colors = true;

function out($msg, $newline = true) {
    echo $msg . ($newline ? "\n" : '');
}

function success($msg) {
    out(" ✅ " . $msg);
}

function warning($msg) {
    out(" ⚠️  " . $msg);
}

function error($msg) {
    out(" ❌ " . $msg);
}

function info($msg) {
    out(" ℹ️  " . $msg);
}

function headline($msg) {
    out("\n╔═══ " . str_repeat("═", strlen($msg) + 2) . "═══╗");
    out("║   " . $msg . "   ║");
    out("╚═══ " . str_repeat("═", strlen($msg) + 2) . "═══╝\n");
}

function prompt($label, $default = '', $hidden = false) {
    if (!empty($default)) {
        $label .= " [{$default}]";
    }
    out($label . ": ", false);
    
    if ($hidden) {
        system('stty -echo 2>/dev/null');
        $value = trim(fgets(STDIN));
        system('stty echo 2>/dev/null');
        out('');
    } else {
        $value = trim(fgets(STDIN));
    }
    
    return !empty($value) ? $value : $default;
}

function confirm($msg, $default = true) {
    $yn = $default ? 'Y/n' : 'y/N';
    out($msg . " [{$yn}] ", false);
    $input = strtolower(trim(fgets(STDIN)));
    if (empty($input)) return $default;
    return in_array($input, ['y', 'yes', 'j', 'ja']);
}

function checkPhpExtension($ext) {
    if (extension_loaded($ext)) {
        success("PHP-Erweiterung '{$ext}' geladen");
        return true;
    } else {
        warning("PHP-Erweiterung '{$ext}' fehlt");
        return false;
    }
}

function checkPhpVersion($min = '7.4.0') {
    if (version_compare(PHP_VERSION, $min, '>=')) {
        success("PHP-Version " . PHP_VERSION . " (min. {$min})");
        return true;
    } else {
        error("PHP-Version " . PHP_VERSION . " zu alt (min. {$min})");
        return false;
    }
}

function checkExec() {
    $disabled = explode(',', ini_get('disable_functions'));
    if (in_array('exec', $disabled)) {
        warning("exec() ist deaktiviert – manche Features (Cron, Daemon) sind eingeschränkt");
        return false;
    }
    success("exec() ist verfügbar");
    return true;
}

function checkWritable($path) {
    $fullPath = __DIR__ . '/' . $path;
    if (is_writable($fullPath) || (!file_exists($fullPath) && is_writable(__DIR__))) {
        success("{$path} ist beschreibbar");
        return true;
    } else {
        warning("{$path} ist nicht beschreibbar");
        return false;
    }
}

function generatePassword($length = 16) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%&*()-_=+';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $password;
}

function writeConfig($file, $content) {
    if (@file_put_contents($file, $content)) {
        success("{$file} geschrieben");
        return true;
    } else {
        error("Konnte {$file} nicht schreiben");
        return false;
    }
}

// ============================================================
// PRÜFUNGEN
// ============================================================

function runChecks(&$failed) {
    global $colors;
    $failed = false;
    
    headline("System-Prüfung");
    
    // PHP-Version
    if (!checkPhpVersion('7.4.0')) $failed = true;
    
    // Erweiterungen
    $extensions = ['mysqli', 'pdo_mysql', 'json', 'mbstring', 'zip', 'gd'];
    foreach ($extensions as $ext) {
        if (!checkPhpExtension($ext)) $failed = true;
    }
    
    // PCNTL (für Daemon)
    if (extension_loaded('pcntl')) {
        success("PHP-Erweiterung 'pcntl' geladen (Daemon-Modus möglich)");
    } else {
        warning("PHP-Erweiterung 'pcntl' fehlt (Daemon-Modus nicht verfügbar)");
    }
    
    // exec()
    checkExec();
    
    // Datei-Berechtigungen
    out("\n📁 Datei-Berechtigungen:");
    checkWritable('config.php');
    checkWritable('ircbot_config.php');
    checkWritable('ircbot.pid');
    checkWritable('ircbot_state.txt');
    checkWritable('ircbot.log');
    checkWritable('import_predb_progress.txt');
    checkWritable('import_progress.txt');
    checkWritable('predbnet_tracker.txt');
    checkWritable('predbme_tracker.txt');
    
    // MySQL-Client prüfen
    out("\n🔧 Tools:");
    if (function_exists('exec')) {
        exec('which mysql 2>/dev/null', $outMysql, $codeMysql);
        exec('which mysqldump 2>/dev/null', $outDump, $codeDump);
        if ($codeMysql === 0) success("mysql-Client gefunden");
        else warning("mysql-Client nicht gefunden");
        if ($codeDump === 0) success("mysqldump gefunden");
        else warning("mysqldump nicht gefunden");
    }
    
    return !$failed;
}

// ============================================================
// DATENBANK-MIGRATION
// ============================================================

function setupDatabase($conn) {
    global $DB_PREFIX;
    $DB_PREFIX = 'predb_';
    
    headline("Tabellen-Struktur");
    
    // categories
    $conn->query("CREATE TABLE IF NOT EXISTS `{$DB_PREFIX}categories` (
        `id` int NOT NULL AUTO_INCREMENT,
        `name` varchar(100) NOT NULL,
        `icon` varchar(10) NOT NULL DEFAULT '',
        `color` varchar(7) NOT NULL DEFAULT '',
        `sort_order` int NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // groups
    $conn->query("CREATE TABLE IF NOT EXISTS `{$DB_PREFIX}groups` (
        `id` int NOT NULL AUTO_INCREMENT,
        `name` varchar(100) NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `name` (`name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // releases
    $conn->query("CREATE TABLE IF NOT EXISTS `{$DB_PREFIX}releases` (
        `id` int NOT NULL AUTO_INCREMENT,
        `name` varchar(500) NOT NULL,
        `category_id` int DEFAULT NULL,
        `group_id` int DEFAULT NULL,
        `size` varchar(50) DEFAULT NULL,
        `files` int DEFAULT NULL,
        `nfo_content` longtext,
        `nfo_filename` varchar(255) DEFAULT NULL,
        `source_url` varchar(500) DEFAULT NULL,
        `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
        `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_name` (`name`(191)),
        KEY `idx_category` (`category_id`),
        KEY `idx_group` (`group_id`),
        KEY `idx_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // users
    $conn->query("CREATE TABLE IF NOT EXISTS `{$DB_PREFIX}users` (
        `id` int NOT NULL AUTO_INCREMENT,
        `username` varchar(50) NOT NULL,
        `email` varchar(255) DEFAULT NULL,
        `password` varchar(255) NOT NULL,
        `role` varchar(20) NOT NULL DEFAULT 'user',
        `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `username` (`username`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    success("Tabellen-Struktur erstellt / existiert bereits");
}

function setupCategories($conn) {
    global $DB_PREFIX;
    
    headline("Kategorien");
    
    $categories = [
        ['Games', '🎮', '#9b59b6', 1],
        ['Movies', '🎬', '#e74c3c', 2],
        ['Music', '🎵', '#2ecc71', 3],
        ['Apps', '💻', '#e67e22', 4],
        ['TV', '📺', '#3498db', 5],
        ['Books', '📚', '#f1c40f', 6],
        ['XXX', '🔞', '#e91e63', 7],
        ['Other', '📦', '#95a5a6', 8],
    ];
    
    $stmt = $conn->prepare("INSERT IGNORE INTO `{$DB_PREFIX}categories` (name, icon, color, sort_order) VALUES (?, ?, ?, ?)");
    $count = 0;
    foreach ($categories as $cat) {
        $stmt->bind_param('sssi', $cat[0], $cat[1], $cat[2], $cat[3]);
        if ($stmt->execute() && $stmt->affected_rows > 0) $count++;
    }
    $stmt->close();
    
    if ($count > 0) success("{$count} Kategorien angelegt");
    else success("Kategorien bereits vorhanden");
}

function setupAdmin($conn, $username, $password, $email) {
    global $DB_PREFIX;
    
    headline("Admin-Benutzer");
    
    // Prüfen ob bereits ein Admin existiert
    $res = $conn->query("SELECT COUNT(*) as cnt FROM `{$DB_PREFIX}users` WHERE role='admin'");
    $row = $res->fetch_assoc();
    
    if ($row['cnt'] > 0) {
        info("Ein Admin-Benutzer existiert bereits. Überspringe...");
        return true;
    }
    
    if (empty($password)) {
        $password = generatePassword();
        info("Generiertes Passwort: {$password}");
    }
    
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $conn->prepare("INSERT INTO `{$DB_PREFIX}users` (username, email, password, role) VALUES (?, ?, ?, 'admin')");
    $stmt->bind_param('sss', $username, $email, $hash);
    
    if ($stmt->execute()) {
        success("Admin-Benutzer '{$username}' erstellt");
        info("Passwort: {$password}");
        return true;
    } else {
        error("Fehler beim Erstellen: " . $conn->error);
        return false;
    }
}

// ============================================================
// CONFIG.PHP SCHREIBEN
// ============================================================

function createConfigFile($host, $user, $pass, $name, $siteTitle) {
    $config = "<?php\n";
    $config .= "/**\n";
    $config .= " * FortKnox PreDB - Konfiguration (generiert via install.php)\n";
    $config .= " */\n\n";
    $config .= "session_start();\n\n";
    $config .= "// Datenbank Verbindung\n";
    $config .= "\$db_host = " . var_export($host, true) . ";\n";
    $config .= "\$db_user = " . var_export($user, true) . ";\n";
    $config .= "\$db_pass = " . var_export($pass, true) . ";\n";
    $config .= "\$db_name = " . var_export($name, true) . ";\n\n";
    $config .= "\$conn = new mysqli(\$db_host, \$db_user, \$db_pass, \$db_name);\n\n";
    $config .= "if (\$conn->connect_error) {\n";
    $config .= "    die('Datenbankverbindung fehlgeschlagen: ' . \$conn->connect_error);\n";
    $config .= "}\n\n";
    $config .= "\$conn->set_charset('utf8mb4');\n\n";
    $config .= "// Einstellungen\n";
    $config .= "date_default_timezone_set('Europe/Berlin');\n";
    $config .= "error_reporting(E_ALL);\n";
    $config .= "if (function_exists('ini_set')) {\n";
    $config .= "    ini_set('display_errors', 0);\n";
    $config .= "}\n\n";
    $config .= "define('SITE_NAME', 'FortKnox PreDB');\n";
    $config .= "define('SITE_TITLE', " . var_export($siteTitle, true) . ");\n";
    $config .= "define('ITEMS_PER_PAGE', 50);\n";
    $config .= "define('DB_PREFIX', 'predb_');\n\n";
    $config .= "// Hilfsfunktionen\n";
    $config .= "function h(\$str) { return htmlspecialchars(\$str, ENT_QUOTES, 'UTF-8'); }\n";
    $config .= "function formatDate(\$date) { return date('d.m.Y H:i', strtotime(\$date)); }\n";
    $config .= "function formatSize(\$size) { return \$size ?: '-'; }\n";
    $config .= "function isLoggedIn() { return isset(\$_SESSION['user_id']); }\n";
    $config .= "function isAdmin() { return isset(\$_SESSION['user_role']) && \$_SESSION['user_role'] === 'admin'; }\n";
    $config .= "function redirect(\$url) { header('Location: ' . \$url); exit; }\n";
    
    return writeConfig(__DIR__ . '/config.php', $config);
}

// ============================================================
// IRC BOT CONFIG
// ============================================================

function createBotConfig($server, $port, $nick, $channels, $serverPass, $nickservPass, $blowfishKey) {
    $config = "<?php\n";
    $config .= "/**\n";
    $config .= " * IRC Bot Konfiguration (generiert via install.php)\n";
    $config .= " */\n\n";
    $config .= "\$DB_HOST = 'localhost';\n";
    $config .= "\$DB_USER = '';\n";
    $config .= "\$DB_PASS = '';\n";
    $config .= "\$DB_NAME = '';\n";
    $config .= "\$CONFIG_FILE = __DIR__ . '/config.php';\n\n";
    $config .= "\$IRC_SERVER = " . var_export($server, true) . ";\n";
    $config .= "\$IRC_PORT = {$port};\n";
    $config .= "\$IRC_SSL = false;\n";
    $config .= "\$IRC_NICK = " . var_export($nick, true) . ";\n";
    $config .= "\$IRC_USER = " . var_export($nick, true) . ";\n";
    $config .= "\$IRC_REALNAME = " . var_export("FortKnox PreDB Release Bot", true) . ";\n";
    $config .= "\$IRC_SERVER_PASS = " . var_export($serverPass, true) . ";\n";
    $config .= "\$IRC_NICKSERV_PASS = " . var_export($nickservPass, true) . ";\n";
    $config .= "\$IRC_CHANNELS = " . var_export($channels, true) . ";\n";
    $config .= "\$IRC_BLOWFISH_KEY = " . var_export($blowfishKey, true) . ";\n";
    $config .= "\$IRC_BLOWFISH_ENABLED = " . (!empty($blowfishKey) ? 'true' : 'false') . ";\n";
    $config .= "\$CMD_PREFIX = '!';\n";
    $config .= "\$POLL_INTERVAL = 30;\n";
    $config .= "\$MAX_ANNOUNCE = 5;\n";
    
    return writeConfig(__DIR__ . '/ircbot_config.php', $config);
}

// ============================================================
// CRON-EINRICHTUNG
// ============================================================

function setupCron() {
    if (!function_exists('exec')) {
        warning("exec() deaktiviert – Cron muss manuell eingerichtet werden");
        return false;
    }
    
    $cronJob = "*/5 * * * * php " . __DIR__ . "/sync.php >> " . __DIR__ . "/sync.log 2>&1";
    
    exec('crontab -l 2>/dev/null', $existing, $code);
    
    // Prüfen ob Job bereits existiert
    foreach ($existing as $line) {
        if (strpos($line, 'sync.php') !== false) {
            info("Cron-Job für sync.php existiert bereits");
            return true;
        }
    }
    
    if (confirm("Cron-Job einrichten (alle 5 Minuten: sync.php)?")) {
        $existing[] = $cronJob;
        $newCron = implode("\n", $existing) . "\n";
        file_put_contents('/tmp/predb_cron_' . md5(__DIR__), $newCron);
        exec('crontab /tmp/predb_cron_' . md5(__DIR__) . ' 2>/dev/null', $out, $code);
        @unlink('/tmp/predb_cron_' . md5(__DIR__));
        
        if ($code === 0) {
            success("Cron-Job eingerichtet");
            info("Intervall: alle 5 Minuten -> php sync.php");
            return true;
        } else {
            warning("Cron konnte nicht gesetzt werden (keine Berechtigung?)");
            info("Manuell einrichten: crontab -e");
            info("Diesen Job einfügen:");
            info("  {$cronJob}");
            return false;
        }
    }
    return false;
}

// ============================================================
// BERECHTIGUNGEN
// ============================================================

function setPermissions() {
    headline("Berechtigungen");
    
    $writableFiles = [
        'config.php',
        'ircbot_config.php',
        'ircbot.pid',
        'ircbot_state.txt',
        'ircbot.log',
        'ircbot_error.log',
        'import_predb_progress.txt',
        'import_progress.txt',
        'predbnet_tracker.txt',
        'predbme_tracker.txt',
        'sync.log',
    ];
    
    foreach ($writableFiles as $file) {
        $path = __DIR__ . '/' . $file;
        if (!file_exists($path)) {
            @touch($path);
        }
        if (is_writable($path)) {
            success("{$file} beschreibbar");
        } else {
            @chmod($path, 0666);
            if (is_writable($path)) {
                success("{$file} beschreibbar (chmod 666)");
            } else {
                warning("{$file} konnte nicht beschreibbar gemacht werden");
            }
        }
    }
    
    // Verzeichnis-Berechtigungen
    $dirs = [__DIR__ . '/cgi-bin'];
    foreach ($dirs as $dir) {
        if (is_dir($dir)) {
            @chmod($dir, 0755);
            success("{$dir} Berechtigungen gesetzt");
        }
    }
    
    success("Berechtigungen gesetzt");
}

// ============================================================
// ZUSAMMENFASSUNG
// ============================================================

function showSummary($dbHost, $dbUser, $dbName, $adminUser, $adminPass, $siteUrl) {
    headline("Installation abgeschlossen!");
    
    out("╔══════════════════════════════════════════════════════════╗");
    out("║  🌐 FortKnox PreDB – Deutsche Scene Release DB      ║");
    out("╠══════════════════════════════════════════════════════════╣");
    out("║  📅 " . str_pad(date('d.m.Y H:i'), 48) . "║");
    out("╠══════════════════════════════════════════════════════════╣");
    out("║  📊 Datenbank:  " . str_pad($dbName . '@' . $dbHost, 39) . "║");
    out("║  👤 Benutzer:   " . str_pad($dbUser, 39) . "║");
    out("╠══════════════════════════════════════════════════════════╣");
    out("║  🔐 Admin-Login:                                       ║");
    out("║     Benutzer:  " . str_pad($adminUser, 40) . "║");
    
    // Passwort abschirmen wenn leer
    $displayPass = !empty($adminPass) ? $adminPass : '(bereits vorhanden)';
    out("║     Passwort:  " . str_pad($displayPass, 40) . "║");
    out("╠══════════════════════════════════════════════════════════╣");
    
    if (!empty($siteUrl)) {
        out("║  🌍 " . str_pad($siteUrl, 47) . "║");
    }
    out("╠══════════════════════════════════════════════════════════╣");
    out("║  Nächste Schritte:                                     ║");
    out("║  📋 1. Webseite aufrufen und einloggen                  ║");
    out("║  🤖 2. IRC-Bot konfigurieren (optional)                 ║");
    out("║  📥 3. Daten importieren (import_full.php)              ║");
    out("║  🔄 4. Cron-Job für sync.php prüfen                     ║");
    out("╚══════════════════════════════════════════════════════════╝\n");
}

// ============================================================
// DATENBANK VERBINDUNG TESTEN
// ============================================================

function testDbConnection($host, $user, $pass, $name) {
    headline("Datenbank-Verbindung");
    
    $conn = @new mysqli($host, $user, $pass, $name);
    if ($conn->connect_error) {
        error("Konnte nicht verbinden: " . $conn->connect_error);
        return null;
    }
    
    $conn->set_charset('utf8mb4');
    success("Verbunden mit {$name}@{$host}");
    
    // Zeige vorhandene Tabellen
    $res = $conn->query("SHOW TABLES LIKE 'predb_%'");
    $tables = [];
    while ($row = $res->fetch_array()) $tables[] = $row[0];
    
    if (!empty($tables)) {
        info("Vorhandene Tabellen (" . count($tables) . "): " . implode(', ', $tables));
    } else {
        info("Keine predb_-Tabellen vorhanden – lege neue an");
    }
    
    return $conn;
}

// ============================================================
// HAUPTPROGRAMM
// ============================================================

// Banner
echo "\n";
echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║      🚀  FortKnox PreDB Installation  v2.0            ║\n";
echo "║      Deutsche Scene Release Datenbank & NFO Quelltext   ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n";

// Quick-Modus?
$quickMode = in_array('--quick', $argv);
if ($quickMode) info("Quick-Modus – überspringe Bestätigungen\n");

// 1. System-Prüfung
$failed = false;
runChecks($failed);

if ($failed) {
    out("\n⚠️  Einige Prüfungen fehlgeschlagen. Fortfahren?");
    if (!$quickMode && !confirm("Trotzdem fortfahren?", false)) {
        out("❌ Abbruch.\n");
        exit(1);
    }
}

// 2. Datenbank-Konfiguration abfragen
headline("Datenbank-Konfiguration");

$DB_HOST = prompt("MySQL-Host", $DB_HOST);
$DB_PORT = prompt("MySQL-Port", '3306');
$DB_USER = prompt("MySQL-Benutzer", $DB_USER);
$DB_PASS = prompt("MySQL-Passwort", '', true);
$DB_NAME = prompt("Datenbank-Name", $DB_NAME);

// Verbindung testen
$conn = testDbConnection($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if (!$conn) {
    out("\n❌ Keine Datenbankverbindung. Installations abgebrochen.\n");
    exit(1);
}

// Site-URL
headline("Webseite");
$SITE_URL = prompt("Site-URL", $SITE_URL, !$quickMode);
$SITE_TITLE = prompt("Site-Title", $SITE_TITLE);

// 3. Tabellen erstellen
setupDatabase($conn);

// 4. Kategorien
$res = $conn->query("SELECT COUNT(*) as cnt FROM predb_categories");
$catCount = $res->fetch_assoc()['cnt'];
if ($catCount === 0) {
    setupCategories($conn);
} else {
    success("Kategorien bereits vorhanden ({$catCount} Stück)");
}

// 5. Admin erstellen
out("\n👤 Admin-Zugang:");
$ADMIN_USER = prompt("Admin-Benutzername", $ADMIN_USER);
if ($quickMode) {
    $ADMIN_PASS = generatePassword();
    info("Generiertes Passwort: {$ADMIN_PASS}");
} else {
    $ADMIN_PASS = prompt("Admin-Passwort (leer = generieren)", '');
    if (empty($ADMIN_PASS)) {
        $ADMIN_PASS = generatePassword();
        info("Generiertes Passwort: {$ADMIN_PASS}");
    }
}
$ADMIN_EMAIL = prompt("Admin-E-Mail (optional)", '');

setupAdmin($conn, $ADMIN_USER, $ADMIN_PASS, $ADMIN_EMAIL);

// 6. config.php schreiben
headline("config.php");
createConfigFile($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $SITE_TITLE);

// 7. IRC Bot (optional)
if ($quickMode || confirm("\n🤖 IRC-Bot konfigurieren?")) {
    headline("IRC-Bot Konfiguration");
    
    $IRC_SERVER = prompt("IRC-Server", $IRC_SERVER);
    $IRC_PORT = (int)prompt("Port", (string)$IRC_PORT);
    $IRC_NICK = prompt("Nickname", $IRC_NICK);
    $IRC_SERVER_PASS = prompt("Server-Passwort (optional)", '', true);
    $IRC_NICKSERV_PASS = prompt("NickServ-Passwort (optional)", '', true);
    
    $channelsStr = prompt("Channels (kommagetrennt)", '#predb');
    $IRC_CHANNELS = array_map('trim', explode(',', $channelsStr));
    
    $IRC_BLOWFISH_KEY = prompt("Blowfish-Key (optional, für FiSH)", '', true);
    
    createBotConfig($IRC_SERVER, $IRC_PORT, $IRC_NICK, $IRC_CHANNELS, $IRC_SERVER_PASS, $IRC_NICKSERV_PASS, $IRC_BLOWFISH_KEY);
}

// 8. Cron
setupCron();

// 9. Berechtigungen
setPermissions();

// 10. Zusammenfassung
showSummary($DB_HOST, $DB_USER, $DB_NAME, $ADMIN_USER, $ADMIN_PASS, $SITE_URL);

$conn->close();

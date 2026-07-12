<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$action = isset($_GET['action']) ? $_GET['action'] : 'list';
$message = '';
$error = '';

// Kategorien und Gruppen für Formulare
$categories = $conn->query("SELECT * FROM " . DB_PREFIX . "categories ORDER BY sort_order")->fetch_all(MYSQLI_ASSOC);
$groups = $conn->query("SELECT * FROM " . DB_PREFIX . "groups ORDER BY name")->fetch_all(MYSQLI_ASSOC);

// -----------------------------------------------------------------------
// Benutzerverwaltung (nur für Admins)
// -----------------------------------------------------------------------
if (in_array($action, ['users', 'user_add', 'user_edit', 'user_delete', 'user_save']) && !isAdmin()) {
    $error = 'Zugriff verweigert.';
    $action = 'list';
}

// Benutzer löschen
if ($action === 'user_delete' && isset($_GET['id'])) {
    $uid = intval($_GET['id']);
    if ($uid == $_SESSION['user_id']) {
        $error = 'Du kannst dich nicht selbst löschen.';
    } else {
        $stmt = $conn->prepare("DELETE FROM " . DB_PREFIX . "users WHERE id = ?");
        $stmt->bind_param('i', $uid);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $message = 'Benutzer wurde gelöscht.';
        } else {
            $error = 'Fehler beim Löschen.';
        }
    }
    $action = 'users';
}

// Benutzer speichern (neu / bearbeiten)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'user_save') {
    $uid = intval($_POST['id'] ?? 0);
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = trim($_POST['role'] ?? 'user');
    $password = $_POST['password'] ?? '';

    if (empty($username)) {
        $error = 'Benutzername darf nicht leer sein.';
    } elseif ($uid > 0) {
        if (!empty($password)) {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $conn->prepare("UPDATE " . DB_PREFIX . "users SET username=?, email=?, role=?, password=? WHERE id=?");
            $stmt->bind_param('ssssi', $username, $email, $role, $hash, $uid);
        } else {
            $stmt = $conn->prepare("UPDATE " . DB_PREFIX . "users SET username=?, email=?, role=? WHERE id=?");
            $stmt->bind_param('sssi', $username, $email, $role, $uid);
        }
        if ($stmt->execute()) {
            $message = 'Benutzer wurde aktualisiert.';
        } else {
            $error = 'Fehler: ' . $conn->error;
        }
    } else {
        if (empty($password)) {
            $error = 'Passwort darf nicht leer sein.';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $conn->prepare("INSERT INTO " . DB_PREFIX . "users (username, email, role, password) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('ssss', $username, $email, $role, $hash);
            if ($stmt->execute()) {
                $message = 'Benutzer wurde erstellt.';
                $action = 'users';
            } else {
                $error = 'Fehler: ' . $conn->error;
            }
        }
    }
    if ($action !== 'users') $action = 'user_edit';
}

// -----------------------------------------------------------------------
// IRC Bot Verwaltung (nur für Admins)
// -----------------------------------------------------------------------
if (in_array($action, ['ircbot', 'ircbot_start', 'ircbot_stop', 'ircbot_restart', 'ircbot_config_save', 'ircbot_log', 'backup', 'backup_download']) && !isAdmin()) {
    $error = 'Zugriff verweigert.';
    $action = 'list';
}

$IRCBOT_DIR = __DIR__;
$IRCBOT_PID_FILE = $IRCBOT_DIR . '/ircbot.pid';
$IRCBOT_CONFIG_FILE = $IRCBOT_DIR . '/ircbot_config.php';
$IRCBOT_STATE_FILE = $IRCBOT_DIR . '/ircbot_state.txt';
$IRCBOT_LOG_FILE = $IRCBOT_DIR . '/ircbot.log';

// IRC Bot Status prüfen
function getIrcbotStatus() {
    global $IRCBOT_PID_FILE;
    if (!file_exists($IRCBOT_PID_FILE)) return 'stopped';
    $pid = trim(file_get_contents($IRCBOT_PID_FILE));
    if (!is_numeric($pid)) return 'stopped';
    // Prüfen ob Prozess läuft
    if (file_exists("/proc/$pid")) {
        return 'running';
    }
    // Fallback: ps Befehl
    $output = [];
    exec("ps $pid 2>/dev/null", $output);
    if (count($output) >= 2) return 'running';
    return 'stopped';
}

// IRC Bot Aktionen
if ($action === 'ircbot_start') {
    exec("php $IRCBOT_DIR/ircbot.php --daemon > /dev/null 2>&1 &");
    sleep(1);
    $status = getIrcbotStatus();
    if ($status === 'running') {
        $message = '🤖 IRC Bot wurde gestartet.';
    } else {
        $error = '❌ IRC Bot konnte nicht gestartet werden.';
    }
    $action = 'ircbot';
}

if ($action === 'ircbot_stop') {
    if (file_exists($IRCBOT_PID_FILE)) {
        $pid = trim(file_get_contents($IRCBOT_PID_FILE));
        if (is_numeric($pid)) {
            exec("kill $pid 2>/dev/null");
            sleep(1);
            @unlink($IRCBOT_PID_FILE);
            $message = '🛑 IRC Bot wurde gestoppt.';
        }
    } else {
        $error = '❌ IRC Bot läuft nicht.';
    }
    $action = 'ircbot';
}

if ($action === 'ircbot_restart') {
    // Stop
    if (file_exists($IRCBOT_PID_FILE)) {
        $pid = trim(file_get_contents($IRCBOT_PID_FILE));
        if (is_numeric($pid)) exec("kill $pid 2>/dev/null");
        sleep(1);
        @unlink($IRCBOT_PID_FILE);
    }
    // Start
    exec("php $IRCBOT_DIR/ircbot.php --daemon > /dev/null 2>&1 &");
    sleep(1);
    $status = getIrcbotStatus();
    if ($status === 'running') {
        $message = '🔄 IRC Bot wurde neu gestartet.';
    } else {
        $error = '❌ IRC Bot konnte nicht gestartet werden.';
    }
    $action = 'ircbot';
}

// IRC Bot Config speichern
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'ircbot_config_save') {
    $server = trim($_POST['server'] ?? 'irc.rizon.net');
    $port = intval($_POST['port'] ?? 6667);
    if ($port < 1 || $port > 65535) $port = 6667;
    $nick = trim($_POST['nick'] ?? 'PreBot');
    $serverPass = trim($_POST['server_pass'] ?? '');
    $nickserv = trim($_POST['nickserv_pass'] ?? '');
    $blowfishKey = trim($_POST['blowfish_key'] ?? '');
    $channels = trim($_POST['channels'] ?? '#predb');
    $interval = intval($_POST['interval'] ?? 30);
    if ($interval < 10) $interval = 10;
    if ($interval > 300) $interval = 300;
    
    // Channels als Array
    $chanArray = array_map('trim', explode(',', $channels));
    $chanArray = array_filter($chanArray, function($c) { return strpos($c, '#') === 0; });
    $chanArray = array_values($chanArray);
    if (empty($chanArray)) $chanArray = ['#predb'];
    
    // Config schreiben
    $configContent = "<?php\n";
    $configContent .= "// IRC Bot Konfiguration (generiert via Admin)\n";
    $configContent .= '$IRC_SERVER = ' . var_export($server, true) . ";\n";
    $configContent .= '$IRC_PORT = ' . $port . ";\n";
    $configContent .= '$IRC_NICK = ' . var_export($nick, true) . ";\n";
    $configContent .= '$IRC_USER = ' . var_export($nick, true) . ";\n";
    $configContent .= '$IRC_REALNAME = ' . var_export('FortKnox PreDB Release Bot - predb.dnsabr.com', true) . ";\n";
    $configContent .= '$IRC_SERVER_PASS = ' . var_export($serverPass, true) . ";\n";
    $configContent .= '$IRC_NICKSERV_PASS = ' . var_export($nickserv, true) . ";\n";
    $configContent .= '$IRC_CHANNELS = ' . var_export($chanArray, true) . ";\n";
    $configContent .= '$IRC_BLOWFISH_KEY = ' . var_export($blowfishKey, true) . ";\n";
    $configContent .= '$IRC_BLOWFISH_ENABLED = ' . (!empty($blowfishKey) ? 'true' : 'false') . ";\n";
    $configContent .= '$CMD_PREFIX = ' . var_export('!', true) . ";\n";
    $configContent .= '$POLL_INTERVAL = ' . $interval . ";\n";
    $configContent .= '$MAX_ANNOUNCE = 5;' . "\n";
    
    if (@file_put_contents($IRCBOT_CONFIG_FILE, $configContent)) {
        $message = '✅ IRC Bot Konfiguration gespeichert.';
    } else {
        $error = '❌ Konnte Konfiguration nicht speichern.';
    }
    $action = 'ircbot';
}

// -----------------------------------------------------------------------
// Backup / Export
// -----------------------------------------------------------------------

// DB-Größe ermitteln
function getDatabaseSize() {
    global $conn, $db_name;
    $res = $conn->query("SELECT SUM(data_length + index_length) as size FROM information_schema.tables WHERE table_schema = '" . $conn->real_escape_string($db_name) . "'");
    $row = $res->fetch_assoc();
    return $row['size'] ?? 0;
}

function formatBytes($bytes) {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

// Backup-Download (SQL)
if ($action === 'backup_download') {
    $type = $_GET['type'] ?? 'full';
    
    // Time limit erhöhen für große DBs
    set_time_limit(300);
    
    if ($type === 'structure') {
        // Nur Struktur (Categories, Groups, Users)
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="predb_structure_' . date('Y-m-d') . '.sql"');
        
        echo "-- FortKnox PreDB Struktur-Backup (" . date('d.m.Y H:i') . ")\n";
        echo "-- Nur Categories, Groups, Users\n\n";
        
        foreach (['categories', 'groups', 'users'] as $table) {
            $fullTable = DB_PREFIX . $table;
            $result = $conn->query("SELECT * FROM $fullTable");
            if (!$result) continue;
            
            echo "TRUNCATE TABLE `$fullTable`;\n";
            
            $cols = [];
            while ($col = $result->fetch_field()) $cols[] = $col->name;
            $colList = '`' . implode('`, `', $cols) . '`';
            
            while ($row = $result->fetch_assoc()) {
                $vals = [];
                foreach ($cols as $col) {
                    $v = $row[$col] ?? null;
                    if ($v === null) $vals[] = 'NULL';
                    else $vals[] = "'" . $conn->real_escape_string($v) . "'";
                }
                echo "INSERT INTO `$fullTable` ($colList) VALUES (" . implode(', ', $vals) . ");\n";
            }
            echo "\n";
        }
        exit;
    }
    
    if ($type === 'full' && function_exists('exec')) {
        // Versuche mysqldump (schneller bei großen DBs)
        $dbHost = $db_host;
        $dbUser = $db_user;
        $dbPass = $db_pass;
        $dbName = $db_name;
        
        $filename = 'predb_full_' . date('Y-m-d_H-i') . '.sql.gz';
        $dumpPath = sys_get_temp_dir() . '/' . $filename;
        
        $cmd = sprintf(
            'mysqldump --host=%s --user=%s --password=%s --single-transaction --routines --triggers %s 2>/dev/null | gzip > %s',
            escapeshellarg($dbHost),
            escapeshellarg($dbUser),
            escapeshellarg($dbPass),
            escapeshellarg($dbName),
            escapeshellarg($dumpPath)
        );
        
        exec($cmd, $output, $exitCode);
        
        if ($exitCode === 0 && file_exists($dumpPath) && filesize($dumpPath) > 1000) {
            header('Content-Type: application/gzip');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($dumpPath));
            readfile($dumpPath);
            @unlink($dumpPath);
            exit;
        }
        
        @unlink($dumpPath);
        // Fallback: PHP-Export mit LIMIT
    }
    
    // PHP-Fallback: Releases in Batches exportieren
    header('Content-Type: text/plain; charset=utf-8');
    $filename = 'predb_releases_' . date('Y-m-d') . '.sql';
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    echo "-- FortKnox PreDB Releases Export (" . date('d.m.Y H:i') . ")\n";
    echo "-- Generiert via PHP\n\n";
    
    // Struktur der releases-Tabelle
    $createRes = $conn->query("SHOW CREATE TABLE " . DB_PREFIX . "releases");
    $createRow = $createRes->fetch_assoc();
    echo $createRow['Create Table'] . ";\n\n";
    
    // Daten in Batches (1000 pro Durchlauf)
    $offset = 0;
    $batchSize = 1000;
    $totalExported = 0;
    
    while (true) {
        $res = $conn->query("SELECT * FROM " . DB_PREFIX . "releases ORDER BY id LIMIT $offset, $batchSize");
        if (!$res || $res->num_rows === 0) break;
        
        $cols = [];
        while ($col = $res->fetch_field()) $cols[] = $col->name;
        $colList = '`' . implode('`, `', $cols) . '`';
        
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $vals = [];
            foreach ($cols as $col) {
                $v = $row[$col] ?? null;
                if ($v === null) $vals[] = 'NULL';
                else $vals[] = "'" . $conn->real_escape_string($v) . "'";
            }
            $rows[] = "(" . implode(', ', $vals) . ")";
            $totalExported++;
        }
        
        if (!empty($rows)) {
            echo "INSERT INTO `" . DB_PREFIX . "releases` ($colList) VALUES\n" . implode(",\n", $rows) . ";\n\n";
        }
        
        $offset += $batchSize;
        flush();
        
        if ($res->num_rows < $batchSize) break;
    }
    
    echo "-- Export abgeschlossen: $totalExported Releases\n";
    exit;
}

// -----------------------------------------------------------------------
// Release löschen
// -----------------------------------------------------------------------
if ($action === 'delete' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $stmt = $conn->prepare("DELETE FROM " . DB_PREFIX . "releases WHERE id = ?");
    $stmt->bind_param('i', $id);
    if ($stmt->execute()) {
        $message = 'Release wurde gelöscht.';
    } else {
        $error = 'Fehler beim Löschen.';
    }
    $action = 'list';
}

// -----------------------------------------------------------------------
// Release speichern (Neu/Bearbeiten)
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'save' || $action === 'edit')) {
    $id = intval($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $category_id = intval($_POST['category_id'] ?? 0);
    $group_id = intval($_POST['group_id'] ?? 0);
    $size = trim($_POST['size'] ?? '');
    $files = intval($_POST['files'] ?? 0);
    $nfo_content = $_POST['nfo_content'] ?? '';

    if (empty($name)) {
        $error = 'Der Release-Name darf nicht leer sein.';
    } elseif ($id > 0) {
        $stmt = $conn->prepare("UPDATE " . DB_PREFIX . "releases SET name=?, category_id=?, group_id=?, size=?, files=?, nfo_content=? WHERE id=?");
        $stmt->bind_param('siissii', $name, $category_id, $group_id ?: null, $size, $files ?: null, $nfo_content, $id);
        $group_id = $group_id ?: null;
        $files = $files ?: null;
        if ($stmt->execute()) {
            $message = 'Release wurde aktualisiert.';
            $action = 'list';
        } else {
            $error = 'Fehler beim Aktualisieren: ' . $conn->error;
        }
    } else {
        $stmt = $conn->prepare("INSERT INTO " . DB_PREFIX . "releases (name, category_id, group_id, size, files, nfo_content) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('siissi', $name, $category_id, $group_id ?: null, $size, $files ?: null, $nfo_content);
        $group_id = $group_id ?: null;
        $files = $files ?: null;
        if ($stmt->execute()) {
            $message = 'Release wurde erstellt.';
            $action = 'list';
        } else {
            $error = 'Fehler beim Erstellen: ' . $conn->error;
        }
    }
}

// -----------------------------------------------------------------------
// Daten für Formulare laden
// -----------------------------------------------------------------------

// Release für Bearbeitung laden
$editRelease = null;
if (($action === 'edit' || $action === 'save') && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $stmt = $conn->prepare("SELECT * FROM " . DB_PREFIX . "releases WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $editRelease = $stmt->get_result()->fetch_assoc();
    if (!$editRelease) $action = 'list';
}

// Benutzer für Bearbeitung laden (nur EINMAL)
$editUser = null;
$userId = intval($_GET['id'] ?? $_POST['id'] ?? 0);
if (($action === 'user_edit' || $action === 'user_save') && $userId > 0) {
    $stmt = $conn->prepare("SELECT id, username, email, role, created_at FROM " . DB_PREFIX . "users WHERE id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $editUser = $stmt->get_result()->fetch_assoc();
    if (!$editUser) $action = 'users';
}

include 'header.php';
?>

<div class="content">
    <?php if ($action === 'list'): ?>
        <div class="admin-header">
            <h1>📋 Admin Bereich</h1>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                <a href="admin.php?action=add" class="search-btn">+ Neuer Release</a>
                <a href="admin.php?action=users" class="reset-btn" style="border-color:var(--accent);">👥 Benutzer</a>
                <a href="admin.php?action=ircbot" class="reset-btn" style="border-color:var(--accent);">🤖 IRC Bot</a>
                <a href="admin.php?action=backup" class="reset-btn" style="border-color:var(--accent);">💾 Backup</a>
                <span style="font-size:11px;color:var(--text-muted);padding:4px 8px;background:var(--bg-hover);border-radius:4px;">
                    Rolle: <?=h($_SESSION['user_role'] ?? '?')?>
                </span>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="success-msg" style="margin: 16px 20px;"><?=h($message)?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="error-msg" style="margin: 16px 20px;"><?=h($error)?></div>
        <?php endif; ?>

        <?php
        $result = $conn->query("
            SELECT r.*, c.name as cat_name, c.icon as cat_icon, g.name as group_name
            FROM " . DB_PREFIX . "releases r
            LEFT JOIN " . DB_PREFIX . "categories c ON r.category_id = c.id
            LEFT JOIN " . DB_PREFIX . "groups g ON r.group_id = g.id
            ORDER BY r.created_at DESC
            LIMIT 100
        ");
        $releases = $result->fetch_all(MYSQLI_ASSOC);
        ?>

        <div class="table-wrapper" style="overflow-x: auto;">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Release Name</th>
                        <th>Kategorie</th>
                        <th>Größe</th>
                        <th>Datum</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($releases)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 40px; color: var(--text-secondary);">
                                Keine Releases vorhanden.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($releases as $rel): ?>
                            <tr>
                                <td><?=$rel['id']?></td>
                                <td><a href="release.php?id=<?=$rel['id']?>"><?=h($rel['name'])?></a></td>
                                <td><?=h($rel['cat_icon'] ?? '')?> <?=h($rel['cat_name'] ?? '-')?></td>
                                <td><?=formatSize($rel['size'])?></td>
                                <td><?=formatDate($rel['created_at'])?></td>
                                <td>
                                    <div class="admin-actions">
                                        <a href="admin.php?action=edit&id=<?=$rel['id']?>" class="btn-sm">✏️</a>
                                        <a href="admin.php?action=delete&id=<?=$rel['id']?>" class="btn-sm danger"
                                           onclick="return confirm('Release wirklich löschen?')">🗑️</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    <?php elseif ($action === 'users'): ?>
        <div class="admin-header">
            <h1>👥 Benutzerverwaltung</h1>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <a href="admin.php?action=user_add" class="search-btn">+ Neuer Benutzer</a>
                <a href="admin.php" class="reset-btn">← Zurück</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="success-msg" style="margin: 16px 20px;"><?=h($message)?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="error-msg" style="margin: 16px 20px;"><?=h($error)?></div>
        <?php endif; ?>

        <?php
        $users = $conn->query("SELECT id, username, email, role, created_at FROM " . DB_PREFIX . "users ORDER BY id");
        ?>

        <div class="table-wrapper" style="overflow-x: auto;">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Benutzername</th>
                        <th>E-Mail</th>
                        <th>Rolle</th>
                        <th>Erstellt</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($u = $users->fetch_assoc()): ?>
                        <tr>
                            <td><?=$u['id']?></td>
                            <td><?=h($u['username'])?></td>
                            <td><?=h($u['email'] ?? '-')?></td>
                            <td>
                                <?php if ($u['role'] === 'admin'): ?>
                                    <span class="category-badge" style="background:rgba(0,212,170,0.2);color:var(--accent);">Admin</span>
                                <?php elseif ($u['role'] === 'mod'): ?>
                                    <span class="category-badge" style="background:rgba(255,170,0,0.2);color:var(--warning);">Mod</span>
                                <?php else: ?>
                                    <span class="category-badge">User</span>
                                <?php endif; ?>
                            </td>
                            <td><?=formatDate($u['created_at'])?></td>
                            <td>
                                <div class="admin-actions">
                                    <a href="admin.php?action=user_edit&id=<?=$u['id']?>" class="btn-sm">✏️</a>
                                    <?php if ($u['id'] != $_SESSION['user_id']): ?>
                                        <a href="admin.php?action=user_delete&id=<?=$u['id']?>" class="btn-sm danger"
                                           onclick="return confirm('Benutzer wirklich löschen?')">🗑️</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <div style="padding:12px 20px;font-size:12px;color:var(--text-muted);border-top:1px solid var(--border);">
            ℹ️ Du kannst dich nicht selbst löschen. Dein Passwort änderst du über das Bearbeiten-Icon ✏️
        </div>

    <?php elseif ($action === 'user_add' || ($action === 'user_edit' && $editUser)): ?>
        <div class="admin-header">
            <h1><?=$editUser ? '✏️ Benutzer bearbeiten: ' . h($editUser['username']) : '➕ Neuen Benutzer erstellen'?></h1>
            <a href="admin.php?action=users" class="reset-btn">← Zurück</a>
        </div>

        <?php if ($error): ?>
            <div class="error-msg" style="margin: 16px 20px;"><?=h($error)?></div>
        <?php endif; ?>
        <?php if ($message): ?>
            <div class="success-msg" style="margin: 16px 20px;"><?=h($message)?></div>
        <?php endif; ?>

        <form method="POST" action="admin.php?action=user_save" class="admin-form">
            <?php if ($editUser): ?>
                <input type="hidden" name="id" value="<?=$editUser['id']?>">
            <?php endif; ?>

            <div class="form-group">
                <label for="username">Benutzername *</label>
                <input type="text" id="username" name="username" required
                       value="<?=h($editUser['username'] ?? '')?>"
                       placeholder="z.B. neuer_user">
            </div>

            <div class="form-group">
                <label for="email">E-Mail</label>
                <input type="email" id="email" name="email"
                       value="<?=h($editUser['email'] ?? '')?>"
                       placeholder="user@example.com">
            </div>

            <div class="form-group">
                <label for="role">Rolle</label>
                <select id="role" name="role" class="category-select" style="width:100%;">
                    <option value="user" <?=($editUser && $editUser['role'] === 'user') ? 'selected' : ''?>>User</option>
                    <option value="mod" <?=($editUser && $editUser['role'] === 'mod') ? 'selected' : ''?>>Moderator</option>
                    <option value="admin" <?=($editUser && $editUser['role'] === 'admin') ? 'selected' : ''?>>Admin</option>
                </select>
            </div>

            <div class="form-group">
                <label for="password">
                    <?=$editUser ? 'Neues Passwort (leer lassen = nicht ändern)' : 'Passwort *'?>
                </label>
                <input type="password" id="password" name="password"
                       placeholder="<?=$editUser ? 'Nur ausfüllen zum Ändern' : 'Passwort eingeben'?>"
                       <?=$editUser ? '' : 'required'?>>
            </div>

            <div class="form-actions">
                <button type="submit" class="search-btn">💾 Speichern</button>
                <a href="admin.php?action=users" class="btn-secondary">Abbrechen</a>
            </div>
        </form>

    <?php elseif ($action === 'ircbot'): 
        $botStatus = getIrcbotStatus();
        // Config laden
        $botConfig = [
            'server' => 'irc.rizon.net',
            'port' => '6667',
            'nick' => 'PreBot',
            'nickserv_pass' => '',
            'server_pass' => '',
            'channels' => '#predb',
            'interval' => '30',
            'blowfish_key' => '',
            'blowfish_enabled' => false,
        ];
        if (file_exists($IRCBOT_CONFIG_FILE)) {
            $cfgContent = file_get_contents($IRCBOT_CONFIG_FILE);
            // Werte parsen
            if (preg_match("/\\\$IRC_SERVER\s*=\s*'([^']+)'/", $cfgContent, $m)) $botConfig['server'] = $m[1];
            if (preg_match("/\\\$IRC_PORT\s*=\s*(\d+)/", $cfgContent, $m)) $botConfig['port'] = $m[1];
            if (preg_match("/\\\$IRC_NICK\s*=\s*'([^']+)'/", $cfgContent, $m)) $botConfig['nick'] = $m[1];
            if (preg_match("/\\\$IRC_NICKSERV_PASS\s*=\s*'([^']*)'/", $cfgContent, $m)) $botConfig['nickserv_pass'] = $m[1];
            if (preg_match("/\\\$IRC_SERVER_PASS\s*=\s*'([^']*)'/", $cfgContent, $m)) $botConfig['server_pass'] = $m[1];
            if (preg_match("/\\\$IRC_BLOWFISH_KEY\s*=\s*'([^']*)'/", $cfgContent, $m)) $botConfig['blowfish_key'] = $m[1];
            if (preg_match("/\\\$IRC_BLOWFISH_ENABLED\s*=\s*(true|false)/", $cfgContent, $m)) $botConfig['blowfish_enabled'] = $m[1] === 'true';
            if (preg_match("/\\\$IRC_CHANNELS\s*=\s*\[(.*?)\]/s", $cfgContent, $m)) {
                $chans = preg_replace("/'|\s/", '', $m[1]);
                $botConfig['channels'] = str_replace(',', ', ', $chans);
            }
            if (preg_match("/\\\$POLL_INTERVAL\s*=\s*(\d+)/", $cfgContent, $m)) $botConfig['interval'] = $m[1];
        }
        // Letzte ID
        $lastId = '–';
        if (file_exists($IRCBOT_STATE_FILE)) $lastId = trim(file_get_contents($IRCBOT_STATE_FILE));
        ?>
        <div class="admin-header">
            <h1>🤖 IRC Bot Verwaltung</h1>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <a href="admin.php?action=users" class="reset-btn" style="border-color:var(--accent);">👥 Benutzer</a>
                <a href="admin.php" class="reset-btn">← Zurück</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="success-msg" style="margin: 16px 20px;"><?=h($message)?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="error-msg" style="margin: 16px 20px;"><?=h($error)?></div>
        <?php endif; ?>

        <!-- Status-Karte -->
        <div style="padding:20px;">
            <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:24px;">
                <div style="flex:1;min-width:200px;background:var(--bg-card);border-radius:12px;padding:20px;border:1px solid var(--border);">
                    <div style="font-size:13px;color:var(--text-muted);margin-bottom:8px;">Status</div>
                    <div style="font-size:24px;font-weight:700;">
                        <?php if ($botStatus === 'running'): ?>
                            <span style="color:var(--accent);">🟢 Läuft</span>
                        <?php else: ?>
                            <span style="color:var(--text-muted);">🔴 Gestoppt</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div style="flex:1;min-width:200px;background:var(--bg-card);border-radius:12px;padding:20px;border:1px solid var(--border);">
                    <div style="font-size:13px;color:var(--text-muted);margin-bottom:8px;">Server</div>
                    <div style="font-size:18px;font-weight:600;"><?=h($botConfig['server'])?>:<?=h($botConfig['port'])?></div>
                </div>
                <div style="flex:1;min-width:200px;background:var(--bg-card);border-radius:12px;padding:20px;border:1px solid var(--border);">
                    <div style="font-size:13px;color:var(--text-muted);margin-bottom:8px;">Nick</div>
                    <div style="font-size:18px;font-weight:600;"><?=h($botConfig['nick'])?></div>
                </div>
                <div style="flex:1;min-width:200px;background:var(--bg-card);border-radius:12px;padding:20px;border:1px solid var(--border);">
                    <div style="font-size:13px;color:var(--text-muted);margin-bottom:8px;">Channels</div>
                    <div style="font-size:14px;font-weight:600;"><?=h($botConfig['channels'])?></div>
                </div>
                <div style="flex:1;min-width:200px;background:var(--bg-card);border-radius:12px;padding:20px;border:1px solid var(--border);">
                    <div style="font-size:13px;color:var(--text-muted);margin-bottom:8px;">Letzte Release-ID</div>
                    <div style="font-size:18px;font-weight:600;"><?=h($lastId)?></div>
                </div>
            </div>

            <!-- Aktionen -->
            <div style="display:flex;gap:8px;margin-bottom:24px;flex-wrap:wrap;">
                <?php if ($botStatus === 'running'): ?>
                    <a href="admin.php?action=ircbot_stop" class="search-btn" style="background:var(--danger);border-color:var(--danger);" onclick="return confirm('Bot wirklich stoppen?')">🛑 Stoppen</a>
                    <a href="admin.php?action=ircbot_restart" class="search-btn" style="border-color:var(--warning);color:var(--warning);">🔄 Neustart</a>
                <?php else: ?>
                    <a href="admin.php?action=ircbot_start" class="search-btn">🚀 Starten</a>
                <?php endif; ?>
            </div>

            <!-- Config-Formular -->
            <div style="background:var(--bg-card);border-radius:12px;border:1px solid var(--border);padding:24px;margin-bottom:24px;">
                <h3 style="margin:0 0 16px 0;font-size:16px;">⚙️ Konfiguration</h3>
                <form method="POST" action="admin.php?action=ircbot_config_save">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                        <div class="form-group">
                            <label for="server">IRC Server</label>
                            <input type="text" id="server" name="server" value="<?=h($botConfig['server'])?>" placeholder="irc.rizon.net">
                        </div>
                        <div class="form-group">
                            <label for="port">Port</label>
                            <input type="number" id="port" name="port" value="<?=h($botConfig['port'])?>" placeholder="6667" min="1" max="65535">
                        </div>
                        <div class="form-group">
                            <label for="nick">Nickname</label>
                            <input type="text" id="nick" name="nick" value="<?=h($botConfig['nick'])?>" placeholder="PreBot">
                        </div>
                        <div class="form-group">
                            <label for="server_pass">Server Passwort</label>
                            <input type="password" id="server_pass" name="server_pass" value="<?=h($botConfig['server_pass'])?>" placeholder="(optional)">
                        </div>
                        <div class="form-group">
                            <label for="nickserv_pass">NickServ Passwort</label>
                            <input type="password" id="nickserv_pass" name="nickserv_pass" value="<?=h($botConfig['nickserv_pass'])?>" placeholder="(optional)">
                        </div>
                        <div class="form-group">
                            <label for="channels">Channels (kommagetrennt)</label>
                            <input type="text" id="channels" name="channels" value="<?=h($botConfig['channels'])?>" placeholder="#predb,#channel2">
                        </div>
                        <div class="form-group">
                            <label for="interval">Poll-Intervall (Sekunden)</label>
                            <input type="number" id="interval" name="interval" value="<?=h($botConfig['interval'])?>" min="10" max="300">
                        </div>
                        <div class="form-group">
                            <label for="blowfish_key">Blowfish Key (FiSH) <span style="color:var(--text-muted);font-weight:400;font-size:11px;">optional</span></label>
                            <input type="password" id="blowfish_key" name="blowfish_key" value="<?=h($botConfig['blowfish_key'])?>" placeholder="Blowfish-Key für verschlüsselte Channels">
                        </div>
                    </div>
                    <div class="form-actions" style="margin-top:16px;">
                        <button type="submit" class="search-btn">💾 Konfiguration speichern</button>
                    </div>
                </form>
            </div>

            <!-- Bot-Script Download -->
            <div style="background:var(--bg-card);border-radius:12px;border:1px solid var(--border);padding:24px;">
                <h3 style="margin:0 0 12px 0;font-size:16px;">📦 Bot-Script exportieren / umziehen</h3>
                <p style="color:var(--text-muted);font-size:13px;margin-bottom:12px;">Lade das komplette Bot-Paket herunter, um es auf einem anderen Server zu verwenden. Enthält den Bot, die Konfiguration und ein Setup-Script.</p>
                <a href="ircbot_export.php" class="search-btn" style="border-color:var(--accent);">📥 Bot-Paket herunterladen (.zip)</a>
                <span style="display:inline-block;margin-left:12px;font-size:12px;color:var(--text-muted);">PHP 7.4+ · IRC Blowfish/FiSH · Server-Passwort</span>
            </div>
        </div>

    <?php elseif ($action === 'backup'): 
        $dbSize = getDatabaseSize();
        $tableStats = [
            'releases' => ['label' => '📦 Releases', 'count' => 0, 'size' => 0],
            'backup_releases' => ['label' => '🗄️ Backup', 'count' => 0, 'size' => 0],
            'groups' => ['label' => '👥 Groups', 'count' => 0, 'size' => 0],
            'categories' => ['label' => '📁 Categories', 'count' => 0, 'size' => 0],
            'users' => ['label' => '👤 Users', 'count' => 0, 'size' => 0],
        ];
        foreach ($tableStats as $tbl => &$info) {
            $fullTable = DB_PREFIX . $tbl;
            $res = $conn->query("SELECT COUNT(*) as cnt FROM $fullTable");
            $info['count'] = $res ? (int)$res->fetch_assoc()['cnt'] : 0;
            $res2 = $conn->query("SELECT (data_length + index_length) as sz FROM information_schema.tables WHERE table_schema = '" . $conn->real_escape_string($db_name) . "' AND table_name = '$fullTable'");
            $info['size'] = $res2 ? (int)$res2->fetch_assoc()['sz'] : 0;
        }
        unset($info);
        ?>
        <div class="admin-header">
            <h1>💾 Backup & Export</h1>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <a href="admin.php?action=ircbot" class="reset-btn" style="border-color:var(--accent);">🤖 IRC Bot</a>
                <a href="admin.php" class="reset-btn">← Zurück</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="success-msg" style="margin: 16px 20px;"><?=h($message)?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="error-msg" style="margin: 16px 20px;"><?=h($error)?></div>
        <?php endif; ?>

        <div style="padding:20px;">
            <!-- Übersichts-Karten -->
            <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:24px;">
                <div style="flex:1;min-width:180px;background:var(--bg-card);border-radius:12px;padding:20px;border:1px solid var(--border);">
                    <div style="font-size:13px;color:var(--text-muted);margin-bottom:8px;">Datenbank gesamt</div>
                    <div style="font-size:22px;font-weight:700;"><?=formatBytes($dbSize)?></div>
                </div>
                <?php foreach ($tableStats as $tbl => $info): ?>
                <div style="flex:1;min-width:140px;background:var(--bg-card);border-radius:12px;padding:16px;border:1px solid var(--border);">
                    <div style="font-size:12px;color:var(--text-muted);margin-bottom:4px;"><?=$info['label']?></div>
                    <div style="font-size:20px;font-weight:600;"><?=number_format($info['count'], 0, ',', '.')?></div>
                    <div style="font-size:11px;color:var(--text-muted);"><?=formatBytes($info['size'])?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Export-Optionen -->
            <div style="background:var(--bg-card);border-radius:12px;border:1px solid var(--border);padding:24px;margin-bottom:24px;">
                <h3 style="margin:0 0 16px 0;font-size:16px;">📤 Export</h3>
                <div style="display:flex;gap:16px;flex-wrap:wrap;">
                    <a href="admin.php?action=backup_download&type=structure" class="search-btn" style="border-color:var(--accent);">
                        📋 Nur Struktur (Cats/Groups/Users)
                    </a>
                    <a href="admin.php?action=backup_download&type=full" class="search-btn" onclick="return confirm('⚠️ Großer Export! Die DB hat <?=formatBytes($dbSize)?> mit <?=number_format($tableStats['releases']['count'], 0, ',', '.')?> Releases.\n\nBei <?=formatBytes($dbSize)?> kann das länger dauern – je nach Server mehrere Minuten.\n\nTrotzdem starten?')">
                        💾 Komplett-Backup (alle Tabellen)
                    </a>
                </div>
                <p style="color:var(--text-muted);font-size:13px;margin-top:16px;margin-bottom:0;">
                    🔹 <strong>Struktur</strong> – Nur Categories, Groups und Users (schnell, klein)<br>
                    🔹 <strong>Komplett</strong> – Alle Tabellen inkl. Releases. Versucht mysqldump, fällt zurück auf PHP-Export.
                </p>
            </div>

            <!-- MySQL-Zugangsdaten (für manuelles Backup per SSH) -->
            <div style="background:var(--bg-card);border-radius:12px;border:1px solid var(--border);padding:24px;margin-bottom:24px;">
                <h3 style="margin:0 0 12px 0;font-size:16px;">🖥️ Manuelles Backup (per SSH)</h3>
                <p style="color:var(--text-muted);font-size:13px;margin-bottom:12px;">Führe diesen Befehl auf dem Server aus:</p>
                <div style="background:var(--bg-hover);border-radius:8px;padding:16px;font-size:13px;font-family:monospace;overflow-x:auto;white-space:nowrap;border:1px solid var(--border);">
                    mysqldump -h <?=h($db_host)?> -u <?=h($db_user)?> -p'***' <?=h($db_name)?> --single-transaction | gzip > predb_$(date +%Y-%m-%d).sql.gz
                </div>
                <p style="color:var(--text-muted);font-size:12px;margin-top:12px;margin-bottom:0;">
                    ⚠️ Das Passwort wird aus Sicherheitsgründen nicht angezeigt.
                </p>
            </div>

            <!-- Backup-Tabelle Info -->
            <div style="background:var(--bg-card);border-radius:12px;border:1px solid var(--border);padding:24px;">
                <h3 style="margin:0 0 12px 0;font-size:16px;">🗄️ Backup-Tabelle <code style="font-size:13px;">predb_backup_releases</code></h3>
                <p style="color:var(--text-muted);font-size:13px;margin-bottom:12px;">
                    Diese Tabelle wird vom Import-Prozess gefüllt und enthält die Rohdaten.
                    <?php if ($tableStats['backup_releases']['count'] > 0): ?>
                        <br>Aktuell <strong><?=number_format($tableStats['backup_releases']['count'], 0, ',', '.')?></strong> Einträge (<?=formatBytes($tableStats['backup_releases']['size'])?>).
                    <?php else: ?>
                        <br>Derzeit leer.
                    <?php endif; ?>
                </p>
                <details style="color:var(--text-muted);font-size:13px;">
                    <summary style="cursor:pointer;color:var(--accent);">Struktur anzeigen</summary>
                    <pre style="background:var(--bg-hover);border-radius:6px;padding:12px;margin-top:8px;overflow-x:auto;font-size:12px;border:1px solid var(--border);"><?php
                        $cr = $conn->query("SHOW CREATE TABLE " . DB_PREFIX . "backup_releases");
                        if ($cr) {
                            $crRow = $cr->fetch_assoc();
                            echo h($crRow['Create Table']);
                        } else {
                            echo 'Tabelle existiert nicht.';
                        }
                    ?></pre>
                </details>
            </div>
        </div>

    <?php elseif ($action === 'add' || ($action === 'edit' && $editRelease)): ?>
        <div class="admin-header">
            <h1><?=$editRelease ? '✏️ Release bearbeiten' : '➕ Neuen Release erstellen'?></h1>
            <a href="admin.php" class="reset-btn">← Zurück</a>
        </div>

        <?php if ($error): ?>
            <div class="error-msg" style="margin: 16px 20px;"><?=h($error)?></div>
        <?php endif; ?>

        <form method="POST" action="admin.php?action=save" class="admin-form">
            <?php if ($editRelease): ?>
                <input type="hidden" name="id" value="<?=$editRelease['id']?>">
            <?php endif; ?>

            <div class="form-group">
                <label for="name">Release Name *</label>
                <input type="text" id="name" name="name" required
                       value="<?=h($editRelease['name'] ?? '')?>"
                       placeholder="z.B. Game.Name.2024.GERMAN-GROUP">
            </div>

            <div class="form-group">
                <label for="category_id">Kategorie</label>
                <select id="category_id" name="category_id" class="category-select" style="width: 100%;">
                    <option value="0">Keine Kategorie</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?=$cat['id']?>" <?=($editRelease && $editRelease['category_id'] == $cat['id']) ? 'selected' : ''?>>
                            <?=h($cat['icon'])?> <?=h($cat['name'])?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="group_id">Gruppe</label>
                <select id="group_id" name="group_id" class="category-select" style="width: 100%;">
                    <option value="0">Keine Gruppe</option>
                    <?php foreach ($groups as $g): ?>
                        <option value="<?=$g['id']?>" <?=($editRelease && $editRelease['group_id'] == $g['id']) ? 'selected' : ''?>>
                            <?=h($g['name'])?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display: flex; gap: 16px;">
                <div class="form-group" style="flex: 1;">
                    <label for="size">Größe</label>
                    <input type="text" id="size" name="size"
                           value="<?=h($editRelease['size'] ?? '')?>"
                           placeholder="z.B. 4.2 GB">
                </div>
                <div class="form-group" style="flex: 1;">
                    <label for="files">Dateien</label>
                    <input type="number" id="files" name="files" min="0"
                           value="<?=intval($editRelease['files'] ?? 0)?>">
                </div>
            </div>

            <div class="form-group">
                <label for="nfo_content">NFO Inhalt</label>
                <textarea id="nfo_content" name="nfo_content"
                          placeholder="NFO-Text hier einfügen..."><?=h($editRelease['nfo_content'] ?? '')?></textarea>
            </div>

            <div class="form-actions">
                <button type="submit" class="search-btn">💾 Speichern</button>
                <a href="admin.php" class="btn-secondary">Abbrechen</a>
            </div>
        </form>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>

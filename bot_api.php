<?php
/**
 * FortKnox PreDB Bot API v1.0
 * ============================
 * Leichte API für den IRC-Bot auf dem VPS.
 * Statt MySQL-Remote-Zugriff nutzt der Bot diese API via HTTPS.
 * 
 * Aufruf:
 *   bot_api.php?secret=KEY&action=new&last_id=12345
 *   bot_api.php?secret=KEY&action=latest&count=5
 *   bot_api.php?secret=KEY&action=search&q=begriff
 *   bot_api.php?secret=KEY&action=stats
 */

// ============================================================
// SICHERHEIT
// ============================================================
// Generiere ein sicheres Secret und trag es hier ein
define('API_SECRET', 'PreBot2024!SecureAPIKey#FortKnox');

// ============================================================
// RATE LIMITING
// ============================================================
$RATE_LIMIT_FILE = __DIR__ . '/bot_api_rate.txt';
$RATE_MAX = 30;        // Max Aufrufe
$RATE_WINDOW = 60;     // Pro Sekunden

// ============================================================
// SETUP
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');

// Secret prüfen
$secret = isset($_GET['secret']) ? $_GET['secret'] : '';
if ($secret !== API_SECRET) {
    http_response_code(403);
    die(json_encode(['error' => 'Ungültiges Secret']));
}

// Rate Limiting
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateData = @file_get_contents($RATE_LIMIT_FILE);
$rates = $rateData ? json_decode($rateData, true) : [];
$now = time();

// Alte Einträge bereinigen
foreach ($rates as $ip => $data) {
    if ($now - $data['time'] > $RATE_WINDOW) {
        unset($rates[$ip]);
    }
}

// Prüfen
if (isset($rates[$clientIp]) && $rates[$clientIp]['count'] >= $RATE_MAX) {
    http_response_code(429);
    die(json_encode(['error' => 'Rate limit erreicht', 'retry_after' => $RATE_WINDOW]));
}

// Zähler aktualisieren
if (!isset($rates[$clientIp])) {
    $rates[$clientIp] = ['count' => 0, 'time' => $now];
}
$rates[$clientIp]['count']++;
$rates[$clientIp]['time'] = $now;
@file_put_contents($RATE_LIMIT_FILE, json_encode($rates), LOCK_EX);

// ============================================================
// DB VERBINDUNG
// ============================================================

require_once __DIR__ . '/config.php';

if (!$conn || $conn->connect_error) {
    http_response_code(500);
    die(json_encode(['error' => 'Datenbankverbindung fehlgeschlagen']));
}

// ============================================================
// AKTIONEN
// ============================================================

$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {

    // ------------------------------------------------------------------
    // NEUE RELEASES SEIT ID
    // ------------------------------------------------------------------
    case 'new':
        $lastId = isset($_GET['last_id']) ? max(0, intval($_GET['last_id'])) : 0;
        $limit = isset($_GET['limit']) ? min(20, max(1, intval($_GET['limit']))) : 5;

        $stmt = $conn->prepare("
            SELECT r.id, r.name, r.category_id, c.name as category_name, 
                   r.group_id, g.name as group_name, r.size, r.created_at
            FROM predb_releases r
            LEFT JOIN predb_categories c ON r.category_id = c.id
            LEFT JOIN predb_groups g ON r.group_id = g.id
            WHERE r.id > ?
            ORDER BY r.id ASC
            LIMIT ?
        ");
        $stmt->bind_param('ii', $lastId, $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        $releases = [];
        while ($row = $res->fetch_assoc()) {
            $releases[] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'category_id' => $row['category_id'] ? (int)$row['category_id'] : null,
                'category_name' => $row['category_name'] ?: 'Sonstiges',
                'group_id' => $row['group_id'] ? (int)$row['group_id'] : null,
                'group_name' => $row['group_name'] ?: 'unknown',
                'size' => $row['size'] ?: '',
                'created_at' => $row['created_at'],
            ];
        }
        $stmt->close();

        echo json_encode([
            'success' => true,
            'last_id' => $lastId,
            'releases' => $releases,
            'count' => count($releases),
            'max_id' => count($releases) > 0 ? $releases[count($releases)-1]['id'] : $lastId,
        ]);
        break;

    // ------------------------------------------------------------------
    // LETZTE RELEASES
    // ------------------------------------------------------------------
    case 'latest':
        $count = isset($_GET['count']) ? min(20, max(1, intval($_GET['count']))) : 5;

        $stmt = $conn->prepare("
            SELECT r.id, r.name, r.category_id, c.name as category_name,
                   r.group_id, g.name as group_name, r.size, r.created_at
            FROM predb_releases r
            LEFT JOIN predb_categories c ON r.category_id = c.id
            LEFT JOIN predb_groups g ON r.group_id = g.id
            ORDER BY r.id DESC
            LIMIT ?
        ");
        $stmt->bind_param('i', $count);
        $stmt->execute();
        $res = $stmt->get_result();
        $releases = [];
        while ($row = $res->fetch_assoc()) {
            $releases[] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'category_id' => $row['category_id'] ? (int)$row['category_id'] : null,
                'category_name' => $row['category_name'] ?: 'Sonstiges',
                'group_id' => $row['group_id'] ? (int)$row['group_id'] : null,
                'group_name' => $row['group_name'] ?: 'unknown',
                'size' => $row['size'] ?: '',
                'created_at' => $row['created_at'],
            ];
        }
        $stmt->close();

        echo json_encode([
            'success' => true,
            'releases' => $releases,
            'count' => count($releases),
        ]);
        break;

    // ------------------------------------------------------------------
    // MAX ID (leichter Ping)
    // ------------------------------------------------------------------
    case 'maxid':
        $res = $conn->query("SELECT MAX(id) as max_id FROM predb_releases");
        $row = $res->fetch_assoc();
        echo json_encode([
            'success' => true,
            'max_id' => (int)$row['max_id'],
        ]);
        break;

    // ------------------------------------------------------------------
    // SEARCH
    // ------------------------------------------------------------------
    case 'search':
        $q = isset($_GET['q']) ? trim($_GET['q']) : '';
        if (empty($q)) {
            echo json_encode(['success' => false, 'error' => 'Kein Suchbegriff']);
            break;
        }

        $searchTerm = '%' . $q . '%';
        $stmt = $conn->prepare("
            SELECT r.id, r.name, r.category_id, c.name as category_name,
                   r.group_id, g.name as group_name, r.size, r.created_at
            FROM predb_releases r
            LEFT JOIN predb_categories c ON r.category_id = c.id
            LEFT JOIN predb_groups g ON r.group_id = g.id
            WHERE r.name LIKE ?
            ORDER BY r.id DESC
            LIMIT 5
        ");
        $stmt->bind_param('s', $searchTerm);
        $stmt->execute();
        $res = $stmt->get_result();
        $releases = [];
        while ($row = $res->fetch_assoc()) {
            $releases[] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'category_id' => $row['category_id'] ? (int)$row['category_id'] : null,
                'category_name' => $row['category_name'] ?: 'Sonstiges',
                'group_id' => $row['group_id'] ? (int)$row['group_id'] : null,
                'group_name' => $row['group_name'] ?: 'unknown',
                'size' => $row['size'] ?: '',
                'created_at' => $row['created_at'],
            ];
        }
        $stmt->close();

        echo json_encode([
            'success' => true,
            'query' => $q,
            'releases' => $releases,
            'count' => count($releases),
        ]);
        break;

    // ------------------------------------------------------------------
    // STATS
    // ------------------------------------------------------------------
    case 'stats':
        $res = $conn->query("SELECT COUNT(*) as total FROM predb_releases");
        $total = $res->fetch_assoc()['total'];
        $res = $conn->query("SELECT COUNT(*) as total FROM predb_groups");
        $groups = $res->fetch_assoc()['total'];
        $res = $conn->query("SELECT COUNT(*) as total FROM predb_releases WHERE created_at >= NOW() - INTERVAL 1 DAY");
        $last24h = $res->fetch_assoc()['total'];
        $res = $conn->query("SELECT COUNT(*) as total FROM predb_releases WHERE created_at >= NOW() - INTERVAL 1 HOUR");
        $last1h = $res->fetch_assoc()['total'];
        $res = $conn->query("SELECT MAX(id) as max_id FROM predb_releases");
        $maxId = $res->fetch_assoc()['max_id'];

        echo json_encode([
            'success' => true,
            'total' => (int)$total,
            'groups' => (int)$groups,
            'last24h' => (int)$last24h,
            'last1h' => (int)$last1h,
            'max_id' => (int)$maxId,
        ]);
        break;

    // ------------------------------------------------------------------
    // DEFAULT
    // ------------------------------------------------------------------
    default:
        echo json_encode([
            'success' => true,
            'service' => 'FortKnox PreDB Bot API',
            'version' => '1.0',
            'endpoints' => [
                'new' => '?secret=KEY&action=new&last_id=ID&limit=N',
                'latest' => '?secret=KEY&action=latest&count=N',
                'maxid' => '?secret=KEY&action=maxid',
                'search' => '?secret=KEY&action=search&q=BEGRIFF',
                'stats' => '?secret=KEY&action=stats',
            ],
        ]);
        break;
}

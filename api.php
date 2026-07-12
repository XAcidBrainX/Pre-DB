<?php
/**
 * FortKnox PreDB API – JSON-Schnittstelle für externe Bots
 * Aufruf: api.php?action=latest&limit=5
 *         api.php?action=search&q=beatport
 *         api.php?action=stats
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {
    case 'latest':
        $limit = isset($_GET['limit']) ? max(1, min(20, intval($_GET['limit']))) : 5;
        $stmt = $conn->prepare(
            "SELECT id, name, category_id, group_id, size, created_at 
             FROM predb_releases 
             ORDER BY id DESC 
             LIMIT ?"
        );
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        $releases = [];
        while ($row = $res->fetch_assoc()) {
            $row['category_name'] = getCategoryName($row['category_id']);
            $row['group_name'] = getGroupName($row['group_id']);
            unset($row['category_id'], $row['group_id']);
            $releases[] = $row;
        }
        $stmt->close();
        echo json_encode(['success' => true, 'data' => $releases, 'count' => count($releases)]);
        break;

    case 'search':
        $q = isset($_GET['q']) ? trim($_GET['q']) : '';
        if (strlen($q) < 2) {
            echo json_encode(['success' => false, 'error' => 'Suchbegriff zu kurz (min. 2 Zeichen)']);
            exit;
        }
        $limit = isset($_GET['limit']) ? max(1, min(20, intval($_GET['limit']))) : 5;
        $like = '%' . $q . '%';
        $stmt = $conn->prepare(
            "SELECT id, name, category_id, group_id, size, created_at 
             FROM predb_releases 
             WHERE name LIKE ? 
             ORDER BY id DESC 
             LIMIT ?"
        );
        $stmt->bind_param('si', $like, $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        $releases = [];
        while ($row = $res->fetch_assoc()) {
            $row['category_name'] = getCategoryName($row['category_id']);
            $row['group_name'] = getGroupName($row['group_id']);
            unset($row['category_id'], $row['group_id']);
            $releases[] = $row;
        }
        $stmt->close();
        echo json_encode(['success' => true, 'data' => $releases, 'count' => count($releases)]);
        break;

    case 'stats':
        $res = $conn->query("SELECT COUNT(*) as total FROM predb_releases");
        $total = $res->fetch_assoc()['total'];
        
        $res = $conn->query("SELECT COUNT(*) as total FROM predb_groups");
        $groups = $res->fetch_assoc()['total'];
        
        $res = $conn->query("SELECT COUNT(*) as total FROM predb_releases WHERE created_at >= NOW() - INTERVAL 1 DAY");
        $last24h = $res->fetch_assoc()['total'];
        
        $res = $conn->query("SELECT COUNT(*) as total FROM predb_releases WHERE created_at >= NOW() - INTERVAL 1 HOUR");
        $last1h = $res->fetch_assoc()['total'];
        
        echo json_encode([
            'success' => true,
            'data' => [
                'total_releases' => (int)$total,
                'total_groups' => (int)$groups,
                'last_24h' => (int)$last24h,
                'last_1h' => (int)$last1h,
            ]
        ]);
        break;

    default:
        echo json_encode([
            'success' => false,
            'error' => 'Unbekannte Aktion. Verfügbar: latest, search, stats',
            'endpoints' => [
                'latest' => 'api.php?action=latest&limit=5',
                'search' => 'api.php?action=search&q=beatport',
                'stats'  => 'api.php?action=stats',
            ]
        ]);
}

/**
 * Kategorie-Name anhand ID
 */
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

/**
 * Group-Name anhand ID
 */
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

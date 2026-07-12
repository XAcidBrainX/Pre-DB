<?php
/**
 * FortKnox PreDB Backup Migration
 * Importiert alle 5,1 Mio. Releases + Groups aus predb_backup_releases
 * und verbindet sie mit der Haupttabelle predb_releases
 */

set_time_limit(0);
ini_set('memory_limit', '1024M');

require_once __DIR__ . '/config.php';

$startTime = time();

// -----------------------------------------------------------------------
// 1. Mapping: Section -> category_id
// -----------------------------------------------------------------------
$sectionMap = [
    'GAMES' => 1, 'GAME' => 1, 'PS2' => 1, 'PS3' => 1, 'PSP' => 1,
    'NDS' => 1, 'GBA' => 1, 'N_GAGE' => 1, 'XBOX' => 1, 'XBOX360' => 1,
    'WII' => 1, 'NGC' => 1, 'DC' => 1, 'PC' => 1, 'GBC' => 1, 'PSX' => 1,
    'DVDR' => 2, 'DVD-R' => 2, 'XVID' => 2, 'DIVX' => 2, 'X264' => 2,
    'AC3' => 2, 'SVCD' => 2, 'VCD' => 2, 'MDVDR' => 2, 'BLURAY' => 2,
    'HDTV' => 2, '1080P' => 2, '720P' => 2, 'M1080' => 2, 'M720' => 2,
    'MP3' => 3, 'MVID' => 3, 'FLAC' => 3, 'WAV' => 3, 'WV' => 3,
    '0DAY' => 4, 'APPS' => 4, 'APP' => 4, 'WIN' => 4, 'DOX' => 4,
    'LINUX' => 4, 'MAC' => 4, 'MOBILE' => 4, 'PDA' => 4, 'PALM' => 4,
    'COVERS' => 4, 'TEMPLATES' => 4, 'DOCS' => 4,
    'TV' => 5, 'EPISODE' => 5,
    'EBOOK' => 6, 'EBOOKS' => 6, 'BOOKS' => 6,
    'XXX' => 7, 'ADULT' => 7,
    'SUBPACK' => 2, 'IMAGE' => 2, 'SUBS' => 2,
];

// -----------------------------------------------------------------------
// 2. Alle Gruppen aus Backup importieren
// -----------------------------------------------------------------------
echo date('H:i:s') . " [1/3] Importiere Gruppen...\n";

$checkGrps = $conn->query("SELECT COUNT(*) as cnt FROM " . DB_PREFIX . "groups");
$existingCount = $checkGrps->fetch_assoc()['cnt'];
echo "  -> Vorhandene Gruppen in DB: " . number_format($existingCount) . "\n";

$backupGroups = [];
$result = $conn->query("SELECT DISTINCT RlsGroup FROM predb_backup_releases WHERE RlsGroup != '' ORDER BY RlsGroup");
while ($row = $result->fetch_assoc()) {
    $backupGroups[] = $row['RlsGroup'];
}
echo "  -> " . number_format(count($backupGroups)) . " Gruppen im Backup gefunden\n";

if ($existingCount < count($backupGroups)) {
    // Nur fehlende Gruppen importieren
    echo "  -> Importiere fehlende Gruppen...\n";
    $insertGroup = $conn->prepare("INSERT IGNORE INTO " . DB_PREFIX . "groups (name) VALUES (?)");
    $groupCount = 0;
    $batchSize = 1000;
    $batch = [];
    
    $conn->begin_transaction();
    foreach ($backupGroups as $group) {
        $insertGroup->bind_param("s", $group);
        if ($insertGroup->execute() && $insertGroup->affected_rows > 0) {
            $groupCount++;
        }
    }
    $conn->commit();
    echo "  -> $groupCount neue Gruppen importiert\n";
} else {
    echo "  -> Bereits alle Gruppen importiert, überspringe...\n";
}

// Gruppen-ID Mapping
$groupMap = [];
$result = $conn->query("SELECT id, name FROM " . DB_PREFIX . "groups");
while ($row = $result->fetch_assoc()) {
    $groupMap[$row['name']] = $row['id'];
}
echo "  -> " . number_format(count($groupMap)) . " Gruppen in DB\n";

// -----------------------------------------------------------------------
// 3. Releases migrieren (in Batches)
// -----------------------------------------------------------------------
echo "\n" . date('H:i:s') . " [2/3] Migriere Releases...\n";

$result = $conn->query("SELECT COUNT(*) as cnt FROM predb_backup_releases");
$backupTotal = $result->fetch_assoc()['cnt'];
echo "  -> " . number_format($backupTotal) . " Releases im Backup\n";

// Disable Keys
$conn->query("ALTER TABLE " . DB_PREFIX . "releases DISABLE KEYS");

$limit = 10000;
$offset = 0;
$total = 0;

$insertNoGroup = $conn->prepare(
    "INSERT IGNORE INTO " . DB_PREFIX . "releases 
     (name, category_id, size, files, source_url, created_at) 
     VALUES (?, ?, ?, ?, ?, ?)"
);
$insertWithGroup = $conn->prepare(
    "INSERT IGNORE INTO " . DB_PREFIX . "releases 
     (name, category_id, group_id, size, files, source_url, created_at) 
     VALUES (?, ?, ?, ?, ?, ?, ?)"
);

$startBatch = time();

while ($offset < $backupTotal) {
    $result = $conn->query(
        "SELECT ReleaseName, Section, RlsGroup, Size, Files, Pretime 
         FROM predb_backup_releases 
         LIMIT $offset, $limit"
    );
    if (!$result || $result->num_rows === 0) break;
    
    $conn->begin_transaction();
    $count = 0;
    
    while ($row = $result->fetch_assoc()) {
        $name = $row['ReleaseName'];
        $section = strtoupper(trim($row['Section']));
        $catId = isset($sectionMap[$section]) ? $sectionMap[$section] : 8;
        $groupId = isset($groupMap[$row['RlsGroup']]) ? $groupMap[$row['RlsGroup']] : null;
        $size = $row['Size'] ?: null;
        $files = ($row['Files'] !== '' && is_numeric($row['Files'])) ? (int)$row['Files'] : null;
        $createdAt = $row['Pretime'] > 0 ? date('Y-m-d H:i:s', $row['Pretime']) : date('Y-m-d H:i:s');
        $sourceUrl = 'https://predb.net/';
        
        if ($groupId !== null) {
            $insertWithGroup->bind_param("siissss", $name, $catId, $groupId, $size, $files, $sourceUrl, $createdAt);
            if ($insertWithGroup->execute()) $count++;
        } else {
            $insertNoGroup->bind_param("sissis", $name, $catId, $size, $files, $sourceUrl, $createdAt);
            if ($insertNoGroup->execute()) $count++;
        }
    }
    
    $conn->commit();
    $offset += $limit;
    $total += $count;
    
    $elapsed = time() - $startTime;
    $rate = $elapsed > 0 ? round($total / $elapsed) : 0;
    $pct = round($offset / $backupTotal * 100);
    echo date('H:i:s') . "  {$pct}% | " . number_format($offset) . "/" . number_format($backupTotal) . " | " . number_format($total) . " migriert | {$rate}/s\n";
}

// Enable Keys
$conn->query("ALTER TABLE " . DB_PREFIX . "releases ENABLE KEYS");

// -----------------------------------------------------------------------
// 4. Alte Testdaten bereinigen
// -----------------------------------------------------------------------
echo "\n" . date('H:i:s') . " [3/3] Bereinige Testdaten...\n";
$conn->query("DELETE FROM " . DB_PREFIX . "releases WHERE id <= 46 AND name LIKE 'Test%'");
echo "  -> Bereinigt\n";

// -----------------------------------------------------------------------
// 5. Statistik
// -----------------------------------------------------------------------
echo "\n" . date('H:i:s') . " === MIGRATION ABGESCHLOSSEN ===\n";
$elapsed = time() - $startTime;
echo "Dauer: " . gmdate('H:i:s', $elapsed) . "\n";

$result = $conn->query("SELECT COUNT(*) as cnt FROM " . DB_PREFIX . "releases");
echo "Releases gesamt: " . number_format($result->fetch_assoc()['cnt']) . "\n";

$result = $conn->query(
    "SELECT c.icon, c.name, COUNT(*) as cnt 
     FROM " . DB_PREFIX . "releases r 
     JOIN " . DB_PREFIX . "categories c ON r.category_id=c.id 
     GROUP BY r.category_id 
     ORDER BY cnt DESC"
);
echo "\nKategorien:\n";
while ($row = $result->fetch_assoc()) {
    echo "  " . $row['icon'] . " " . str_pad($row['name'], 15) . " " . number_format($row['cnt']) . "\n";
}

$conn->close();

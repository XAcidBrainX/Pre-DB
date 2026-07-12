<?php
/**
 * Transformiert predb_backup_releases in predb_releases
 * Mapping: Section -> category_id
 */

set_time_limit(0);
ini_set('memory_limit', '1024M');

$conn = new mysqli('localhost', 'st75757_roun131', '5S8(5FT[8p', 'st75757_roun131');
if ($conn->connect_error) die("Fehler: ".$conn->connect_error);

echo date('H:i:s')." Starte Transformation...\n";

// Mapping: Section -> category_id
$sectionMap = [
    // Games
    'GAMES' => 1, 'GAME' => 1, 'PS2' => 1, 'PS3' => 1, 'PSP' => 1, 'NDS' => 1,
    'GBA' => 1, 'N_GAGE' => 1, 'XBOX' => 1, 'XBOX360' => 1, 'WII' => 1,
    'NGC' => 1, 'DC' => 1, 'PC' => 1, 'GBC' => 1, 'PSX' => 1,
    
    // Movies
    'DVDR' => 2, 'DVD-R' => 2, 'XVID' => 2, 'DIVX' => 2, 'X264' => 2,
    'AC3' => 2, 'SVCD' => 2, 'VCD' => 2, 'MDVDR' => 2, 'BLURAY' => 2,
    'HDTV' => 2, '1080P' => 2, '720P' => 2,
    
    // Music
    'MP3' => 3, 'MVID' => 3, 'FLAC' => 3, 'WAV' => 3, 'WV' => 3,
    
    // Apps
    '0DAY' => 4, 'APPS' => 4, 'APP' => 4, 'WIN' => 4, 'DOX' => 4,
    'LINUX' => 4, 'MAC' => 4, 'MOBILE' => 4,
    
    // TV
    'TV' => 5, 'EPISODE' => 5,
    
    // Books
    'EBOOK' => 6, 'EBOOKS' => 6, 'BOOKS' => 6,
    
    // XXX
    'XXX' => 7, 'ADULT' => 7,
    
    // PDA
    'PDA' => 4, 'PALM' => 4,
    
    // Andere
    'COVERS' => 4, 'TEMPLATES' => 4, 'DOCS' => 4,
    'SUBPACK' => 2, 'IMAGE' => 2, 'SUBS' => 2,
];

// Erstelle temporäre Tabelle mit der transformation
$conn->query("DROP TABLE IF EXISTS predb_releases_new");

$sql = "CREATE TABLE predb_releases_new LIKE predb_releases";
$conn->query($sql);
echo date('H:i:s')." Tabelle erstellt\n";

// Batch-Insert mit Mapping
$limit = 50000;
$offset = 0;
$total = 0;

$insertStmt = $conn->prepare("INSERT INTO predb_releases_new (name, category_id, source_url, created_at) VALUES (?, ?, ?, ?)");

$backupTotal = 0;
$r = $conn->query("SELECT COUNT(*) as cnt FROM predb_backup_releases");
$backupTotal = $r->fetch_assoc()['cnt'];

while ($offset < $backupTotal) {
    $result = $conn->query("SELECT ID, ReleaseName, Section, Pretime FROM predb_backup_releases LIMIT $offset, $limit");
    if (!$result || $result->num_rows === 0) break;
    
    $conn->begin_transaction();
    $count = 0;
    
    while ($row = $result->fetch_assoc()) {
        $name = $row['ReleaseName'];
        $section = strtoupper(trim($row['Section']));
        $catId = isset($sectionMap[$section]) ? $sectionMap[$section] : 8; // Default: Sonstiges
        
        // Pretime (Unix-Timestamp) in DateTime
        $createdAt = $row['Pretime'] > 0 ? date('Y-m-d H:i:s', $row['Pretime']) : date('Y-m-d H:i:s');
        $sourceUrl = 'https://predb.net/';
        
        $insertStmt->bind_param("siss", $name, $catId, $sourceUrl, $createdAt);
        if ($insertStmt->execute()) {
            $count++;
        }
    }
    
    $conn->commit();
    $offset += $limit;
    $total += $count;
    
    echo date('H:i:s')." $offset / $backupTotal - ".number_format($total)." transformiert\n";
}

echo "\n".date('H:i:s')." Transformation fertig!\n";
echo "Transformiert: ".number_format($total)."\n";

// Prüfe
$r = $conn->query("SELECT COUNT(*) as cnt FROM predb_releases_new");
echo "Releases neu: ".number_format($r->fetch_assoc()['cnt'])."\n";

$r = $conn->query("SELECT c.name, c.icon, COUNT(*) as cnt FROM predb_releases_new r JOIN predb_categories c ON r.category_id=c.id GROUP BY r.category_id ORDER BY cnt DESC");
echo "\nKategorien:\n";
while ($row = $r->fetch_assoc()) {
    echo "  ".$row['icon']." ".$row['name'].": ".number_format($row['cnt'])."\n";
}

$conn->close();

<?php
/**
 * PreDB Import für predb.txt (TSV Format)
 * Importiert Releases aus der TSV-Datei in predb_releases
 */

set_time_limit(0);
ini_set('memory_limit', '1024M');

require_once __DIR__ . '/config.php';

$tsvFile = '/home/st75757/domains/st75757.ispot.cc/public_html/sql/predb.txt';
$progressFile = __DIR__ . '/import_predb_progress.txt';

echo date('H:i:s') . " Starte Import von predb.txt\n";
echo "Dateigröße: " . number_format(filesize($tsvFile)) . " Bytes\n";

// Mapping: Section -> category_id
$sectionMap = [
    // Games
    'GAMES' => 1, 'GAME' => 1, 'PS2' => 1, 'PS3' => 1, 'PSP' => 1, 'NDS' => 1,
    'GBA' => 1, 'N_GAGE' => 1, 'XBOX' => 1, 'XBOX360' => 1, 'WII' => 1,
    'NGC' => 1, 'DC' => 1, 'PC' => 1, 'GBC' => 1, 'PSX' => 1,
    'GAMES-PC' => 1, 'GAMES-NSW' => 1, 'GAMES-WIIU' => 1, 'GAMES-WII' => 1,
    'GAMES-XBOX360' => 1, 'GAMES-NDS' => 1, 'GAMES-3' => 1,
    'WIIU' => 1, 'PS4' => 1, 'DREAMCAST' => 1, 'PSX' => 1,
    
    // Movies
    'DVDR' => 2, 'DVD-R' => 2, 'XVID' => 2, 'DIVX' => 2, 'X264' => 2,
    'AC3' => 2, 'SVCD' => 2, 'VCD' => 2, 'MDVDR' => 2, 'BLURAY' => 2,
    'HDTV' => 2, '1080P' => 2, '720P' => 2, 'M1080' => 2, 'M720' => 2,
    'X264-SD' => 2, 'X264-720P' => 2, 'X264-1080P' => 2, 'X264-6' => 2,
    'X265-2160P' => 2, 'BDR' => 2, 'HDDVD' => 2, 'DVD' => 2,
    'DVDR-DE' => 2, 'HD-1080P-DE' => 2, 'HD-720P-DE' => 2,
    'X264-SD-DE' => 2, 'X264-HD-DE' => 2, 'DOKUS-HD-DE' => 2, 'DOKUS-SD-DE' => 2,
    'DOKUS-X264-SD' => 2, 'DOKUS-X264-HD' => 2, 'DOKU-HD-DE' => 2, 'DOKU-SD-DE' => 2,
    'DOKU-X264-HD' => 2, 'HD-X264-DE' => 2, 'HDTV-720P' => 2, 'HDTV-1080P' => 2,
    'TV-X264-SD' => 2, 'TV-X264-1080P' => 2, 'XXX-X264-HD' => 2,
    
    // TV / Serien
    'TV' => 5, 'EPISODE' => 5, 'SERIEN' => 5, 'SERIEN_X264' => 5,
    'TV-SD-DE' => 5, 'TV-HD-DE' => 5, 'TV-1080P-DE' => 5, 'TV-720P-DE' => 5,
    'TV-HD-720P-DE' => 5, 'TV-HD-1080P-DE' => 5, 'SERIEN-720P' => 5,
    'TV-X264-SD' => 5, 'TV-SPORT-DE' => 5, 'ANIME' => 5,
    
    // Music
    'MP3' => 3, 'MVID' => 3, 'FLAC' => 3, 'WAV' => 3, 'WV' => 3,
    'MV' => 3, 'CHARTS' => 3,
    
    // Apps
    '0DAY' => 4, 'APPS' => 4, 'APP' => 4, 'WIN' => 4, 'DOX' => 4,
    'LINUX' => 4, 'MAC' => 4, 'MOBILE' => 4, 'PDA' => 4, 'PALM' => 4,
    'COVERS' => 4, 'TEMPLATES' => 4, 'DOCS' => 4, 'BOOKWARE' => 4,
    '0-DAY' => 4, 'RLS,0DAY' => 4, 'RLS,APPS' => 4,
    
    // Books
    'EBOOK' => 6, 'EBOOKS' => 6, 'BOOKS' => 6, 'ABOOK' => 6,
    'ABOOKS' => 6, 'AUDIOBOOKS' => 6,
    
    // XXX
    'XXX' => 7, 'ADULT' => 7, 'XXX-SD' => 7,
    
    // Subs
    'SUBPACK' => 2, 'IMAGE' => 2, 'SUBS' => 2, 'IMAGESET' => 2,
    
    // Doku
    'DOKU' => 2,
];

// Checkpoint laden
$skipLines = 0;
if (file_exists($progressFile)) {
    $skipLines = (int)trim(file_get_contents($progressFile));
    echo "Wiederaufnahme ab Zeile: " . number_format($skipLines) . "\n";
}

$handle = fopen($tsvFile, 'r');
if (!$handle) die("Kann Datei nicht öffnen\n");

// Header überspringen
$header = fgets($handle);

$totalInserted = 0;
$totalSkipped = 0;
$errors = 0;
$processed = 0;
$startTime = time();
$batchSize = 1000;
$batch = [];

$insertStmt = $conn->prepare(
    "INSERT IGNORE INTO " . DB_PREFIX . "releases 
     (name, category_id, size, files, source_url, created_at) 
     VALUES (?, ?, ?, ?, ?, ?)"
);

while (($line = fgets($handle)) !== false) {
    $processed++;
    
    // Bereits verarbeitete Zeilen überspringen
    if ($processed <= $skipLines) {
        if ($processed % 100000 === 0) {
            echo "  Übersprungen: " . number_format($processed) . "\n";
        }
        continue;
    }
    
    $line = rtrim($line, "\r\n");
    if (empty($line)) continue;
    
    // TSV parsen
    $parts = explode("\t", $line);
    if (count($parts) < 3) continue;
    
    $rlsDate = $parts[0];
    $section = strtoupper(trim($parts[1]));
    $name = trim($parts[2]);
    $files = isset($parts[3]) && $parts[3] !== '' && is_numeric($parts[3]) ? (int)$parts[3] : null;
    $size = isset($parts[4]) && $parts[4] !== '' ? trim($parts[4]) : null;
    // $infos = isset($parts[5]) ? $parts[5] : '';
    
    if (empty($name)) continue;
    
    // Kategorie bestimmen
    $catId = isset($sectionMap[$section]) ? $sectionMap[$section] : 8; // Default: Sonstiges
    
    // Datum parsen
    $createdAt = $rlsDate;
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $rlsDate)) {
        $createdAt = date('Y-m-d H:i:s');
    }
    
    $sourceUrl = 'https://predb.net/';
    
    $batch[] = [$name, $catId, $size, $files, $sourceUrl, $createdAt];
    
    // Batch ausführen
    if (count($batch) >= $batchSize) {
        $conn->begin_transaction();
        $batchInserted = 0;
        
        foreach ($batch as $row) {
            $insertStmt->bind_param("sissss", $row[0], $row[1], $row[2], $row[3], $row[4], $row[5]);
            if ($insertStmt->execute() && $insertStmt->affected_rows > 0) {
                $batchInserted++;
            }
        }
        
        $conn->commit();
        $totalInserted += $batchInserted;
        $batch = [];
        
        // Fortschritt speichern
        file_put_contents($progressFile, $processed);
    }
    
    // Fortschritt anzeigen
    if ($processed % 50000 === 0) {
        $elapsed = time() - $startTime;
        $rate = $elapsed > 0 ? round($processed / $elapsed) : 0;
        echo date('H:i:s') . " Zeile " . number_format($processed) . " | +" . number_format($totalInserted) . " inserts | {$rate} Zeilen/s\n";
    }
}

// Restliche Batch verarbeiten
if (!empty($batch)) {
    $conn->begin_transaction();
    $batchInserted = 0;
    
    foreach ($batch as $row) {
        $insertStmt->bind_param("sissss", $row[0], $row[1], $row[2], $row[3], $row[4], $row[5]);
        if ($insertStmt->execute() && $insertStmt->affected_rows > 0) {
            $batchInserted++;
        }
    }
    
    $conn->commit();
    $totalInserted += $batchInserted;
    file_put_contents($progressFile, $processed);
}

fclose($handle);

$elapsed = time() - $startTime;

echo "\n" . date('H:i:s') . " === IMPORT FERTIG ===\n";
echo "Dauer: " . gmdate('H:i:s', $elapsed) . "\n";
echo "Verarbeitete Zeilen: " . number_format($processed) . "\n";
echo "Neu eingefügt: " . number_format($totalInserted) . "\n";
echo "Fehler: {$errors}\n";

// Endstatistik
$result = $conn->query("SELECT COUNT(*) as cnt FROM " . DB_PREFIX . "releases");
echo "\nReleases gesamt in DB: " . number_format($result->fetch_assoc()['cnt']) . "\n";

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
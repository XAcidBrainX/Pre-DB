<?php
/**
 * PreDB Chunk Import - Läuft in <30s pro Durchlauf
 * Nutzt eine Checkpoint-Datei um den Fortschritt zu speichern
 */

$sqlFile = '/home/st75757/domains/st75757.ispot.cc/public_html/backup.sql';
$checkpointFile = __DIR__ . '/import_checkpoint.txt';

$conn = new mysqli('localhost', 'st75757_roun131', '5S8(5FT[8p', 'st75757_roun131');
if ($conn->connect_error) die("Fehler: " . $conn->connect_error);

// Checkpoint laden
$startLine = 0;
if (file_exists($checkpointFile)) {
    $startLine = (int)trim(file_get_contents($checkpointFile));
}

$handle = fopen($sqlFile, 'r');
if (!$handle) die("Kann Datei nicht oeffnen\n");

$totalRows = 0;
$processed = 0;
$errors = 0;
$currentLine = 0;
$startTime = time();
$maxTime = 25; // Max 25 Sekunden laufen
$maxQueries = 30; // Oder max 30 INSERTS

while (($line = fgets($handle)) !== false) {
    $currentLine++;
    if ($currentLine <= $startLine) continue;
    
    $line = trim($line);
    if (empty($line)) continue;

    if (strpos($line, 'INSERT INTO `releases`') === 0) {
        $sql = str_replace('INSERT INTO `releases`', 'INSERT IGNORE INTO predb_backup_releases', $line);
        $sql = rtrim($sql, ';');

        if ($conn->query($sql)) {
            $totalRows += $conn->affected_rows;
            $processed++;
        } else {
            $errors++;
        }

        // Checkpoint speichern
        file_put_contents($checkpointFile, $currentLine);

        // Check ob Zeitlimit erreicht
        if ($processed >= $maxQueries || (time() - $startTime) >= $maxTime) {
            break;
        }
    }
}

fclose($handle);

echo "Durchlauf: {$processed} queries, " . number_format($totalRows) . " rows, {$errors} errors\n";
echo "Checkpoint: Zeile {$currentLine}\n";

$result = $conn->query("SELECT COUNT(*) as cnt FROM predb_backup_releases");
echo "DB Gesamt: " . number_format($result->fetch_assoc()['cnt']) . "\n";
$conn->close();

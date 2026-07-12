<?php
/**
 * PreDB Import mit Fortsetzungsfunktion
 * Speichert nach jedem INSERT den Fortschritt
 */

set_time_limit(0);
ini_set('memory_limit', '1024M');

$sqlFile = __DIR__ . '/../sql/backup.sql';
$progressFile = __DIR__ . '/import_progress.txt';

$conn = new mysqli('localhost', 'st75757_roun131', '5S8(5FT[8p', 'st75757_roun131');
if ($conn->connect_error) die("Fehler: " . $conn->connect_error);

// Wie viele INSERTS wurden schon verarbeitet?
$skipCount = 0;
if (file_exists($progressFile)) {
    $skipCount = (int)trim(file_get_contents($progressFile));
}

echo date('H:i:s') . " Starte ab INSERT #{$skipCount}\n";

$handle = fopen($sqlFile, 'r');
if (!$handle) die("Kann Datei nicht oeffnen\n");

$totalRows = 0;
$processed = 0;
$errors = 0;
$startTime = time();

while (($line = fgets($handle)) !== false) {
    $line = trim($line);
    if (empty($line)) continue;

    if (strpos($line, 'INSERT INTO `releases`') === 0) {
        $processed++;
        
        // Bereits verarbeitete überspringen
        if ($processed <= $skipCount) continue;
        
        $sql = str_replace('INSERT INTO `releases`', 'INSERT IGNORE INTO predb_backup_releases', $line);
        $sql = rtrim($sql, ';');

        if ($conn->query($sql)) {
            $totalRows += $conn->affected_rows;
        } else {
            $errors++;
        }

        // Fortschritt speichern
        file_put_contents($progressFile, $processed);

        if ($processed % 25 === 0) {
            $elapsed = time() - $startTime;
            $rate = $elapsed > 0 ? round($totalRows / $elapsed) : 0;
            $pct = round($processed / 527 * 100);
            echo date('H:i:s') . " {$pct}% | #{$processed} | +" . number_format($totalRows) . " rows | {$rate}/s | err:{$errors}\n";
        }
    }
}

fclose($handle);
$elapsed = time() - $startTime;

echo "\n" . date('H:i:s') . " === FERTIG ===\n";
echo "Dauer: " . gmdate('H:i:s', $elapsed) . "\n";
echo "Neu importiert: " . number_format($totalRows) . " rows\n";
echo "Errors: {$errors}\n";

$result = $conn->query("SELECT COUNT(*) as cnt FROM predb_backup_releases");
echo "DB Gesamt: " . number_format($result->fetch_assoc()['cnt']) . "\n";
$conn->close();

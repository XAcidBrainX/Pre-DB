<?php
/**
 * PreDB Backup Final Import
 * Verwendet INSERT IGNORE und korrekte String-Replacements
 */

$sqlFile = '/home/st75757/domains/st75757.ispot.cc/public_html/backup.sql';

$conn = new mysqli('localhost', 'st75757_roun131', '5S8(5FT[8p', 'st75757_roun131');
if ($conn->connect_error) die("Fehler: " . $conn->connect_error);

echo date('H:i:s') . " Verbunden\n";
echo date('H:i:s') . " Datei: " . number_format(filesize($sqlFile), 0, ',', '.') . " Bytes\n";

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
        // INSERT INTO `releases` -> INSERT IGNORE INTO predb_backup_releases
        $sql = str_replace('INSERT INTO `releases`', 'INSERT IGNORE INTO predb_backup_releases', $line);
        $sql = rtrim($sql, ';');

        if ($conn->query($sql)) {
            $totalRows += $conn->affected_rows;
            $processed++;
        } else {
            $errors++;
            if ($errors <= 3) {
                echo date('H:i:s') . " FEHLER: " . $conn->error . "\n";
                echo "SQL: " . substr($sql, 0, 200) . "...\n";
            }
        }

        if ($processed % 50 === 0) {
            $elapsed = time() - $startTime;
            $rate = $elapsed > 0 ? round($totalRows / $elapsed) : 0;
            $pct = round($processed / 527 * 100);
            echo date('H:i:s') . " {$pct}% | {$processed}q | " . number_format($totalRows) . " rows | {$rate}/s | err:{$errors}\n";
        }
    }
}

fclose($handle);
$elapsed = time() - $startTime;

echo "\n";
echo date('H:i:s') . " === FERTIG ===\n";
echo "Dauer: " . gmdate('H:i:s', $elapsed) . "\n";
echo "Queries: {$processed}\n";
echo "Rows: " . number_format($totalRows) . "\n";
echo "Errors: {$errors}\n";

$result = $conn->query("SELECT COUNT(*) as cnt FROM predb_backup_releases");
echo "DB Count: " . number_format($result->fetch_assoc()['cnt']) . "\n";

// Statistik
$result = $conn->query("SELECT Section, COUNT(*) as cnt FROM predb_backup_releases GROUP BY Section ORDER BY cnt DESC LIMIT 15");
echo "\nTop Sections:\n";
while ($row = $result->fetch_assoc()) {
    echo "  " . str_pad($row['Section'], 20) . " " . number_format($row['cnt']) . "\n";
}

$conn->close();

<?php
require_once __DIR__ . '/config.php';

echo "Erstelle predb_backup_releases...\n";
$sql = 'CREATE TABLE IF NOT EXISTS predb_backup_releases (
  ID int(10) unsigned NOT NULL AUTO_INCREMENT,
  Pretime int(11) NOT NULL DEFAULT 0,
  ReleaseName varchar(160) NOT NULL DEFAULT "",
  Section varchar(20) NOT NULL DEFAULT "",
  Files varchar(10) NOT NULL,
  Size varchar(10) NOT NULL,
  Status int(2) NOT NULL DEFAULT 0,
  Nukereason varchar(255) NOT NULL DEFAULT "",
  Genre varchar(20) NOT NULL DEFAULT "",
  RlsGroup varchar(20) NOT NULL DEFAULT "",
  PRIMARY KEY (ID),
  UNIQUE KEY release_name (ReleaseName),
  KEY grp (RlsGroup)
) ENGINE=MyISAM DEFAULT CHARSET=latin1';

if ($conn->query($sql)) {
    echo "  ✅ predb_backup_releases erstellt\n";
} else {
    echo "  ❌ " . $conn->error . "\n";
}

// Check if predb_groups exists
$result = $conn->query("SHOW TABLES LIKE 'predb_groups'");
if ($result && $result->num_rows > 0) {
    echo "  ✅ predb_groups existiert bereits\n";
} else {
    echo "  ⚠️ predb_groups existiert nicht - erstelle...\n";
    $sql2 = "CREATE TABLE IF NOT EXISTS predb_groups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if ($conn->query($sql2)) {
        echo "  ✅ predb_groups erstellt\n";
    } else {
        echo "  ❌ " . $conn->error . "\n";
    }
}

$conn->close();

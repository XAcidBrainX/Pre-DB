<?php
/**
 * FortKnox PreDB IRC Bot Export Script
 * 
 * Erstellt ein portables Bot-Paket (.zip) zum Umzug auf einen anderen Server.
 * Enthält: ircbot.php, ircbot_config.php, config.php, setup.sh
 */

require_once 'config.php';

if (!isLoggedIn() || !isAdmin()) {
    redirect('login.php');
}

// Dateien, die ins Paket sollen
$files = [
    'ircbot.php',
    'ircbot_config.php',
    'config.php',
];

// Prüfen ob alle Dateien existieren
$missing = [];
foreach ($files as $f) {
    if (!file_exists($f)) {
        $missing[] = $f;
    }
}

if (!empty($missing)) {
    die('❌ Fehlende Dateien: ' . implode(', ', $missing));
}

// Setup-Script Inhalt
$setupScript = <<<'SETUP'
#!/bin/bash
# FortKnox PreDB IRC Bot - Setup für neuen Server
# Führe aus: chmod +x setup.sh && ./setup.sh

echo "╔══════════════════════════════════════╗"
echo "║   FortKnox PreDB IRC Bot Setup       ║"
echo "╚══════════════════════════════════════╝"
echo ""

# PHP prüfen
if ! command -v php &> /dev/null; then
    echo "❌ PHP ist nicht installiert."
    echo "   Installiere: apt install php-cli php-mysqli"
    exit 1
fi

PHP_VER=$(php -r "echo PHP_VERSION;")
echo "✅ PHP $PHP_VER gefunden"

# Prüfe ob config.php angepasst werden muss
if grep -q "XXXX" config.php 2>/dev/null; then
    echo ""
    echo "⚠️  Die config.php enthält noch alte Zugangsdaten."
    echo "   Bitte bearbeite config.php und trage die neuen DB-Daten ein."
    echo "   Oder kopiere die config.php vom Hauptserver."
    echo ""
fi

# Prüfe ircbot_config.php
if grep -q "PreBot" ircbot_config.php 2>/dev/null; then
    echo "✅ ircbot_config.php vorhanden"
fi

echo ""
echo "🚀 Starte Bot mit:"
echo "   php ircbot.php              (interaktiv)"
echo "   php ircbot.php --daemon     (Hintergrund)"
echo "   php ircbot.php --export     (Konfiguration anzeigen)"
echo ""
echo "🌐 Web-Admin: http://deine-domain.com/admin.php"
echo ""

# Optional: .env erstellen
if [ ! -f .env ]; then
    echo "IRC_SERVER=XXXX" > .env
    echo "IRC_PORT=6667" >> .env
    echo "IRC_NICK=PreBot" >> .env
    echo "✅ .env Datei erstellt"
fi

echo ""
echo "✅ Setup abgeschlossen!"
SETUP;

// ZIP erstellen
$zipFilename = 'ircbot_package_' . date('Y-m-d_H-i') . '.zip';
$zipPath = sys_get_temp_dir() . '/' . $zipFilename;

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
    die('❌ Konnte ZIP nicht erstellen.');
}

// Bot-Dateien hinzufügen
foreach ($files as $f) {
    $content = file_get_contents($f);
    // Keine Sensiblen Daten mehr – alles bleibt wie in den Originaldateien
    $zip->addFromString($f, $content);
}

// setup.sh hinzufügen
$zip->addFromString('setup.sh', $setupScript);

// README.md hinzufügen
$readme = <<<'README'
# FortKnox PreDB IRC Bot – Portable Edition v2.0

## Installation
1. Dateien per FTP/SSH auf den Zielserver kopieren
2. `chmod +x setup.sh && ./setup.sh` ausführen
3. `config.php` mit DB-Daten anpassen (falls nötig)
4. Bot starten: `php ircbot.php --daemon`

## Features
- IRC Blowfish/FiSH Verschlüsselung
- Server-Passwort Unterstützung
- NickServ Identifikation
- Automatische Release-Announcements
- Befehle: !latest, !search, !stats, !help
- Daemon-Modus mit PID-Datei

## Anforderungen
- PHP 7.4 oder höher
- PHP MySQLi Erweiterung
- Shell-Zugriff (für Daemon-Modus)

## Web-Admin
Der Bot wird über das Admin-Panel verwaltet:
http://deine-domain.com/admin.php?action=ircbot
README;

$zip->addFromString('README.md', $readme);

$zip->close();

// ZIP ausliefern
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipFilename . '"');
header('Content-Length: ' . filesize($zipPath));
header('Pragma: no-cache');
header('Expires: 0');

readfile($zipPath);

// Aufräumen
@unlink($zipPath);
exit;

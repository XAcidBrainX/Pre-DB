#!/bin/bash
# =============================================================================
# FortKnox PreDB - Debian 12 (Bookworm) Deploy-Skript
# =============================================================================
# Dieses Skript richtet alles automatisch ein:
#   - Apache2 + PHP 8.2 + MariaDB
#   - Datenbank + User
#   - Projekt-Dateien kopieren
#   - config.php anpassen
#   - Apache VirtualHost
#   - Cron-Job für sync.php
#   - Optional: IRC-Bot als systemd-Service
#
# Aufruf:
#   sudo bash deploy_debian.sh
#
# Hinweis: Das Projekt-Verzeichnis muss bereits auf dem Server sein
# oder per Git geklont werden.
# =============================================================================

set -e

# Farben für Ausgaben
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

info()  { echo -e "${BLUE}[INFO]${NC} $1"; }
ok()    { echo -e "${GREEN}[OK]${NC}   $1"; }
warn()  { echo -e "${YELLOW}[WARN]${NC} $1"; }
err()   { echo -e "${RED}[ERR]${NC}  $1"; }

# =============================================================================
# KONFIGURATION – Hier anpassen!
# =============================================================================

# Projekt-Verzeichnis (wo das Skript liegt oder Zielpfad)
PROJECT_DIR="$(cd "$(dirname "$0")" && pwd)"

# Web-Root (Ziel-Verzeichnis für die Installation)
TARGET_DIR="/var/www/predb"

# Domain / ServerName für Apache
DOMAIN="predb.dnsabr.com"

# Admin-E-Mail für Apache
ADMIN_EMAIL="admin@${DOMAIN}"

# Datenbank-Konfiguration
DB_NAME="predb"
DB_USER="predb_user"
DB_PASS="$(openssl rand -base64 24 2>/dev/null || echo 'mein_sicheres_passwort_123')"

# PHP-Version (8.2 empfohlen)
PHP_VERSION="8.2"

# =============================================================================
# PRÜFUNGEN
# =============================================================================

# Nur als root ausführen
if [ "$(id -u)" -ne 0 ]; then
    err "Dieses Skript muss als root ausgeführt werden! (sudo bash deploy_debian.sh)"
    exit 1
fi

echo ""
echo "╔══════════════════════════════════════════════════════════════╗"
echo "║    🚀  FortKnox PreDB - Debian Deploy-Skript v1.0        ║"
echo "╚══════════════════════════════════════════════════════════════╝"
echo ""

info "Projekt-Verzeichnis: ${PROJECT_DIR}"
info "Ziel-Verzeichnis:    ${TARGET_DIR}"
info "Domain:              ${DOMAIN}"
info "Datenbank:           ${DB_NAME}"
info "PHP-Version:         ${PHP_VERSION}"
echo ""

# Prüfen ob Projekt-Dateien vorhanden sind
if [ ! -f "${PROJECT_DIR}/config.php" ] && [ ! -f "${PROJECT_DIR}/index.php" ]; then
    warn "Keine Projekt-Dateien in ${PROJECT_DIR} gefunden."
    echo ""
    echo "Mögliche Quellen:"
    echo "  1) Dieses Skript liegt bereits im Projekt-Verzeichnis"
    echo "  2) Per Git klonen:"
    echo "     git clone <repository> ${PROJECT_DIR}"
    echo "  3) Dateien manuell per SCP/rsync hochladen"
    echo ""
    read -rp "Weiter mit manuellem Pfad? [Pfad eingeben oder Enter = Abbruch]: " MANUAL_PATH
    if [ -n "$MANUAL_PATH" ] && [ -f "${MANUAL_PATH}/index.php" ]; then
        PROJECT_DIR="$MANUAL_PATH"
        info "Verwende: ${PROJECT_DIR}"
    else
        err "Keine gültigen Projekt-Dateien gefunden. Abbruch."
        exit 1
    fi
fi

# =============================================================================
# INSTALLATION DER PAKETE
# =============================================================================

install_packages() {
    echo ""
    info "======================================="
    info " 1/8: System-Pakete installieren"
    info "======================================="
    
    apt update -qq
    
    # Apache + Tools
    apt install -y apache2 apache2-utils wget curl unzip git
    
    # MariaDB (MySQL-kompatibel)
    apt install -y mariadb-server mariadb-client
    
    # PHP 8.2 + Erweiterungen
    apt install -y php${PHP_VERSION} php${PHP_VERSION}-cli php${PHP_VERSION}-mysql \
        php${PHP_VERSION}-mbstring php${PHP_VERSION}-curl php${PHP_VERSION}-gd \
        php${PHP_VERSION}-xml php${PHP_VERSION}-zip php${PHP_VERSION}-intl \
        php${PHP_VERSION}-bcmath php${PHP_VERSION}-sockets php${PHP_VERSION}-pcntl \
        php${PHP_VERSION}-posix php${PHP_VERSION}-dom
    
    # libapache2-mod-php für Apache
    apt install -y libapache2-mod-php${PHP_VERSION}
    
    # Optional: redis, memcached
    # apt install -y php${PHP_VERSION}-redis php${PHP_VERSION}-memcached
    
    ok "Pakete installiert"
}

# =============================================================================
# PHP-KONFIGURATION
# =============================================================================

configure_php() {
    echo ""
    info "======================================="
    info " 2/8: PHP-Konfiguration anpassen"
    info "======================================="
    
    PHP_INI_CLI="/etc/php/${PHP_VERSION}/cli/php.ini"
    PHP_INI_APACHE="/etc/php/${PHP_VERSION}/apache2/php.ini"
    
    for ini in "$PHP_INI_CLI" "$PHP_INI_APACHE"; do
        if [ -f "$ini" ]; then
            # Wichtige Werte setzen
            sed -i 's/^memory_limit = .*/memory_limit = 512M/' "$ini"
            sed -i 's/^max_execution_time = .*/max_execution_time = 180/' "$ini"
            sed -i 's/^max_input_time = .*/max_input_time = 120/' "$ini"
            sed -i 's/^post_max_size = .*/post_max_size = 64M/' "$ini"
            sed -i 's/^upload_max_filesize = .*/upload_max_filesize = 64M/' "$ini"
            sed -i 's/^display_errors = .*/display_errors = Off/' "$ini"
            sed -i 's/^error_reporting = .*/error_reporting = E_ALL \& ~E_DEPRECATED \& ~E_STRICT/' "$ini"
            
            # Ausgabe: Erlaube更大 Speicher für file_get_contents
            sed -i 's/^allow_url_fopen = .*/allow_url_fopen = On/' "$ini"
            
            ok "PHP-Ini bearbeitet: ${ini}"
        else
            warn "PHP-Ini nicht gefunden: ${ini}"
        fi
    done
    
    # PHP-OpCache aktivieren
    apt install -y php${PHP_VERSION}-opcache
    
    ok "PHP-Konfiguration abgeschlossen"
}

# =============================================================================
# MARIADB / DATENBANK
# =============================================================================

setup_database() {
    echo ""
    info "======================================="
    info " 3/8: MariaDB/Datenbank einrichten"
    info "======================================="
    
    # MariaDB starten
    systemctl enable mariadb
    systemctl start mariadb
    
    # Datenbank + User anlegen (idempotent)
    mysql -u root <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` 
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' 
    IDENTIFIED BY '${DB_PASS}';

GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
    
    ok "Datenbank '${DB_NAME}' und User '${DB_USER}' angelegt"
    
    # Zugangsdaten in Datei speichern (für Referenz)
    cat > /root/.predb_db_credentials.txt <<EOF
========================================
FortKnox PreDB - Datenbank-Zugangsdaten
========================================
Host:     localhost
Datenbank: ${DB_NAME}
Benutzer: ${DB_USER}
Passwort: ${DB_PASS}
========================================
EOF
    chmod 600 /root/.predb_db_credentials.txt
    
    ok "Zugangsdaten gespeichert: /root/.predb_db_credentials.txt"
}

# =============================================================================
# PROJEKT-DATEIEN KOPIEREN
# =============================================================================

copy_project() {
    echo ""
    info "======================================="
    info " 4/8: Projekt-Dateien kopieren"
    info "======================================="
    
    # Ziel-Verzeichnis erstellen
    mkdir -p "${TARGET_DIR}"
    
    # Dateien kopieren (mit rsync oder cp)
    if command -v rsync &>/dev/null; then
        rsync -av --exclude='.git' --exclude='deploy_debian.sh' \
            "${PROJECT_DIR}/" "${TARGET_DIR}/"
    else
        cp -r "${PROJECT_DIR}"/* "${TARGET_DIR}/" 2>/dev/null || true
        cp -r "${PROJECT_DIR}"/.* "${TARGET_DIR}/" 2>/dev/null || true
    fi
    
    # config.php entfernen (wird neu erstellt)
    rm -f "${TARGET_DIR}/config.php"
    
    ok "Projekt-Dateien kopiert nach: ${TARGET_DIR}"
}

# =============================================================================
# config.php ERSTELLEN
# =============================================================================

create_config() {
    echo ""
    info "======================================="
    info " 5/8: config.php erstellen"
    info "======================================="
    
    # config.php ohne ini_set (weil auf manchen Systemen deaktiviert)
    cat > "${TARGET_DIR}/config.php" <<'PHPEOF'
<?php
/**
 * FortKnox PreDB - Konfiguration
 * Automatisch generiert von deploy_debian.sh
 */

session_start();

// Datenbank Verbindung
$db_host = 'localhost';
$db_user = 'DB_USER_PLACEHOLDER';
$db_pass = 'DB_PASS_PLACEHOLDER';
$db_name = 'DB_NAME_PLACEHOLDER';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    die('Datenbankverbindung fehlgeschlagen: ' . $conn->connect_error);
}

$conn->set_charset('utf8mb4');

// Einstellungen
date_default_timezone_set('Europe/Berlin');
error_reporting(E_ALL);

// ini_set nur verwenden wenn verfügbar
if (function_exists('ini_set')) {
    ini_set('display_errors', 0);
}

define('SITE_NAME', 'FortKnox PreDB');
define('SITE_TITLE', 'FortKnox PreDB › Deutsche Scene Release Datenbank & NFO Quelltext');
define('ITEMS_PER_PAGE', 50);
define('DB_PREFIX', 'predb_');

// Hilfsfunktionen
function h($str) {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function formatDate($date) {
    return date('d.m.Y H:i', strtotime($date));
}

function formatSize($size) {
    if (!$size) return '-';
    return $size;
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

function redirect($url) {
    header('Location: ' . $url);
    exit;
}
PHPEOF

    # Platzhalter ersetzen
    sed -i "s/DB_USER_PLACEHOLDER/${DB_USER}/g" "${TARGET_DIR}/config.php"
    sed -i "s|DB_PASS_PLACEHOLDER|${DB_PASS}|g" "${TARGET_DIR}/config.php"
    sed -i "s/DB_NAME_PLACEHOLDER/${DB_NAME}/g" "${TARGET_DIR}/config.php"
    
    ok "config.php erstellt"
}

# =============================================================================
# APACHE VIRTUALHOST
# =============================================================================

setup_apache() {
    echo ""
    info "======================================="
    info " 6/8: Apache VirtualHost einrichten"
    info "======================================="
    
    # Apache-Module aktivieren
    a2enmod rewrite
    a2enmod headers
    
    # VirtualHost erstellen
    cat > /etc/apache2/sites-available/predb.conf <<APACHEEOF
<VirtualHost *:80>
    ServerName ${DOMAIN}
    ServerAdmin ${ADMIN_EMAIL}
    DocumentRoot ${TARGET_DIR}
    
    <Directory ${TARGET_DIR}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    
    # Sicherheits-Header
    <IfModule mod_headers.c>
        Header always set X-Content-Type-Options "nosniff"
        Header always set X-Frame-Options "DENY"
        Header always set X-XSS-Protection "1; mode=block"
    </IfModule>
    
    # Zugriff auf .git verweigern
    <DirectoryMatch "^/.*\.git">
        Require all denied
    </DirectoryMatch>
    
    # Logging
    ErrorLog \${APACHE_LOG_DIR}/predb_error.log
    CustomLog \${APACHE_LOG_DIR}/predb_access.log combined
    
    # Caching für statische Dateien
    <FilesMatch "\.(css|js|ico|png|jpg|jpeg|gif|svg|woff|woff2)$">
        Header set Cache-Control "max-age=2592000, public"
    </FilesMatch>
</VirtualHost>
APACHEEOF

    # Default-Site deaktivieren, unsere aktivieren
    a2dissite 000-default.conf 2>/dev/null || true
    a2ensite predb.conf
    
    # Apache neustarten
    systemctl restart apache2
    
    ok "Apache VirtualHost eingerichtet: ${DOMAIN}"
}

# =============================================================================
# BERECHTIGUNGEN
# =============================================================================

set_permissions() {
    echo ""
    info "======================================="
    info " 7/8: Berechtigungen setzen"
    info "======================================="
    
    # Owner: www-data
    chown -R www-data:www-data "${TARGET_DIR}"
    
    # Verzeichnisse: 755
    find "${TARGET_DIR}" -type d -exec chmod 755 {} \;
    
    # PHP-Dateien: 644
    find "${TARGET_DIR}" -name "*.php" -exec chmod 644 {} \;
    
    # Statische Dateien: 644
    find "${TARGET_DIR}" -name "*.css" -o -name "*.js" -o -name "*.html" | xargs chmod 644 2>/dev/null || true
    
    # Beschreibbare Dateien für den Web-Server
    chmod 664 "${TARGET_DIR}/config.php"
    
    # Log/Tracker-Dateien (erstellen falls nicht vorhanden)
    for f in ircbot.pid ircbot_state.txt ircbot.log ircbot_error.log \
             ircbot_config.php sync.log import_predb_progress.txt \
             import_progress.txt predbnet_tracker.txt predbme_tracker.txt; do
        touch "${TARGET_DIR}/$f" 2>/dev/null || true
        chmod 664 "${TARGET_DIR}/$f"
        chown www-data:www-data "${TARGET_DIR}/$f"
    done
    
    ok "Berechtigungen gesetzt"
}

# =============================================================================
# CRON + IRC-BOT SERVICE
# =============================================================================

setup_cron() {
    echo ""
    info "======================================="
    info " 8/8: Cron-Job + IRC-Bot Service"
    info "======================================="
    
    # Cron-Job für sync.php (alle 5 Minuten)
    CRON_JOB="*/5 * * * * php ${TARGET_DIR}/sync.php >> ${TARGET_DIR}/sync.log 2>&1"
    
    # Prüfen ob Job bereits existiert
    EXISTING_CRON=$(crontab -u www-data -l 2>/dev/null || true)
    if echo "$EXISTING_CRON" | grep -q "sync.php"; then
        info "Cron-Job für sync.php existiert bereits"
    else
        (echo "$EXISTING_CRON"; echo "$CRON_JOB") | crontab -u www-data -
        ok "Cron-Job eingerichtet: alle 5 Minuten sync.php"
    fi
    
    # IRC-Bot systemd Service (optional)
    cat > /etc/systemd/system/predb-ircbot.service <<SERVICEEOF
[Unit]
Description=FortKnox PreDB IRC Bot
After=network.target mariadb.service
Wants=mariadb.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=${TARGET_DIR}
ExecStart=/usr/bin/php${PHP_VERSION} ${TARGET_DIR}/ircbot.php
Restart=on-failure
RestartSec=15
StandardOutput=append:${TARGET_DIR}/ircbot.log
StandardError=append:${TARGET_DIR}/ircbot_error.log

# Schutz-Einstellungen
NoNewPrivileges=true
ProtectSystem=full
ProtectHome=true
PrivateTmp=true

[Install]
WantedBy=multi-user.target
SERVICEEOF

    systemctl daemon-reload
    
    # Nur aktivieren, wenn gewünscht (Benutzer muss Config anpassen!)
    warn "IRC-Bot Service wurde angelegt, ist aber NICHT aktiviert."
    warn "Zum Aktivieren: systemctl enable --now predb-ircbot"
    warn "Vorher ircbot_config.php anpassen!"
    
    ok "Cron-Job und IRC-Bot Service eingerichtet"
}

# =============================================================================
# ZUSAMMENFASSUNG
# =============================================================================

show_summary() {
    echo ""
    echo "╔══════════════════════════════════════════════════════════════╗"
    echo "║   ✅  Debian-Deploy abgeschlossen!                       ║"
    echo "╚══════════════════════════════════════════════════════════════╝"
    echo ""
    echo "  🌐 Webseite:    http://${DOMAIN}/"
    echo "  📁 Pfad:        ${TARGET_DIR}"
    echo ""
    echo "  🗄️  Datenbank:  ${DB_NAME}"
    echo "  👤 Benutzer:    ${DB_USER}"
    echo "  🔑 Passwort:    ${DB_PASS}"
    echo "     (auch in /root/.predb_db_credentials.txt)"
    echo ""
    echo "  📋 Nächste Schritte:"
    echo "  1. DNS-Eintrag für ${DOMAIN} auf diese IP setzen"
    echo "  2. Falls SSL gewünscht: sudo apt install certbot python3-certbot-apache"
    echo "     sudo certbot --apache -d ${DOMAIN}"
    echo "  3. Webseite aufrufen und Installation starten:"
    echo "     http://${DOMAIN}/install.php"
    echo "     (Datenbank-Zugangsdaten von oben verwenden)"
    echo "  4. IRC-Bot konfigurieren (optional):"
    echo "     nano ${TARGET_DIR}/ircbot_config.php"
    echo "     sudo systemctl enable --now predb-ircbot"
    echo "  5. phpMyAdmin/Adminer: ${TARGET_DIR}/adminer/adminer.php"
    echo ""
    echo "  ⚠️  Wichtig: Nach der Installation per install.php"
    echo "     unbedingt install.php löschen oder schützen!"
    echo "     sudo rm ${TARGET_DIR}/install.php"
    echo ""
}

# =============================================================================
# HAUPTFUNKTION
# =============================================================================

main() {
    echo ""
    info "Starte Debian-Deploy für FortKnox PreDB..."
    echo ""
    
    # Bestätigung
    echo "Folgende Schritte werden ausgeführt:"
    echo "  1. System-Pakete installieren (Apache, PHP ${PHP_VERSION}, MariaDB)"
    echo "  2. PHP-Konfiguration anpassen"
    echo "  3. MariaDB Datenbank + User anlegen"
    echo "  4. Projekt-Dateien nach ${TARGET_DIR} kopieren"
    echo "  5. config.php mit DB-Zugangsdaten erstellen"
    echo "  6. Apache VirtualHost für ${DOMAIN} einrichten"
    echo "  7. Berechtigungen setzen"
    echo "  8. Cron-Job + IRC-Bot Service einrichten"
    echo ""
    read -rp "Fortfahren? [j/N]: " CONFIRM
    if [[ ! "$CONFIRM" =~ ^[jJyY] ]]; then
        err "Abgebrochen."
        exit 1
    fi
    
    # Schritte ausführen
    install_packages
    configure_php
    setup_database
    copy_project
    create_config
    setup_apache
    set_permissions
    setup_cron
    
    # Zusammenfassung
    show_summary
    
    ok "Deploy erfolgreich abgeschlossen!"
}

main "$@"

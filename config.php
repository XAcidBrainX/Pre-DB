<?php
/**
 * FortKnox PreDB - Deutsche Scene Release Datenbank & NFO Quelltext
 * Konfiguration
 */

session_start();

// Datenbank Verbindung
$db_host = 'localhost';
$db_user = 'st75757_roun131';
$db_pass = '5S8(5FT[8p';
$db_name = 'st75757_roun131';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    die('Datenbankverbindung fehlgeschlagen: ' . $conn->connect_error);
}

$conn->set_charset('utf8mb4');

// Einstellungen
date_default_timezone_set('Europe/Berlin');
error_reporting(E_ALL);

// ini_set nur verwenden wenn verfügbar (z. B. auf manchen Hostings deaktiviert)
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

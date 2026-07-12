<?php
/**
 * FortKnox PreDB Multi-Source Auto-Sync
 * Holt Releases von predb.net (API), m2v.ru (HTML) und predb.me (HTML) und importiert sie.
 * 
 * Aufruf: php sync.php
 * Als Cron: alle 5 Minuten
 */

set_time_limit(180);
ini_set('memory_limit', '512M');

require_once __DIR__ . '/config.php';

// -----------------------------------------------------------------------
// Konfiguration
// -----------------------------------------------------------------------
define('TRACKER_FILE', __DIR__ . '/predbnet_tracker.txt');
define('PREDBME_TRACKER_FILE', __DIR__ . '/predbme_tracker.txt');
define('XRELDOTTO_TRACKER_FILE', __DIR__ . '/xrelto_tracker.txt');

// Section → category_id Mapping (predb.net Section → unsere category_id)
$sectionMap = [
    'GAMES' => 1, 'GAME' => 1, 'PS2' => 1, 'PS3' => 1, 'PSP' => 1,
    'NDS' => 1, 'GBA' => 1, 'N_GAGE' => 1, 'XBOX' => 1, 'XBOX360' => 1,
    'WII' => 1, 'NGC' => 1, 'DC' => 1, 'PC' => 1, 'GBC' => 1, 'PSX' => 1,
    'PS4' => 1, 'PS5' => 1, 'SWITCH' => 1,
    'DVDR' => 2, 'DVD-R' => 2, 'XVID' => 2, 'DIVX' => 2, 'X264' => 2,
    'AC3' => 2, 'SVCD' => 2, 'VCD' => 2, 'MDVDR' => 2, 'BLURAY' => 2,
    'HDTV' => 2, '1080P' => 2, '720P' => 2, 'M1080' => 2, 'M720' => 2,
    'MOVIE' => 2, 'MOVIES' => 2,
    'MP3' => 3, 'MVID' => 3, 'FLAC' => 3, 'WAV' => 3, 'WV' => 3, 'MUSIC' => 3,
    '0DAY' => 4, 'APPS' => 4, 'APP' => 4, 'WIN' => 4, 'DOX' => 4,
    'LINUX' => 4, 'MAC' => 4, 'MOBILE' => 4, 'PDA' => 4, 'PALM' => 4,
    'COVERS' => 4, 'TEMPLATES' => 4, 'DOCS' => 4,
    'TV' => 5, 'EPISODE' => 5,
    'EBOOK' => 6, 'EBOOKS' => 6, 'BOOKS' => 6,
    'XXX' => 7, 'ADULT' => 7,
    'SUBPACK' => 8, 'IMAGE' => 8, 'SUBS' => 8,
];

// m2v.ru Kategorie-Mapping (section-Slug → unsere category_id)
$m2vCatMap = [
    '0day'              => 4,   // Anwendungen
    'appz'              => 4,   // Anwendungen (Tutorials)
    'mp3-flac'          => 3,   // Musik
    'movies-sd'         => 2,   // Filme
    'movies-hd'         => 2,   // Filme
    'movies-dvdr'       => 2,   // Filme
    'tv-hd'             => 5,   // Serien
    'games-pc'          => 1,   // Spiele
    'games-playstation' => 1,   // Spiele
    'games-nsw'         => 1,   // Spiele
    'games-xbox'        => 1,   // Spiele
    'games-console'     => 1,   // Spiele
    'games-wii'         => 1,   // Spiele
    'games-psp'         => 1,   // Spiele
    'xxx-0day'          => 7,   // XXX
    'music-video'       => 3,   // Musik
    'ebooks-audiobooks' => 6,   // E-Books
];

// predb.me Kategorie-Mapping (Slug → unsere category_id)
$predbMeCatMap = [
    'movies-sd'         => 2,
    'movies-hd'         => 2,
    'movies-discs'      => 2,
    'tv-sd'             => 5,
    'tv-hd'             => 5,
    'tv-discs'          => 5,
    'music-audio'       => 3,
    'music-videos'      => 3,
    'music-discs'       => 3,
    'games-pc'          => 1,
    'games-xbox'        => 1,
    'games-playstation' => 1,
    'games-nintendo'    => 1,
    'apps-windows'      => 4,
    'apps-linux'        => 4,
    'apps-mac'          => 4,
    'apps-mobile'       => 4,
    'books-ebooks'      => 6,
    'books-audio-books' => 6,
    'xxx-videos'        => 7,
    'xxx-images'        => 7,
    'dox'               => 8,
    'unknown'           => 8,
];

// xrel.to Kategorie-Mapping (API section → unsere category_id)
$xrelCatMap = [
    'games'             => 1,
    'console'           => 1,
    'pc'                => 1,
    'ps3'               => 1,
    'ps4'               => 1,
    'ps5'               => 1,
    'psp'               => 1,
    'xbox360'           => 1,
    'xboxone'           => 1,
    'xboxseries'        => 1,
    'wii'               => 1,
    'nds'               => 1,
    '3ds'               => 1,
    'switch'            => 1,
    'movies'            => 2,
    'movie'             => 2,
    'video'             => 2,
    'bluray'            => 2,
    'dvd'               => 2,
    'hd'                => 2,
    'uhd'               => 2,
    'music'             => 3,
    'mp3'               => 3,
    'flac'              => 3,
    'audio'             => 3,
    'apps'              => 4,
    'app'               => 4,
    'application'       => 4,
    '0day'              => 4,
    'tutorial'          => 4,
    'tv'                => 5,
    'series'            => 5,
    'episode'           => 5,
    'books'             => 6,
    'ebook'             => 6,
    'magazine'          => 6,
    'xxx'               => 7,
    'adult'             => 7,
    'misc'              => 8,
    'other'             => 8,
    'image'             => 8,
];

$defaultCategoryId = 8; // Sonstiges

// -----------------------------------------------------------------------
// Hilfsfunktionen
// -----------------------------------------------------------------------

function getOrCreateGroup($conn, $groupName) {
    if (empty($groupName)) return null;
    $stmt = $conn->prepare("SELECT id FROM " . DB_PREFIX . "groups WHERE name = ?");
    $stmt->bind_param('s', $groupName);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) return (int)$row['id'];
    $stmt = $conn->prepare("INSERT INTO " . DB_PREFIX . "groups (name) VALUES (?)");
    $stmt->bind_param('s', $groupName);
    if ($stmt->execute()) return (int)$conn->insert_id;
    return null;
}

function fetchUrl($url, $timeout = 15) {
    return @file_get_contents($url, false, stream_context_create([
        'http' => [
            'timeout' => $timeout,
            'user_agent' => 'Mozilla/5.0 (compatible; FortKnox PreDB-Sync/1.0)',
        ]
    ]));
}

function importReleases($conn, $releases, &$imported, &$skipped, &$groupsCreated) {
    $checkStmt = $conn->prepare("SELECT id FROM " . DB_PREFIX . "releases WHERE name = ? LIMIT 1");
    $insertStmt = $conn->prepare(
        "INSERT IGNORE INTO " . DB_PREFIX . "releases 
         (name, category_id, group_id, size, files, source_url, created_at) 
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    
    foreach ($releases as $rel) {
        $checkStmt->bind_param('s', $rel['name']);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows > 0) { $skipped++; continue; }
        
        $groupId = null;
        if ($rel['group_name']) {
            $groupId = getOrCreateGroup($conn, $rel['group_name']);
            if ($groupId) $groupsCreated++;
        }
        
        $insertStmt->bind_param('siissss',
            $rel['name'], $rel['category_id'], $groupId,
            $rel['size'], $rel['files'], $rel['source_url'], $rel['created_at']
        );
        
        if ($insertStmt->execute() && $insertStmt->affected_rows > 0) $imported++;
        else $skipped++;
    }
}

// -----------------------------------------------------------------------
// QUELLE 1: predb.net API
// -----------------------------------------------------------------------
function syncPredbNet($conn, $lastSyncedName) {
    echo "\n--- predb.net (API) ---\n";
    
    $newReleases = [];
    $newestName = '';
    $reachedEnd = false;
    
    // Max 5 Seiten = bis zu 500 Releases
    for ($page = 1; $page <= 5; $page++) {
        $url = "https://api.predb.net/?page={$page}&limit=100";
        echo "  Seite {$page}... ";
        
        $json = fetchUrl($url);
        if ($json === false) { echo "❌ Fehler\n"; break; }
        
        $data = json_decode($json, true);
        if (!$data || !isset($data['data'])) { echo "❌ Parse-Fehler\n"; break; }
        
        $items = $data['data'];
        echo count($items) . " Releases\n";
        
        if (empty($items)) break;
        
        foreach ($items as $item) {
            $name = trim($item['release'] ?? '');
            if (empty($name)) continue;
            
            if ($newestName === '') $newestName = $name;
            
            if ($lastSyncedName && $name === $lastSyncedName) {
                $reachedEnd = true;
                break 2;
            }
            
            // Section → category
            $sectionRaw = $item['section'] ?? '';
            $section = strtoupper(explode('-', $sectionRaw)[0]);
            global $sectionMap, $defaultCategoryId;
            $categoryId = isset($sectionMap[$section]) ? $sectionMap[$section] : $defaultCategoryId;
            
            $groupName = strtoupper(trim($item['group'] ?? ''));
            $size = $item['size'] > 0 ? $item['size'] . ' MB' : null;
            $files = (int)($item['files'] ?? 0);
            $pretime = (int)($item['pretime'] ?? 0);
            $createdAt = $pretime > 0 ? date('Y-m-d H:i:s', $pretime) : date('Y-m-d H:i:s');
            
            $newReleases[] = [
                'name' => $name,
                'category_id' => $categoryId,
                'group_name' => $groupName,
                'size' => $size,
                'files' => $files ?: null,
                'created_at' => $createdAt,
                'source_url' => 'https://predb.net' . ($item['url'] ?? ''),
            ];
        }
        
        usleep(200000); // 200ms Pause
    }
    
    if ($reachedEnd) echo "  → Letzten bekannten Release erreicht\n";
    echo "  → " . count($newReleases) . " neue Releases\n";
    
    return [$newReleases, $newestName];
}

// -----------------------------------------------------------------------
// QUELLE 4: xrel.to (API v2)
// -----------------------------------------------------------------------
function syncXrelTo($conn, $lastSyncedId) {
    echo "\n--- xrel.to (API v2) ---\n";
    
    global $defaultCategoryId;
    
    // xrel.to Kategorie-Mapping (category_name + fake_sub → unsere category_id)
    $xrelCatMap = [
        'games'               => 1,
        'movies'              => 2,
        'music'               => 3,
        'applications'        => 4,
        'tv'                  => 5,
        'books'               => 6,
        'adult'               => 7,
        'other'               => 8,
        'anime'               => 5,
        'manga'               => 6,
        'dox'                 => 8,
        'covers'              => 8,
        'templates'           => 8,
        'mobile'              => 4,
        'images'              => 8,
    ];
    
    $newReleases = [];
    $newestId = 0;
    $reachedEnd = false;
    
    // Max 3 Seiten
    for ($page = 1; $page <= 3; $page++) {
        $url = "https://api.xrel.to/v2/release/browse.json?page={$page}&limit=100&extended=1";
        if ($lastSyncedId > 0) {
            $url .= "&since_id={$lastSyncedId}";
        }
        
        echo "  Seite {$page}... ";
        
        $json = fetchUrl($url, 15);
        if ($json === false) { echo "❌ Fehler\n"; break; }
        
        $data = json_decode($json, true);
        if (!$data || !isset($data['payload']['listings'])) { echo "❌ Parse-Fehler\n"; break; }
        
        $listings = $data['payload']['listings'];
        echo count($listings) . " Releases\n";
        
        if (empty($listings)) break;
        
        foreach ($listings as $item) {
            $id = (int)($item['id'] ?? 0);
            $name = trim($item['name'] ?? '');
            if (empty($name)) continue;
            
            if ($newestId === 0) $newestId = $id;
            
            // Prüfen ob wir den letzten bekannten Release erreicht haben
            if ($lastSyncedId > 0 && $id <= $lastSyncedId) {
                $reachedEnd = true;
                break 2;
            }
            
            // Kategorie bestimmen
            $categoryId = $defaultCategoryId;
            if (isset($item['category'])) {
                $catName = strtolower($item['category']['name'] ?? '');
                $fakeSub = strtolower($item['category']['fake'] ?? '');
                
                if (isset($xrelCatMap[$catName])) {
                    $categoryId = $xrelCatMap[$catName];
                } elseif (isset($xrelCatMap[$fakeSub])) {
                    $categoryId = $xrelCatMap[$fakeSub];
                }
                
                // Feinjustierung
                if ($catName === 'movies' && in_array($fakeSub, ['hd', 'fullhd', '4k', 'uhd'])) {
                    $categoryId = 2; // Movies
                } elseif ($catName === 'tv' && in_array($fakeSub, ['hd', 'fullhd'])) {
                    $categoryId = 5; // TV
                } elseif ($catName === 'music' && in_array($fakeSub, ['flac', 'lossless'])) {
                    $categoryId = 3; // Music
                }
            }
            
            // Group
            $groupName = '';
            if (isset($item['group']['name'])) {
                $groupName = strtoupper(trim($item['group']['name']));
            } elseif (preg_match('/-([A-Z0-9_]+)$/', $name, $gm)) {
                $groupName = strtoupper($gm[1]);
            }
            
            // Size
            $size = null;
            if (!empty($item['size'])) {
                $size = $item['size'];
            } elseif (!empty($item['byte_size'])) {
                $size = round($item['byte_size'] / 1048576, 2) . ' MB';
            }
            
            // Files
            $files = (int)($item['files'] ?? 0);
            
            // Datum
            $createdAt = date('Y-m-d H:i:s');
            if (!empty($item['time'])) {
                $createdAt = date('Y-m-d H:i:s', $item['time']);
            } elseif (!empty($item['added'])) {
                $createdAt = date('Y-m-d H:i:s', strtotime($item['added']));
            }
            
            // Source URL
            $sourceUrl = 'https://www.xrel.to' . ($item['link'] ?? '');
            
            $newReleases[] = [
                'name' => $name,
                'category_id' => $categoryId,
                'group_name' => $groupName,
                'size' => $size,
                'files' => $files ?: null,
                'created_at' => $createdAt,
                'source_url' => $sourceUrl,
            ];
        }
        
        usleep(300000); // 300ms Pause
    }
    
    if ($reachedEnd) echo "  → Letzten bekannten Release erreicht\n";
    echo "  → " . count($newReleases) . " neue Releases\n";
    
    return [$newReleases, $newestId];
}

// -----------------------------------------------------------------------
// QUELLE 2: m2v.ru (HTML Scraping)
// -----------------------------------------------------------------------
function syncM2vRu($conn, &$imported, &$skipped, &$groupsCreated) {
    echo "\n--- m2v.ru ---\n";
    
    global $m2vCatMap, $defaultCategoryId;
    
    $html = fetchUrl('https://m2v.ru/', 20);
    if ($html === false) {
        echo "  ❌ Konnte m2v.ru nicht laden\n";
        return;
    }
    
    $dom = new DOMDocument();
    @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    $xpath = new DOMXPath($dom);
    
    // Alle Kategorie-Blöcke finden
    $blocks = $xpath->query("//section[contains(@class, 'block')]");
    echo "  " . $blocks->length . " Kategorie-Blöcke gefunden\n";
    
    $allReleases = [];
    
    foreach ($blocks as $block) {
        // Kategorie-Slug aus dem Link extrahieren
        $catLink = $xpath->query(".//h2/a[1]", $block)->item(0);
        if (!$catLink) continue;
        
        $catHref = $catLink->getAttribute('href');
        $catSlug = str_replace('/s/', '', $catHref);
        $categoryId = isset($m2vCatMap[$catSlug]) ? $m2vCatMap[$catSlug] : $defaultCategoryId;
        
        // Tabellen-Zeilen
        $rows = $xpath->query(".//table[contains(@class, 'releases')]/tbody/tr", $block);
        
        foreach ($rows as $row) {
            $nameNode = $xpath->query(".//td[contains(@class, 'rname')]/a", $row)->item(0);
            $groupNode = $xpath->query(".//td[contains(@class, 'rgrp')]/a", $row)->item(0);
            $sizeNode = $xpath->query(".//td[contains(@class, 'rsize')]", $row)->item(0);
            $filesNode = $xpath->query(".//td[contains(@class, 'rfiles')]", $row)->item(0);
            $dateNode = $xpath->query(".//td[contains(@class, 'rdate')]", $row)->item(0);
            
            if (!$nameNode) continue;
            $name = trim($nameNode->textContent);
            if (empty($name)) continue;
            
            $groupName = $groupNode ? strtoupper(trim($groupNode->textContent)) : null;
            $size = $sizeNode ? trim($sizeNode->textContent) : null;
            $files = $filesNode ? (int)trim($filesNode->textContent) : 0;
            $dateStr = $dateNode ? trim($dateNode->textContent) : '';
            
            $createdAt = $dateStr ? date('Y-m-d H:i:s', strtotime($dateStr)) : date('Y-m-d H:i:s');
            
            $allReleases[] = [
                'name' => $name,
                'category_id' => $categoryId,
                'group_name' => $groupName,
                'size' => $size,
                'files' => $files ?: null,
                'created_at' => $createdAt,
                'source_url' => 'https://m2v.ru/',
            ];
        }
    }
    
    echo "  " . count($allReleases) . " Releases gefunden\n";
    
    if (!empty($allReleases)) {
        $conn->begin_transaction();
        importReleases($conn, $allReleases, $imported, $skipped, $groupsCreated);
        $conn->commit();
    }
}

// -----------------------------------------------------------------------
// QUELLE 3: predb.me (RSS Feed)
// -----------------------------------------------------------------------
function syncPredbMe($conn, $lastSyncedName) {
    echo "\n--- predb.me (RSS) ---\n";
    
    global $predbMeCatMap, $defaultCategoryId;
    
    $rss = fetchUrl('https://predb.me/?rss=1', 15);
    if ($rss === false) {
        echo "  ❌ Konnte RSS nicht laden\n";
        return [[], ''];
    }
    
    $xml = simplexml_load_string($rss);
    if (!$xml || !isset($xml->channel->item)) {
        echo "  ❌ RSS Parse-Fehler\n";
        return [[], ''];
    }
    
    $newReleases = [];
    $newestName = '';
    $reachedEnd = false;
    
    foreach ($xml->channel->item as $item) {
        $title = trim((string)$item->title);
        $link = trim((string)$item->link);
        if (empty($title)) continue;
        
        if ($newestName === '') $newestName = $title;
        
        if ($lastSyncedName && $title === $lastSyncedName) {
            $reachedEnd = true;
            break;
        }
        
        // Kategorie aus der URL extrahieren (falls vorhanden)
        $categoryId = $defaultCategoryId;
        if (preg_match('/[?&]cats=([^&]+)/', $link, $m)) {
            $catSlug = $m[1];
            $categoryId = isset($predbMeCatMap[$catSlug]) ? $predbMeCatMap[$catSlug] : $defaultCategoryId;
        }
        
        // Group-Name aus dem Release-Namen extrahieren (letzter Teil nach letztem "-")
        $groupName = '';
        if (preg_match('/-([A-Z0-9_]+)$/', $title, $gm)) {
            $groupName = strtoupper($gm[1]);
        }
        
        $newReleases[] = [
            'name' => $title,
            'category_id' => $categoryId,
            'group_name' => $groupName,
            'size' => null,
            'files' => null,
            'created_at' => date('Y-m-d H:i:s'),
            'source_url' => $link,
        ];
    }
    
    if ($reachedEnd) echo "  → Letzten bekannten Release erreicht\n";
    echo "  → " . count($newReleases) . " neue Releases\n";
    
    return [$newReleases, $newestName];
}

// -----------------------------------------------------------------------
// Hauptprogramm
// -----------------------------------------------------------------------

echo date('H:i:s') . " === FortKnox PreDB Multi-Sync gestartet ===\n";

$lastSyncedName = '';
if (file_exists(TRACKER_FILE)) {
    $lastSyncedName = trim(file_get_contents(TRACKER_FILE));
    echo "Letzter Sync: {$lastSyncedName}\n";
}

$totalImported = 0;
$totalSkipped = 0;
$totalGroups = 0;

// QUELLE 1: predb.net
list($predbReleases, $newestName) = syncPredbNet($conn, $lastSyncedName);

if (!empty($predbReleases)) {
    echo "\nImportiere predb.net Releases...\n";
    $conn->begin_transaction();
    importReleases($conn, $predbReleases, $totalImported, $totalSkipped, $totalGroups);
    $conn->commit();
    
    echo "  → {$totalImported} neu, {$totalSkipped} übersprungen, {$totalGroups} neue Groups\n";
    
    // Tracker aktualisieren
    if ($newestName) {
        file_put_contents(TRACKER_FILE, $newestName);
        echo "  → Tracker auf: {$newestName}\n";
    }
}

// QUELLE 2: m2v.ru (immer alle aktuellen holen, keine Duplikate dank INSERT IGNORE)
echo "\nImportiere m2v.ru Releases...\n";
syncM2vRu($conn, $totalImported, $totalSkipped, $totalGroups);

// QUELLE 3: predb.me (RSS)
$lastPredbMeName = '';
if (file_exists(PREDBME_TRACKER_FILE)) {
    $lastPredbMeName = trim(file_get_contents(PREDBME_TRACKER_FILE));
}
list($predbMeReleases, $predbMeNewest) = syncPredbMe($conn, $lastPredbMeName);

if (!empty($predbMeReleases)) {
    echo "\nImportiere predb.me Releases...\n";
    $conn->begin_transaction();
    importReleases($conn, $predbMeReleases, $totalImported, $totalSkipped, $totalGroups);
    $conn->commit();
    
    echo "  → {$totalImported} neu, {$totalSkipped} übersprungen, {$totalGroups} neue Groups\n";
    
    // Tracker aktualisieren
    if ($predbMeNewest) {
        file_put_contents(PREDBME_TRACKER_FILE, $predbMeNewest);
        echo "  → predb.me Tracker auf: {$predbMeNewest}\n";
    }
}

// Statistik
echo "\n" . date('H:i:s') . " === SYNC ABGESCHLOSSEN ===\n";
echo "  Neu importiert: {$totalImported}\n";
echo "  Übersprungen:   {$totalSkipped}\n";
echo "  Neue Groups:    {$totalGroups}\n";

// DB-Stand ausgeben
$r = $conn->query("SELECT COUNT(*) as cnt FROM " . DB_PREFIX . "releases");
echo "  DB gesamt:      " . number_format($r->fetch_assoc()['cnt']) . " Releases\n";

$conn->close();

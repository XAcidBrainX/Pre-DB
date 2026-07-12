<?php
require_once 'config.php';

$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = ITEMS_PER_PAGE;
$offset = ($page - 1) * $limit;
$days = isset($_GET['days']) ? intval($_GET['days']) : 7;

// Gesamtanzahl (letzte X Tage)
$countSql = "SELECT COUNT(*) as total FROM " . DB_PREFIX . "releases WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
$stmt = $conn->prepare($countSql);
$stmt->bind_param('i', $days);
$stmt->execute();
$total = $stmt->get_result()->fetch_assoc()['total'];
$totalPages = ceil($total / $limit);

// Releases abrufen
$sql = "SELECT r.*, c.name as category_name, c.slug as category_slug, c.icon as category_icon,
               g.name as group_name
        FROM " . DB_PREFIX . "releases r
        LEFT JOIN " . DB_PREFIX . "categories c ON r.category_id = c.id
        LEFT JOIN " . DB_PREFIX . "groups g ON r.group_id = g.id
        WHERE r.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ORDER BY r.created_at DESC
        LIMIT ? OFFSET ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param('iii', $days, $limit, $offset);
$stmt->execute();
$releases = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

include 'header.php';
?>

<div class="content">
    <div class="admin-header">
        <h1>🆕 Neueste Releases</h1>
        <div class="main-nav">
            <a href="?days=1" class="nav-link <?=$days == 1 ? 'search-btn' : ''?>">24h</a>
            <a href="?days=3" class="nav-link <?=$days == 3 ? 'search-btn' : ''?>">3 Tage</a>
            <a href="?days=7" class="nav-link <?=$days == 7 ? 'search-btn' : ''?>">7 Tage</a>
            <a href="?days=30" class="nav-link <?=$days == 30 ? 'search-btn' : ''?>">30 Tage</a>
        </div>
    </div>

    <div class="stats-bar">
        <span>📊 <?=number_format($total, 0, ',', '.')?> Releases in den letzten <?=$days?> Tagen</span>
        <span>Seite <?=$page?> von <?=$totalPages ?: 1?></span>
    </div>

    <div class="release-table">
        <div class="table-header">
            <span class="col-name">Release Name</span>
            <span class="col-cat">Kategorie</span>
            <span class="col-size">Größe</span>
            <span class="col-date">Hinzugefügt</span>
        </div>

        <?php if (empty($releases)): ?>
            <div class="no-results">
                <p>Keine Releases in diesem Zeitraum gefunden.</p>
            </div>
        <?php else: ?>
            <?php foreach ($releases as $rel): ?>
                <a href="release.php?id=<?=$rel['id']?>" class="release-row">
                    <span class="col-name">
                        <span class="release-name"><?=h($rel['name'])?></span>
                    </span>
                    <span class="col-cat">
                        <span class="category-badge">
                            <?=h($rel['category_icon'] ?? '📦')?> <?=h($rel['category_name'] ?? 'Sonstiges')?>
                        </span>
                    </span>
                    <span class="col-size"><?=formatSize($rel['size'])?></span>
                    <span class="col-date"><?=formatDate($rel['created_at'])?></span>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="?page=<?=$page-1?>&days=<?=$days?>" class="page-link">‹ Zurück</a>
            <?php endif; ?>
            
            <?php
            $start = max(1, $page - 2);
            $end = min($totalPages, $page + 2);
            for ($i = $start; $i <= $end; $i++):
            ?>
                <a href="?page=<?=$i?>&days=<?=$days?>" 
                   class="page-link <?=$i == $page ? 'active' : ''?>"><?=$i?></a>
            <?php endfor; ?>
            
            <?php if ($page < $totalPages): ?>
                <a href="?page=<?=$page+1?>&days=<?=$days?>" class="page-link">Weiter ›</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>

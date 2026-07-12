<?php
require_once 'config.php';

$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category = isset($_GET['cat']) ? intval($_GET['cat']) : 0;
$limit = ITEMS_PER_PAGE;
$offset = ($page - 1) * $limit;

$where = [];
$params = [];

if ($search) {
    $where[] = "(r.name LIKE ? OR r.nfo_content LIKE ?)";
    $searchTerm = '%' . $search . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if ($category > 0) {
    $where[] = "r.category_id = ?";
    $params[] = $category;
}

$whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

// Gesamtanzahl
$countSql = "SELECT COUNT(*) as total FROM " . DB_PREFIX . "releases r $whereClause";
$stmt = $conn->prepare($countSql);
if (!empty($params)) {
    $types = str_repeat('s', count($params));
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$total = $stmt->get_result()->fetch_assoc()['total'];
$totalPages = ceil($total / $limit);

// Releases abrufen
$sql = "SELECT r.*, c.name as category_name, c.slug as category_slug, c.icon as category_icon,
               g.name as group_name
        FROM " . DB_PREFIX . "releases r
        LEFT JOIN " . DB_PREFIX . "categories c ON r.category_id = c.id
        LEFT JOIN " . DB_PREFIX . "groups g ON r.group_id = g.id
        $whereClause
        ORDER BY r.created_at DESC
        LIMIT ? OFFSET ?";

$stmt = $conn->prepare($sql);
$allParams = array_merge($params, [$limit, $offset]);
if (!empty($params)) {
    $types = str_repeat('s', count($params)) . 'ii';
    $stmt->bind_param($types, ...$allParams);
} else {
    $stmt->bind_param('ii', $limit, $offset);
}
$stmt->execute();
$releases = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Kategorien für Navigation
$cats = $conn->query("SELECT * FROM " . DB_PREFIX . "categories ORDER BY sort_order")->fetch_all(MYSQLI_ASSOC);

include 'header.php';
?>

<div class="content">
    <div class="search-box">
        <form method="GET" action="">
            <div class="search-row">
                <input type="text" name="search" value="<?=h($search)?>" placeholder="Release suchen..." class="search-input">
                <select name="cat" class="category-select">
                    <option value="0">Alle Kategorien</option>
                    <?php foreach ($cats as $cat): ?>
                        <option value="<?=$cat['id']?>" <?=$category == $cat['id'] ? 'selected' : ''?>>
                            <?=h($cat['icon'])?> <?=h($cat['name'])?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="search-btn">Suchen</button>
                <?php if ($search || $category): ?>
                    <a href="index.php" class="reset-btn">Zurücksetzen</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="stats-bar">
        <span>📊 <?=number_format($total, 0, ',', '.')?> Releases gefunden</span>
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
                <p>Keine Releases gefunden.</p>
                <?php if ($search): ?>
                    <p>Versuche einen anderen Suchbegriff.</p>
                <?php endif; ?>
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
                <a href="?page=<?=$page-1?>&search=<?=urlencode($search)?>&cat=<?=$category?>" class="page-link">‹ Zurück</a>
            <?php endif; ?>
            
            <?php
            $start = max(1, $page - 2);
            $end = min($totalPages, $page + 2);
            for ($i = $start; $i <= $end; $i++):
            ?>
                <a href="?page=<?=$i?>&search=<?=urlencode($search)?>&cat=<?=$category?>" 
                   class="page-link <?=$i == $page ? 'active' : ''?>"><?=$i?></a>
            <?php endfor; ?>
            
            <?php if ($page < $totalPages): ?>
                <a href="?page=<?=$page+1?>&search=<?=urlencode($search)?>&cat=<?=$category?>" class="page-link">Weiter ›</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>

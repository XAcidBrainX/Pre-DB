<?php
require_once 'config.php';

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$id) {
    redirect('index.php');
}

$stmt = $conn->prepare("
    SELECT r.*, c.name as category_name, c.slug as category_slug, c.icon as category_icon,
           g.name as group_name
    FROM " . DB_PREFIX . "releases r
    LEFT JOIN " . DB_PREFIX . "categories c ON r.category_id = c.id
    LEFT JOIN " . DB_PREFIX . "groups g ON r.group_id = g.id
    WHERE r.id = ?
");
$stmt->bind_param('i', $id);
$stmt->execute();
$release = $stmt->get_result()->fetch_assoc();

if (!$release) {
    redirect('index.php');
}

include 'header.php';
?>

<div class="content">
    <div class="breadcrumb">
        <a href="index.php">‹ Zurück zur Übersicht</a>
    </div>

    <div class="release-detail">
        <div class="detail-header">
            <h1 class="detail-title"><?=h($release['name'])?></h1>
            <div class="detail-meta">
                <span class="meta-item">
                    <span class="meta-label">Kategorie:</span>
                    <span class="category-badge">
                        <?=h($release['category_icon'] ?? '📦')?> <?=h($release['category_name'] ?? 'Sonstiges')?>
                    </span>
                </span>
                <span class="meta-item">
                    <span class="meta-label">Größe:</span>
                    <span><?=formatSize($release['size'])?></span>
                </span>
                <?php if ($release['files']): ?>
                <span class="meta-item">
                    <span class="meta-label">Dateien:</span>
                    <span><?=intval($release['files'])?></span>
                </span>
                <?php endif; ?>
                <span class="meta-item">
                    <span class="meta-label">Hinzugefügt:</span>
                    <span><?=formatDate($release['created_at'])?></span>
                </span>
            </div>
        </div>

        <?php if ($release['nfo_content']): ?>
            <div class="nfo-viewer">
                <div class="nfo-header">
                    <span>📄 NFO Inhalt</span>
                    <button onclick="toggleNfo()" class="nfo-toggle">Einklappen</button>
                </div>
                <pre class="nfo-content" id="nfoContent"><?=h($release['nfo_content'])?></pre>
            </div>
        <?php endif; ?>
    </div>

    <div class="actions">
        <?php if (isAdmin()): ?>
            <a href="admin.php?action=edit&id=<?=$release['id']?>" class="action-btn">✏️ Bearbeiten</a>
            <a href="admin.php?action=delete&id=<?=$release['id']?>" class="action-btn danger" 
               onclick="return confirm('Wirklich löschen?')">🗑️ Löschen</a>
        <?php endif; ?>
    </div>
</div>

<script>
function toggleNfo() {
    var nfo = document.getElementById('nfoContent');
    var btn = event.target;
    if (nfo.style.maxHeight && nfo.style.maxHeight !== '0px') {
        nfo.style.maxHeight = '0';
        nfo.style.overflow = 'hidden';
        btn.textContent = 'Ausklappen';
    } else {
        nfo.style.maxHeight = nfo.scrollHeight + 'px';
        nfo.style.overflow = 'auto';
        btn.textContent = 'Einklappen';
    }
}
</script>

<?php include 'footer.php'; ?>

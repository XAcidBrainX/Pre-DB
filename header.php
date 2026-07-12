<span class="logo-text">FortKnox PreDB</span><!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?=SITE_TITLE?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <header class="site-header">
        <div class="header-inner">
            <a href="index.php" class="logo">
                <span class="logo-icon">⬇</span>
                <span class="logo-text">FortKnox PreDB</span>
                <span class="logo-sub">Scene Release Database</span>
            </a>
            <nav class="main-nav">
                <a href="index.php" class="nav-link">Startseite</a>
                <a href="recent.php" class="nav-link">Neueste</a>
                <?php if (isLoggedIn()): ?>
                    <a href="admin.php" class="nav-link">Admin</a>
                    <a href="logout.php" class="nav-link">Logout (<?=h($_SESSION['username'])?>)</a>
                <?php else: ?>
                    <a href="login.php" class="nav-link">Login</a>
                <?php endif; ?>
            </nav>
        </div>
    </header>

    <div class="page-wrapper">

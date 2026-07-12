<?php
require_once 'config.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if ($username && $password) {
        $stmt = $conn->prepare("SELECT id, username, password, role FROM " . DB_PREFIX . "users WHERE username = ?");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['user_role'] = $user['role'];
            redirect('admin.php');
        } else {
            $error = 'Benutzername oder Passwort falsch.';
        }
    } else {
        $error = 'Bitte alle Felder ausfüllen.';
    }
}

include 'header.php';
?>

<div class="content">
    <div class="login-box">
        <h1>🔐 Login</h1>
        <p>Melde dich an, um Releases zu verwalten.</p>
        
        <?php if ($error): ?>
            <div class="error-msg"><?=h($error)?></div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="form-group">
                <label for="username">Benutzername</label>
                <input type="text" id="username" name="username" placeholder="Benutzername" required>
            </div>
            <div class="form-group">
                <label for="password">Passwort</label>
                <input type="password" id="password" name="password" placeholder="Passwort" required>
            </div>
            <button type="submit" class="btn-primary">Anmelden</button>
        </form>
        
        <p style="margin-top: 16px; font-size: 12px; color: var(--text-muted);">
            Demo: admin / admin123
        </p>
    </div>
</div>

<?php include 'footer.php'; ?>

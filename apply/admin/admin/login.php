<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/main-admin-bridge.php';

if (isApplyAdminLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$error  = '';
$return = trim((string) ($_GET['return'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = attemptMainAdminLogin(
        (string) ($_POST['username'] ?? ''),
        (string) ($_POST['password'] ?? '')
    );

    if ($result['ok']) {
        $target = $return !== '' && str_starts_with($return, '/') ? $return : 'dashboard.php';
        header('Location: ' . $target);
        exit;
    }

    $error = $result['error'];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Apply Admin Login</title>
<style>
body{margin:0;background:#020617;font-family:Arial,sans-serif;color:#fff}
.login-box{width:400px;max-width:95%;margin:100px auto;background:#0f172a;padding:30px;border-radius:20px}
h1{text-align:center;font-size:1.35rem}
.hint{color:#94a3b8;font-size:0.9rem;text-align:center;margin:0 0 1.25rem}
input{width:100%;padding:14px;margin-bottom:15px;border:none;border-radius:10px;background:#1e293b;color:#fff;box-sizing:border-box}
button{width:100%;padding:14px;border:none;border-radius:10px;background:#2563eb;color:#fff;cursor:pointer;font-weight:bold}
.error{background:#7f1d1d;padding:10px;border-radius:10px;margin-bottom:15px}
</style>
</head>
<body>
<div class="login-box">
<h1>Apply Admin</h1>
<p class="hint">Use the same username and password as the main ERP admin.</p>
<?php if ($error !== ''): ?>
<div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<form method="POST">
<input type="text" name="username" placeholder="Username" required autocomplete="username">
<input type="password" name="password" placeholder="Password" required autocomplete="current-password">
<button type="submit">Login</button>
</form>
</div>
</body>
</html>

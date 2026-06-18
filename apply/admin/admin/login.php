<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/main-admin-bridge.php';
require_once __DIR__ . '/../includes/apply-sso.php';
require_once __DIR__ . '/../includes/apply-urls.php';

if (function_exists('tryApplyAdminCookieLogin') === false) {
    function tryApplyAdminCookieLogin(): bool
    {
        if (!empty($_SESSION['admin_id'])) {
            return true;
        }

        $token = (string) ($_COOKIE[getApplySsoCookieName()] ?? '');
        if ($token === '') {
            return false;
        }

        $payload = verifyApplySsoToken($token);
        if ($payload === null) {
            return false;
        }

        $user = fetchMainAdminUser($payload['admin_id']);
        if ($user === null || !applyAdminRoleAllowed((string) ($user['role'] ?? ''))) {
            return false;
        }

        setApplyAdminSession($user);

        return true;
    }
}

if (tryApplyAdminCookieLogin()) {
    header('Location: dashboard.php');
    exit;
}

$error  = '';
$return = trim((string) ($_GET['return'] ?? ''));

if (isset($_GET['timeout'])) {
    $error = 'Your session expired after 5 minutes of inactivity. Please sign in again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = attemptMainAdminLogin(
        (string) ($_POST['username'] ?? ''),
        (string) ($_POST['password'] ?? '')
    );

    if ($result['ok']) {
        $target = $return !== '' ? apply_safe_redirect_path($return) : 'dashboard.php';
        header('Location: ' . $target);
        exit;
    }

    $error = $result['error'];
}

$mainAdminUrl = 'https://admin.olasentra.com';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>Restricted Access — Apply Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #05080f;
            --panel: #0c1220;
            --panel-border: rgba(248, 113, 113, 0.35);
            --text: #f1f5f9;
            --muted: #94a3b8;
            --danger: #ef4444;
            --danger-soft: rgba(239, 68, 68, 0.12);
            --warn: #f59e0b;
            --accent: #6366f1;
            --input-bg: #111827;
            --input-border: rgba(148, 163, 184, 0.22);
            --scan: rgba(99, 102, 241, 0.05);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            min-height: 100dvh;
            font-family: 'IBM Plex Sans', system-ui, sans-serif;
            color: var(--text);
            background:
                linear-gradient(var(--scan) 1px, transparent 1px),
                linear-gradient(90deg, var(--scan) 1px, transparent 1px),
                radial-gradient(ellipse 70% 50% at 50% -20%, rgba(99, 102, 241, 0.16), transparent 55%),
                radial-gradient(ellipse 40% 30% at 100% 100%, rgba(239, 68, 68, 0.06), transparent),
                var(--bg);
            background-size: 24px 24px, 24px 24px, auto, auto, auto;
        }

        .secure-shell {
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: max(1rem, env(safe-area-inset-top)) 1rem max(1rem, env(safe-area-inset-bottom));
        }

        .secure-banner {
            width: 100%;
            max-width: 440px;
            margin-bottom: 0.75rem;
            padding: 0.55rem 1rem;
            border-radius: 8px;
            border: 1px solid rgba(239, 68, 68, 0.45);
            background: linear-gradient(90deg, rgba(127, 29, 29, 0.55), rgba(69, 10, 10, 0.35));
            display: flex;
            align-items: center;
            gap: 0.65rem;
            font-family: 'IBM Plex Mono', monospace;
            font-size: 0.72rem;
            font-weight: 500;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: #fecaca;
        }

        .secure-banner__pulse {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--danger);
            box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.6);
            animation: pulse 2s infinite;
            flex-shrink: 0;
        }

        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.55); }
            70% { box-shadow: 0 0 0 10px rgba(239, 68, 68, 0); }
            100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
        }

        .secure-card {
            width: 100%;
            max-width: 440px;
            padding: 1.75rem 1.5rem 1.35rem;
            border-radius: 16px;
            border: 1px solid var(--panel-border);
            background: linear-gradient(180deg, rgba(15, 23, 42, 0.98), rgba(12, 18, 32, 0.99));
            box-shadow:
                0 0 0 1px rgba(0, 0, 0, 0.4) inset,
                0 24px 60px rgba(0, 0, 0, 0.55),
                0 0 40px rgba(239, 68, 68, 0.06);
            position: relative;
            overflow: hidden;
        }

        .secure-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #6366f1, #ef4444, #6366f1);
        }

        .secure-card__header {
            text-align: center;
            margin-bottom: 1.35rem;
        }

        .secure-card__shield {
            width: 58px;
            height: 58px;
            margin: 0 auto 0.85rem;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--danger-soft);
            border: 1px solid rgba(239, 68, 68, 0.35);
            color: #fca5a5;
        }

        .secure-card__shield svg {
            width: 30px;
            height: 30px;
        }

        .secure-card__title {
            margin: 0 0 0.35rem;
            font-size: 1.4rem;
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        .secure-card__subtitle {
            margin: 0;
            font-size: 0.875rem;
            color: var(--muted);
            line-height: 1.45;
        }

        .secure-card__zone {
            display: inline-block;
            margin-top: 0.65rem;
            padding: 0.2rem 0.55rem;
            border-radius: 4px;
            font-family: 'IBM Plex Mono', monospace;
            font-size: 0.68rem;
            font-weight: 500;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #fde68a;
            background: rgba(245, 158, 11, 0.12);
            border: 1px solid rgba(245, 158, 11, 0.35);
        }

        .secure-alert {
            margin-bottom: 1rem;
            padding: 0.75rem 0.85rem;
            border-radius: 8px;
            font-size: 0.875rem;
            line-height: 1.4;
            color: #fecaca;
            background: rgba(127, 29, 29, 0.45);
            border: 1px solid rgba(239, 68, 68, 0.4);
        }

        .secure-form label {
            display: block;
            margin-bottom: 0.35rem;
            font-size: 0.78rem;
            font-weight: 600;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #cbd5e1;
        }

        .secure-form .field {
            margin-bottom: 1rem;
        }

        .secure-form input {
            width: 100%;
            padding: 0.8rem 0.9rem;
            border-radius: 8px;
            border: 1px solid var(--input-border);
            background: var(--input-bg);
            color: var(--text);
            font-size: 1rem;
            font-family: inherit;
            transition: border-color 0.15s, box-shadow 0.15s;
        }

        .secure-form input:focus {
            outline: none;
            border-color: rgba(59, 130, 246, 0.65);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.18);
        }

        .secure-form button {
            width: 100%;
            margin-top: 0.25rem;
            padding: 0.85rem 1rem;
            border: none;
            border-radius: 8px;
            font-family: inherit;
            font-size: 0.95rem;
            font-weight: 600;
            letter-spacing: 0.02em;
            color: #fff;
            cursor: pointer;
            background: linear-gradient(180deg, #1d4ed8, #1e40af);
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.35);
            transition: transform 0.12s, box-shadow 0.12s;
        }

        .secure-form button:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(37, 99, 235, 0.42);
        }

        .secure-form button:active {
            transform: translateY(0);
        }

        .secure-notices {
            margin-top: 1.25rem;
            padding-top: 1rem;
            border-top: 1px solid rgba(148, 163, 184, 0.15);
        }

        .secure-notices ul {
            margin: 0;
            padding: 0 0 0 1.1rem;
            font-size: 0.72rem;
            line-height: 1.55;
            color: #64748b;
        }

        .secure-notices li + li {
            margin-top: 0.35rem;
        }

        .secure-footer {
            margin-top: 1.1rem;
            text-align: center;
            font-size: 0.78rem;
            color: var(--muted);
        }

        .secure-footer a {
            color: #93c5fd;
            text-decoration: none;
        }

        .secure-footer a:hover {
            text-decoration: underline;
        }

        .secure-id {
            margin-top: 0.5rem;
            font-family: 'IBM Plex Mono', monospace;
            font-size: 0.65rem;
            color: #475569;
            letter-spacing: 0.06em;
        }
    </style>
</head>
<body>
<div class="secure-shell">
    <div class="secure-banner" role="status">
        <span class="secure-banner__pulse" aria-hidden="true"></span>
        Restricted area — authorized personnel only
    </div>

    <section class="secure-card" aria-labelledby="login-title">
        <header class="secure-card__header">
            <div class="secure-card__shield" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                    <path d="M9 12l2 2 4-4"/>
                </svg>
            </div>
            <h1 class="secure-card__title" id="login-title">Apply Admin</h1>
            <p class="secure-card__subtitle">Staff profiles, PSA compliance &amp; payroll data</p>
            <span class="secure-card__zone">Premium UI X · Security level: high</span>
        </header>

        <?php if ($error !== ''): ?>
            <div class="secure-alert" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="post" class="secure-form" autocomplete="on">
            <div class="field">
                <label for="username">Operator ID</label>
                <input type="text" id="username" name="username" autocomplete="username" required spellcheck="false" inputmode="email">
            </div>
            <div class="field">
                <label for="password">Access credential</label>
                <input type="password" id="password" name="password" autocomplete="current-password" required>
            </div>
            <button type="submit">Authenticate &amp; enter</button>
        </form>

        <div class="secure-notices">
            <ul>
                <li>This system is monitored. Unauthorized access is prohibited.</li>
                <li>Use your main ERP administrator credentials only.</li>
                <li>Sessions are encrypted and time-limited.</li>
                <li>Do not share credentials or leave terminals unattended.</li>
            </ul>
        </div>

        <p class="secure-footer">
            Signed in on main admin?
            <a href="<?= htmlspecialchars($mainAdminUrl, ENT_QUOTES, 'UTF-8') ?>/apply-portal.php">Enter via ERP console</a>
        </p>
        <p class="secure-id">ZONE-APPLY-ADMIN · Premium UI X · apply.olasentra.com</p>
    </section>
</div>
</body>
</html>

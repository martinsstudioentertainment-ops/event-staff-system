<?php

declare(strict_types=1);

require_once __DIR__ . '/secure-admin-theme.php';
require_once __DIR__ . '/date-format.php';

function secure_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function secure_format_date(?string $date): string
{
    require_once __DIR__ . '/main-admin-bridge.php';

    return formatDisplayDate($date, getMainAdminPdo());
}

function secure_format_datetime(?string $datetime): string
{
    require_once __DIR__ . '/main-admin-bridge.php';

    return formatDisplayDateTime($datetime, getMainAdminPdo());
}

/**
 * @return list<array{key: string, label: string, href: string, external?: bool}>
 */
function secure_nav_items(): array
{
    return [
        ['key' => 'dashboard', 'label' => 'Command center', 'href' => 'dashboard.php'],
        ['key' => 'staff', 'label' => 'Staff directory', 'href' => 'staff-list.php'],
        ['key' => 'psa', 'label' => 'PSA compliance', 'href' => 'psa-compliance.php'],
        ['key' => 'payroll', 'label' => 'Payroll vault', 'href' => 'payroll.php'],
        ['key' => 'applicants', 'label' => 'Main registrations', 'href' => 'applicants.php'],
        ['key' => 'settings', 'label' => 'Security settings', 'href' => 'settings.php'],
        ['key' => 'erp', 'label' => 'Main ERP console', 'href' => 'https://admin.olasentra.com/dashboard.php', 'external' => true],
    ];
}

function secure_layout_start(string $pageTitle, string $activeKey = '', string $subtitle = ''): void
{
    $adminName = secure_h((string) ($_SESSION['admin_name'] ?? $_SESSION['admin_username'] ?? 'Operator'));
    $role      = secure_h((string) ($_SESSION['admin_role'] ?? 'admin'));
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title><?= secure_h($pageTitle) ?> | Apply Secure Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500&display=swap" rel="stylesheet">
    <?php secure_print_admin_theme(); ?>
</head>
<body data-session-idle-timeout="600" data-session-signout-url="logout.php?timeout=1">
<div class="secure-sidebar-backdrop" id="secure-sidebar-backdrop" aria-hidden="true"></div>
<div class="secure-app secure-app--premium-x">
    <aside class="secure-sidebar" id="secure-sidebar" aria-label="Secure navigation">
        <div class="secure-sidebar__brand">
            <div class="secure-sidebar__shield" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/></svg>
            </div>
            <p class="secure-sidebar__title">Apply Secure Admin</p>
            <span class="secure-sidebar__zone">Premium UI X · High security zone</span>
        </div>
        <nav class="secure-sidebar__nav">
            <div class="secure-sidebar__label">Operations</div>
            <?php foreach (secure_nav_items() as $item): ?>
                <?php
                $active = ($item['key'] === $activeKey) ? ' secure-sidebar__link--active' : '';
                $ext    = !empty($item['external']) ? ' secure-sidebar__link--external' : '';
                $target = !empty($item['external']) ? ' target="_blank" rel="noopener noreferrer"' : '';
                ?>
                <a class="secure-sidebar__link<?= $active . $ext ?>" href="<?= secure_h($item['href']) ?>"<?= $target ?>><?= secure_h($item['label']) ?></a>
            <?php endforeach; ?>
            <div class="secure-sidebar__label">Session</div>
            <a class="secure-sidebar__link" href="logout.php">Sign out</a>
        </nav>
        <div class="secure-sidebar__foot">ZONE-APPLY-ADMIN</div>
    </aside>
    <div class="secure-main">
        <header class="secure-topbar">
            <div style="display:flex;align-items:center;gap:0.5rem;">
                <button type="button" class="secure-mobile-toggle" id="secure-menu-btn" aria-label="Open menu">☰</button>
                <div class="secure-banner" role="status">
                    <span class="secure-banner__dot" aria-hidden="true"></span>
                    Restricted — monitored session
                </div>
            </div>
            <div class="secure-operator">
                <strong><?= $adminName ?></strong>
                <?= $role ?> · cleared access
                · <a href="logout.php" class="secure-operator__signout">Sign out</a>
            </div>
        </header>
        <main class="secure-content">
            <h1 class="secure-page-title"><?= secure_h($pageTitle) ?></h1>
            <?php if ($subtitle !== ''): ?>
                <p class="secure-page-sub"><?= secure_h($subtitle) ?></p>
            <?php endif; ?>
    <?php
}

function secure_layout_end(): void
{
    ?>
            <footer class="secure-footer">
                APPLY SECURE ADMIN · Premium UI X · AUTHORIZED USE ONLY · <?= secure_h(gmdate('Y-m-d H:i') . ' UTC') ?>
            </footer>
        </main>
    </div>
</div>
<script>
(function () {
    var btn = document.getElementById('secure-menu-btn');
    var sidebar = document.getElementById('secure-sidebar');
    var backdrop = document.getElementById('secure-sidebar-backdrop');
    if (!btn || !sidebar || !backdrop) return;
    function close() {
        sidebar.classList.remove('is-open');
        backdrop.classList.remove('is-open');
    }
    btn.addEventListener('click', function () {
        sidebar.classList.toggle('is-open');
        backdrop.classList.toggle('is-open');
    });
    backdrop.addEventListener('click', close);
})();
(function () {
    var intervalMs = 120000;
    var url = 'auto-sync.php';
    function ping(force) {
        var u = force ? url + '?force=1' : url;
        fetch(u, { credentials: 'same-origin', cache: 'no-store' }).catch(function () {});
    }
    setTimeout(function () { ping(false); }, 4000);
    setInterval(function () { ping(false); }, intervalMs);
})();
</script>
<?php
$idleJsPath = __DIR__ . '/../assets/js/session-idle-timeout.js';
$idleJsVer  = is_file($idleJsPath) ? (string) filemtime($idleJsPath) : '1';
?>
<script src="../assets/js/session-idle-timeout.js?v=<?= secure_h($idleJsVer) ?>"></script>
</body>
</html>
    <?php
}

function secure_status_badge(string $status): string
{
    $map = [
        'Incomplete'      => 'incomplete',
        'Pending Review'  => 'pending-review',
        'Verified'        => 'verified',
        'Expired PSA'     => 'expired-psa',
        'pending'         => 'pending',
        'approved'        => 'approved',
        'rejected'        => 'rejected',
    ];

    $trimmed = trim($status);
    $key     = $map[$trimmed] ?? strtolower(preg_replace('/\s+/', '-', $trimmed) ?: 'incomplete');

    return '<span class="secure-badge secure-badge--' . secure_h($key) . '">' . secure_h($status) . '</span>';
}

function secure_psa_status(?string $expiryDate): string
{
    if ($expiryDate === null || $expiryDate === '' || $expiryDate === '0000-00-00') {
        return 'No Date';
    }

    try {
        $expiry = new DateTime($expiryDate);
        $today  = new DateTime('today');

        if ($expiry < $today) {
            return 'Expired';
        }

        if ($expiry->diff($today)->days <= 30) {
            return 'Expiring Soon';
        }
    } catch (Exception $e) {
        return 'No Date';
    }

    return 'Valid';
}

function secure_psa_badge(?string $expiryDate): string
{
    $label = secure_psa_status($expiryDate);
    $key   = strtolower(str_replace(' ', '-', $label));

    return '<span class="secure-badge secure-badge--' . secure_h($key) . '">' . secure_h($label) . '</span>';
}

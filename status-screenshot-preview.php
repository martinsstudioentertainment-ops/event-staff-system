<?php

declare(strict_types=1);

/**
 * Local UX screenshot frame for post-submit success panel (no database).
 */

$remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$isLocal = in_array($remote, ['127.0.0.1', '::1'], true);
if (!$isLocal) {
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/includes/feature-flags.php';
    try {
        $pdo = getDB();
        if (!isFeatureEnabled($pdo, 'feature_registration_wizard_v2')) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Success preview requires feature_registration_wizard_v2 enabled on production.';
            exit;
        }
    } catch (Throwable $e) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Success preview unavailable.';
        exit;
    }
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

header('Content-Type: text/html; charset=UTF-8');

$siteName = 'Olasentra';
$pdo = null;
$assetBase = '';
require_once __DIR__ . '/includes/public/staff-public-shell.php';
require_once __DIR__ . '/includes/public/registration-success-panel.php';

$rows = [[
    'id' => 1042,
    'first_name' => 'Jane',
    'surname' => 'Smith',
    'email' => 'jane.smith@example.com',
    'event_name' => 'Sample Festival',
    'event_date' => '2026-07-15',
    'staff_role' => 'dsp',
    'status' => 'pending',
]];
$successMsg = 'Registration submitted successfully for 1 event! Your application is pending approval.';

?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Status success screenshot | <?= h($siteName) ?></title>
    <link rel="stylesheet" href="assets/css/variables.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/public-front.css">
    <link rel="stylesheet" href="assets/css/mobile.css">
    <style>html { background: #030712; } body { max-width: 420px; margin: 0 auto; }</style>
</head>
<body class="staff-public-shell staff-public-shell--event-ops staff-public-shell--narrow login-page staff-mobile-page">
    <?php renderStaffPublicBackground(true); ?>
    <main class="login-page__wrap staff-public-main">
        <section class="card login-card staff-public-card">
            <div class="card__header">
                <h1 class="card__title">My Registration</h1>
                <p class="card__subtitle"><?= h($siteName) ?></p>
            </div>
            <div class="alert alert--success alert--visible"><?= h($successMsg) ?></div>
            <?php renderRegistrationSuccessPanel($rows); ?>
            <dl class="detail-list detail-list--compact">
                <div class="detail-list__row"><dt>Name</dt><dd>Jane Smith</dd></div>
                <div class="detail-list__row"><dt>Email</dt><dd>jane.smith@example.com</dd></div>
            </dl>
            <h2 class="form-section-title">Your Events</h2>
            <article class="status-card">
                <div class="status-card__header">
                    <strong>Sample Festival - 15 Jul 2026</strong>
                    <span class="badge badge--pending">Pending</span>
                </div>
                <p class="status-card__meta">Awaiting admin approval.</p>
            </article>
        </section>
    </main>
</body>
</html>

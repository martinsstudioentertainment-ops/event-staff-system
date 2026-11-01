<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/pwa-install-tracking.php';
require_once __DIR__ . '/../includes/date-format.php';

requireAdminCapability('settings');

$pdo    = getDB();
$stats  = getPwaInstallStats($pdo);
$flash  = getAdminFlash();

$pageTitle  = 'App installs';
$activePage = 'settings';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<?php if ($flash): ?>
    <div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">App installs</h2>
            <p class="card__subtitle">Home-screen installs and standalone app opens (staff + admin PWA).</p>
        </div>
        <a href="settings-production.php#pwa-push" class="btn btn--secondary">PWA settings</a>
    </div>

    <div class="platform-ops-metrics" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:1rem;margin-bottom:1.5rem;">
        <div class="platform-ops-metric">
            <div class="platform-ops-metric__value"><?= (int) $stats['installed_total'] ?></div>
            <div class="platform-ops-metric__label">Devices installed</div>
        </div>
        <div class="platform-ops-metric">
            <div class="platform-ops-metric__value"><?= (int) $stats['standalone_devices'] ?></div>
            <div class="platform-ops-metric__label">Using app (standalone)</div>
        </div>
        <div class="platform-ops-metric">
            <div class="platform-ops-metric__value"><?= (int) ($stats['by_context']['staff'] ?? 0) ?></div>
            <div class="platform-ops-metric__label">Staff app installs</div>
        </div>
        <div class="platform-ops-metric">
            <div class="platform-ops-metric__value"><?= (int) ($stats['by_context']['admin'] ?? 0) ?></div>
            <div class="platform-ops-metric__label">Admin app installs</div>
        </div>
    </div>

    <h3 class="form-section-title">Installed by device</h3>
    <div class="table-wrap table-wrap--fit" style="margin-bottom:1.5rem;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Platform</th>
                    <th>Installs</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($stats['by_platform'] as $platform => $count): ?>
                    <?php if ((int) $count < 1) {
                        continue;
                    } ?>
                    <tr>
                        <td><?= h(formatPwaInstallPlatformLabel((string) $platform)) ?></td>
                        <td><?= (int) $count ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ((int) $stats['installed_total'] === 0): ?>
                    <tr>
                        <td colspan="2" class="form-hint">No installs recorded yet. Tracking starts when staff or admins add the app to their home screen.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <h3 class="form-section-title">Recent activity</h3>
    <p class="form-hint">iOS installs are counted when someone opens the app from their home screen (Safari does not send an install event).</p>
    <?php if ($stats['recent'] === []): ?>
        <p class="form-hint">No events yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>App</th>
                        <th>Event</th>
                        <th>Device</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stats['recent'] as $row): ?>
                        <tr>
                            <td><?= h(formatSystemDateTime((string) ($row['created_at'] ?? ''), $pdo)) ?></td>
                            <td><?= h((string) ($row['context'] ?? '') === 'admin' ? 'Admin' : 'Staff') ?></td>
                            <td><?= h((string) ($row['event_type'] ?? '') === 'installed' ? 'Installed' : 'Opened standalone') ?></td>
                            <td><?= h(formatPwaInstallPlatformLabel((string) ($row['platform'] ?? 'unknown'))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>

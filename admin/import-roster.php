<?php
/**
 * Import summer roster while logged into admin (no token needed).
 * URL: https://admin.olasentra.com/import-roster.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/site-urls.php';
require_once __DIR__ . '/../includes/live-events-sync.php';
require_once __DIR__ . '/../includes/audit-log.php';

requireAdminCapability('events');

$pdo          = getDB();
$importResult = null;
$importError  = null;
$ranImport    = $_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['run']);

if ($ranImport) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrf($_POST['csrf_token'] ?? null)) {
        setAdminFlash('error', 'Invalid request. Please try again.');
        header('Location: import-roster.php');
        exit;
    }

    try {
        $importResult = syncLiveEventsFromMasterFile($pdo, false);
        logAdminAudit(
            $pdo,
            'import_live_roster',
            'system',
            0,
            'created ' . (int) $importResult['created'] . ', updated ' . (int) $importResult['updated']
        );
    } catch (Throwable $e) {
        $importError = $e->getMessage();
    }
}

$masterPath = getLiveEventsMasterFilePath();
$regUrl     = getRegistrationSiteUrl($pdo);
$sample     = $importResult !== null ? getLiveRosterSampleEvent($pdo, 'Nick Cave') : null;

$pageTitle  = 'Import summer roster';
$activePage = 'events';
$flash      = getAdminFlash();

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<?php if ($flash): ?>
    <div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<?php if ($importError !== null): ?>
    <div class="alert alert--error alert--visible"><?= h($importError) ?></div>
<?php endif; ?>

<?php if ($importResult !== null): ?>
    <div class="alert alert--success alert--visible">
        Summer roster imported — created <?= (int) $importResult['created'] ?>,
        updated <?= (int) $importResult['updated'] ?>,
        skipped <?= (int) $importResult['skipped'] ?>.
    </div>
<?php endif; ?>

<section class="card">
    <div class="card__header">
        <h2 class="card__title">Import summer roster</h2>
        <p class="card__subtitle">
            Loads all 32 events from <code>database/live-events-2026.php</code> into the database.
        </p>
    </div>

    <?php if (!$ranImport || $importResult === null): ?>
        <ul class="form-hint" style="margin:0 0 1.25rem 1.25rem">
            <li>Location / venue</li>
            <li>Staff needed</li>
            <li>Times (where set)</li>
            <li>On-site company: only if you set it per event (optional)</li>
        </ul>

        <dl class="detail-list" style="margin-bottom:1.25rem">
            <div class="detail-list__row">
                <dt>Master file on server</dt>
                <dd><?= is_file($masterPath) ? 'Yes — ' . h($masterPath) : 'Missing — deploy Git first' ?></dd>
            </div>
        </dl>

        <form method="post" class="toolbar">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <button type="submit" class="btn btn--primary">Run import now</button>
            <a href="events.php" class="btn btn--secondary">← Events</a>
            <a href="roster-diagnostic.php" class="btn btn--secondary">Roster diagnostic</a>
        </form>
    <?php else: ?>
        <?php
        $contractor = trim((string) ($importResult['main_security_company'] ?? ''));
        ?>
        <dl class="detail-list">
            <div class="detail-list__row">
                <dt>Listed contractor (roster default)</dt>
                <dd><?= $contractor !== '' ? h($contractor) : 'none — portal-only' ?></dd>
            </div>
            <div class="detail-list__row">
                <dt>Created / updated / skipped</dt>
                <dd>
                    <?= (int) $importResult['created'] ?> /
                    <?= (int) $importResult['updated'] ?> /
                    <?= (int) $importResult['skipped'] ?>
                </dd>
            </div>
        </dl>

        <?php if ($importResult['messages'] !== []): ?>
            <h3 class="form-section-title">Import log</h3>
            <ul class="form-hint" style="margin:0 0 1rem 1.25rem;max-height:240px;overflow:auto">
                <?php foreach (array_slice($importResult['messages'], 0, 40) as $line): ?>
                    <li><?= h((string) $line) ?></li>
                <?php endforeach; ?>
                <?php if (count($importResult['messages']) > 40): ?>
                    <li>… and <?= count($importResult['messages']) - 40 ?> more</li>
                <?php endif; ?>
            </ul>
        <?php endif; ?>

        <?php if ($importResult['errors'] !== []): ?>
            <div class="alert alert--error alert--visible">
                <strong>Errors</strong>
                <ul style="margin:0.5rem 0 0 1.25rem">
                    <?php foreach ($importResult['errors'] as $err): ?>
                        <li><?= h((string) $err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <h3 class="form-section-title">Verify (Nick Cave sample)</h3>
        <pre style="margin:0 0 1rem;white-space:pre-wrap;background:var(--color-surface-muted, #f1f5f9);padding:1rem;border-radius:6px"><?= h(json_encode($sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: 'not found') ?></pre>

        <?php if ($sample && trim((string) ($sample['main_security_company'] ?? '')) === ''): ?>
            <div class="alert alert--error alert--visible">
                <strong>main_security_company still empty</strong> — check database/migrate-phase33 or contact support.
            </div>
        <?php endif; ?>

        <div class="toolbar">
            <a href="<?= h($regUrl) ?>" class="btn btn--primary" target="_blank" rel="noopener">Open registration form ↗</a>
            <a href="roster-diagnostic.php" class="btn btn--secondary">Full diagnostic</a>
            <a href="import-roster.php" class="btn btn--secondary">Import again</a>
            <a href="events.php" class="btn btn--secondary">← Events</a>
        </div>
        <p class="form-hint" style="margin-top:0.75rem">Hard refresh the registration site (Ctrl+F5) after import.</p>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>

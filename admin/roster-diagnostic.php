<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/site-urls.php';
require_once __DIR__ . '/../includes/live-events-sync.php';
require_once __DIR__ . '/../includes/registration-options-repository.php';

requireAdminCapability('events');

$pdo = getDB();
$sample = getLiveRosterSampleEvent($pdo, 'Nick Cave');
$master = getLiveEventsMasterFilePath();
$opts   = getRegistrationOptionsForForm($pdo, 'dsp');
$apiEv  = null;
foreach ($opts['eventsByVenue'] ?? [] as $list) {
    foreach ($list as $ev) {
        if (stripos((string) ($ev['name'] ?? ''), 'Nick Cave') !== false) {
            $apiEv = $ev;
            break 2;
        }
    }
}

$pageTitle  = 'Roster diagnostic';
$activePage = 'events';
include __DIR__ . '/../includes/admin/layout-top.php';
?>

<section class="card">
    <div class="card__header">
        <h2 class="card__title">Roster diagnostic</h2>
        <p class="card__subtitle">Shows whether the database and registration API have your summer roster data.</p>
    </div>

    <dl class="detail-list">
        <div class="detail-list__row">
            <dt>Master file on server</dt>
            <dd><?= is_file($master) ? 'Yes — ' . h($master) : 'Missing — deploy Git first' ?></dd>
        </div>
        <div class="detail-list__row">
            <dt>DB: Nick Cave (sample)</dt>
            <dd><pre style="margin:0;white-space:pre-wrap"><?= h(json_encode($sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: 'not found') ?></pre></dd>
        </div>
        <div class="detail-list__row">
            <dt>API: Nick Cave (registration)</dt>
            <dd><pre style="margin:0;white-space:pre-wrap"><?= h(json_encode($apiEv, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: 'not in dsp form') ?></pre></dd>
        </div>
    </dl>

    <?php
    $ok = $sample
        && trim((string) ($sample['main_security_company'] ?? '')) !== ''
        && (int) ($sample['venue_id'] ?? 0) > 0
        && $apiEv
        && trim((string) ($apiEv['mainSecurityCompany'] ?? '')) !== '';
    ?>
    <?php if ($ok): ?>
        <div class="alert alert--success alert--visible">Roster looks correct. Hard refresh <a href="<?= h(getRegistrationSiteUrl($pdo)) ?>" target="_blank" rel="noopener">registration form</a> (Ctrl+F5).</div>
    <?php else: ?>
        <div class="alert alert--error alert--visible">
            Roster not applied to the database or API yet.
            <a href="import-roster.php">Run import now</a>, then reload this page.
        </div>
    <?php endif; ?>

    <div class="toolbar" style="margin-top:1rem">
        <a href="import-roster.php" class="btn btn--primary">Import master roster</a>
        <a href="events.php" class="btn btn--secondary">Events</a>
    </div>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>

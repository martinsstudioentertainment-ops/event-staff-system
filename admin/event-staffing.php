<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/workforce/workforce-analytics.php';

requireAdminCapability('staff');

$pdo     = getDB();
$eventId = (int) ($_GET['event_id'] ?? 0);
$events  = wf_get_event_staffing_analysis($pdo, $eventId > 0 ? $eventId : null, 100);

try {
    $eventOptions = $pdo->query(
        "SELECT id, name, event_date FROM events WHERE is_active = 1 ORDER BY event_date DESC LIMIT 200"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $eventOptions = [];
}

$pageTitle  = 'Event Staffing Intelligence';
$activePage = 'event-staffing';
$erpPageContentClass = 'wf-page';

include __DIR__ . '/../includes/admin/layout-top.php';
?>
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/platform-ops.css">
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/workforce-suite.css">

<div class="wf-hero">
    <div>
        <h1 class="wf-hero__title">Event Staffing Intelligence</h1>
        <p class="wf-hero__subtitle">Required vs approved staff, check-ins, absences, reliability averages, and staffing scores per event.</p>
    </div>
    <form method="get" class="wf-toolbar">
        <select name="event_id" class="input" onchange="this.form.submit()">
            <option value="">All upcoming events</option>
            <?php foreach ($eventOptions as $opt): ?>
                <option value="<?= (int) ($opt['id'] ?? 0) ?>" <?= $eventId === (int) ($opt['id'] ?? 0) ? 'selected' : '' ?>>
                    <?= h((string) ($opt['name'] ?? '')) ?> — <?= h(formatSystemDate((string) ($opt['event_date'] ?? ''), $pdo)) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<section class="card erp-card">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Event</th>
                    <th>Date</th>
                    <th>Required</th>
                    <th>Approved</th>
                    <th>Checked in</th>
                    <th>Absent</th>
                    <th>Late</th>
                    <th>Reliability avg</th>
                    <th>Att. risk</th>
                    <th>Staffing score</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if ($events === []): ?>
                <tr><td colspan="11" class="data-table__empty">No events to analyse.</td></tr>
            <?php else: ?>
                <?php foreach ($events as $row): ?>
                    <?php
                    $attRisk = (string) ($row['attendance_risk'] ?? 'low');
                    $riskClass = $attRisk === 'high' ? 'red' : ($attRisk === 'medium' ? 'amber' : 'green');
                    $score = (int) ($row['staffing_score'] ?? 0);
                    $scoreClass = $score >= 70 ? 'high' : ($score >= 50 ? 'mid' : 'low');
                    ?>
                    <tr>
                        <td><strong><?= h((string) ($row['name'] ?? '')) ?></strong></td>
                        <td><?= h(formatSystemDate((string) ($row['event_date'] ?? ''), $pdo)) ?></td>
                        <td><?= (int) ($row['staff_needed'] ?? 0) ?></td>
                        <td><?= (int) ($row['approved'] ?? 0) ?></td>
                        <td><?= (int) ($row['checked_in'] ?? 0) ?></td>
                        <td><?= (int) ($row['absent'] ?? 0) ?></td>
                        <td><?= (int) ($row['late_cnt'] ?? 0) ?></td>
                        <td><?= (int) ($row['reliability_avg'] ?? 0) ?></td>
                        <td><span class="wf-risk wf-risk--<?= h($riskClass) ?>"><?= h(ucfirst($attRisk)) ?></span></td>
                        <td><span class="wf-score wf-score--<?= h($scoreClass) ?>"><?= $score ?></span></td>
                        <td><a class="btn btn--secondary" href="event-hub.php?event_id=<?= (int) ($row['id'] ?? 0) ?>">Hub</a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>

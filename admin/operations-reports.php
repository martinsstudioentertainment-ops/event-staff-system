<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';

requireAdminCapability('export');

$pageTitle  = 'Operations Reporting Centre';
$activePage = 'operations-reports';
$erpPageContentClass = 'wf-page';

$reports = [
    [
        'title'       => 'Attendance',
        'description' => 'Export check-ins, late arrivals, and GPS exceptions.',
        'csv'         => 'export-attendance.php',
        'excel'       => 'export-attendance.php',
    ],
    [
        'title'       => 'Event sign-ins',
        'description' => 'Everyone who signed in for a selected event (self, manual desk, or QR scan).',
        'csv'         => 'export-event-signins.php?format=csv',
        'excel'       => 'export-event-signins.php?format=xlsx',
    ],
    [
        'title'       => 'Work hours / payroll prep',
        'description' => 'Hours worked by staff and event for payroll preparation.',
        'csv'         => 'export-work-hours.php',
        'excel'       => 'export-work-hours.php',
    ],
    [
        'title'       => 'Staff directory',
        'description' => 'Full staff export with contact and profile fields.',
        'csv'         => 'export-staff.php',
        'excel'       => 'export-staff.php',
    ],
    [
        'title'       => 'Staff pool download',
        'description' => 'Active staff pool payroll columns - complete or incomplete profiles.',
        'csv'         => 'staff-download.php',
        'excel'       => 'staff-download.php',
        'pdf'         => 'staff-download.php',
    ],
    [
        'title'       => 'Staff reliability',
        'description' => 'Performance centre with reliability scores � use browser print for PDF.',
        'csv'         => 'workforce-performance.php?period=30d',
        'pdf'         => 'workforce-performance.php?period=30d',
    ],
    [
        'title'       => 'GPS compliance',
        'description' => 'Attendance export includes GPS failure and manual review statuses.',
        'csv'         => 'export-attendance.php',
    ],
    [
        'title'       => 'Event performance',
        'description' => 'Event staffing intelligence � staffing scores and attendance risk.',
        'csv'         => 'event-staffing.php',
        'pdf'         => 'event-staffing.php',
    ],
    [
        'title'       => 'Finance / invoices',
        'description' => 'Commission invoices and monthly invoice exports.',
        'csv'         => adminCan('invoices') ? 'export-invoices-month.php' : null,
        'excel'       => adminCan('invoices') ? 'exports.php' : null,
    ],
];

include __DIR__ . '/../includes/admin/layout-top.php';
?>
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/platform-ops.css">
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/workforce-suite.css">

<div class="wf-hero">
    <div>
        <h1 class="wf-hero__title">Operations Reporting Centre</h1>
        <p class="wf-hero__subtitle">Attendance, finance, reliability, GPS compliance, event performance, and payroll exports.</p>
    </div>
</div>

<section class="card erp-card">
    <div class="wf-report-grid">
        <?php foreach ($reports as $report): ?>
            <article class="wf-report-card">
                <h3><?= h((string) $report['title']) ?></h3>
                <p><?= h((string) $report['description']) ?></p>
                <div class="wf-actions">
                    <?php if (!empty($report['csv'])): ?>
                        <a class="btn btn--primary" href="<?= h((string) $report['csv']) ?>">CSV export</a>
                    <?php endif; ?>
                    <?php if (!empty($report['excel'])): ?>
                        <a class="btn btn--secondary" href="<?= h((string) $report['excel']) ?>">Excel / open</a>
                    <?php endif; ?>
                    <?php if (!empty($report['pdf'])): ?>
                        <a class="btn btn--secondary" href="<?= h((string) $report['pdf']) ?>" target="_blank" rel="noopener">View / print PDF</a>
                    <?php endif; ?>
                    <?php if (empty($report['csv']) && empty($report['excel']) && empty($report['pdf'])): ?>
                        <span class="text-muted">Requires invoice permission</span>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>

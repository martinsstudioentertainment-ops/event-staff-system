<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';

requireAdminCapability('export');

$pageTitle  = 'Staff pool download';
$activePage = 'staff-download';
$erpPageContentClass = 'auto-page wf-page';

include __DIR__ . '/../includes/admin/layout-top.php';
?>
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/platform-ops.css">
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/workforce-suite.css">

<div class="wf-hero">
    <div>
        <h1 class="wf-hero__title">Staff pool download</h1>
        <p class="wf-hero__subtitle">Export active staff pool records (not registrations) with payroll spreadsheet columns. Choose complete or incomplete profiles.</p>
    </div>
</div>

<section class="card erp-card">
    <div class="wf-report-grid">
        <article class="wf-report-card">
            <h3>Complete profiles</h3>
            <p>Active staff with all required profile fields filled in.</p>
            <div class="wf-actions">
                <a class="btn btn--primary" href="staff-download-export.php?profile=complete&amp;format=xlsx">Excel download</a>
                <a class="btn btn--secondary" href="staff-download-export.php?profile=complete&amp;format=pdf" target="_blank" rel="noopener">View / print PDF</a>
            </div>
        </article>
        <article class="wf-report-card">
            <h3>Incomplete profiles</h3>
            <p>Active staff missing one or more required profile fields.</p>
            <div class="wf-actions">
                <a class="btn btn--primary" href="staff-download-export.php?profile=incomplete&amp;format=xlsx">Excel download</a>
                <a class="btn btn--secondary" href="staff-download-export.php?profile=incomplete&amp;format=pdf" target="_blank" rel="noopener">View / print PDF</a>
            </div>
        </article>
    </div>
    <p class="card__subtitle">Excel uses payroll columns (name, address, bank, etc.). If ZipArchive is unavailable, Excel downloads fall back to CSV.</p>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>

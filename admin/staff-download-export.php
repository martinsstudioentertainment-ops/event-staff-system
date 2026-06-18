<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/staff-roster-download.php';
require_once __DIR__ . '/../includes/audit-log.php';

requireAdminCapability('export');

$format  = trim((string) ($_GET['format'] ?? ''));
$profile = trim((string) ($_GET['profile'] ?? ''));

if ($format === '' || $profile === '') {
    setAdminFlash('error', 'Choose a profile and format to download.');
    header('Location: staff-download.php');
    exit;
}

try {
    $format  = staffRosterDownloadNormalizeFormat($format);
    $profile = staffRosterDownloadNormalizeProfile($profile);
} catch (InvalidArgumentException $e) {
    setAdminFlash('error', $e->getMessage());
    header('Location: staff-download.php');
    exit;
}

$pdo  = getDB();
$rows = staffRosterDownloadFetchRows($pdo, $profile);
$sheet = staffRosterDownloadBuildSheetRows($rows);

if (function_exists('logAdminAudit')) {
    logAdminAudit(
        $pdo,
        'export_staff_pool',
        'staff',
        null,
        $profile . ' / ' . $format . ' / ' . count($rows) . ' rows'
    );
}

$basename = 'staff-pool-' . $profile . '-' . date('Y-m-d');

if ($format === 'xlsx') {
    staffRosterSendXlsxDownload($sheet['headers'], $sheet['sheetRows'], $basename);
    exit;
}

$siteName = function_exists('getSiteName') ? getSiteName($pdo) : 'Staff pool';
$title    = 'Staff pool — ' . ($profile === 'complete' ? 'Complete profiles' : 'Incomplete profiles');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($title) ?></title>
    <style>
        body { font-family: Segoe UI, Arial, sans-serif; color: #111; padding: 1.25rem; max-width: 1200px; margin: 0 auto; }
        .print-header { border-bottom: 2px solid #111; padding-bottom: 1rem; margin-bottom: 1rem; }
        .print-header h1 { margin: 0 0 0.35rem; font-size: 1.5rem; }
        .print-meta { color: #475569; margin: 0; line-height: 1.6; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th, td { border: 1px solid #cbd5e1; padding: 0.45rem 0.5rem; text-align: left; vertical-align: top; }
        th { background: #f8fafc; }
        @media print {
            body { padding: 0; }
            tr { page-break-inside: avoid; }
        }
    </style>
</head>
<body>
    <header class="print-header">
        <p style="margin:0 0 0.25rem;font-size:12px;text-transform:uppercase;letter-spacing:0.08em;color:#64748b;"><?= h($siteName) ?></p>
        <h1><?= h($title) ?></h1>
        <p class="print-meta">Generated <?= h(date('Y-m-d H:i')) ?> · <?= count($rows) ?> staff</p>
    </header>

    <table>
        <thead>
            <tr>
                <?php foreach ($sheet['headers'] as $header): ?>
                    <th><?= h($header) ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php if ($sheet['sheetRows'] === []): ?>
                <tr><td colspan="<?= count($sheet['headers']) ?>">No staff match this profile filter.</td></tr>
            <?php else: ?>
                <?php foreach ($sheet['sheetRows'] as $row): ?>
                    <tr>
                        <?php foreach ($row as $cell): ?>
                            <td><?= h((string) $cell) ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <script>window.addEventListener('load', function () { window.print(); });</script>
</body>
</html>

<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/events-repository.php';
require_once __DIR__ . '/../includes/staff-repository.php';
require_once __DIR__ . '/../includes/attendance-repository.php';

requireAdminCapability('events');

$pdo     = getDB();
$eventId = (int) ($_GET['event_id'] ?? 0);
$events  = getEventsForFilter($pdo);
$list    = $eventId > 0 ? getAttendanceList($pdo, $eventId) : [];
$event   = $eventId > 0 ? getEventById($pdo, $eventId) : null;

if ($eventId <= 0 || !$event) {
    setAdminFlash('error', 'Please select an event to print QR codes.');
    header('Location: attendance.php');
    exit;
}

$siteName = getSiteName($pdo);
$assetBase = '../';
$themeColor = '#2563eb';
require_once __DIR__ . '/../includes/theme.php';
$themeColor = getThemeColor($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print QR Codes — <?= h($event['name']) ?></title>
    <?php include __DIR__ . '/../includes/pwa-head.php'; ?>
    <style>
        body { background: #fff; color: #111; }
        .print-toolbar {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: 1.5rem;
        }
        .print-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem;
        }
        .print-card {
            border: 1px solid #d1d5db;
            border-radius: 12px;
            padding: 1rem;
            page-break-inside: avoid;
            text-align: center;
        }
        .print-card__event {
            font-size: 12px;
            color: #64748b;
            margin-bottom: 0.5rem;
        }
        .print-card__name {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        .print-card__role {
            font-size: 13px;
            margin-bottom: 0.75rem;
        }
        .print-card__qr img {
            margin: 0 auto;
        }
        .print-card__url {
            font-size: 10px;
            word-break: break-all;
            color: #64748b;
            margin-top: 0.5rem;
        }
        @media print {
            .print-toolbar, .no-print { display: none !important; }
            .print-grid { grid-template-columns: repeat(2, 1fr); }
            body { padding: 0; }
        }
        @media (max-width: 768px) {
            .print-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body class="page-content">
    <div class="print-toolbar no-print">
        <button type="button" class="btn btn--primary" onclick="window.print()">Print All QR Codes</button>
        <a href="attendance.php?event_id=<?= (int) $eventId ?>" class="btn btn--secondary">← Back to Attendance</a>
    </div>

    <header class="card__header" style="margin-bottom: 1.5rem;">
        <h1 class="card__title"><?= h($event['name']) ?> — QR Check-in Sheets</h1>
        <p class="card__subtitle"><?= h($siteName) ?> · <?= h(formatEventDateLabel($event['event_date'])) ?> · <?= count($list) ?> approved staff</p>
    </header>

    <?php if ($list === []): ?>
        <div class="alert alert--warning alert--visible">No approved staff for this event yet.</div>
    <?php else: ?>
        <div class="print-grid">
            <?php foreach ($list as $row): ?>
                <?php
                $token = ensureCheckinToken($pdo, (int) $row['id']);
                $url   = $token ? getCheckinUrl($token, $pdo) : '';
                $qrUrl = $url !== '' ? 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' . urlencode($url) : '';
                ?>
                <article class="print-card">
                    <p class="print-card__event"><?= h(formatEventLabel($row)) ?></p>
                    <p class="print-card__name"><?= h($row['first_name'] . ' ' . $row['surname']) ?></p>
                    <p class="print-card__role"><?= h(formatRoleLabel($row['staff_role'])) ?></p>
                    <?php if ($qrUrl !== ''): ?>
                        <div class="print-card__qr">
                            <img src="<?= h($qrUrl) ?>" width="180" height="180" alt="QR code for <?= h($row['first_name']) ?>">
                        </div>
                        <p class="print-card__url"><?= h($url) ?></p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</body>
</html>

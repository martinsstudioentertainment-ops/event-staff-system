<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
initSecureSession();
require_once __DIR__ . '/../includes/staff-portal-session.php';
require_once __DIR__ . '/../includes/staff-profile-gate.php';
require_once __DIR__ . '/../includes/public/staff-public-shell.php';
require_once __DIR__ . '/../includes/brand-logo.php';
require_once __DIR__ . '/../includes/theme.php';
require_once __DIR__ . '/../includes/settings-repository.php';
require_once __DIR__ . '/../includes/automation/staff-portal.php';

$pdo   = getDB();
$staff = getStaffFromPortalSession($pdo);
if ($staff === null) {
    header('Location: ../staff-portal.php?return=portal/staff-dashboard.php');
    exit;
}

auto_ensure_schema($pdo);
auto_ensure_phase67_schema($pdo);

$email   = strtolower(trim((string) ($staff['email'] ?? '')));
$staffId = (int) ($staff['id'] ?? 0);
$month   = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['month'] ?? '')) ? (string) $_GET['month'] : date('Y-m');
$tab     = in_array($_GET['tab'] ?? '', ['assignments', 'attendance', 'documents', 'availability', 'notifications'], true)
    ? (string) $_GET['tab'] : 'assignments';
$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'shift_response') {
        $ok = ssp_set_shift_response($pdo, (int) ($_POST['registration_id'] ?? 0), $email, (string) ($_POST['response'] ?? ''));
        $success = $ok ? 'Shift response saved.' : 'Could not update shift.';
    } elseif ($action === 'confirm_attendance') {
        $ok = portal_confirm_attendance($pdo, (int) ($_POST['registration_id'] ?? 0), $email);
        $success = $ok ? 'Attendance confirmed for this shift.' : 'Could not confirm.';
    } elseif ($action === 'availability') {
        $ok = ssp_confirm_availability($pdo, $staffId, (string) ($_POST['avail_date'] ?? ''), (string) ($_POST['status'] ?? 'available'), (string) ($_POST['notes'] ?? ''));
        $success = $ok ? 'Availability saved.' : 'Could not save availability.';
    } elseif ($action === 'leave') {
        $ok = ssp_request_leave($pdo, $staffId, (string) ($_POST['avail_date'] ?? ''), (string) ($_POST['leave_type'] ?? 'leave'), (string) ($_POST['notes'] ?? ''));
        $success = $ok ? 'Leave request submitted.' : 'Could not submit leave request.';
    }
    header('Location: staff-dashboard.php?tab=' . urlencode($tab) . '&month=' . urlencode($month));
    exit;
}

$assignments  = ssp_get_assignments($pdo, $email);
$upcoming     = portal_upcoming_events($pdo, $email);
$attendance   = ssp_attendance_history($pdo, $email, 40);
$reliability  = ssp_reliability_for_staff($pdo, $staffId);
$psaStatus    = wf_psa_compliance_status((string) ($staff['psa_expiry_date'] ?? ''), (string) ($staff['psa_licence'] ?? ''));
$documents    = portal_staff_documents($pdo, $staff);
$availability = portal_availability_month($pdo, $staffId, $month);
$notifications = portal_notifications($pdo, $email);

$siteName  = getSiteName($pdo);
$assetBase = '../';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Staff Dashboard | <?= h($siteName) ?></title>
    <?php include __DIR__ . '/../includes/pwa-head.php'; ?>
    <link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/admin-v3.css">
    <link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/workforce-suite.css">
    <style>
        .portal-dash { max-width: 960px; margin: 0 auto; padding: 1rem; }
        .portal-tabs { display: flex; flex-wrap: wrap; gap: 0.35rem; margin: 1rem 0; }
        .portal-tabs a { padding: 0.4rem 0.75rem; border-radius: 999px; font-size: 0.82rem; text-decoration: none; background: rgba(15,23,42,0.06); color: #334155; }
        .portal-tabs a.is-active { background: #4f46e5; color: #fff; }
        .portal-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 0.65rem; margin-bottom: 1rem; }
        .portal-kpi { padding: 0.75rem; border-radius: 10px; background: #fff; border: 1px solid rgba(148,163,184,0.25); text-align: center; }
        .portal-kpi__val { font-size: 1.35rem; font-weight: 700; }
        .portal-kpi__lbl { font-size: 0.72rem; color: #64748b; text-transform: uppercase; }
        .portal-section { background: #fff; border-radius: 12px; border: 1px solid rgba(148,163,184,0.2); padding: 1rem; margin-bottom: 1rem; }
        .portal-cal { display: grid; grid-template-columns: repeat(7, 1fr); gap: 0.25rem; font-size: 0.72rem; }
        .portal-cal-day { min-height: 48px; padding: 0.25rem; border-radius: 6px; background: #f8fafc; border: 1px solid #e2e8f0; }
    </style>
</head>
<body class="staff-public-shell staff-mobile-page">
<?php renderStaffPublicBackground(true); ?>
<?php renderStaffPublicHeader($pdo, $siteName, ['home_url' => $assetBase . 'staff-app.php']); ?>

<main class="portal-dash staff-public-main">
    <section class="card staff-public-card">
        <h1 class="card__title">Staff Dashboard</h1>
        <p class="card__subtitle">Welcome, <?= h(trim(((string) ($staff['first_name'] ?? '')) . ' ' . ((string) ($staff['surname'] ?? '')))) ?></p>

        <?php if ($success): ?><div class="alert alert--success alert--visible"><?= h($success) ?></div><?php endif; ?>

        <div class="portal-kpis">
            <div class="portal-kpi"><div class="portal-kpi__val"><?= (int) ($reliability['score'] ?? 0) ?></div><div class="portal-kpi__lbl">Reliability</div></div>
            <div class="portal-kpi"><div class="portal-kpi__val"><?= h(ucfirst($psaStatus)) ?></div><div class="portal-kpi__lbl">Compliance</div></div>
            <div class="portal-kpi"><div class="portal-kpi__val"><?= count($upcoming) ?></div><div class="portal-kpi__lbl">Upcoming</div></div>
            <div class="portal-kpi"><div class="portal-kpi__val"><?= count($notifications) ?></div><div class="portal-kpi__lbl">Notifications</div></div>
        </div>

        <nav class="portal-tabs" aria-label="Portal sections">
            <?php foreach (['assignments' => 'My assignments', 'attendance' => 'Attendance', 'documents' => 'Documents', 'availability' => 'Availability', 'notifications' => 'Notifications'] as $key => $label): ?>
                <a href="staff-dashboard.php?tab=<?= h($key) ?>" class="<?= $tab === $key ? 'is-active' : '' ?>"><?= h($label) ?></a>
            <?php endforeach; ?>
        </nav>

        <?php if ($tab === 'assignments'): ?>
        <div class="portal-section">
            <h2 style="font-size:1rem;margin:0 0 0.75rem;">Upcoming events</h2>
            <?php if ($upcoming === []): ?><p>No upcoming approved shifts.</p><?php else: ?>
            <table class="data-table">
                <thead><tr><th>Event</th><th>Date</th><th>Location</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($upcoming as $a): ?>
                    <tr>
                        <td><?= h((string) ($a['event_name'] ?? '')) ?></td>
                        <td><?= h(formatSystemDate((string) ($a['event_date'] ?? ''), $pdo)) ?></td>
                        <td><?= h((string) ($a['location'] ?? '—')) ?></td>
                        <td><?= h((string) ($a['shift_response'] ?? 'pending')) ?></td>
                        <td>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="action" value="shift_response">
                                <input type="hidden" name="registration_id" value="<?= (int) ($a['id'] ?? 0) ?>">
                                <button name="response" value="accepted" class="btn btn--primary btn--sm">Accept</button>
                                <button name="response" value="declined" class="btn btn--secondary btn--sm">Decline</button>
                            </form>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="action" value="confirm_attendance">
                                <input type="hidden" name="registration_id" value="<?= (int) ($a['id'] ?? 0) ?>">
                                <button class="btn btn--secondary btn--sm">Confirm</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($tab === 'attendance'): ?>
        <div class="portal-section">
            <h2 style="font-size:1rem;margin:0 0 0.75rem;">Attendance history</h2>
            <?php if ($attendance === []): ?><p>No check-ins recorded yet.</p><?php else: ?>
            <ul><?php foreach ($attendance as $att): ?>
                <li><?= h((string) ($att['event_name'] ?? '')) ?> — <?= h(formatSystemDateTime((string) ($att['checked_in_at'] ?? ''), $pdo)) ?>
                    <?php if (($att['attendance_status'] ?? '') !== ''): ?> (<?= h((string) $att['attendance_status']) ?>)<?php endif; ?>
                </li>
            <?php endforeach; ?></ul>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($tab === 'documents'): ?>
        <div class="portal-section">
            <h2 style="font-size:1rem;margin:0 0 0.75rem;">My documents</h2>
            <?php if ($documents === []): ?><p>No documents on file. <a href="<?= h($assetBase) ?>staff-profile.php">Update profile</a> to upload PSA images.</p><?php else: ?>
            <ul><?php foreach ($documents as $doc): ?>
                <li><?= h((string) ($doc['label'] ?? '')) ?> — <?= h(ucfirst((string) ($doc['status'] ?? ''))) ?>
                    <?php if (($doc['expiry'] ?? '') !== ''): ?> · Expires <?= h(formatSystemDate((string) $doc['expiry'], $pdo)) ?><?php endif; ?>
                </li>
            <?php endforeach; ?></ul>
            <?php endif; ?>
            <p style="margin-top:0.75rem;"><a href="<?= h($assetBase) ?>staff-profile.php" class="btn btn--secondary">Upload / update documents</a></p>
        </div>
        <?php endif; ?>

        <?php if ($tab === 'availability'): ?>
        <div class="portal-section">
            <h2 style="font-size:1rem;margin:0 0 0.75rem;">Availability calendar</h2>
            <form method="get" style="margin-bottom:0.75rem;">
                <input type="hidden" name="tab" value="availability">
                <input type="month" name="month" value="<?= h($month) ?>" onchange="this.form.submit()">
            </form>
            <?php
            $byDate = [];
            foreach ($availability as $entry) {
                $byDate[(string) ($entry['avail_date'] ?? '')] = (string) ($entry['status'] ?? '');
            }
            $daysInMonth = (int) date('t', strtotime($month . '-01'));
            ?>
            <div class="portal-cal">
                <?php for ($d = 1; $d <= $daysInMonth; $d++):
                    $date = sprintf('%s-%02d', $month, $d);
                    $st = $byDate[$date] ?? '';
                ?>
                    <div class="portal-cal-day" title="<?= h($st) ?>"><strong><?= $d ?></strong><?php if ($st !== ''): ?><br><?= h(substr(str_replace('_', ' ', $st), 0, 8)) ?><?php endif; ?></div>
                <?php endfor; ?>
            </div>
            <form method="post" class="form-grid" style="margin-top:1rem;">
                <input type="hidden" name="action" value="availability">
                <div class="form-group"><label>Date</label><input type="date" name="avail_date" class="form-input" required></div>
                <div class="form-group"><label>Status</label><select name="status" class="form-input"><option value="available">Available</option><option value="unavailable">Unavailable</option></select></div>
                <div class="form-group form-group--full"><label>Notes</label><input type="text" name="notes" class="form-input"></div>
                <button type="submit" class="btn btn--primary">Mark availability</button>
            </form>
            <form method="post" class="form-grid" style="margin-top:0.75rem;">
                <input type="hidden" name="action" value="leave">
                <div class="form-group"><label>Leave date</label><input type="date" name="avail_date" class="form-input" required></div>
                <div class="form-group"><label>Type</label><select name="leave_type" class="form-input"><option value="leave">Leave</option><option value="holiday">Holiday</option></select></div>
                <div class="form-group form-group--full"><label>Reason</label><input type="text" name="notes" class="form-input"></div>
                <button type="submit" class="btn btn--secondary">Request leave</button>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($tab === 'notifications'): ?>
        <div class="portal-section">
            <h2 style="font-size:1rem;margin:0 0 0.75rem;">Notifications</h2>
            <?php if ($notifications === []): ?><p>No notifications.</p><?php else: ?>
            <ul><?php foreach ($notifications as $n): ?>
                <li><strong><?= h((string) ($n['title'] ?? '')) ?></strong> — <?= h((string) ($n['body'] ?? '')) ?></li>
            <?php endforeach; ?></ul>
            <?php endif; ?>
            <p><a href="<?= h($assetBase) ?>staff-notifications.php">View all notifications →</a></p>
        </div>
        <?php endif; ?>

        <p style="margin-top:1rem;">
            <a href="<?= h($assetBase) ?>staff-profile.php" class="btn btn--secondary">Update profile</a>
            <a href="<?= h($assetBase) ?>staff-app.php" class="btn btn--secondary">← Staff app</a>
        </p>
    </section>
</main>
</body>
</html>

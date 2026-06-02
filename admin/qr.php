<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/staff-repository.php';
require_once __DIR__ . '/../includes/attendance-repository.php';

requireAdminCapability('events');

$id  = (int) ($_GET['id'] ?? 0);
$pdo = getDB();
$row = $id > 0 ? getStaffRegistrationById($pdo, $id) : null;

if (!$row || $row['status'] !== 'approved') {
    setAdminFlash('error', 'Approved registration not found.');
    header('Location: attendance.php');
    exit;
}

$token = ensureCheckinToken($pdo, $id);
$url   = $token ? getCheckinUrl($token, $pdo) : '';
$qrUrl = $url !== '' ? 'https://api.qrserver.com/v1/create-qr-code/?size=260x260&data=' . urlencode($url) : '';

$pageTitle  = 'QR Check-in';
$activePage = 'attendance';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">QR Check-in Code</h2>
            <p class="card__subtitle"><?= h($row['first_name'] . ' ' . $row['surname']) ?> — <?= h(formatEventLabel($row)) ?></p>
        </div>
        <a href="attendance.php" class="btn btn--secondary">← Back to Attendance</a>
    </div>

    <?php if ($url === ''): ?>
        <div class="alert alert--error alert--visible">Unable to generate check-in link.</div>
    <?php else: ?>
        <div class="qr-panel">
            <img class="qr-panel__image" src="<?= h($qrUrl) ?>" width="260" height="260" alt="QR check-in code">
            <p class="form-hint">Staff scan this QR code on event day to check in.</p>
            <div class="qr-panel__link">
                <label class="form-label" for="checkin-url">Check-in link</label>
                <div class="copy-field">
                    <input class="form-input copy-field__input" type="text" id="checkin-url" readonly value="<?= h($url) ?>" onclick="this.select()">
                    <button type="button" class="btn btn--primary copy-field__btn" data-copy-target="checkin-url">Copy Link</button>
                </div>
            </div>
            <a href="<?= h($url) ?>" class="btn btn--secondary btn--block" target="_blank" rel="noopener">Open check-in page</a>
        </div>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>

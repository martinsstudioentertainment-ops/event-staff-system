<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/staff-messages.php';
require_once __DIR__ . '/../includes/staff-repository.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/components/message-thread.php';
require_once __DIR__ . '/../includes/rich-text.php';

requireAdminCapability('staff');

$pdo     = getDB();
$staffId = (int) ($_GET['staff_id'] ?? 0);
$emailParam = normalizeStaffMessageEmail((string) ($_GET['email'] ?? ''));
$staff   = null;

if ($emailParam !== '') {
    $staffId = resolveCanonicalStaffIdForEmail($pdo, $emailParam);
    $staff   = $staffId > 0 ? getStaffById($pdo, $staffId) : getStaffByEmail($pdo, $emailParam);
} elseif ($staffId > 0) {
    $staff = getStaffById($pdo, $staffId);
}

if ($staff !== null) {
    $canonicalId = resolveCanonicalStaffIdForEmail($pdo, (string) ($staff['email'] ?? ''));
    if ($canonicalId > 0) {
        $staffId = $canonicalId;
        $staff   = getStaffById($pdo, $staffId) ?? $staff;
    }
}

if ($staff === null) {
    setAdminFlash('error', 'Staff member not found.');
    header('Location: staff-inbox.php');
    exit;
}

$adminUser      = getAdminUser();
$flash          = '';
$viewRegId      = 0;
$staffEmail     = normalizeStaffMessageEmail((string) ($staff['email'] ?? ''));
if ($staffEmail !== '') {
    $latestReg = getLatestRegistrationByEmail($pdo, $staffEmail);
    $viewRegId = (int) ($latestReg['id'] ?? 0);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reply') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        $flash = 'Invalid request. Refresh and try again.';
    } else {
        $result = sendAdminReplyToStaff(
            $pdo,
            $staffId,
            richPost('body'),
            (int) ($adminUser['id'] ?? 0),
            (string) ($_POST['subject'] ?? '')
        );
        if (!empty($result['ok'])) {
            setAdminFlash('success', (string) ($result['message'] ?? 'Message sent.'));
            header('Location: staff-inbox-thread.php?staff_id=' . $staffId . '&email=' . rawurlencode($staffEmail));
            exit;
        }
        $flash = (string) ($result['message'] ?? 'Could not send message.');
    }
}

markStaffMessagesReadForAdmin($pdo, $staffId);
$mailboxEmail = $emailParam !== '' ? $emailParam : $staffEmail;
$messages = getStaffMessageThreadForEmail($pdo, $mailboxEmail);
if ($messages === [] && $staffId > 0) {
    $messages = getStaffMessageThread($pdo, $staffId);
}
$name     = trim((string) (($staff['first_name'] ?? '') . ' ' . ($staff['surname'] ?? '')));

$defaultSubject = 'Message from coordinator';
foreach (array_reverse($messages) as $msg) {
    $lastSubject = trim((string) ($msg['subject'] ?? ''));
    if ($lastSubject !== '') {
        $defaultSubject = str_starts_with(strtolower($lastSubject), 're:')
            ? $lastSubject
            : 'Re: ' . $lastSubject;
        break;
    }
}

$pageTitle  = 'Message — ' . ($name !== '' ? $name : 'Staff');
$activePage = 'staff-inbox';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/messages.css">

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title"><?= h($name !== '' ? $name : 'Staff member') ?></h2>
            <p class="card__subtitle"><?= h((string) ($staff['email'] ?? '')) ?> · <?= count($messages) ?> message<?= count($messages) === 1 ? '' : 's' ?> in this thread</p>
        </div>
        <div class="toolbar" style="gap:0.5rem">
            <?php if ($viewRegId > 0): ?>
                <a href="view-staff.php?id=<?= $viewRegId ?>" class="btn btn--secondary btn--small">Registration</a>
            <?php endif; ?>
            <a href="staff-directory.php?q=<?= rawurlencode($staffEmail) ?>" class="btn btn--secondary btn--small">Directory</a>
            <a href="communication-hub.php" class="btn btn--secondary btn--small">Bulk email</a>
            <a href="staff-inbox.php" class="btn btn--secondary btn--small">← Inbox</a>
        </div>
    </div>

    <?php if ($flash !== ''): ?>
        <div class="alert alert--error alert--visible"><?= h($flash) ?></div>
    <?php endif; ?>

    <?php renderMessageThread($messages, true, $pdo); ?>

    <form method="post" class="msg-compose">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="reply">
        <div class="form-group">
            <label class="form-label form-label--required" for="subject">Subject</label>
            <input class="form-input" type="text" id="subject" name="subject" maxlength="255" value="<?= h($defaultSubject) ?>" required>
        </div>
        <div class="form-group">
            <label class="form-label form-label--required" for="body">Message</label>
            <textarea class="form-input rich-text" id="body" name="body" rows="8" maxlength="8000" placeholder="Type your message to staff…" required></textarea>
            <p class="form-hint">Sent as a real email and saved in this conversation thread.</p>
        </div>
        <button type="submit" class="btn btn--primary">Send email</button>
    </form>
</section>

<?php $enableRichTextEditor = true; ?>
<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>

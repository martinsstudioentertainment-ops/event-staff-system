<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/events-repository.php';
require_once __DIR__ . '/../includes/staff-repository.php';
require_once __DIR__ . '/../includes/staff-messaging.php';
require_once __DIR__ . '/../includes/attendance-repository.php';
require_once __DIR__ . '/../includes/audit-log.php';

requireAdminCapability('staff');

$pdo     = getDB();
$eventId = (int) ($_GET['event_id'] ?? $_POST['event_id'] ?? 0);
$events  = getEventsForFilter($pdo);
$event   = $eventId > 0 ? getEventById($pdo, $eventId) : null;
$stats   = $eventId > 0 ? getAttendanceStats($pdo, $eventId) : null;
$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid request.';
    } elseif ($eventId <= 0 || !$event) {
        $error = 'Please select an event.';
    } else {
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $message = richPost('message');
        $include = !empty($_POST['include_signin_links']);

        $result = sendEventStaffBroadcast($pdo, $eventId, $subject, $message, $include);
        if ($result['total'] === 0) {
            $error = 'No approved staff found for this event.';
        } elseif ($result['sent'] === 0) {
            $error = 'Emails could not be sent. Check email settings or storage/logs/mail.log.';
        } else {
            logAdminAudit($pdo, 'staff_email', 'event', $eventId, 'Sent ' . $result['sent'] . ' of ' . $result['total']);
            $success = 'Sent ' . $result['sent'] . ' of ' . $result['total'] . ' email(s)'
                . ($result['failed'] > 0 ? ' (' . $result['failed'] . ' failed).' : '.');
        }
    }
}

$pageTitle  = 'Email Staff';
$activePage = 'attendance';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Email approved staff</h2>
            <p class="card__subtitle">Send a reminder or update to every approved staff member for an event.</p>
        </div>
        <a href="attendance.php<?= $eventId > 0 ? '?event_id=' . (int) $eventId : '' ?>" class="btn btn--secondary">← Attendance</a>
    </div>

    <?php if ($success !== ''): ?><div class="alert alert--success alert--visible"><?= h($success) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert--error alert--visible"><?= h($error) ?></div><?php endif; ?>

    <form method="get" class="filter-bar filter-bar--attendance">
        <div class="filter-bar__group">
            <select class="form-select" name="event_id" required onchange="this.form.submit()">
                <option value="">Select event…</option>
                <?php foreach ($events as $ev): ?>
                    <option value="<?= (int) $ev['id'] ?>"<?= $eventId === (int) $ev['id'] ? ' selected' : '' ?>>
                        <?= h($ev['name'] . ' — ' . date('d.m.Y', strtotime($ev['event_date']))) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>

    <?php if ($event && $stats): ?>
        <div class="stat-grid stat-grid--compact">
            <div class="stat-card">
                <p class="stat-card__value"><?= (int) $stats['approved'] ?></p>
                <p class="stat-card__label">Approved staff</p>
            </div>
            <?php if ($stats['staff_needed'] !== null): ?>
                <div class="stat-card">
                    <p class="stat-card__value"><?= (int) $stats['staff_needed'] ?></p>
                    <p class="stat-card__label">Staff needed</p>
                </div>
                <div class="stat-card">
                    <p class="stat-card__value"><?= (int) $stats['spaces_remaining'] ?></p>
                    <p class="stat-card__label">Spaces remaining</p>
                </div>
            <?php endif; ?>
        </div>

        <form method="post" class="form-grid settings-form">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="event_id" value="<?= (int) $eventId ?>">

            <div class="form-group form-group--full">
                <label class="form-label" for="subject">Email subject</label>
                <input class="form-input" id="subject" name="subject" value="<?= h($event['name'] . ' — event reminder') ?>" placeholder="Subject line">
            </div>

            <div class="form-group form-group--full">
                <label class="form-label" for="message">Message</label>
                <textarea class="form-textarea rich-text" id="message" name="message" rows="8" placeholder="Your message to staff…"><?= h((string) ($_POST['message'] ?? '<p>Hi team,</p><p>This is a reminder about your upcoming shift. Please arrive on time and bring your ID.</p>')) ?></textarea>
                <p class="form-hint">Rich text supported. Event date, time, location, and optional sign-in links are added automatically.</p>
            </div>

            <div class="form-group form-group--full">
                <label class="form-radio">
                    <input type="checkbox" name="include_signin_links" value="1" checked>
                    Include email and venue sign-in links
                </label>
            </div>

            <div class="form-actions form-group--full">
                <button type="submit" class="btn btn--primary" onclick="return confirm('Send this email to all approved staff for this event?');">Send to <?= (int) $stats['approved'] ?> staff</button>
            </div>
        </form>
    <?php elseif ($eventId > 0): ?>
        <p class="data-table__empty">Event not found.</p>
    <?php else: ?>
        <p class="data-table__empty">Choose an event above to compose a staff email.</p>
    <?php endif; ?>
</section>

<?php
$enableRichTextEditor = true;
include __DIR__ . '/../includes/admin/layout-bottom.php';

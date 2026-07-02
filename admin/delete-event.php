<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/events-repository.php';
require_once __DIR__ . '/../includes/event-complete-purge.php';
require_once __DIR__ . '/../includes/audit-log.php';

requireAdminCapability('events');

$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        setAdminFlash('error', 'Invalid request. Please try again.');
        header('Location: events.php');
        exit;
    }

    $eventId       = (int) ($_POST['event_id'] ?? $_POST['id'] ?? 0);
    $confirmPhrase = trim((string) ($_POST['confirm_phrase'] ?? $_POST['confirm_name'] ?? ''));
    $redirect      = trim((string) ($_POST['redirect'] ?? 'events.php'));
    if (!str_starts_with($redirect, 'event-form.php') && $redirect !== 'events.php') {
        $redirect = 'events.php';
    }

    if ($eventId < 1) {
        setAdminFlash('error', 'Invalid event.');
        header('Location: events.php');
        exit;
    }

    $event = getEventById($pdo, $eventId);
    if ($event === null) {
        setAdminFlash('error', 'Event not found.');
        header('Location: events.php');
        exit;
    }

    $eventName = trim((string) ($event['name'] ?? ''));
    if (!isEventDeletePhraseValid($confirmPhrase)) {
        setAdminFlash('error', 'Type DELETE to confirm permanent deletion.');
        header('Location: delete-event.php?id=' . $eventId . ($redirect === 'event-form.php' ? '&redirect=event-form.php' : ''));
        exit;
    }

    $result = deleteEventCompletely($pdo, $eventId);
    if (!($result['ok'] ?? false)) {
        setAdminFlash('error', 'Could not delete event: ' . (string) ($result['error'] ?? 'Unknown error'));
        header('Location: delete-event.php?id=' . $eventId);
        exit;
    }

    $impact  = $result['impact_before'] ?? [];
    $summary = sprintf(
        'Deleted event "%s" — %d registration(s), %d attendance, %d invoice(s).',
        $eventName,
        (int) ($impact['registrations'] ?? 0),
        (int) ($impact['attendance'] ?? 0),
        (int) ($impact['invoices'] ?? 0)
    );

    logAdminAudit($pdo, 'event_delete', 'event', $eventId, $summary);
    setAdminFlash('success', $summary . ' Google Sheet link removed from the system (file in Drive is not deleted).');
    header('Location: events.php');
    exit;
}

$eventId  = (int) ($_GET['id'] ?? $_GET['event_id'] ?? 0);
$redirect = trim((string) ($_GET['redirect'] ?? 'events.php'));
if (!str_starts_with($redirect, 'event-form.php') && $redirect !== 'events.php') {
    $redirect = 'events.php';
}

if ($eventId < 1) {
    setAdminFlash('error', 'Invalid event.');
    header('Location: events.php');
    exit;
}

$event = getEventById($pdo, $eventId);
if ($event === null) {
    setAdminFlash('error', 'Event not found.');
    header('Location: events.php');
    exit;
}

$regCountStmt = $pdo->prepare('SELECT COUNT(*) FROM staff_registrations WHERE event_id = :event_id');
$regCountStmt->execute(['event_id' => $eventId]);
$event['registration_count'] = (int) $regCountStmt->fetchColumn();

$flash      = getAdminFlash();
$pageTitle  = 'Delete event';
$activePage = 'events';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<section class="card event-delete-page">
    <div class="card__header">
        <h2 class="card__title" style="color:#dc2626;">Delete event permanently</h2>
        <p class="card__subtitle">This removes the event and all related registrations, attendance, invoices, and history. Staff profiles are kept. This cannot be undone.</p>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
    <?php endif; ?>

    <dl class="detail-list" style="margin-bottom:1rem;">
        <div class="detail-list__row">
            <dt>Event</dt>
            <dd><strong><?= h((string) $event['name']) ?></strong></dd>
        </div>
        <div class="detail-list__row">
            <dt>Date</dt>
            <dd><?= h(formatEventDateLabel((string) ($event['event_date'] ?? ''))) ?></dd>
        </div>
        <div class="detail-list__row">
            <dt>Registrations</dt>
            <dd><?= (int) ($event['registration_count'] ?? 0) ?></dd>
        </div>
    </dl>

    <form method="post" action="delete-event.php" class="form-grid" style="max-width:24rem;">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
        <input type="hidden" name="redirect" value="<?= h($redirect) ?>">
        <div class="form-group form-group--full">
            <label class="form-label" for="confirm_phrase">Type <strong>DELETE</strong> to confirm</label>
            <input
                class="form-input"
                type="text"
                id="confirm_phrase"
                name="confirm_phrase"
                required
                autocomplete="off"
                autocapitalize="characters"
                spellcheck="false"
                placeholder="DELETE"
            >
        </div>
        <div class="form-group form-group--full form-actions">
            <a href="<?= h($redirect === 'event-form.php' ? 'event-form.php?id=' . (int) $event['id'] : 'events.php') ?>" class="btn btn--secondary">Cancel</a>
            <button type="submit" class="btn btn--secondary" style="border-color:#dc2626;color:#dc2626;">Delete permanently</button>
        </div>
    </form>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>

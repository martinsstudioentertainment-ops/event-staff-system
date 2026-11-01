<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/google-form-intake.php';
require_once __DIR__ . '/../includes/events-repository.php';
require_once __DIR__ . '/../includes/system-settings.php';
require_once __DIR__ . '/../includes/google-drive-oauth.php';

requireAdminCapability('events');

$pdo = getDB();
googleFormIntakeSeedAvivaIntegration($pdo);

if (!empty($_GET['google_oauth'])) {
    if (!empty($_SESSION['google_oauth_success'])) {
        setAdminFlash('success', (string) $_SESSION['google_oauth_success']);
        unset($_SESSION['google_oauth_success']);
    } elseif (!empty($_SESSION['google_oauth_error'])) {
        setAdminFlash('error', (string) $_SESSION['google_oauth_error']);
        unset($_SESSION['google_oauth_error']);
    }
}

$flash = getAdminFlash();
$rows = googleFormEventIntegrationsList($pdo);
$events = getAllEvents($pdo);
$saEmail = googleFormEventIntegrationServiceAccountEmail();
$prefillEventId = (int) ($_GET['event_id'] ?? 0);
$todayYmd = date('Y-m-d');
$oauthConnected = googleDriveOAuthConfigured($pdo);
$canConnectGoogle = trim(getSetting($pdo, 'google_oauth_client_id', '')) !== ''
    && isGoogleOAuthClientSecretConfigured($pdo);
$connectGoogleUrl = $canConnectGoogle
    ? googleDriveOAuthAuthorizeUrl($pdo, 'google-form-integrations.php')
    : '';
$avivaEvent = googleFormIntakeFindAvivaEvent($pdo);
$avivaEventId = (int) ($avivaEvent['id'] ?? 0);
$connectedEventIds = [];
foreach ($rows as $existingRow) {
    $connectedEventIds[(int) ($existingRow['event_id'] ?? 0)] = true;
}
$createEvents = [];
foreach ($events as $event) {
    $eid = (int) $event['id'];
    if ($eid === $avivaEventId || isset($connectedEventIds[$eid])) {
        continue;
    }
    $createEvents[] = $event;
}

$staffLink = trim((string) ($_SESSION['google_form_staff_link'] ?? ''));
$staffEvent = trim((string) ($_SESSION['google_form_staff_event'] ?? ''));
unset($_SESSION['google_form_staff_link'], $_SESSION['google_form_staff_event']);

$pageTitle  = 'Google Form import';
$activePage = 'google-form-integrations';

include __DIR__ . '/../includes/admin/layout-top.php';
?>
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/platform-ops.css?v=3">

<?php if ($flash): ?>
<div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<?php if ($staffLink !== ''): ?>
<section class="card" id="staff-link" style="margin-top:0.5rem;border-color:var(--success, #16a34a);">
    <div class="card__header">
        <h2 class="card__title">Staff link<?= $staffEvent !== '' ? ' — ' . h($staffEvent) : '' ?></h2>
        <p class="card__subtitle">Send this URL to staff. They fill it; Olasentra imports the responses automatically.</p>
    </div>
    <div class="form-group">
        <input class="form-input" type="text" id="staff_form_link" value="<?= h($staffLink) ?>" readonly onclick="this.select()">
    </div>
    <div class="form-actions">
        <a class="btn btn--primary" href="<?= h($staffLink) ?>" target="_blank" rel="noopener">Open form</a>
        <button type="button" class="btn btn--secondary" onclick="navigator.clipboard.writeText(document.getElementById('staff_form_link').value); this.textContent='Copied';">Copy link</button>
    </div>
</section>
<?php endif; ?>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Google Form event integrations</h2>
            <p class="card__subtitle">
                Create a Google Form for an event, or connect an existing Form.
                Anyone who fills a live Form is imported automatically (about one minute). Same email on the same event updates the existing record.
            </p>
        </div>
        <form method="post" action="google-form-integrations-action.php">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="sync_all">
            <button type="submit" class="btn btn--secondary">Run watcher now</button>
        </form>
    </div>

    <p class="form-hint">
        Generated Forms are shared automatically with
        <code><?= h($saEmail) ?></code>
        as Viewer. Existing Sheets still need that share.
        Do not use the event roster Sheet from Events → Sheets — that is a different file.
    </p>
    <?php if ($connectGoogleUrl !== ''): ?>
        <p class="form-hint">
            Creating a Form uses
            <strong>martinsstudioentertainment@gmail.com</strong>.
            <?php if (!$oauthConnected): ?>
                Google is not connected yet — click
                <a href="<?= h($connectGoogleUrl) ?>">Connect Google account</a>
                once (approve Drive, Sheets, and Forms), then create the Form.
            <?php else: ?>
                If Google asks for Forms permission, click
                <a href="<?= h($connectGoogleUrl) ?>">Connect Google account</a>
                once, then create the Form again.
            <?php endif; ?>
        </p>
    <?php else: ?>
        <p class="form-hint">
            Save the Google OAuth client in
            <a href="settings-production.php#google-sheets">Settings → Google Sheets</a>
            before creating a Form.
        </p>
    <?php endif; ?>
</section>

<section class="card" id="update-psa" style="margin-top:0.5rem;">
    <div class="card__header">
        <h2 class="card__title">Wrong PSA licence</h2>
        <p class="card__subtitle">Type a name or email, open the person, update the licence.</p>
    </div>
    <div class="form-actions">
        <a class="btn btn--primary" href="psa-update.php">Update PSA</a>
    </div>
</section>

<section class="card" id="create" style="margin-top:0.5rem;">
    <div class="card__header">
        <h2 class="card__title">Get a staff Form link</h2>
        <p class="card__subtitle">Type the event name and date. Olasentra creates the event, the Google Form, and the public link.</p>
    </div>
    <?php if (!$oauthConnected && $connectGoogleUrl !== ''): ?>
        <p class="form-hint">One Google approval is required the first time (martinsstudioentertainment@gmail.com). After that, generating a link is one click.</p>
        <div class="form-actions">
            <a href="<?= h($connectGoogleUrl) ?>" class="btn btn--primary">Connect Google — then you can generate links</a>
        </div>
    <?php else: ?>
        <form method="post" action="google-form-integrations-action.php" class="form-grid settings-form">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="quick">
            <div class="form-group">
                <label class="form-label form-label--required" for="event_name">Event name</label>
                <input class="form-input" type="text" id="event_name" name="event_name" required placeholder="Palmerstown Event" value="<?= $prefillEventId > 0 ? '' : '' ?>">
            </div>
            <div class="form-group">
                <label class="form-label form-label--required" for="event_date">Event date</label>
                <input class="form-input" type="date" id="event_date" name="event_date" required value="<?= h($todayYmd) ?>">
            </div>
            <div class="form-group form-group--full">
                <label class="form-label">Optional extra questions</label>
                <label class="form-radio">
                    <input type="checkbox" name="include_pps" value="1">
                    Ask for PPS number
                </label>
                <label class="form-radio">
                    <input type="checkbox" name="include_dob" value="1">
                    Ask for date of birth
                </label>
                <p class="form-hint">Leave both unticked to match the Aviva form.</p>
            </div>
            <div class="form-actions form-group--full">
                <button type="submit" class="btn btn--primary">Generate staff Form link</button>
            </div>
        </form>
    <?php endif; ?>
</section>

<?php foreach ($rows as $row): ?>
    <?php
    $locked = googleFormEventIntegrationIsLocked($row);
    $enabled = (int) ($row['enabled'] ?? 0) === 1;
    $permOk = (int) ($row['last_permission_ok'] ?? 0) === 1;
    $failed = (int) ($row['last_failed'] ?? 0);
    $generated = (int) ($row['form_created_by_system'] ?? 0) === 1 || (string) ($row['source'] ?? '') === 'generated';
    $published = (int) ($row['published'] ?? 0) === 1 || $locked;
    $liveFormUrl = googleFormEventIntegrationStaffUrl($row);
    ?>
    <section class="card" style="margin-top:1rem;">
        <div class="card__header card__header--row">
            <div>
                <h3 class="card__title"><?= h((string) ($row['event_name'] ?? 'Unknown event')) ?></h3>
                <p class="card__subtitle">
                    <?= h(formatEventDateLabel((string) ($row['event_date'] ?? ''))) ?>
                    <?php if ($locked): ?>
                        · Locked Aviva mapping
                    <?php elseif ($generated): ?>
                        · Created by Olasentra
                    <?php endif; ?>
                </p>
            </div>
            <div class="toolbar" style="gap:0.4rem;flex-wrap:wrap;">
                <?php if ($enabled): ?>
                    <span class="status-badge status-badge--approved">Import on</span>
                <?php else: ?>
                    <span class="status-badge status-badge--rejected">Import off</span>
                <?php endif; ?>
                <?php if ($permOk): ?>
                    <span class="status-badge status-badge--approved">Viewer ✓</span>
                <?php else: ?>
                    <span class="status-badge status-badge--pending">Permission unknown</span>
                <?php endif; ?>
                <?php if ($published): ?>
                    <span class="status-badge status-badge--approved">Published ✓</span>
                <?php elseif ($generated): ?>
                    <span class="status-badge status-badge--pending">Unpublished</span>
                <?php endif; ?>
            </div>
        </div>

        <dl class="list-card__facts">
            <div>
                <dt>Event</dt>
                <dd><?= h((string) ($row['event_name'] ?? '')) ?></dd>
            </div>
            <div>
                <dt>Google Form</dt>
                <dd>
                    <?php if ($generated): ?>
                        Created ✓
                    <?php elseif (!empty($row['google_form_url'])): ?>
                        Connected ✓
                    <?php else: ?>
                        Sheet only
                    <?php endif; ?>
                    <?php if ($liveFormUrl !== ''): ?>
                        <div class="form-hint"><a href="<?= h($liveFormUrl) ?>" target="_blank" rel="noopener">Staff Form link ↗</a></div>
                    <?php endif; ?>
                </dd>
            </div>
            <div>
                <dt>Published</dt>
                <dd><?= $published ? 'Yes ✓' : 'No' ?></dd>
            </div>
            <div>
                <dt>Responses Sheet</dt>
                <dd>
                    <?= $generated ? 'Created ✓' : 'Connected ✓' ?>
                    <?php if (!empty($row['google_sheet_url'])): ?>
                        <div class="form-hint"><a href="<?= h((string) $row['google_sheet_url']) ?>" target="_blank" rel="noopener">Sheet URL ↗</a></div>
                    <?php endif; ?>
                    <div class="form-hint">Tab: <?= h((string) ($row['google_sheet_tab'] ?? 'Form Responses 1')) ?></div>
                </dd>
            </div>
            <div>
                <dt>Service account</dt>
                <dd><code><?= h($saEmail) ?></code></dd>
            </div>
            <div>
                <dt>Viewer</dt>
                <dd><?= $permOk ? 'Granted ✓' : 'Needs attention' ?></dd>
            </div>
            <div>
                <dt>PSA checking</dt>
                <dd>Enabled ✓</dd>
            </div>
            <div>
                <dt>Automatic import</dt>
                <dd><?= $enabled ? 'Enabled ✓' : 'Paused' ?></dd>
            </div>
            <div>
                <dt>Last sync</dt>
                <dd><?= !empty($row['last_sync_at']) ? h(formatSystemDateTime((string) $row['last_sync_at'], $pdo)) : 'Not yet' ?></dd>
            </div>
            <div>
                <dt>Rows imported</dt>
                <dd>
                    Processed <?= (int) ($row['processed_rows'] ?? 0) ?>
                    · last imported <?= (int) ($row['last_imported'] ?? 0) ?>
                    · skipped <?= (int) ($row['last_skipped'] ?? 0) ?>
                </dd>
            </div>
            <div>
                <dt>Errors</dt>
                <dd>
                    <?= $failed ?>
                    <?php if (!empty($row['last_error']) || !empty($row['creation_error'])): ?>
                        <div class="form-hint"><?= h((string) ($row['last_error'] ?: $row['creation_error'])) ?></div>
                    <?php endif; ?>
                </dd>
            </div>
        </dl>

        <div class="list-card__actions" style="margin-top:1rem;">
            <form method="post" action="google-form-integrations-action.php" class="inline-form">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="verify">
                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                <button type="submit" class="btn btn--small btn--secondary">Verify sheet</button>
            </form>
            <form method="post" action="google-form-integrations-action.php" class="inline-form">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="sync">
                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                <button type="submit" class="btn btn--small btn--primary">Sync now</button>
            </form>
            <?php if (!$locked): ?>
                <form method="post" action="google-form-integrations-action.php" class="inline-form">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="<?= $enabled ? 'disable' : 'enable' ?>">
                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                    <button type="submit" class="btn btn--small btn--secondary"><?= $enabled ? 'Pause import' : 'Enable import' ?></button>
                </form>
                <form method="post" action="google-form-integrations-action.php" class="inline-form" onsubmit="return confirm('Remove this Google Form connection? Existing staff stay in Olasentra.');">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                    <button type="submit" class="btn btn--small btn--danger">Remove</button>
                </form>
            <?php endif; ?>
            <a href="event-form.php?id=<?= (int) $row['event_id'] ?>" class="btn btn--small btn--secondary">Open event</a>
        </div>
    </section>
<?php endforeach; ?>

<details class="card" id="connect" style="margin-top:1.5rem;">
    <summary class="card__header" style="cursor:pointer;">
        <h2 class="card__title">Connect an existing Google Form (advanced)</h2>
        <p class="card__subtitle">Only if the Form already exists. New events use Generate staff Form link above.</p>
    </summary>
    <form method="post" action="google-form-integrations-action.php" class="form-grid settings-form">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="save">
        <div class="form-group">
            <label class="form-label form-label--required" for="event_id">Event</label>
            <select class="form-input" id="event_id" name="event_id" required>
                <option value="">Select event…</option>
                <?php foreach ($events as $event): ?>
                    <?php
                    $eid = (int) $event['id'];
                    $date = substr((string) ($event['event_date'] ?? ''), 0, 10);
                    $label = (string) $event['name'] . ' — ' . formatEventDateLabel($date);
                    ?>
                    <option value="<?= $eid ?>"<?= $prefillEventId === $eid ? ' selected' : '' ?>><?= h($label) ?><?= $date < $todayYmd ? ' (past)' : '' ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label" for="google_form_url">Google Form URL (optional)</label>
            <input class="form-input" type="url" id="google_form_url" name="google_form_url" placeholder="https://docs.google.com/forms/d/…/edit">
            <p class="form-hint">The Form <strong>edit</strong> URL, not the public /d/e/ view link.</p>
        </div>
        <div class="form-group form-group--full">
            <label class="form-label form-label--required" for="google_sheet_url">Responses Sheet URL</label>
            <input class="form-input" type="url" id="google_sheet_url" name="google_sheet_url" required placeholder="https://docs.google.com/spreadsheets/d/…">
            <p class="form-hint">
                Form → Responses → Link to Sheets. Then Share → Viewer →
                <code><?= h($saEmail) ?></code>
            </p>
        </div>
        <div class="form-group">
            <label class="form-label" for="google_sheet_tab">Sheet tab</label>
            <input class="form-input" type="text" id="google_sheet_tab" name="google_sheet_tab" value="Form Responses 1">
        </div>
        <div class="form-group">
            <label class="form-label">Automatic import</label>
            <label class="form-radio">
                <input type="checkbox" name="enabled" value="1" checked>
                Enable the minute watcher for this event
            </label>
        </div>
        <div class="form-actions form-group--full">
            <button type="submit" class="btn btn--primary">Save and verify sheet</button>
        </div>
    </form>
</details>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>

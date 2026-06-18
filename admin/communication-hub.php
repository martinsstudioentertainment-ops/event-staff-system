<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/audit-log.php';
require_once __DIR__ . '/../includes/events-repository.php';
require_once __DIR__ . '/../includes/automation/automation-schema.php';
require_once __DIR__ . '/../includes/automation/comms-hub.php';
require_once __DIR__ . '/../includes/rich-text.php';
require_once __DIR__ . '/../includes/date-format.php';

requireAdminCapability('staff');

$pdo     = getDB();
$flash   = getAdminFlash();
$success = $error = '';
auto_ensure_schema($pdo);
auto_ensure_phase67_schema($pdo);

$events = getEventsForFilter($pdo);
comms_ensure_signin_internal_template($pdo);
$templates = comms_list_templates($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? null)) {
    $action = (string) ($_POST['action'] ?? 'send');
    if ($action === 'send_signin_inbox') {
        if (!isAdminSuperUser()) {
            setAdminFlash('error', 'Only the main admin account can send bulk inbox messages.');
            header('Location: communication-hub.php');
            exit;
        }
        $admin = getAdminUser();
        $result = comms_send_signin_inbox_to_all($pdo, $admin ? (int) $admin['id'] : null);
        logAdminAudit($pdo, 'staff_inbox_bulk', 'comms_campaign', 0, "Sign-in guide to {$result['sent']} of {$result['target']} inboxes");
        if ($result['sent'] > 0) {
            setAdminFlash('success', "Sign-in guide sent to {$result['sent']} of {$result['target']} staff inbox(es).");
        } elseif ($result['target'] === 0) {
            setAdminFlash('error', 'No staff found in the directory.');
        } else {
            setAdminFlash('error', 'Could not deliver to any staff inboxes.');
        }
        header('Location: communication-hub.php');
        exit;
    }
    if ($action === 'save_template') {
        $_POST['body'] = richPost('body');
        comms_save_template($pdo, $_POST, (int) ($_POST['template_id'] ?? 0) ?: null);
        setAdminFlash('success', 'Template saved.');
        header('Location: communication-hub.php');
        exit;
    }
    $subject = trim((string) ($_POST['subject'] ?? ''));
    $body    = richPost('body');
    if ($subject === '' || plainTextFromRich($body) === '') {
        setAdminFlash('error', 'Subject and message are required.');
        header('Location: communication-hub.php');
        exit;
    }
    $admin = getAdminUser();
    $result = comms_send_campaign(
        $pdo,
        (string) ($_POST['channel'] ?? 'email'),
        $subject,
        $body,
        [
            'event_id'          => (int) ($_POST['event_id'] ?? 0),
            'role'              => trim((string) ($_POST['role'] ?? '')),
            'venue'             => trim((string) ($_POST['venue'] ?? '')),
            'risk'              => trim((string) ($_POST['risk'] ?? '')),
            'min_reliability'   => trim((string) ($_POST['min_reliability'] ?? '')),
            'attendance_status' => trim((string) ($_POST['attendance_status'] ?? '')),
            'compliance_status' => trim((string) ($_POST['compliance_status'] ?? '')),
        ],
        $admin ? (int) $admin['id'] : null
    );
    logAdminAudit($pdo, 'staff_email', 'comms_campaign', 0, "Sent {$result['sent']} of {$result['target']}");
    if ($result['sent'] > 0) {
        setAdminFlash('success', "Sent {$result['sent']} of {$result['target']} message(s).");
    } elseif ($result['target'] === 0) {
        setAdminFlash('error', 'No staff matched your filters. Set Event to “All staff” and leave Role, Venue, Risk, Compliance, and Attendance blank unless you need them.');
    } elseif ($result['failed'] > 0) {
        $channel = (string) ($_POST['channel'] ?? 'email');
        if ($channel === 'email') {
            setAdminFlash('error', "Matched {$result['target']} staff but email delivery failed for all {$result['failed']}. Check Admin → Settings → Email (SMTP). In-app copies are only saved when email sends successfully.");
        } else {
            setAdminFlash('error', "Matched {$result['target']} staff but could not deliver ({$result['failed']} failed). Check the channel you selected.");
        }
    } else {
        setAdminFlash('error', 'No messages sent. Check channel and filters.');
    }
    header('Location: communication-hub.php');
    exit;
}

$campaigns = comms_recent_campaigns($pdo);
$commsDefaults = comms_compose_defaults($pdo);
$allStaffPreviewCount = count(comms_resolve_recipients($pdo, [
    'event_id'          => (int) $commsDefaults['event_id'],
    'role'              => (string) $commsDefaults['role'],
    'venue'             => (string) $commsDefaults['venue'],
    'risk'              => (string) $commsDefaults['risk'],
    'min_reliability'   => (string) $commsDefaults['min_reliability'],
    'attendance_status' => (string) $commsDefaults['attendance_status'],
    'compliance_status' => (string) $commsDefaults['compliance_status'],
]));

$pageTitle  = 'Staff Communication Hub';
$activePage = basename($_SERVER['SCRIPT_NAME'] ?? '', '.php') === 'communication-centre' ? 'communication-centre' : 'communication-hub';
$erpPageContentClass = 'auto-page wf-page';

include __DIR__ . '/../includes/admin/layout-top.php';
?>
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/platform-ops.css">
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/workforce-suite.css">

<?php if ($flash): ?><div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div><?php endif; ?>

<div class="wf-hero">
    <div>
        <h1 class="wf-hero__title">Staff Communication Hub</h1>
        <p class="wf-hero__subtitle">Send direct inbox messages or emails to all staff or filtered groups. Defaults use <strong>Internal messages</strong> (staff app inbox only). Each send is logged below and copied into each staff thread.</p>
    </div>
    <div class="toolbar" style="gap:0.5rem;flex-wrap:wrap">
        <?php if (isAdminSuperUser() && $allStaffPreviewCount > 0): ?>
            <form method="post" onsubmit="return confirm('Send the sign-in guide to <?= (int) $allStaffPreviewCount ?> staff inbox(es)?');">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="send_signin_inbox">
                <button type="submit" class="btn btn--primary">Send sign-in guide to all inboxes</button>
            </form>
        <?php endif; ?>
        <a href="staff-inbox.php" class="btn btn--secondary">Individual threads</a>
    </div>
</div>

<section class="card erp-card" id="bulk-inbox">
    <h2 class="wf-panel__title">Compose message</h2>
    <p class="form-hint" style="margin-bottom:1rem;">Defaults send an <strong>internal inbox</strong> message to <strong>all staff</strong> with no extra filters. Choose <strong>Email</strong> if you also want SMTP delivery. Edit subject and message below, then click Send campaign.
        <?php if ($allStaffPreviewCount > 0): ?>
            <strong><?= (int) $allStaffPreviewCount ?> staff</strong> will receive this with the current default filters.
        <?php else: ?>
            <strong>No mailable staff found</strong> — check the Staff directory has people with email addresses.
        <?php endif; ?>
    </p>
    <form method="post" id="comms-compose-form" class="wf-filters"><?= csrfField() ?><input type="hidden" name="action" value="send">
        <div><label>Channel</label><select name="channel" class="input">
            <option value="internal"<?= comms_option_selected((string) $commsDefaults['channel'], 'internal') ?>>Internal messages (staff app inbox)</option>
            <option value="email"<?= comms_option_selected((string) $commsDefaults['channel'], 'email') ?>>Email</option>
            <option value="whatsapp"<?= comms_option_selected((string) $commsDefaults['channel'], 'whatsapp') ?>>WhatsApp (record only)</option>
            <option value="sms"<?= comms_option_selected((string) $commsDefaults['channel'], 'sms') ?>>SMS (not configured)</option>
        </select>
            <p class="form-hint" style="margin-top:0.35rem;"><strong>Email</strong> → staff’s email address + inbox thread + app notification. <strong>Internal</strong> → staff app inbox only (no email).</p>
        </div>
        <div><label>Event</label><select name="event_id" class="input">
            <option value="0"<?= (int) $commsDefaults['event_id'] === 0 ? ' selected' : '' ?>>All staff</option>
            <?php foreach ($events as $ev): ?>
                <option value="<?= (int) $ev['id'] ?>"<?= (int) $commsDefaults['event_id'] === (int) $ev['id'] ? ' selected' : '' ?>><?= h($ev['name'] ?? '') ?></option>
            <?php endforeach; ?>
        </select></div>
        <div><label>Role</label><select name="role" class="input">
            <option value=""<?= comms_option_selected((string) $commsDefaults['role'], '') ?>>Any role</option>
            <option value="dsp"<?= comms_option_selected((string) $commsDefaults['role'], 'dsp') ?>>DSP</option>
            <option value="static"<?= comms_option_selected((string) $commsDefaults['role'], 'static') ?>>Static</option>
            <option value="steward"<?= comms_option_selected((string) $commsDefaults['role'], 'steward') ?>>Steward</option>
        </select></div>
        <div><label>Venue</label><input name="venue" class="input" placeholder="Leave blank for all" value="<?= h((string) $commsDefaults['venue']) ?>"></div>
        <div><label>Risk level</label><select name="risk" class="input">
            <option value=""<?= comms_option_selected((string) $commsDefaults['risk'], '') ?>>Any</option>
            <option value="green"<?= comms_option_selected((string) $commsDefaults['risk'], 'green') ?>>Low</option>
            <option value="amber"<?= comms_option_selected((string) $commsDefaults['risk'], 'amber') ?>>Medium</option>
            <option value="red"<?= comms_option_selected((string) $commsDefaults['risk'], 'red') ?>>High</option>
        </select></div>
        <div><label>Compliance</label><select name="compliance_status" class="input">
            <option value=""<?= comms_option_selected((string) $commsDefaults['compliance_status'], '') ?>>Any</option>
            <option value="valid"<?= comms_option_selected((string) $commsDefaults['compliance_status'], 'valid') ?>>Valid</option>
            <option value="expiring"<?= comms_option_selected((string) $commsDefaults['compliance_status'], 'expiring') ?>>Expiring</option>
            <option value="expired"<?= comms_option_selected((string) $commsDefaults['compliance_status'], 'expired') ?>>Expired</option>
            <option value="missing"<?= comms_option_selected((string) $commsDefaults['compliance_status'], 'missing') ?>>Missing</option>
        </select></div>
        <div><label>Min reliability</label><input type="number" name="min_reliability" min="0" max="100" class="input" placeholder="Leave blank for all" value="<?= h((string) $commsDefaults['min_reliability']) ?>"></div>
        <div><label>Attendance</label><select name="attendance_status" class="input">
            <option value=""<?= comms_option_selected((string) $commsDefaults['attendance_status'], '') ?>>Any</option>
            <option value="high"<?= comms_option_selected((string) $commsDefaults['attendance_status'], 'high') ?>>High (≥80%)</option>
            <option value="low"<?= comms_option_selected((string) $commsDefaults['attendance_status'], 'low') ?>>Low (&lt;80%)</option>
        </select></div>
        <div class="form-group--full"><label>Template</label>
            <select class="input" id="comms-template-picker">
                <option value="">— Load template —</option>
                <?php foreach ($templates as $t): ?>
                    <option value="<?= h(json_encode(['subject' => $t['subject'] ?? '', 'body' => $t['body'] ?? ''], JSON_HEX_APOS | JSON_HEX_QUOT)) ?>"><?= h((string) ($t['name'] ?? '')) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group--full"><label>Subject</label><input name="subject" class="input" required value="<?= h((string) $commsDefaults['subject']) ?>"></div>
        <div class="form-group--full" style="grid-column:1/-1;"><label>Message</label><textarea name="body" id="comms-body" class="input rich-text" rows="8"><?= h((string) $commsDefaults['body']) ?></textarea></div>
        <div class="comms-form-actions" style="grid-column:1/-1;"><button type="submit" class="btn btn--primary">Send campaign</button> <span class="text-muted">Schedule: use cron reminders or save as template for later.</span></div>
    </form>
</section>

<section class="card erp-card">
    <h2 class="wf-panel__title">Message templates</h2>
    <form method="post" class="wf-filters"><?= csrfField() ?><input type="hidden" name="action" value="save_template">
        <div><label>Name</label><input name="name" class="input" required></div>
        <div><label>Channel</label><select name="channel" class="input"><option value="email">Email</option><option value="internal">Internal</option><option value="whatsapp">WhatsApp</option><option value="sms">SMS</option></select></div>
        <div class="form-group--full"><label>Subject</label><input name="subject" class="input"></div>
        <div class="form-group--full" style="grid-column:1/-1;"><label>Body</label><textarea name="body" id="comms-template-body" class="input rich-text" rows="5"></textarea></div>
        <div><button class="btn btn--secondary">Save template</button></div>
    </form>
</section>

<section class="card erp-card">
    <h2 class="wf-panel__title">Recent campaigns — your sent messages</h2>
    <p class="form-hint" style="margin-bottom:1rem;">Every bulk send is logged here with the full message. Per-staff copies also appear in <a href="staff-inbox.php">Staff inbox</a> → open that person’s thread.</p>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>When</th><th>Channel</th><th>Subject</th><th>Message</th><th>Target</th><th>Sent</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($campaigns as $c): ?>
                <?php $campaignBody = (string) ($c['body'] ?? ''); ?>
                <tr>
                    <td><?= h(formatSystemDateTime((string) ($c['created_at'] ?? ''), $pdo)) ?></td>
                    <td><?= h((string) ($c['channel'] ?? '')) ?></td>
                    <td><?= h((string) ($c['subject'] ?? '—')) ?></td>
                    <td class="comms-campaign-body">
                        <?php if ($campaignBody !== ''): ?>
                            <details>
                                <summary><?= h(plainTextFromRich($campaignBody, 120)) ?></summary>
                                <div class="rich-content comms-campaign-body__html"><?= renderRichText($campaignBody) ?></div>
                            </details>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td><?= (int) ($c['target_count'] ?? 0) ?></td>
                    <td><?= (int) ($c['sent_count'] ?? 0) ?></td>
                    <td><?= h((string) ($c['status'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($campaigns === []): ?><tr><td colspan="7" class="data-table__empty">No campaigns yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php $enableRichTextEditor = true; ?>
<script>
(function () {
    var templateSelect = document.getElementById('comms-template-picker');
    if (!templateSelect) return;
    templateSelect.addEventListener('change', function () {
        if (!this.value) return;
        try {
            var t = JSON.parse(this.value);
            var form = document.getElementById('comms-compose-form');
            var subject = form ? form.querySelector('input[name="subject"]') : null;
            var body = document.getElementById('comms-body');
            if (subject) subject.value = t.subject || '';
            if (body) {
                body.value = t.body || '';
                var wrapper = body.closest('.rich-text-editor');
                var surface = wrapper ? wrapper.querySelector('.ql-editor') : null;
                if (surface) surface.innerHTML = t.body || '';
                if (typeof window.syncRichTextArea === 'function') {
                    window.syncRichTextArea(body);
                }
            }
        } catch (e) { /* ignore */ }
    });
})();
</script>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>

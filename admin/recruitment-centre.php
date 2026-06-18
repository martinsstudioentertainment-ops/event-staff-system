<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/audit-log.php';
require_once __DIR__ . '/../includes/automation/automation-schema.php';
require_once __DIR__ . '/../includes/automation/recruitment-repository.php';

requireAdminCapability('staff');

$pdo   = getDB();
$flash = getAdminFlash();
auto_ensure_schema($pdo);
auto_ensure_phase67_schema($pdo);

$stage   = trim((string) ($_GET['stage'] ?? ''));
$viewId  = (int) ($_GET['view'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? null)) {
    $action = (string) ($_POST['action'] ?? 'stage');
    if ($action === 'interview_note') {
        $admin = getAdminUser();
        recruitment_add_interview_note(
            $pdo,
            (int) ($_POST['pipeline_id'] ?? 0),
            (string) ($_POST['note_text'] ?? ''),
            trim((string) ($_POST['interview_date'] ?? '')) ?: null,
            $admin ? (int) $admin['id'] : null
        );
        setAdminFlash('success', 'Interview note saved.');
        header('Location: recruitment-centre.php?view=' . (int) ($_POST['pipeline_id'] ?? 0));
        exit;
    }
    recruitment_update_stage($pdo, (int) ($_POST['id'] ?? 0), (string) ($_POST['stage'] ?? ''), (string) ($_POST['notes'] ?? ''));
    logAdminAudit($pdo, 'status_change', 'recruitment', (int) ($_POST['id'] ?? 0), 'Stage → ' . ($_POST['stage'] ?? ''));
    setAdminFlash('success', 'Pipeline stage updated.');
    header('Location: recruitment-centre.php' . (($_GET['stage'] ?? '') !== '' ? '?stage=' . urlencode((string) $_GET['stage']) : ''));
    exit;
}

$metrics = recruitment_funnel_metrics($pdo);
$rows    = recruitment_list_by_stage($pdo, $stage !== '' ? $stage : null, 150);
$conv    = recruitment_conversion_rate($metrics);
$viewRow = null;
$interviewNotes = $viewId > 0 ? recruitment_interview_notes($pdo, $viewId) : [];
foreach ($rows as $r) {
    if ((int) ($r['id'] ?? 0) === $viewId) {
        $viewRow = $r;
        break;
    }
}

$pageTitle  = 'Recruitment Pipeline';
$activePage = 'recruitment-centre';
$erpPageContentClass = 'auto-page wf-page';

include __DIR__ . '/../includes/admin/layout-top.php';
?>
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/platform-ops.css">
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/workforce-suite.css">
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/automation-suite.css">

<?php if ($flash): ?><div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div><?php endif; ?>

<div class="wf-hero">
    <div>
        <h1 class="wf-hero__title">Recruitment Pipeline</h1>
        <p class="wf-hero__subtitle">Application → screening → interview → approved → training → active. Conversion rate: <strong><?= h((string) $conv) ?>%</strong></p>
    </div>
</div>

<div class="auto-funnel">
    <?php foreach (recruitment_stages() as $st): ?>
        <a href="recruitment-centre.php?stage=<?= h($st) ?>" class="auto-funnel__step" style="text-decoration:none;color:inherit;">
            <div class="auto-funnel__value"><?= (int) ($metrics[$st] ?? 0) ?></div>
            <div class="auto-funnel__label"><?= h(recruitment_stage_label($st)) ?></div>
        </a>
    <?php endforeach; ?>
</div>

<section class="card erp-card">
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Candidate</th><th>Event</th><th>Stage</th><th>Changed</th><th>Profile</th><th>Move to</th></tr></thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="6" class="data-table__empty">No records in this stage.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= h(trim(($row['first_name'] ?? '') . ' ' . ($row['surname'] ?? ''))) ?><br><span class="text-muted"><?= h((string) ($row['email'] ?? '')) ?></span></td>
                        <td><?= h((string) ($row['event_name'] ?? '—')) ?></td>
                        <td><?= h(recruitment_stage_label((string) ($row['stage'] ?? ''))) ?></td>
                        <td><?= h(formatSystemDateTime((string) ($row['stage_changed_at'] ?? ''), $pdo)) ?></td>
                        <td><a href="recruitment-centre.php?view=<?= (int) ($row['id'] ?? 0) ?><?= $stage !== '' ? '&amp;stage=' . h(urlencode($stage)) : '' ?>">Notes</a></td>
                        <td>
                            <form method="post" class="wf-toolbar"><?= csrfField() ?>
                                <input type="hidden" name="id" value="<?= (int) ($row['id'] ?? 0) ?>">
                                <select name="stage" class="input">
                                    <?php foreach (recruitment_stages() as $st): ?>
                                        <option value="<?= h($st) ?>" <?= ($row['stage'] ?? '') === $st ? 'selected' : '' ?>><?= h(recruitment_stage_label($st)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn--secondary">Update</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php if ($viewId > 0): ?>
<section class="card erp-card">
    <h2 class="wf-panel__title">Candidate profile<?= $viewRow ? ': ' . h(trim(($viewRow['first_name'] ?? '') . ' ' . ($viewRow['surname'] ?? ''))) : '' ?></h2>
    <?php if ($viewRow): ?>
        <p class="text-muted"><?= h((string) ($viewRow['email'] ?? '')) ?> · <?= h(recruitment_stage_label((string) ($viewRow['stage'] ?? ''))) ?> · <?= h((string) ($viewRow['event_name'] ?? '—')) ?></p>
    <?php endif; ?>
    <form method="post" class="wf-filters"><?= csrfField() ?>
        <input type="hidden" name="action" value="interview_note">
        <input type="hidden" name="pipeline_id" value="<?= $viewId ?>">
        <div><label>Interview date</label><input type="date" name="interview_date" class="input"></div>
        <div class="form-group--full" style="grid-column:1/-1;"><label>Interview notes</label><textarea name="note_text" class="input" rows="3" required></textarea></div>
        <div><button class="btn btn--primary">Save note</button> <a href="recruitment-centre.php<?= $stage !== '' ? '?stage=' . h(urlencode($stage)) : '' ?>" class="btn btn--secondary">Back</a></div>
    </form>
    <?php if ($interviewNotes !== []): ?>
    <div class="table-wrap" style="margin-top:1rem;">
        <table class="data-table">
            <thead><tr><th>Date</th><th>Note</th><th>Logged</th></tr></thead>
            <tbody>
            <?php foreach ($interviewNotes as $n): ?>
                <tr>
                    <td><?= ($n['interview_date'] ?? '') ? h(formatSystemDate((string) $n['interview_date'], $pdo)) : '—' ?></td>
                    <td><?= nl2br(h((string) ($n['note_text'] ?? ''))) ?></td>
                    <td><?= h(formatSystemDateTime((string) ($n['created_at'] ?? ''), $pdo)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>

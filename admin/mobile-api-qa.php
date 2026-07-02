<?php
/**
 * Temporary Admin QA page — Mobile API sign-off (read-only).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/staff-repository.php';
require_once __DIR__ . '/../includes/site-urls.php';
require_once __DIR__ . '/../includes/mobile/mobile-api-qa-runner.php';
require_once __DIR__ . '/../includes/mobile/mobile-api-qa-screenshots.php';

requireAdminCapability('settings');

$pdo = getDB();

// Serve saved artifact (PNG/SVG/HTML/JSON) to logged-in admin only.
$artifact = trim((string) ($_GET['artifact'] ?? ''));
if ($artifact !== '' && preg_match('/^[a-zA-Z0-9._-]+\.(png|svg|html|json)$/', $artifact) === 1) {
    $path = mobileQaOutputDir() . '/' . $artifact;
    if (is_file($path)) {
        $ext = strtolower(pathinfo($artifact, PATHINFO_EXTENSION));
        $types = [
            'png'  => 'image/png',
            'svg'  => 'image/svg+xml',
            'html' => 'text/html; charset=utf-8',
            'json' => 'application/json; charset=utf-8',
        ];
        header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
        header('Cache-Control: private, no-store');
        readfile($path);
        exit;
    }
    http_response_code(404);
    echo 'Artifact not found.';
    exit;
}

$staffOptions = mobileQaListApprovedStaff($pdo);
$report       = null;
$artifacts    = null;
$error        = '';
$runWarning   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'run_qa') {
    if (!verifyCsrf((string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'Invalid session token. Refresh and try again.';
    } else {
        $staffId = (int) ($_POST['staff_id'] ?? 0);
        $googleToken = trim((string) ($_POST['google_id_token'] ?? ''));

        $validStaff = false;
        foreach ($staffOptions as $row) {
            if ((int) ($row['id'] ?? 0) === $staffId) {
                $validStaff = true;
                break;
            }
        }

        if (!$validStaff) {
            $error = 'Select an approved staff account from the list.';
        } else {
            try {
                @set_time_limit(180);
                $report = mobileQaRun($pdo, $staffId, $googleToken !== '' ? $googleToken : null);
                if (empty($report['ok'])) {
                    $error = (string) ($report['message'] ?? 'QA run failed.');
                    $report = null;
                } else {
                    $save = mobileQaSaveArtifacts($report);
                    if (empty($save['ok'])) {
                        $runWarning = (string) ($save['message'] ?? 'Tests completed but artifacts could not be saved.');
                    } else {
                        $artifacts = $save;
                        $report['artifacts'] = [
                            'json' => basename((string) ($save['json'] ?? '')),
                            'html' => basename((string) ($save['html'] ?? '')),
                            'svg'  => basename((string) ($save['svg'] ?? '')),
                            'png'  => !empty($save['png']) ? basename((string) $save['png']) : null,
                            'dir'  => (string) ($save['dir'] ?? ''),
                        ];
                    }
                }
            } catch (Throwable $e) {
                error_log('[MobileAPI QA] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
                $error = 'QA run failed: ' . $e->getMessage();
                $report = null;
            }
        }
    }
}

$outputDir = mobileQaOutputDir();
$recentFiles = [];
if (is_dir($outputDir)) {
    $files = glob($outputDir . '/*.json') ?: [];
    usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
    $recentFiles = array_slice($files, 0, 8);
}

$pageTitle  = 'Mobile API QA (temporary)';
$activePage = 'settings-production';
include __DIR__ . '/../includes/admin/layout-top.php';
?>

<section class="card">
    <div class="card__header">
        <h1 class="card__title">Mobile API QA — Phase 1 sign-off</h1>
        <p class="card__subtitle">
            Temporary read-only tester for authenticated Mobile API validation.
            Does <strong>not</strong> create refresh tokens, messages, notifications, or availability changes.
        </p>
    </div>

    <?php if ($error !== ''): ?>
        <div class="alert alert--error alert--visible"><?= h($error) ?></div>
    <?php endif; ?>
    <?php if ($runWarning !== ''): ?>
        <div class="alert alert--visible" style="background:#fffbeb;border-color:#fcd34d;color:#92400e;"><?= h($runWarning) ?></div>
    <?php endif; ?>

    <p class="form-hint" style="margin-bottom:1rem;">
        Base URL: <code><?= h(rtrim(getRegistrationSiteUrl($pdo), '/') . '/api/mobile/v1') ?></code>
        · Artifacts: <code><?= h(str_replace(dirname(__DIR__) . '/', '', $outputDir)) ?></code>
    </p>

    <form method="post" class="erp-settings-form" style="max-width:720px;">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="run_qa">

        <div class="form-group form-group--full">
            <label class="form-label" for="staff_id">Approved staff account</label>
            <select class="form-input" id="staff_id" name="staff_id" required>
                <option value="">— Select staff —</option>
                <?php foreach ($staffOptions as $row): ?>
                    <?php
                    $sid = (int) ($row['id'] ?? 0);
                    $label = trim((string) ($row['surname'] ?? '') . ', ' . (string) ($row['first_name'] ?? ''))
                        . ' · ' . (string) ($row['email'] ?? '')
                        . ' (' . (int) ($row['approved_count'] ?? 0) . ' approved)';
                    $selected = $report !== null && (int) ($report['staff']['id'] ?? 0) === $sid;
                    ?>
                    <option value="<?= $sid ?>"<?= $selected ? ' selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="form-hint">Only staff with at least one approved registration are listed.</p>
        </div>

        <div class="form-group form-group--full">
            <label class="form-label" for="google_id_token">Google ID token (optional)</label>
            <textarea class="form-input" id="google_id_token" name="google_id_token" rows="3" placeholder="Paste a live Google ID token to verify OAuth (read-only — no login issued)"><?= h((string) ($_POST['google_id_token'] ?? '')) ?></textarea>
            <p class="form-hint">Leave blank to test eligibility only. Pasting a token verifies Google OAuth without storing refresh tokens.</p>
        </div>

        <div class="form-actions form-actions--end" style="flex-wrap:wrap;gap:0.5rem;">
            <button type="submit" class="btn btn--primary">Run read-only QA tests</button>
            <a href="settings-production.php#mobile-api" class="btn btn--secondary">Mobile API settings</a>
            <a href="staff-google-signin-diagnostic.php" class="btn btn--secondary">Gmail diagnostic</a>
        </div>
    </form>
</section>

<?php if ($report !== null && !empty($report['results'])): ?>
    <?php
    $overall = (string) ($report['overall'] ?? 'FAIL');
    $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
    $overallClass = $overall === 'PASS' ? 'alert--success' : 'alert--error';
    ?>
    <section class="card" id="qa-results">
        <div class="card__header">
            <h2 class="card__title">Results — <?= h($overall) ?></h2>
            <p class="card__subtitle">
                Run <?= h((string) ($report['run_id'] ?? '')) ?>
                · <?= (int) ($summary['passed'] ?? 0) ?> passed, <?= (int) ($summary['failed'] ?? 0) ?> failed
            </p>
        </div>

        <div class="alert alert--visible <?= h($overallClass) ?>">
            <strong><?= h($overall) ?></strong>
            — <?= h(trim((string) ($report['staff']['name'] ?? '') . ' · ' . (string) ($report['staff']['email'] ?? ''))) ?>
        </div>

        <?php if (!empty($artifacts['ok']) && is_array($report['artifacts'] ?? null)): ?>
            <p class="form-hint" style="margin-bottom:1rem;">
                Saved to <code><?= h((string) ($report['artifacts']['dir'] ?? '')) ?></code>:
                <?php if (!empty($report['artifacts']['png'])): ?>
                    <a href="mobile-api-qa.php?artifact=<?= h((string) $report['artifacts']['png']) ?>" target="_blank" rel="noopener">PNG screenshot</a> ·
                <?php endif; ?>
                <a href="mobile-api-qa.php?artifact=<?= h((string) ($report['artifacts']['svg'] ?? '')) ?>" target="_blank" rel="noopener">SVG</a> ·
                <a href="mobile-api-qa.php?artifact=<?= h((string) ($report['artifacts']['html'] ?? '')) ?>" target="_blank" rel="noopener">HTML report</a> ·
                <a href="mobile-api-qa.php?artifact=<?= h((string) ($report['artifacts']['json'] ?? '')) ?>" target="_blank" rel="noopener">JSON</a>
            </p>
            <?php if (!empty($report['artifacts']['png'])): ?>
                <p style="margin:0 0 1rem;">
                    <img src="mobile-api-qa.php?artifact=<?= h((string) $report['artifacts']['png']) ?>" alt="QA screenshot" style="max-width:100%;border:1px solid #e2e8f0;border-radius:8px;">
                </p>
            <?php elseif (!empty($report['artifacts']['svg'])): ?>
                <p style="margin:0 0 1rem;">
                    <img src="mobile-api-qa.php?artifact=<?= h((string) $report['artifacts']['svg']) ?>" alt="QA screenshot" style="max-width:100%;border:1px solid #e2e8f0;border-radius:8px;">
                </p>
            <?php endif; ?>
        <?php endif; ?>

        <table class="data-table" style="width:100%;">
            <thead>
                <tr>
                    <th>Group</th>
                    <th>Test</th>
                    <th>Status</th>
                    <th>Detail</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($report['results'] as $row): ?>
                    <?php if (!is_array($row)) {
                        continue;
                    } ?>
                    <?php $status = (string) ($row['status'] ?? ''); ?>
                    <tr>
                        <td><?= h((string) ($row['group'] ?? '')) ?></td>
                        <td><?= h((string) ($row['name'] ?? '')) ?></td>
                        <td>
                            <?php if ($status === 'PASS'): ?>
                                <span style="color:#15803d;font-weight:700;">PASS</span>
                            <?php else: ?>
                                <span style="color:#b91c1c;font-weight:700;">FAIL</span>
                            <?php endif; ?>
                        </td>
                        <td><?= h((string) ($row['detail'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
<?php endif; ?>

<?php if ($recentFiles !== []): ?>
    <section class="card">
        <div class="card__header">
            <h2 class="card__title">Recent sign-off runs</h2>
        </div>
        <ul style="margin:0;padding-left:1.25rem;line-height:1.7;">
            <?php foreach ($recentFiles as $jsonPath): ?>
                <?php
                $base = basename($jsonPath, '.json');
                $dir  = dirname($jsonPath);
                ?>
                <li>
                    <?= h($base) ?>
                    — <?= h(date('Y-m-d H:i', (int) filemtime($jsonPath))) ?>
                    · <a href="mobile-api-qa.php?artifact=<?= h($base) ?>.html" target="_blank" rel="noopener">HTML</a>
                    <?php if (is_file($dir . '/' . $base . '.png')): ?>
                        · <a href="mobile-api-qa.php?artifact=<?= h($base) ?>.png" target="_blank" rel="noopener">PNG</a>
                    <?php endif; ?>
                    · <a href="mobile-api-qa.php?artifact=<?= h($base) ?>.json" target="_blank" rel="noopener">JSON</a>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>

<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/platform/platform-schema.php';
require_once __DIR__ . '/../includes/platform/trust-scores.php';

requireAdminCapability('staff');
requirePlatformFeature(getDB(), 'trust_scores', 'Trust scores');

$pdo   = getDB();
$flash = getAdminFlash();
$tier  = trim((string) ($_GET['tier'] ?? ''));

if (!empty($_GET['recompute'])) {
    $count = recomputeAllTrustScores($pdo);
    setAdminFlash('success', 'Recomputed trust scores for ' . $count . ' staff.');
    header('Location: trust-scores.php');
    exit;
}

$scores = listStaffTrustScores($pdo, $tier !== '' ? $tier : null, 150);
$tiers  = summarizeTrustScoreTiers($pdo);

$pageTitle  = 'Trust Scores';
$activePage = 'trust-scores';

include __DIR__ . '/../includes/admin/layout-top.php';
?>
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/platform-ops.css">

<?php if ($flash): ?>
<div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<section class="card erp-card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Staff trust scores</h2>
            <p class="card__subtitle">Bronze · Silver · Gold · Platinum — based on attendance, profile, events, blacklist.</p>
        </div>
        <a href="trust-scores.php?recompute=1" class="btn btn--secondary">Recompute all</a>
    </div>

    <div class="platform-ops-grid">
        <?php foreach ($tiers as $t => $cnt): ?>
        <a href="trust-scores.php?tier=<?= h($t) ?>" class="platform-ops-metric" style="text-decoration:none;">
            <div class="platform-ops-metric__value"><?= (int) $cnt ?></div>
            <div class="platform-ops-metric__label"><span class="trust-tier trust-tier--<?= h($t) ?>"><?= h($t) ?></span></div>
        </a>
        <?php endforeach; ?>
    </div>

    <div class="table-wrap" style="margin-top:1rem;">
        <table class="data-table">
            <thead><tr><th>Staff</th><th>Email</th><th>Score</th><th>Tier</th><th>Updated</th></tr></thead>
            <tbody>
            <?php foreach ($scores as $row): ?>
                <tr>
                    <td><?= h(trim(($row['first_name'] ?? '') . ' ' . ($row['surname'] ?? ''))) ?></td>
                    <td><?= h((string) ($row['email'] ?? '')) ?></td>
                    <td><?= (int) ($row['score'] ?? 0) ?></td>
                    <td><span class="trust-tier trust-tier--<?= h((string) ($row['tier'] ?? 'bronze')) ?>"><?= h(trustScoreTierLabel((string) ($row['tier'] ?? 'bronze'))) ?></span></td>
                    <td><?= h(formatSystemDateTime((string) ($row['computed_at'] ?? ''), $pdo)) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($scores === []): ?>
                <tr><td colspan="5">No scores yet. Click <strong>Recompute all</strong>.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>

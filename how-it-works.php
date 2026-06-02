<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/settings-repository.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/includes/site-urls.php';
require_once __DIR__ . '/includes/company.php';
require_once __DIR__ . '/includes/website-content.php';

$pdo = getDB();
$web = initPublicWebsite($pdo, 'how');
$p   = $web['content']['how'];
$pageTitle = $p['title'] ?? 'How it works';
$pageDescription = ($p['subtitle'] ?? '') . ' — ' . $web['companyName'];

include __DIR__ . '/includes/public/layout-top.php';
$hero = ['title' => $p['title'], 'subtitle' => $p['subtitle'], 'intro' => $p['intro']];
include __DIR__ . '/includes/public/page-hero.php';
?>

<section class="site-section">
    <div class="site-section__inner">
        <div class="site-steps site-steps--full">
            <?php foreach (($p['steps'] ?? []) as $step): ?>
                <article class="site-step site-step--card">
                    <span class="site-step__num"><?= h($step['num'] ?? '') ?></span>
                    <h2><?= h($step['title'] ?? '') ?></h2>
                    <div class="rich-content"><?= renderRichText($step['desc'] ?? '') ?></div>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="site-section site-section--muted">
    <div class="site-section__inner site-split">
        <div>
            <p class="site-eyebrow">Why use our portal</p>
            <h2 class="site-heading">Designed for first-time applicants</h2>
        </div>
        <ul class="site-checklist">
            <?php foreach (($p['trust'] ?? []) as $point): ?>
                <li><?= h($point) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>

<section class="site-cta-band">
    <div class="site-cta-band__inner">
        <h2>Ready to register?</h2>
        <a class="btn btn--white btn--lg" href="<?= h($web['registrationUrl']) ?>">Open registration form</a>
    </div>
</section>

<?php include __DIR__ . '/includes/public/layout-bottom.php'; ?>

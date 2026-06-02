<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/settings-repository.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/includes/site-urls.php';
require_once __DIR__ . '/includes/company.php';
require_once __DIR__ . '/includes/website-content.php';

$pdo = getDB();
$web = initPublicWebsite($pdo, 'events');
$p   = $web['content']['events'];
$pageTitle = $p['title'] ?? 'Events';
$pageDescription = ($p['subtitle'] ?? '') . ' — ' . $web['companyName'];

include __DIR__ . '/includes/public/layout-top.php';
$hero = ['title' => $p['title'], 'subtitle' => $p['subtitle'], 'intro' => $p['intro']];
include __DIR__ . '/includes/public/page-hero.php';
?>

<section class="site-section">
    <div class="site-section__inner">
        <p class="site-eyebrow">Event types</p>
        <div class="site-pills site-pills--lg">
            <?php foreach (($p['types'] ?? []) as $type): ?>
                <span class="site-pill site-pill--solid"><?= h($type) ?></span>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="site-section site-section--muted">
    <div class="site-section__inner">
        <p class="site-eyebrow">Application flow</p>
        <h2 class="site-heading">How event registration works</h2>
        <div class="site-timeline">
            <?php foreach (($p['steps'] ?? []) as $i => $step): ?>
                <article class="site-timeline__item">
                    <span class="site-timeline__dot"><?= (int) $i + 1 ?></span>
                    <h3><?= h($step['title'] ?? '') ?></h3>
                    <div class="rich-content"><?= renderRichText($step['desc'] ?? '') ?></div>
                </article>
            <?php endforeach; ?>
        </div>
        <div class="site-section__actions">
            <a class="btn btn--primary btn--lg" href="<?= h($web['registrationUrl']) ?>">View open events & register</a>
            <a class="btn btn--secondary btn--lg" href="<?= h(getWebsitePageUrl('how', $pdo)) ?>">How it works</a>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/public/layout-bottom.php'; ?>

<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/settings-repository.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/includes/site-urls.php';
require_once __DIR__ . '/includes/company.php';
require_once __DIR__ . '/includes/brand-logo.php';
require_once __DIR__ . '/includes/website-content.php';
require_once __DIR__ . '/includes/rich-text.php';

try {
    $pdo = getDB();
} catch (Throwable $e) {
    $pdo = null;
}

$web         = initPublicWebsite($pdo, 'home');
$c           = $web['content']['home'];
$pageTitle   = 'Home';
$pageDescription = ($c['hero_lead'] ?? '') . ' — ' . $web['companyName'];

include __DIR__ . '/includes/public/layout-top.php';
?>

<section class="home-hero site-hero">
    <div class="site-hero__mesh" aria-hidden="true"></div>
    <div class="site-hero__inner">
        <div class="site-hero__copy">
            <p class="site-hero__eyebrow"><?= h($c['hero_eyebrow'] ?? '') ?></p>
            <h1 class="site-hero__title"><?= h($c['hero_title'] ?? '') ?></h1>
            <p class="site-hero__lead rich-content"><?= renderRichText($c['hero_lead'] ?? '') ?></p>
            <div class="site-hero__actions">
                <a class="btn btn--primary btn--lg" href="<?= h($web['registrationUrl']) ?>"><?= h($c['cta_primary'] ?? 'Register') ?></a>
                <a class="btn btn--ghost btn--lg" href="<?= h(getWebsitePageUrl('how', $pdo)) ?>"><?= h($c['cta_secondary'] ?? 'Learn more') ?></a>
            </div>
        </div>
        <aside class="site-hero__brand-panel" aria-label="Company branding">
            <div class="site-hero__brand-card">
                <?php renderSiteBrandLogo($pdo, 'hero', '', $web['companyName']); ?>
                <p class="site-hero__brand-name"><?= h($web['companyName']) ?></p>
                <p class="site-hero__brand-tagline"><?= h($pdo ? getCompanyTagline($pdo) : '') ?></p>
            </div>
        </aside>
    </div>
    <div class="site-hero__stats-row">
        <div class="site-hero__stats">
            <?php foreach (($c['stats'] ?? []) as $stat): ?>
                <article class="site-stat">
                    <p class="site-stat__value"><?= h($stat['value'] ?? '') ?></p>
                    <p class="site-stat__label"><?= h($stat['label'] ?? '') ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="site-section">
    <div class="site-section__inner">
        <div class="site-section__head site-section__head--row">
            <div>
                <p class="site-eyebrow">Quick links</p>
                <h2 class="site-heading"><?= h($c['preview_title'] ?? '') ?></h2>
                <div class="site-lead rich-content"><?= renderRichText($c['preview_desc'] ?? '') ?></div>
            </div>
            <a class="btn btn--secondary" href="<?= h(getWebsitePageUrl('roles', $pdo)) ?>">View all roles →</a>
        </div>
        <div class="site-card-grid site-card-grid--3">
            <?php foreach (array_slice($web['content']['roles']['items'] ?? [], 0, 3) as $item): ?>
                <a class="site-card site-card--link" href="<?= h(getWebsitePageUrl('roles', $pdo)) ?>">
                    <span class="site-card__icon"><?= renderHomeServiceIcon($item['icon'] ?? 'shield') ?></span>
                    <h3 class="site-card__title"><?= h($item['title'] ?? '') ?></h3>
                    <p class="site-card__text"><?= h($item['desc'] ?? '') ?></p>
                    <span class="site-card__arrow">Learn more →</span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="site-section site-section--dark">
    <div class="site-section__inner site-split">
        <div>
            <p class="site-eyebrow site-eyebrow--light">Process</p>
            <h2 class="site-heading site-heading--light">From registration to event day</h2>
            <p class="site-lead site-lead--light">Four simple steps — no agency runaround.</p>
            <a class="btn btn--primary" href="<?= h(getWebsitePageUrl('how', $pdo)) ?>">Full guide</a>
        </div>
        <ol class="site-steps site-steps--compact">
            <?php foreach (($web['content']['how']['steps'] ?? []) as $step): ?>
                <li class="site-step">
                    <span class="site-step__num"><?= h($step['num'] ?? '') ?></span>
                    <div>
                        <strong><?= h($step['title'] ?? '') ?></strong>
                        <p><?= renderRichText($step['desc'] ?? '') ?></p>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
    </div>
</section>

<section class="site-section">
    <div class="site-section__inner">
        <div class="site-section__head">
            <p class="site-eyebrow">Events</p>
            <h2 class="site-heading">Where opportunities appear</h2>
        </div>
        <div class="site-pills">
            <?php foreach (($web['content']['events']['types'] ?? []) as $type): ?>
                <a class="site-pill" href="<?= h(getWebsitePageUrl('events', $pdo)) ?>"><?= h($type) ?></a>
            <?php endforeach; ?>
        </div>
        <p class="site-section__foot"><a href="<?= h(getWebsitePageUrl('events', $pdo)) ?>">See how events work →</a></p>
    </div>
</section>

<section class="site-cta-band">
    <div class="site-cta-band__inner">
        <h2><?= h($c['cta_band_title'] ?? '') ?></h2>
        <div class="rich-content"><?= renderRichText($c['cta_band_desc'] ?? '') ?></div>
        <div class="site-cta-band__actions">
            <a class="btn btn--white btn--lg" href="<?= h($web['registrationUrl']) ?>">Start registration</a>
            <a class="btn btn--ghost-white btn--lg" href="<?= h(getWebsitePageUrl('faq', $pdo)) ?>">Read FAQ</a>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/public/layout-bottom.php'; ?>

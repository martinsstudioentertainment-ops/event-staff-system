<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/settings-repository.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/includes/site-urls.php';
require_once __DIR__ . '/includes/company.php';
require_once __DIR__ . '/includes/website-content.php';

$pdo = getDB();
$web = initPublicWebsite($pdo, 'faq');
$p   = $web['content']['faq'];
$pageTitle = $p['title'] ?? 'FAQ';
$pageDescription = ($p['subtitle'] ?? '') . ' — ' . $web['companyName'];

include __DIR__ . '/includes/public/layout-top.php';
$hero = ['title' => $p['title'], 'subtitle' => $p['subtitle'], 'compact' => true];
include __DIR__ . '/includes/public/page-hero.php';
?>

<section class="site-section">
    <div class="site-section__inner site-faq">
        <?php foreach (($p['items'] ?? []) as $item): ?>
            <details class="site-faq__item">
                <summary><?= h($item['q'] ?? '') ?></summary>
                <div class="rich-content"><?= renderRichText($item['a'] ?? '') ?></div>
            </details>
        <?php endforeach; ?>
    </div>
</section>

<section class="site-cta-band site-cta-band--slim">
    <div class="site-cta-band__inner">
        <p>Still have questions? <a href="<?= h(getWebsitePageUrl('contact', $pdo)) ?>" style="color:#fff;text-decoration:underline">Contact us</a> or <a href="<?= h($web['registrationUrl']) ?>" style="color:#fff;text-decoration:underline">start your registration</a>.</p>
    </div>
</section>

<?php include __DIR__ . '/includes/public/layout-bottom.php'; ?>

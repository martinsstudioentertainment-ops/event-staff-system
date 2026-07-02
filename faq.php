<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/settings-repository.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/includes/site-urls.php';
require_once __DIR__ . '/includes/company.php';
require_once __DIR__ . '/includes/website-content.php';
require_once __DIR__ . '/includes/staff-google-oauth.php';

$pdo = getDB();
$staffAppUrl = $pdo ? rtrim(getRegistrationSiteUrl($pdo), '/') . '/staff-app.php' : 'staff-app.php';
$registerHost = $pdo ? (parse_url(getRegistrationSiteUrl($pdo), PHP_URL_HOST) ?: 'register.olasentra.com') : 'register.olasentra.com';
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

<section class="site-section site-section--alt">
    <div class="site-section__inner site-faq">
        <h2 class="site-section__title" style="margin-bottom:1rem;">Returning staff — staff app &amp; Gmail</h2>
        <details class="site-faq__item" open>
            <summary>How do I sign in to view my shifts?</summary>
            <div class="rich-content">
                <p>Open the <a href="<?= h($staffAppUrl) ?>">staff app</a> and tap <strong>Continue with Google</strong> with the same Gmail you used when registering. You stay signed in on your phone for shift tracking.</p>
            </div>
        </details>
        <details class="site-faq__item">
            <summary>Why Google sign-in instead of typing my PPS?</summary>
            <div class="rich-content">
                <p>Google verifies your identity — you do not type PPS into the staff app. That is more secure on a phone. <strong>Venue QR sign-in</strong> at the event still uses email plus last 4 of PPS once when you arrive.</p>
            </div>
        </details>
        <details class="site-faq__item">
            <summary>Location and GPS during my shift</summary>
            <div class="rich-content">
                <p>Allow <strong>location</strong> for <?= h($registerHost) ?> in your phone settings. Add the staff app to your <strong>home screen</strong> and reopen it during breaks — phones cannot track GPS when the browser is fully closed.</p>
            </div>
        </details>
    </div>
</section>

<section class="site-cta-band site-cta-band--slim">
    <div class="site-cta-band__inner">
        <p>Still have questions? <a href="<?= h(getWebsitePageUrl('contact', $pdo)) ?>" style="color:#fff;text-decoration:underline">Contact us</a> or <a href="<?= h($web['registrationUrl']) ?>" style="color:#fff;text-decoration:underline">start your registration</a>.</p>
    </div>
</section>

<?php include __DIR__ . '/includes/public/layout-bottom.php'; ?>

<?php
/** @var array $web */
/** @var string $pageTitle */
/** @var string $pageDescription */

require_once __DIR__ . '/../website-content.php';
require_once __DIR__ . '/../theme.php';
require_once __DIR__ . '/../brand-logo.php';
require_once __DIR__ . '/../share-meta.php';
require_once __DIR__ . '/../rich-text.php';
require_once __DIR__ . '/../feature-flags.php';

$pageTitle       = $pageTitle ?? $web['companyName'];
$pageDescription = $pageDescription ?? '';
$themeColor      = $web['themeColor'];
$assetBase       = $web['assetBase'];
$pdo             = $web['pdo'];
$enablePwa       = false;
// Premium public UI (feature_public_premium_v2): homepage gets the full hp-*
// experience; the same flag now extends the premium design system to all
// public marketing pages so the whole site feels consistent.
$premiumPublicSlugs = ['home', 'roles', 'events', 'how', 'faq', 'contact'];
$isPremiumPublic = in_array($web['pageSlug'] ?? '', $premiumPublicSlugs, true)
    && isFeatureEnabled($pdo, 'feature_public_premium_v2');
$isPremiumHome   = $isPremiumPublic && ($web['pageSlug'] ?? '') === 'home';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= $isPremiumPublic ? 'dark' : 'light' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php
    renderShareMeta([
        'title'       => $pageTitle . ' | ' . $web['companyName'],
        'description' => $pageDescription ?? '',
        'url'         => $shareUrl ?? '',
        'site_name'   => $web['companyName'],
    ], $pdo);
    ?>
    <title><?= h($pageTitle) ?> | <?= h($web['companyName']) ?></title>
    <?php include __DIR__ . '/../pwa-head.php'; ?>
    <link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/website.css">
    <link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/mobile.css">
    <link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/site-notice.css">
    <?php if ($isPremiumPublic): ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <?php if ($isPremiumHome): ?>
    <link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/home-premium.css">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/premium-site.css">
    <?php endif; ?>
</head>
<body class="site-page<?= $isPremiumPublic ? ' site-page--premium' : '' ?><?= $isPremiumHome ? ' site-page--premium-home' : '' ?>" data-page="<?= h($web['pageSlug']) ?>">

<header class="site-header">
    <div class="site-header__inner">
        <a class="site-header__brand" href="<?= h(getWebsitePageUrl('home', $pdo, $assetBase)) ?>">
            <span class="site-header__logo">
                <?php renderSiteBrandLogo($pdo, 'header', $assetBase, $web['companyName'] ?? ''); ?>
            </span>
            <span>
                <span class="site-header__name"><?= h($web['companyName']) ?></span>
                <span class="site-header__tag"><?= h($web['content']['global']['brand_tag'] ?? '') ?></span>
            </span>
        </a>
        <button class="site-header__menu-btn" id="site-menu-btn" type="button" aria-label="Menu" aria-expanded="false">
            <span></span><span></span><span></span>
        </button>
        <nav class="site-nav" id="site-nav" aria-label="Main">
            <?php foreach (getWebsiteNavItems($pdo, $web['pageSlug'], $assetBase) as $item): ?>
                <a href="<?= h($item['url']) ?>" class="site-nav__link<?= $item['slug'] === $web['pageSlug'] ? ' site-nav__link--active' : '' ?>"><?= h($item['label']) ?></a>
            <?php endforeach; ?>
            <a class="site-nav__cta" href="<?= h($web['registrationUrl']) ?>">Register</a>
        </nav>
    </div>
</header>

<?php include __DIR__ . '/site-notice.php'; ?>

<main class="site-main">

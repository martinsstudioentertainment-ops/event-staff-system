<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/settings-repository.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/includes/site-urls.php';
require_once __DIR__ . '/includes/company.php';
require_once __DIR__ . '/includes/website-content.php';

$pdo = null;
try {
    $pdo = getDB();
} catch (Throwable $e) {
    $pdo = null;
}

$formUrl = $pdo ? trim((string) getSetting($pdo, 'google_form_aviva_url', '')) : '';
if ($formUrl === '') {
    $formUrl = 'https://docs.google.com/forms/d/e/1FAIpQLSd3zQMebmrJPpi1_4jVMTfNdwr6raAwjDIgXqQ_uabFB86lOQ/viewform';
}

$web = initPublicWebsite($pdo, 'home');
$pageTitle = 'AVIVA Stadium — 1 October';
$pageDescription = 'Register for AVIVA Stadium, 1 October 2026, 16:00–23:00. DSP and Static only.';

include __DIR__ . '/includes/public/layout-top.php';
?>

<section class="site-section">
    <div class="site-section__inner">
        <p class="hp-eyebrow">Open registration</p>
        <h1 class="site-section__title">AVIVA Stadium — 1 October 2026</h1>
        <p>Thursday 1 October 2026 · 16:00–23:00 · DSP and Static only</p>
        <p>Olasentra is not a security company. We only link staff to security companies. All PSA licences will be verified.</p>
        <p>On the form, upload a photo of the <strong>front</strong> and <strong>back</strong> of your PSA badge. You must be signed into Google. If an iPhone photo will not attach, open the photo, take a screenshot, and upload the screenshot. Do not use Instagram or Facebook to open the form — use Chrome or Safari.</p>
        <p><a class="hp-btn hp-btn--primary" href="<?= h($formUrl) ?>">Open staff form</a></p>
    </div>
</section>

<?php include __DIR__ . '/includes/public/layout-bottom.php'; ?>

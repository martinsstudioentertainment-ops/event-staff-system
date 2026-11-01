<?php
/** Event page: /aviva-match-event.php (short link /aviva-match). Mirrors aviva-stadium.php layout. */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/settings-repository.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/includes/site-urls.php';
require_once __DIR__ . '/includes/company.php';
require_once __DIR__ . '/includes/website-content.php';
require_once __DIR__ . '/includes/aviva-match.php';

$pdo = null; $count = 0; $full = false; $date = 'TBC'; $formUrl = '';
try {
    $pdo = getDB();
    $count = avivaMatchCount($pdo);
    $full = $count >= AVIVA_MATCH_CAPACITY;
    $date = avivaMatchDate($pdo);
    $formUrl = avivaMatchFormUrl($pdo);
} catch (Throwable $e) { error_log('[EventStaff] aviva-match page: ' . $e->getMessage()); }
$dateLabel = $date === 'TBC' ? 'Date TBC' : date('l j F Y', strtotime($date));

$web = initPublicWebsite($pdo, 'home');
$pageTitle = AVIVA_MATCH_NAME;
$pageDescription = AVIVA_MATCH_NAME . ' · ' . AVIVA_MATCH_VENUE . ' · ' . $dateLabel . ' · ' . AVIVA_MATCH_TIME . '. Registered Olasentra staff only.';
include __DIR__ . '/includes/public/layout-top.php';
?>
<section class="site-section">
  <div class="site-section__inner">
    <p class="hp-eyebrow"><?= $full ? 'Event full' : 'Open registration · registered staff only' ?></p>
    <h1 class="site-section__title"><?= h(AVIVA_MATCH_NAME) ?></h1>
    <p><?= h(AVIVA_MATCH_VENUE) ?> · <?= h($dateLabel) ?> · <?= h(AVIVA_MATCH_TIME) ?></p>
    <p><strong id="am-count"><?= (int) $count ?></strong> / <?= AVIVA_MATCH_CAPACITY ?> places confirmed</p>
    <p>Only staff already registered on Olasentra can take a place. Use the same email or mobile you registered with.
       Not registered yet? <a href="/">Register on olasentra.com first</a>.</p>
    <?php if ($full): ?>
      <p class="hp-btn hp-btn--secondary" aria-disabled="true">Event full</p>
    <?php elseif ($formUrl !== ''): ?>
      <p><a class="hp-btn hp-btn--primary" href="<?= h($formUrl) ?>">Open staff form</a></p>
    <?php else: ?>
      <p>Form opening soon.</p>
    <?php endif; ?>
  </div>
</section>
<?php include __DIR__ . '/includes/public/layout-bottom.php'; ?>

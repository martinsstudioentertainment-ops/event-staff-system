<?php

/** @var array $web */

/** @var PDO|null $pdo */

require_once __DIR__ . '/../website-content.php';
require_once __DIR__ . '/../site-urls.php';
require_once __DIR__ . '/../auth.php';

$pdo           = $web['pdo'] ?? null;
$items         = getWebsiteNoticeItems($pdo);
$noticeVariant = (string) ($web['notice_variant'] ?? 'scroll');
$isStatic      = $noticeVariant === 'static';

if ($items === []) {
    return;
}

$noticeEditUrl = $pdo ? getWebsiteNoticeEditUrl($pdo) : '';
$showAdminEdit = isAdminLoggedIn() && $noticeEditUrl !== '';

?>
<div class="site-notice<?= $isStatic ? ' site-notice--static' : '' ?>" role="region" aria-label="Important notice" data-site-notice>
    <div class="site-notice__label" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>
        Notice
    </div>
    <div class="site-notice__viewport">
        <div class="site-notice__track">
            <div class="site-notice__group">
                <?php foreach ($items as $item): ?>
                    <?php if ($isStatic): ?>
                        <p class="site-notice__item"><?= h($item) ?></p>
                    <?php else: ?>
                        <span class="site-notice__item"><?= h($item) ?></span>
                        <span class="site-notice__sep" aria-hidden="true">◆</span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php if ($showAdminEdit): ?>
        <a class="site-notice__edit" href="<?= h($noticeEditUrl) ?>" title="Edit notice messages">Edit notices</a>
    <?php endif; ?>
</div>

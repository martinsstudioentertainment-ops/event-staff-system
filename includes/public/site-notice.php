<?php

/** @var array $web */

/** @var PDO|null $pdo */

require_once __DIR__ . '/../website-content.php';
require_once __DIR__ . '/../site-urls.php';
require_once __DIR__ . '/../auth.php';

$pdo           = $web['pdo'] ?? null;
$items         = getWebsiteNoticeItems($pdo);
$noticeVariant   = (string) ($web['notice_variant'] ?? 'scroll');
$isStatic        = $noticeVariant === 'static';
$foldOnMobile    = !empty($web['notice_collapsible']);
$summaryText     = trim((string) ($items[0] ?? 'Important notice'));

if ($items === []) {
    return;
}

$noticeEditUrl = $pdo ? getWebsiteNoticeEditUrl($pdo) : '';
$showAdminEdit = isAdminLoggedIn() && $noticeEditUrl !== '';

$noticeClass = 'site-notice' . ($isStatic ? ' site-notice--static' : '') . ($foldOnMobile ? ' site-notice--fold' : '');

if ($foldOnMobile): ?>
<details class="<?= h($noticeClass) ?>" data-site-notice>
    <summary class="site-notice__fold-summary">
        <span class="site-notice__fold-icon" aria-hidden="true">!</span>
        <span class="site-notice__fold-text"><?= h($summaryText) ?></span>
        <span class="site-notice__fold-chevron" aria-hidden="true"></span>
    </summary>
    <div class="site-notice__fold-body">
        <div class="site-notice__group">
            <?php foreach ($items as $item): ?>
                <p class="site-notice__item"><?= h($item) ?></p>
            <?php endforeach; ?>
        </div>
        <?php if ($showAdminEdit): ?>
            <a class="site-notice__edit" href="<?= h($noticeEditUrl) ?>" title="Edit notice messages">Edit notices</a>
        <?php endif; ?>
    </div>
</details>
<?php else: ?>
<div class="<?= h($noticeClass) ?>" role="region" aria-label="Important notice" data-site-notice>
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
<?php endif; ?>

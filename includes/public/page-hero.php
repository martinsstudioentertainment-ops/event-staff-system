<?php
/** @var array $web */
/** @var array $hero */
?>
<section class="page-hero<?= !empty($hero['compact']) ? ' page-hero--compact' : '' ?>">
    <div class="page-hero__bg" aria-hidden="true"></div>
    <div class="page-hero__inner">
        <?php if (!empty($hero['eyebrow'])): ?>
            <p class="page-hero__eyebrow"><?= h($hero['eyebrow']) ?></p>
        <?php endif; ?>
        <h1 class="page-hero__title"><?= h($hero['title'] ?? '') ?></h1>
        <?php if (!empty($hero['subtitle'])): ?>
            <p class="page-hero__subtitle"><?= h($hero['subtitle']) ?></p>
        <?php endif; ?>
        <?php if (!empty($hero['intro'])): ?>
            <div class="page-hero__intro rich-content"><?= renderRichText($hero['intro']) ?></div>
        <?php endif; ?>
    </div>
</section>

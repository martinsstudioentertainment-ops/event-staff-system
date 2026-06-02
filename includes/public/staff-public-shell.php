<?php

require_once __DIR__ . '/../theme.php';

/**
 * Animated mesh background for staff-facing pages.
 */
function renderStaffPublicBackground(bool $eventOps = false): void
{
    $extraClass = $eventOps ? ' staff-public-bg--event-ops' : '';
    ?>
    <div class="staff-public-bg<?= $extraClass ?>" aria-hidden="true">
        <div class="staff-public-bg__mesh"></div>
        <?php if ($eventOps): ?>
            <div class="staff-public-bg__lights"></div>
        <?php endif; ?>
        <span class="staff-public-bg__blob staff-public-bg__blob--1"></span>
        <span class="staff-public-bg__blob staff-public-bg__blob--2"></span>
        <span class="staff-public-bg__blob staff-public-bg__blob--3"></span>
        <?php if ($eventOps): ?>
            <span class="staff-public-bg__blob staff-public-bg__blob--4"></span>
            <span class="staff-public-bg__blob staff-public-bg__blob--5"></span>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * @param array{
 *   subtitle?: string,
 *   language_switcher?: bool,
 *   lang_query?: string,
 *   theme_toggle?: bool,
 *   home_url?: string
 * } $opts
 */
function renderStaffPublicHeader(?PDO $pdo, string $siteName, array $opts = []): void
{
    $subtitle    = (string) ($opts['subtitle'] ?? ($pdo ? getThemePublicSubtitle($pdo) : 'Event Staff'));
    $showLang    = !empty($opts['language_switcher']);
    $langQuery   = (string) ($opts['lang_query'] ?? '');
    $showTheme   = ($opts['theme_toggle'] ?? true) === true;
    $homeUrl     = (string) ($opts['home_url'] ?? 'staff-app.php');
    ?>
    <header class="staff-public-header">
        <a class="staff-public-header__brand" href="<?= h($homeUrl) ?>">
            <span class="staff-public-header__logo brand-icon" aria-hidden="true">
                <?= $pdo ? renderThemeBrandIcon($pdo) : renderThemeCategoryIcon('events') ?>
            </span>
            <span class="staff-public-header__text">
                <span class="staff-public-header__title"><?= h($siteName) ?></span>
                <span class="staff-public-header__subtitle"><?= h($subtitle) ?></span>
            </span>
        </a>
        <div class="staff-public-header__actions">
            <?php if ($showLang && $pdo) {
                renderLanguageSwitcher($langQuery);
            } ?>
            <?php if ($showTheme): ?>
                <button class="staff-public-header__theme theme-toggle" id="theme-toggle" type="button" aria-label="Toggle dark mode" title="Toggle theme">
                    <span class="theme-toggle__icon" aria-hidden="true">🌙</span>
                </button>
            <?php endif; ?>
        </div>
    </header>
    <?php
}

/**
 * @param array{eyebrow?: string, title: string, lead?: string} $hero
 */
function renderStaffPublicHero(array $hero): void
{
    $eyebrow = trim((string) ($hero['eyebrow'] ?? ''));
    $title   = trim((string) ($hero['title'] ?? ''));
    $lead    = (string) ($hero['lead'] ?? '');
    if ($title === '') {
        return;
    }
    ?>
    <div class="staff-public-hero">
        <?php if ($eyebrow !== ''): ?>
            <p class="staff-public-hero__eyebrow"><?= h($eyebrow) ?></p>
        <?php endif; ?>
        <h1 class="staff-public-hero__title"><?= h($title) ?></h1>
        <?php if ($lead !== ''): ?>
            <div class="staff-public-hero__lead rich-content"><?= $lead ?></div>
        <?php endif; ?>
    </div>
    <?php
}

function renderStaffPublicFooter(string $siteName): void
{
    ?>
    <footer class="staff-public-footer">
        <p>&copy; <?= date('Y') ?> <?= h($siteName) ?> — Staff use only · <a href="privacy.php">Privacy</a></p>
    </footer>
    <?php
}

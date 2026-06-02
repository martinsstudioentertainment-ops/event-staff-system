<?php

require_once __DIR__ . '/maps.php';
require_once __DIR__ . '/settings-repository.php';
require_once __DIR__ . '/events-repository.php';

function isSigninPpsRequired(?PDO $pdo = null): bool
{
    if ($pdo === null && function_exists('getDB')) {
        try {
            $pdo = getDB();
        } catch (Throwable $e) {
            return true;
        }
    }

    if ($pdo === null) {
        return true;
    }

    return getSetting($pdo, 'signin_require_pps_last4', '1') === '1';
}

function formatEventReportingLabel(array $event): string
{
    return trim((string) ($event['reporting_point'] ?? ''));
}

/**
 * Prominent block: who employs staff on this shift (not the registration portal).
 *
 * @param array<string, mixed> $event
 */
function renderEventMainSecurityEmployerBlock(array $event): void
{
    $employer = formatEventMainSecurityLabel($event);
    if ($employer === '') {
        return;
    }

    require_once __DIR__ . '/i18n.php';
    ?>
    <div class="employer-notice" role="note">
        <p class="employer-notice__label"><?= h(t('working_for')) ?></p>
        <p class="employer-notice__company"><?= h($employer) ?></p>
        <p class="employer-notice__hint"><?= h(t('portal_not_employer')) ?></p>
    </div>
    <?php
}

/**
 * @param array<string, mixed> $window
 * @return array{key: string, label: string}
 */
function getSigninPhaseMeta(array $window): array
{
    require_once __DIR__ . '/i18n.php';

    $status = (string) ($window['status'] ?? 'open');

    if ($status === 'after') {
        return ['key' => 'after', 'label' => t('sign_in_status_after')];
    }

    if ($status === 'before') {
        return ['key' => 'before', 'label' => t('sign_in_status_before')];
    }

    $eventEnd = $window['event_end'] ?? null;
    if ($eventEnd instanceof DateTimeInterface) {
        $now = new DateTime('now', $eventEnd->getTimezone());
        if ($now > $eventEnd) {
            return ['key' => 'event_ended', 'label' => t('sign_in_status_event_ended')];
        }
    }

    return ['key' => 'open', 'label' => t('sign_in_status_open')];
}

/**
 * @param array<string, mixed>|null $event
 * @param array<string, mixed>|null $window
 */
function renderSigninPageHeading(?array $event, ?array $window, string $signInKindLabel, string $subtitle): void
{
    require_once __DIR__ . '/i18n.php';
    require_once __DIR__ . '/events-repository.php';

    $eventName = $event ? trim((string) ($event['name'] ?? $event['event_name'] ?? '')) : '';
    $title     = $eventName !== '' ? $eventName : $signInKindLabel;
    $kindLine  = $eventName !== '' ? $signInKindLabel : '';

    if ($event && $kindLine !== '' && !empty($event['event_date'])) {
        $kindLine .= ' · ' . formatEventDateLabel((string) $event['event_date']);
    }

    $eventEndIso = ($window && ($window['event_end'] ?? null) instanceof DateTimeInterface)
        ? $window['event_end']->format(DateTimeInterface::ATOM)
        : '';
    ?>
    <div class="signin-page-heading">
        <?php if ($window): ?>
            <?php $phase = getSigninPhaseMeta($window); ?>
            <p
                class="signin-page-heading__status signin-page-heading__status--<?= h($phase['key']) ?>"
                data-signin-phase-status
                data-event-end-at="<?= h($eventEndIso) ?>"
                data-label-before="<?= h(t('sign_in_status_before')) ?>"
                data-label-open="<?= h(t('sign_in_status_open')) ?>"
                data-label-event-ended="<?= h(t('sign_in_status_event_ended')) ?>"
                data-label-after="<?= h(t('sign_in_status_after')) ?>"
            ><?= h($phase['label']) ?></p>
        <?php endif; ?>
        <h1 class="card__title signin-page-heading__title"><?= h($title) ?></h1>
        <?php if ($kindLine !== ''): ?>
            <p class="signin-page-heading__kind"><?= h($kindLine) ?></p>
        <?php endif; ?>
        <p class="card__subtitle"><?= h($subtitle) ?></p>
    </div>
    <?php
}

/**
 * @param array<string, mixed> $window
 */
function renderSigninCountdown(array $window, ?string $registrationCreatedAt = null): void
{
    require_once __DIR__ . '/i18n.php';
    require_once __DIR__ . '/system-settings.php';

    $status    = (string) ($window['status'] ?? 'open');
    $opensIso  = $window['opens_at'] instanceof DateTimeInterface ? $window['opens_at']->format(DateTimeInterface::ATOM) : '';
    $closesIso = $window['closes_at'] instanceof DateTimeInterface ? $window['closes_at']->format(DateTimeInterface::ATOM) : '';
    $timeFmt   = getSystemDateFormat() . ' H:i';
    ?>
    <div
        class="signin-countdown signin-countdown--<?= h($status) ?>"
        data-signin-countdown
        data-status="<?= h($status) ?>"
        data-opens-at="<?= h($opensIso) ?>"
        data-closes-at="<?= h($closesIso) ?>"
        data-label-opens="<?= h(t('sign_in_opens_in')) ?>"
        data-label-closes="<?= h(t('sign_in_closes_in')) ?>"
        data-label-closed="<?= h(t('sign_in_closed')) ?>"
    >
        <div class="signin-countdown__meta">
            <?php if ($registrationCreatedAt): ?>
                <p class="signin-countdown__registered">
                    <?= h(t('registered_on')) ?>:
                    <strong><?= h(formatSystemDateTime($registrationCreatedAt)) ?></strong>
                </p>
            <?php endif; ?>
            <p class="signin-countdown__opens">
                <?= h(t('sign_in_opens_at')) ?>:
                <strong><?= h($window['opens_at']->format($timeFmt)) ?></strong>
            </p>
            <p class="signin-countdown__closes">
                <?= h(t('sign_in_closes_at')) ?>:
                <strong><?= h($window['closes_at']->format($timeFmt)) ?></strong>
            </p>
        </div>
        <p class="signin-countdown__label"><?= h($status === 'before' ? t('sign_in_opens_in') : ($status === 'after' ? t('sign_in_closed') : t('sign_in_closes_in'))) ?></p>
        <p class="signin-countdown__timer" aria-live="polite">--:--:--</p>
    </div>
    <?php
}

function getGoogleMapsEmbedUrl(?float $lat, ?float $lng, ?PDO $pdo = null): string
{
    if ($lat === null || $lng === null) {
        return '';
    }

    $key = getGoogleMapsApiKey($pdo);
    if ($key === '') {
        return '';
    }

    return 'https://www.google.com/maps/embed/v1/view?key=' . rawurlencode($key)
        . '&center=' . rawurlencode($lat . ',' . $lng)
        . '&zoom=16&maptype=roadmap';
}

/**
 * Render venue map block for public sign-in pages.
 *
 * @param array<string, mixed> $event
 */
function renderVenueMapBlock(array $event, ?PDO $pdo = null): void
{
    $coords = getEventVenueCoordinates($event);
    if ($coords === null) {
        return;
    }

    $mapsLink = buildGoogleMapsLink($coords['lat'], $coords['lng']);
    $embedUrl = getGoogleMapsEmbedUrl($coords['lat'], $coords['lng'], $pdo);
    $reporting = formatEventReportingLabel($event);
    ?>
    <div class="venue-map-block">
        <?php if ($reporting !== ''): ?>
            <p class="venue-map-block__reporting"><strong>Reporting point:</strong> <?= h($reporting) ?></p>
        <?php endif; ?>
        <?php if ($embedUrl !== ''): ?>
            <div class="venue-map-block__frame-wrap">
                <iframe
                    class="venue-map-block__frame"
                    title="Venue map"
                    loading="lazy"
                    referrerpolicy="no-referrer-when-downgrade"
                    src="<?= h($embedUrl) ?>"
                    allowfullscreen
                ></iframe>
            </div>
        <?php endif; ?>
        <?php if ($mapsLink !== ''): ?>
            <p class="venue-map-block__link">
                <a href="<?= h($mapsLink) ?>" target="_blank" rel="noopener noreferrer">Open venue in Google Maps ↗</a>
            </p>
        <?php endif; ?>
    </div>
    <?php
}

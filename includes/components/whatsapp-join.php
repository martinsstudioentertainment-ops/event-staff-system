<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/company.php';
require_once dirname(__DIR__) . '/settings-repository.php';
require_once dirname(__DIR__) . '/date-format.php';

/**
 * Mobile-friendly WhatsApp group join card (invite link from Settings).
 */
function renderWhatsappGroupCard(PDO $pdo, string $variant = 'staff'): void
{
    $groupUrl  = getCompanyWhatsappGroup($pdo);
    $contact   = getCompanyWhatsapp($pdo);
    $contactUrl = formatWhatsappHref($contact);
    $label     = trim(getSetting($pdo, 'company_whatsapp_group_label', ''));
    if ($label === '') {
        $label = 'Join our staff WhatsApp group';
    }
    $hint = trim(getSetting($pdo, 'company_whatsapp_group_hint', ''));
    if ($hint === '') {
        $hint = 'Get shift updates, reminders, and last-minute calls. Tap to open WhatsApp — you may need approval from an admin to join.';
    }

    if ($groupUrl === '' && $contactUrl === '') {
        return;
    }

    $isCompact = $variant === 'compact';
    ?>
<section class="wa-join-card<?= $isCompact ? ' wa-join-card--compact' : '' ?>" aria-labelledby="wa-join-title">
    <div class="wa-join-card__icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="28" height="28" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 2C6.477 2 2 6.477 2 12c0 1.89.525 3.66 1.438 5.168L2 22l4.832-1.438A9.955 9.955 0 0 0 12 22c5.523 0 10-4.477 10-10S17.523 2 12 2zm0 18a7.96 7.96 0 0 1-4.075-1.125l-.293-.175-2.87.85.85-2.87-.175-.293A7.96 7.96 0 0 1 4 12c0-4.411 3.589-8 8-8s8 3.589 8 8-3.589 8-8 8z"/></svg>
    </div>
    <div class="wa-join-card__body">
        <h2 id="wa-join-title" class="wa-join-card__title"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></h2>
        <p class="wa-join-card__text"><?= htmlspecialchars($hint, ENT_QUOTES, 'UTF-8') ?></p>
        <div class="wa-join-card__actions">
            <?php if ($groupUrl !== ''): ?>
                <a href="<?= htmlspecialchars($groupUrl, ENT_QUOTES, 'UTF-8') ?>" class="wa-join-card__btn wa-join-card__btn--primary" target="_blank" rel="noopener noreferrer">Join WhatsApp group</a>
            <?php endif; ?>
            <?php if ($contactUrl !== ''): ?>
                <a href="<?= htmlspecialchars($contactUrl, ENT_QUOTES, 'UTF-8') ?>" class="wa-join-card__btn wa-join-card__btn--ghost" target="_blank" rel="noopener noreferrer">Message office</a>
            <?php endif; ?>
        </div>
    </div>
</section>
    <?php
}

/**
 * Event-specific WhatsApp group cards for approved upcoming shifts.
 */
function renderStaffEventWhatsappGroups(PDO $pdo, string $email): void
{
    require_once dirname(__DIR__) . '/event-whatsapp.php';

    $groups = getStaffApprovedEventWhatsappGroups($pdo, $email);
    if ($groups === []) {
        return;
    }

    foreach ($groups as $group) {
        $eventName = (string) ($group['event_name'] ?? 'Event');
        $eventDate = formatEventDateLabel((string) ($group['event_date'] ?? ''));
        $groupUrl  = (string) ($group['whatsapp_group_url'] ?? '');
        if ($groupUrl === '') {
            continue;
        }

        $title = $eventName;
        if ($eventDate !== '' && $eventDate !== '—') {
            $title .= ' · ' . $eventDate;
        }
        ?>
<section class="wa-join-card wa-join-card--compact" aria-label="<?= htmlspecialchars('WhatsApp group for ' . $eventName, ENT_QUOTES, 'UTF-8') ?>">
    <div class="wa-join-card__icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="28" height="28" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 2C6.477 2 2 6.477 2 12c0 1.89.525 3.66 1.438 5.168L2 22l4.832-1.438A9.955 9.955 0 0 0 12 22c5.523 0 10-4.477 10-10S17.523 2 12 2zm0 18a7.96 7.96 0 0 1-4.075-1.125l-.293-.175-2.87.85.85-2.87-.175-.293A7.96 7.96 0 0 1 4 12c0-4.411 3.589-8 8-8s8 3.589 8 8-3.589 8-8 8z"/></svg>
    </div>
    <div class="wa-join-card__body">
        <h2 class="wa-join-card__title"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h2>
        <p class="wa-join-card__text">Join the event WhatsApp group for shift updates and last-minute calls. You may need approval from a group admin.</p>
        <div class="wa-join-card__actions">
            <a href="<?= htmlspecialchars($groupUrl, ENT_QUOTES, 'UTF-8') ?>" class="wa-join-card__btn wa-join-card__btn--primary" target="_blank" rel="noopener noreferrer">Join event WhatsApp group</a>
        </div>
    </div>
</section>
        <?php
    }
}

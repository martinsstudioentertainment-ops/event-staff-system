<?php
/**
 * Legal-safe wording: this product is a registration portal only — not a security
 * company, employer, or payroll provider.
 */

require_once __DIR__ . '/settings-repository.php';
require_once __DIR__ . '/events-repository.php';

/**
 * Replace Unicode punctuation with ASCII in outbound email copy only.
 */
function emailAsciiSafe(string $text): string
{
    return str_replace(
        ["\u{2013}", "\u{2014}", "\u{00B7}", "\u{2022}"],
        ['-', '-', '|', '*'],
        $text
    );
}

/**
 * Event time range for emails (UI keeps en dash via formatEventTimeRangeLabel).
 */
function formatEventTimeRangeLabelForEmail(array $event): string
{
    return emailAsciiSafe(formatEventTimeRangeLabel($event));
}

/**
 * Event label for emails (UI keeps em dash via formatEventLabel).
 */
function formatEventLabelForEmail(array $row): string
{
    require_once __DIR__ . '/staff-repository.php';

    return emailAsciiSafe(formatEventLabel($row));
}

/**
 * Event location for emails (UI keeps middle dot via formatEventLocationLabel).
 */
function formatEventLocationLabelForEmail(array $event): string
{
    return emailAsciiSafe(formatEventLocationLabel($event));
}

/**
 * Event name + optional date cell for HTML email tables.
 */
function formatEmailEventNameDateCell(string $eventName, string $dateLabel): string
{
    $eventName = trim($eventName);
    $dateLabel = trim($dateLabel);
    if ($eventName === '') {
        return $dateLabel;
    }
    if ($dateLabel === '') {
        return $eventName;
    }

    return $eventName . ' | ' . $dateLabel;
}

/**
 * Full notice for forms and footers (plain text) — website only, not emails.
 */
function getPortalLegalNotice(PDO $pdo): string
{
    $site = getSiteName($pdo);

    return $site
        . ' is a free registration portal for PSA-licensed security staff (door supervisors and static guards) to find event shifts.'
        . ' Listings are for licensed security work only — not stewarding or general event crew.'
        . ' We are not a security company, PSA licence holder, employer, event organiser, or payroll provider.'
        . ' We do not hire staff, assign shifts as an employer, or pay wages.'
        . ' Pay, hours, and working conditions are agreed directly with the event organiser or any on-site contractor named for a shift.';
}

/**
 * One short line for staff emails (replaces repeating the full legal block).
 */
function getEmailShortIntro(PDO $pdo): string
{
    return getSiteName($pdo) . ' is a shift registration portal only - not your employer or payroll provider.';
}

/**
 * Short email footer — one line, no duplication.
 */
function getEmailShortFooter(PDO $pdo): string
{
    return 'Sent by the registration portal only. Pay and duties are agreed with the on-site contractor or event organiser.';
}

/**
 * @deprecated Use getEmailShortIntro() in emails.
 */
function getEmailPortalIntroLine(PDO $pdo): string
{
    return getEmailShortIntro($pdo);
}

/**
 * Short disclaimer for email footers (plain + HTML).
 */
function getEmailSenderDisclaimer(PDO $pdo): string
{
    return getEmailShortFooter($pdo);
}

/**
 * Optional third-party contractor line (only when admin entered a name on the event).
 */
function formatEmailOnSiteSecurityLine(PDO $pdo, array $row): ?string
{
    $name = formatEventMainSecurityLabel($row);
    if ($name === '') {
        return null;
    }

    return 'Contractor listed for this shift: ' . $name . ' (confirm pay and duties with them).';
}

/**
 * @param list<string> $bodyLines
 * @return list<string>
 */
function appendEmailShortFooter(PDO $pdo, array $bodyLines): array
{
    $bodyLines[] = '';
    $bodyLines[] = getEmailShortFooter($pdo);

    return $bodyLines;
}

/**
 * @param list<string> $bodyLines
 * @return list<string>
 */
function appendEmailPortalIntro(PDO $pdo, array $bodyLines): array
{
    $insert = min(2, count($bodyLines));
    array_splice($bodyLines, $insert, 0, ['', getEmailShortIntro($pdo), '']);

    return $bodyLines;
}

/**
 * @param list<string> $bodyLines
 * @return list<string>
 */
function appendEmailSenderDisclaimer(PDO $pdo, array $bodyLines): array
{
    return appendEmailShortFooter($pdo, $bodyLines);
}

/**
 * Short footer only — avoids repeating legal text in every email.
 *
 * @param list<string> $bodyLines
 * @return list<string>
 */
function appendEmailPortalContext(PDO $pdo, array $bodyLines): array
{
    return appendEmailShortFooter($pdo, $bodyLines);
}

function buildStaffEmailButton(string $url, string $label): string
{
    $url   = trim($url);
    $label = trim($label);
    if ($url === '' || $label === '') {
        return '';
    }

    $esc = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

    return '<p style="margin:20px 0 8px;">'
        . '<a href="' . $esc($url) . '" style="display:inline-block;padding:12px 22px;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:600;">'
        . $esc($label)
        . '</a></p>';
}

/**
 * @param list<string> $bodyLines
 */
function buildStaffEmailHtmlFromLines(array $bodyLines, ?string $ctaUrl = null, ?string $ctaLabel = null, ?PDO $pdo = null): string
{
    require_once __DIR__ . '/email-layout.php';

    $html = buildEmailBodyFromLines($bodyLines);

    if ($ctaUrl !== null && $ctaLabel !== null && trim($ctaUrl) !== '' && trim($ctaLabel) !== '') {
        if ($pdo instanceof PDO) {
            $html .= buildEmailButton($pdo, $ctaUrl, $ctaLabel);
        } else {
            $html .= buildStaffEmailButton($ctaUrl, $ctaLabel);
        }
    }

    return $html;
}

/**
 * HTML snippet for registration / status pages.
 */
function renderRegistrationPortalNotice(PDO $pdo): void
{
    ?>
    <div class="portal-legal-notice" role="note">
        <p class="portal-legal-notice__title">Registration portal only</p>
        <p class="portal-legal-notice__text"><?= h(getPortalLegalNotice($pdo)) ?></p>
    </div>
    <?php
}

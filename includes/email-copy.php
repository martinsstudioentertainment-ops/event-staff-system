<?php
/**
 * Legal-safe wording: this product is a registration portal only — not a security
 * company, employer, or payroll provider.
 */

require_once __DIR__ . '/settings-repository.php';
require_once __DIR__ . '/events-repository.php';

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
    return getSiteName($pdo) . ' is a shift registration portal only — not your employer or payroll provider.';
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

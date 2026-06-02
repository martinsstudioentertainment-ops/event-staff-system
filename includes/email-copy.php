<?php
/**
 * Legal-safe wording: this product is a registration portal only — not a security
 * company, employer, or payroll provider.
 */

require_once __DIR__ . '/settings-repository.php';
require_once __DIR__ . '/events-repository.php';

/**
 * Full notice for forms and footers (plain text).
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
 * Short line after "Dear …" in staff emails.
 */
function getEmailPortalIntroLine(PDO $pdo): string
{
    return getPortalLegalNotice($pdo);
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

    $site = getSiteName($pdo);

    return 'Third-party information — contractor listed for this shift: ' . $name
        . ' (' . $site . ' is not this company; confirm pay and duties with them before you work).';
}

/**
 * Email footer — who sent the message.
 */
function getEmailSenderDisclaimer(PDO $pdo): string
{
    return getPortalLegalNotice($pdo)
        . ' This email was sent by the portal only — not by any contractor or event named in this message.';
}

/**
 * @param list<string> $bodyLines
 * @return list<string>
 */
function appendEmailPortalIntro(PDO $pdo, array $bodyLines): array
{
    $insert = 2;
    if (count($bodyLines) < 2) {
        $insert = count($bodyLines);
    }

    array_splice($bodyLines, $insert, 0, ['', getEmailPortalIntroLine($pdo), '']);

    return $bodyLines;
}

/**
 * @param list<string> $bodyLines
 * @return list<string>
 */
function appendEmailSenderDisclaimer(PDO $pdo, array $bodyLines): array
{
    $bodyLines[] = '';
    $bodyLines[] = getEmailSenderDisclaimer($pdo);

    return $bodyLines;
}

/**
 * Intro + footer for transactional staff emails.
 *
 * @param list<string> $bodyLines
 * @return list<string>
 */
function appendEmailPortalContext(PDO $pdo, array $bodyLines): array
{
    return appendEmailSenderDisclaimer($pdo, appendEmailPortalIntro($pdo, $bodyLines));
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

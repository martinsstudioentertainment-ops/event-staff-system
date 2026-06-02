<?php
/**
 * Company website content (main domain — separate from registration form).
 * Positioning: helps people find security & event work, not a licensed security firm.
 */

require_once __DIR__ . '/settings-repository.php';

function getCompanyName(PDO $pdo): string
{
    $name = trim(getSetting($pdo, 'company_name', ''));

    return $name !== '' ? $name : getSiteName($pdo);
}

function getCompanyTagline(PDO $pdo): string
{
    return getSetting(
        $pdo,
        'company_tagline',
        'Helping people find security, steward, and event jobs — even if you have never done it before'
    );
}

function getCompanyEmail(PDO $pdo): string
{
    return getSetting($pdo, 'company_email', 'info@example.com');
}

function getCompanyPhone(PDO $pdo): string
{
    return getSetting($pdo, 'company_phone', '+353 1 000 0000');
}

function getCompanyWhatsapp(PDO $pdo): string
{
    $value = trim(getSetting($pdo, 'company_whatsapp', ''));

    return $value !== '' ? $value : getCompanyPhone($pdo);
}

function getCompanyWhatsappGroup(PDO $pdo): string
{
    return trim(getSetting($pdo, 'company_whatsapp_group', ''));
}

function formatTelHref(string $phone): string
{
    return 'tel:' . preg_replace('/\s+/', '', trim($phone));
}

function formatWhatsappHref(string $phone): string
{
    $digits = preg_replace('/\D/', '', trim($phone));
    if ($digits === '') {
        return '';
    }

    if (str_starts_with($digits, '0')) {
        $digits = '353' . substr($digits, 1);
    }

    return 'https://wa.me/' . $digits;
}

function getCompanyAbout(PDO $pdo): string
{
    return getSetting(
        $pdo,
        'company_about',
        'Many people want security or event work but do not know where to start. We run a simple registration portal so you can apply for upcoming festivals, concerts, and events in one place — no confusing agencies, no endless forms.'
    );
}

/**
 * Payment details shown on printed commission invoices (your company bank — not staff IBANs).
 *
 * @return array{bank_name: string, iban: string, bic: string, vat_number: string}
 */
function getInvoicePaymentDetails(PDO $pdo): array
{
    return [
        'bank_name'  => trim(getSetting($pdo, 'invoice_bank_name', '')),
        'iban'       => trim(getSetting($pdo, 'invoice_bank_iban', '')),
        'bic'        => trim(getSetting($pdo, 'invoice_bank_bic', '')),
        'vat_number' => trim(getSetting($pdo, 'invoice_vat_number', '')),
    ];
}

/** @return array<int, array{title: string, desc: string, icon: string}> */
function getCompanyServices(): array
{
    return [
        [
            'title' => 'Security Roles',
            'desc'  => 'Register for door, venue, and perimeter security shifts at festivals, gigs, and corporate events.',
            'icon'  => 'shield',
        ],
        [
            'title' => 'Stewarding',
            'desc'  => 'Apply for steward jobs — helping guests, guiding crowds, and front-of-house support.',
            'icon'  => 'steward',
        ],
        [
            'title' => 'Crowd & Entry',
            'desc'  => 'Queue management and entry-point roles at busy venues and outdoor events.',
            'icon'  => 'crowd',
        ],
        [
            'title' => 'Gig & Concert Work',
            'desc'  => 'Arena shows, live music, and festival crew — register once and pick the events that suit you.',
            'icon'  => 'gig',
        ],
        [
            'title' => 'Access & Check-in',
            'desc'  => 'Wristband, badge, and gate roles — a common first step into event work.',
            'icon'  => 'access',
        ],
        [
            'title' => 'General Event Staff',
            'desc'  => 'Not sure which role fits? Register for mixed event staff and choose from listed events.',
            'icon'  => 'vip',
        ],
    ];
}

/** @return string[] */
function getCompanyEventTypes(): array
{
    return [
        'Music festivals',
        'Concerts & arena shows',
        'Corporate events',
        'Sporting venues',
        'Nightlife & clubs',
        'Outdoor & festival sites',
    ];
}

/** @return string[] */
function getCompanyTrustPoints(): array
{
    return [
        'Free registration portal — we help you find shifts, not employ you',
        'Clear list of open events with dates and roles',
        'Email updates when your application is reviewed',
        'Check-in link when approved — portal only, not a security licence',
    ];
}

function renderHomeServiceIcon(string $icon): string
{
    $icons = [
        'shield' => '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M12 2 4 5.5v6.5c0 4.5 3.4 8.7 8 10 4.6-1.3 8-5.5 8-10V5.5L12 2z"/><path d="m9 12 2 2 4-4"/></svg>',
        'steward' => '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M12 2a4 4 0 0 1 4 4v1h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-9a2 2 0 0 1 2-2h2V6a4 4 0 0 1 4-4z"/><path d="M8 14h8"/></svg>',
        'crowd' => '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.75"><circle cx="9" cy="7" r="3"/><circle cx="17" cy="8" r="2.5"/><path d="M3 21v-1a5 5 0 0 1 5-5h2a5 5 0 0 1 5 5v1"/><path d="M17 14.5a4 4 0 0 1 3 3.9V21"/></svg>',
        'vip' => '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.75"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>',
        'access' => '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.75"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/><circle cx="12" cy="16" r="1"/></svg>',
        'gig' => '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M12 3a9 9 0 0 0-9 9v1.5"/><path d="M8 15a3 3 0 0 0 6 0v-2"/><path d="m6 13 2 2"/><path d="m18 13-2 2"/></svg>',
    ];

    return $icons[$icon] ?? $icons['shield'];
}

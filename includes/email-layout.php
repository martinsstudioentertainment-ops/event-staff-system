<?php

declare(strict_types=1);

/**
 * Enterprise HTML email layout system (PHP equivalent of Laravel Blade email components).
 */

require_once __DIR__ . '/email-branding.php';

function emailEsc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function emailIsFullHtmlDocument(string $html): bool
{
    return preg_match('/<!DOCTYPE|<html[\s>]/i', $html) === 1;
}

function extractEmailTitleFromSubject(string $subject): string
{
    $title = trim($subject);
    $title = preg_replace('/^\[[^\]]+\]\s*/', '', $title) ?? $title;
    $title = preg_replace('/^[^:]+:\s*/', '', $title, 1) ?? $title;

    return $title !== '' ? $title : 'Notification';
}

/**
 * @param array{
 *     preheader?: string,
 *     subtitle?: string,
 *     show_banner?: bool,
 *     footer_note?: string,
 *     unsubscribe_url?: string
 * } $options
 */
function buildEmailMasterLayout(PDO $pdo, string $title, string $innerHtml, array $options = []): string
{
    $brand      = getEmailBranding($pdo);
    $preheader  = trim((string) ($options['preheader'] ?? ''));
    $subtitle   = trim((string) ($options['subtitle'] ?? ''));
    $showBanner = (bool) ($options['show_banner'] ?? true);
    $footerNote = trim((string) ($options['footer_note'] ?? getEmailShortFooter($pdo)));
    $unsubUrl   = trim((string) ($options['unsubscribe_url'] ?? ''));

    $primary   = $brand['primary_color'];
    $secondary = $brand['secondary_color'];
    $button    = $brand['button_color'];
    $text      = $brand['text_color'];
    $muted     = $brand['muted_color'];
    $cardBg    = $brand['card_bg'];

    $hasHeroBanner = $showBanner && $brand['banner_url'] !== '';

    $headerRow = '';
    if (!$hasHeroBanner) {
        $headerContent = '';
        if ($brand['logo_url'] !== '') {
            $headerContent .= '<img src="' . emailEsc($brand['logo_url']) . '" width="200" alt="' . emailEsc($brand['company_name']) . '" style="display:block;margin:0 auto;max-width:200px;width:100%;height:auto;border:0;outline:none;">';
        } else {
            $headerContent .= '<span style="color:#ffffff;font-size:18px;font-weight:700;letter-spacing:0.04em;">' . emailEsc($brand['company_name']) . '</span>';
        }
        if ($brand['event_staff_label'] !== '' && $brand['logo_url'] === '') {
            $headerContent .= '<p style="margin:8px 0 0;font-size:13px;font-weight:600;color:#ffffff;letter-spacing:0.02em;">'
                . emailEsc($brand['event_staff_label'])
                . '</p>';
        }
        $headerRow = '<tr><td style="background-color:' . emailEsc($primary) . ';padding:20px 24px;text-align:center;">' . $headerContent . '</td></tr>';
    }

    $bannerBlock = '';
    if ($hasHeroBanner) {
        $bannerBlock = '<tr><td style="padding:0;line-height:0;font-size:0;background-color:' . emailEsc($primary) . ';">'
            . '<img src="' . emailEsc($brand['banner_url']) . '" width="600" height="315" alt="' . emailEsc($brand['company_name'] . ' — ' . $brand['event_staff_label']) . '" style="display:block;width:100%;max-width:600px;height:auto;border:0;outline:none;">'
            . '</td></tr>';
    } elseif ($showBanner) {
        $bannerBlock = '<tr><td style="padding:0;background:linear-gradient(135deg,' . emailEsc($primary) . ' 0%,' . emailEsc($secondary) . ' 100%);height:120px;line-height:120px;text-align:center;">'
            . '<span style="color:#ffffff;font-size:18px;font-weight:700;letter-spacing:0.04em;">' . emailEsc($brand['company_name']) . '</span>'
            . '</td></tr>';
    }

    $socialHtml = '';
    if ($brand['social_links'] !== []) {
        $links = [];
        foreach ($brand['social_links'] as $network => $url) {
            $links[] = '<a href="' . emailEsc($url) . '" style="color:' . emailEsc($secondary) . ';text-decoration:none;font-size:12px;margin:0 8px;">'
                . emailEsc(ucfirst($network))
                . '</a>';
        }
        $socialHtml = '<p style="margin:0 0 12px;text-align:center;">' . implode('', $links) . '</p>';
    }

    $contactLines = [];
    if ($brand['website_url'] !== '') {
        $contactLines[] = '<a href="' . emailEsc($brand['website_url']) . '" style="color:' . emailEsc($secondary) . ';text-decoration:none;">' . emailEsc(parse_url($brand['website_url'], PHP_URL_HOST) ?: $brand['website_url']) . '</a>';
    }
    if ($brand['support_email'] !== '' && filter_var($brand['support_email'], FILTER_VALIDATE_EMAIL)) {
        $contactLines[] = '<a href="mailto:' . emailEsc($brand['support_email']) . '" style="color:' . emailEsc($secondary) . ';text-decoration:none;">' . emailEsc($brand['support_email']) . '</a>';
    }
    if ($brand['support_phone'] !== '') {
        $contactLines[] = emailEsc($brand['support_phone']);
    }

    $legalLinks = '<a href="' . emailEsc($brand['privacy_url']) . '" style="color:' . emailEsc($muted) . ';text-decoration:underline;">Privacy</a>'
        . ' &nbsp;|&nbsp; '
        . '<a href="' . emailEsc($brand['terms_url']) . '" style="color:' . emailEsc($muted) . ';text-decoration:underline;">Terms</a>';

    $unsubBlock = '';
    if ($unsubUrl !== '') {
        $unsubBlock = '<p style="margin:12px 0 0;font-size:11px;color:' . emailEsc($muted) . ';text-align:center;">'
            . '<a href="' . emailEsc($unsubUrl) . '" style="color:' . emailEsc($muted) . ';text-decoration:underline;">Unsubscribe</a>'
            . '</p>';
    }

    $preheaderHidden = $preheader !== ''
        ? '<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;">' . emailEsc($preheader) . str_repeat('&nbsp;&zwnj;', 80) . '</div>'
        : '';

    $subtitleHtml = $subtitle !== ''
        ? '<p style="margin:0 0 16px;font-size:15px;color:' . emailEsc($muted) . ';line-height:1.5;">' . emailEsc($subtitle) . '</p>'
        : '';

    return '<!DOCTYPE html>'
        . '<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">'
        . '<head>'
        . '<meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="color-scheme" content="light dark">'
        . '<meta name="supported-color-schemes" content="light dark">'
        . '<title>' . emailEsc($title) . '</title>'
        . '<!--[if mso]><noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript><![endif]-->'
        . '<style>'
        . '@media only screen and (max-width:620px){.email-container{width:100%!important;}.email-pad{padding:20px 16px!important;}.email-btn{display:block!important;width:100%!important;text-align:center!important;}}'
        . '@media (prefers-color-scheme:dark){.email-body-bg{background-color:#0f172a!important;}.email-card{background-color:#1e293b!important;}.email-text{color:#f1f5f9!important;}.email-muted{color:#94a3b8!important;}}'
        . '</style>'
        . '</head>'
        . '<body class="email-body-bg" style="margin:0;padding:0;background-color:#eef2f7;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">'
        . $preheaderHidden
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#eef2f7;">'
        . '<tr><td align="center" style="padding:24px 12px;">'
        . '<table role="presentation" class="email-container" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background-color:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(15,23,42,0.08);">'
        . $headerRow
        . $bannerBlock
        . '<tr><td class="email-pad email-card" style="padding:28px 32px 8px;">'
        . '<h1 class="email-text" style="margin:0 0 8px;font-size:24px;line-height:1.3;font-weight:700;color:' . emailEsc($text) . ';">' . emailEsc($title) . '</h1>'
        . $subtitleHtml
        . '<div class="email-text" style="font-size:15px;line-height:1.6;color:' . emailEsc($text) . ';">' . $innerHtml . '</div>'
        . '</td></tr>'
        . '<tr><td class="email-pad" style="padding:8px 32px 28px;">'
        . '<p class="email-muted" style="margin:0;font-size:11px;line-height:1.5;color:' . emailEsc($muted) . ';text-align:center;">' . emailEsc($footerNote) . '</p>'
        . '</td></tr>'
        . '<tr><td style="background-color:' . emailEsc($cardBg) . ';padding:24px 32px;border-top:1px solid #e2e8f0;">'
        . $socialHtml
        . '<p style="margin:0 0 8px;font-size:12px;color:' . emailEsc($muted) . ';text-align:center;line-height:1.6;">' . implode(' &nbsp;·&nbsp; ', $contactLines) . '</p>'
        . '<p style="margin:0 0 8px;font-size:11px;color:' . emailEsc($muted) . ';text-align:center;">' . $legalLinks . '</p>'
        . '<p style="margin:0;font-size:11px;color:' . emailEsc($muted) . ';text-align:center;">' . emailEsc($brand['copyright']) . '</p>'
        . $unsubBlock
        . '</td></tr>'
        . '</table>'
        . '</td></tr>'
        . '</table>'
        . '</body></html>';
}

function buildEmailButton(PDO $pdo, string $url, string $label, bool $fullWidth = false): string
{
    $url   = trim($url);
    $label = trim($label);
    if ($url === '' || $label === '') {
        return '';
    }

    $brand  = getEmailBranding($pdo);
    $button = $brand['button_color'];
    $width  = $fullWidth ? 'display:block;width:100%;text-align:center;box-sizing:border-box;' : 'display:inline-block;';

    return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:20px 0 8px;">'
        . '<tr><td style="border-radius:10px;background-color:' . emailEsc($button) . ';">'
        . '<!--[if mso]><v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" href="' . emailEsc($url) . '" style="height:48px;v-text-anchor:middle;width:220px;" arcsize="12%" strokecolor="' . emailEsc($button) . '" fillcolor="' . emailEsc($button) . '"><w:anchorlock/><center style="color:#ffffff;font-family:Segoe UI,Arial,sans-serif;font-size:16px;font-weight:bold;">' . emailEsc($label) . '</center></v:roundrect><![endif]-->'
        . '<a class="email-btn" href="' . emailEsc($url) . '" style="' . $width . 'padding:14px 28px;font-size:16px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:10px;mso-hide:all;">'
        . emailEsc($label)
        . '</a>'
        . '</td></tr></table>';
}

/**
 * @param array<string, mixed> $job
 */
function buildEmailJobCard(PDO $pdo, array $job): string
{
    $brand = getEmailBranding($pdo);
    $title = trim((string) ($job['job_title'] ?? 'Security Officer'));
    $event = trim((string) ($job['event_name'] ?? ''));
    $employer = trim((string) ($job['employer'] ?? ''));
    $location = trim((string) ($job['location'] ?? ''));
    $date     = trim((string) ($job['date'] ?? ''));
    $timeRange = trim((string) ($job['time_range'] ?? ''));
    if ($timeRange === '') {
        $start = trim((string) ($job['start_time'] ?? ''));
        $end   = trim((string) ($job['end_time'] ?? ''));
        if ($start !== '' && $end !== '') {
            $timeRange = $start . ' - ' . $end;
        }
    }
    $hours     = trim((string) ($job['hours'] ?? ''));
    $payRate   = trim((string) ($job['pay_rate'] ?? formatEmailPayRateLabelOptional($pdo, $job)));
    $vacancies = trim((string) ($job['vacancies'] ?? ''));
    $applyUrl  = trim((string) ($job['apply_url'] ?? ''));
    $applyLabel = trim((string) ($job['apply_label'] ?? 'Apply Now'));

    $metaRows = [];
    if ($event !== '' && $event !== $title) {
        $metaRows[] = ['Event', $event];
    }
    if ($employer !== '') {
        $metaRows[] = ['Employer', $employer];
    }
    if ($location !== '') {
        $metaRows[] = ['📍', $location];
    }
    if ($date !== '') {
        $metaRows[] = ['📅', $date];
    }
    if ($timeRange !== '') {
        $metaRows[] = ['🕒', $timeRange];
    }
    if ($hours !== '') {
        $metaRows[] = ['⏱', $hours];
    }
    if ($payRate !== '') {
        $metaRows[] = ['💰', $payRate];
    }
    if ($vacancies !== '') {
        $metaRows[] = ['Vacancies', $vacancies];
    }

    $metaHtml = '';
    foreach ($metaRows as [$icon, $value]) {
        $metaHtml .= '<tr><td style="padding:4px 0;font-size:14px;color:' . emailEsc($brand['muted_color']) . ';width:28px;vertical-align:top;">' . emailEsc($icon) . '</td>'
            . '<td style="padding:4px 0;font-size:14px;color:' . emailEsc($brand['text_color']) . ';vertical-align:top;">' . emailEsc(emailAsciiSafe((string) $value)) . '</td></tr>';
    }

    $cta = $applyUrl !== '' ? buildEmailButton($pdo, $applyUrl, $applyLabel, true) : '';

    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px;background-color:' . emailEsc($brand['card_bg']) . ';border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;">'
        . '<tr><td style="padding:18px 20px;">'
        . '<h2 style="margin:0 0 12px;font-size:18px;font-weight:700;color:' . emailEsc($brand['primary_color']) . ';">' . emailEsc($title) . '</h2>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $metaHtml . '</table>'
        . $cta
        . '</td></tr></table>';
}

/**
 * @param array<string, mixed> $data
 */
function buildEmailEventCard(PDO $pdo, array $data): string
{
    $brand = getEmailBranding($pdo);

    $eventName = trim((string) ($data['event_name'] ?? $data['event'] ?? 'Event'));
    $role      = trim((string) ($data['role'] ?? ''));
    $status    = trim((string) ($data['status'] ?? ''));
    $location  = trim((string) ($data['location'] ?? ''));
    $times     = trim((string) ($data['times'] ?? $data['time_range'] ?? ''));
    $date      = trim((string) ($data['date'] ?? ''));
    $payRate   = trim((string) ($data['pay_rate'] ?? formatEmailPayRateLabelOptional($pdo, $data)));
    $hours     = trim((string) ($data['hours'] ?? formatEmailShiftHoursLabel($data)));
    $extraHtml = trim((string) ($data['extra_html'] ?? ''));
    $ctaUrl    = trim((string) ($data['cta_url'] ?? ''));
    $ctaLabel  = trim((string) ($data['cta_label'] ?? ''));

    $rows = [];
    if ($date !== '') {
        $rows[] = ['📅', $date];
    }
    if ($times !== '') {
        $rows[] = ['🕒', $times];
    }
    if ($location !== '') {
        $rows[] = ['📍', $location];
    }
    if ($role !== '') {
        $rows[] = ['Role', $role];
    }
    if ($status !== '') {
        $rows[] = ['Status', $status];
    }
    if ($hours !== '') {
        $rows[] = ['⏱', $hours];
    }
    if ($payRate !== '') {
        $rows[] = ['💰', $payRate];
    }

    $metaHtml = '';
    foreach ($rows as [$icon, $value]) {
        $metaHtml .= '<tr><td style="padding:4px 0;font-size:14px;color:' . emailEsc($brand['muted_color']) . ';width:28px;vertical-align:top;">' . emailEsc($icon) . '</td>'
            . '<td style="padding:4px 0;font-size:14px;color:' . emailEsc($brand['text_color']) . ';vertical-align:top;">' . emailEsc(emailAsciiSafe((string) $value)) . '</td></tr>';
    }

    $cta = ($ctaUrl !== '' && $ctaLabel !== '') ? buildEmailButton($pdo, $ctaUrl, $ctaLabel) : '';

    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px;background-color:#ffffff;border:2px solid ' . emailEsc($brand['secondary_color']) . ';border-radius:14px;">'
        . '<tr><td style="padding:18px 20px;">'
        . '<h2 style="margin:0 0 12px;font-size:17px;font-weight:700;color:' . emailEsc($brand['primary_color']) . ';">' . emailEsc($eventName) . '</h2>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $metaHtml . '</table>'
        . $extraHtml
        . $cta
        . '</td></tr></table>';
}

function buildEmailNotificationCard(PDO $pdo, string $title, string $bodyHtml, ?string $ctaUrl = null, ?string $ctaLabel = null): string
{
    $brand = getEmailBranding($pdo);
    $cta   = ($ctaUrl !== null && $ctaLabel !== null && trim($ctaUrl) !== '' && trim($ctaLabel) !== '')
        ? buildEmailButton($pdo, $ctaUrl, $ctaLabel)
        : '';

    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px;background-color:' . emailEsc($brand['card_bg']) . ';border-left:4px solid ' . emailEsc($brand['secondary_color']) . ';border-radius:0 12px 12px 0;">'
        . '<tr><td style="padding:18px 20px;">'
        . ($title !== '' ? '<h2 style="margin:0 0 10px;font-size:17px;font-weight:700;color:' . emailEsc($brand['primary_color']) . ';">' . emailEsc($title) . '</h2>' : '')
        . '<div style="font-size:15px;line-height:1.6;color:' . emailEsc($brand['text_color']) . ';">' . $bodyHtml . '</div>'
        . $cta
        . '</td></tr></table>';
}

/**
 * @param list<string> $bodyLines
 */
function buildEmailBodyFromLines(array $bodyLines): string
{
    require_once __DIR__ . '/email-copy.php';

    $html = '';

    foreach ($bodyLines as $line) {
        $line = (string) $line;
        if ($line === '') {
            $html .= '<br>';
            continue;
        }

        if ($line === '---') {
            $html .= '<hr style="border:none;border-top:1px solid #e2e8f0;margin:16px 0;">';
            continue;
        }

        if (preg_match('#^https?://#i', $line) === 1) {
            $html .= '<p style="margin:0 0 10px;"><a href="' . emailEsc($line) . '" style="color:#2563eb;word-break:break-all;">' . emailEsc($line) . '</a></p>';
            continue;
        }

        if (strncmp($line, '* ', 2) === 0) {
            $html .= '<p style="margin:0 0 6px;padding-left:4px;">• ' . emailEsc(emailAsciiSafe(substr($line, 2))) . '</p>';
            continue;
        }

        $html .= '<p style="margin:0 0 10px;">' . emailEsc(emailAsciiSafe($line)) . '</p>';
    }

    return $html;
}

function buildEmailHtmlFromPlainText(PDO $pdo, string $subject, string $plain): string
{
    $lines = explode("\n", str_replace("\r\n", "\n", $plain));
    $inner = buildEmailBodyFromLines($lines);
    $title = extractEmailTitleFromSubject($subject);
    $pre   = '';
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line !== '' && strncmp($line, 'http', 4) !== 0) {
            $pre = $line;
            break;
        }
    }

    return buildEmailMasterLayout($pdo, $title, $inner, [
        'preheader' => mb_substr($pre, 0, 140),
    ]);
}

function wrapEmailHtmlFragment(PDO $pdo, string $subject, string $innerHtml, array $options = []): string
{
    if ($innerHtml === '') {
        return '';
    }

    $title = trim((string) ($options['title'] ?? extractEmailTitleFromSubject($subject)));
    if ($title === '') {
        $title = 'Notification';
    }

    return buildEmailMasterLayout($pdo, $title, $innerHtml, $options);
}

function buildEmailOtpContent(PDO $pdo, string $code, string $username, int $ttlMinutes = 10): string
{
    $brand = getEmailBranding($pdo);

    return '<p style="margin:0 0 16px;">Use this verification code to complete your admin sign-in:</p>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;">'
        . '<tr><td align="center" style="background-color:' . emailEsc($brand['card_bg']) . ';border:2px dashed ' . emailEsc($brand['secondary_color']) . ';border-radius:14px;padding:24px;">'
        . '<span style="font-size:36px;font-weight:800;letter-spacing:0.25em;color:' . emailEsc($brand['primary_color']) . ';font-family:Consolas,Monaco,monospace;">' . emailEsc($code) . '</span>'
        . '</td></tr></table>'
        . '<p style="margin:0 0 8px;font-size:14px;color:' . emailEsc($brand['muted_color']) . ';">This code expires in ' . (int) $ttlMinutes . ' minutes.</p>'
        . '<p style="margin:0 0 8px;font-size:14px;">Account: <strong>' . emailEsc($username) . '</strong></p>'
        . '<p style="margin:0;font-size:14px;color:' . emailEsc($brand['muted_color']) . ';">If you did not try to sign in, you can safely ignore this email.</p>';
}

function finalizeOutboundEmailHtml(PDO $pdo, string $subject, ?string $htmlBody, string $plainBody): ?string
{
    if ($htmlBody === null || trim($htmlBody) === '') {
        return buildEmailHtmlFromPlainText($pdo, $subject, $plainBody);
    }

    $htmlBody = trim($htmlBody);
    if (emailIsFullHtmlDocument($htmlBody)) {
        return $htmlBody;
    }

    return wrapEmailHtmlFragment($pdo, $subject, $htmlBody, [
        'preheader' => trim(preg_replace('/\s+/u', ' ', $plainBody) ?? ''),
    ]);
}

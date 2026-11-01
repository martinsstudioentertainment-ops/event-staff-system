<?php

/**
 * Sanitize and render admin rich-text (Quill) content on public pages.
 */

function sanitizeRichText(string $input): string
{
    $input = trim($input);
    if ($input === '') {
        return '';
    }

    $allowed = '<p><br><strong><b><em><i><u><ul><ol><li><a><h2><h3><h4><blockquote><span>';
    $clean   = strip_tags($input, $allowed);

    $clean = preg_replace('/\s(on\w+|style|class)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean);
    $clean = preg_replace_callback(
        '/<a\s([^>]*?)href=(["\'])(.*?)\2([^>]*)>/i',
        static function (array $m): string {
            $href = trim($m[3]);
            if ($href === '' || preg_match('#^(https?://|mailto:)#i', $href) !== 1) {
                return '<a href="#" rel="noopener noreferrer">';
            }

            return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" rel="noopener noreferrer" target="_blank">';
        },
        $clean
    );

    return trim($clean);
}

function renderRichText(?string $html): string
{
    $clean = sanitizeRichText((string) $html);
    if ($clean === '') {
        return '';
    }

    if ($clean === strip_tags($clean)) {
        return nl2br(htmlspecialchars($clean, ENT_QUOTES, 'UTF-8'), false);
    }

    return $clean;
}

function plainTextFromRich(?string $html, int $maxLen = 200): string
{
    $raw = (string) $html;
    if ($raw === '') {
        return '';
    }

    // Turn common block/break tags into spaces so words don't stick together.
    $raw = preg_replace('/<\s*br\s*\/?\s*>/i', ' ', $raw) ?? $raw;
    $raw = preg_replace('/<\/\s*(p|div|li|h[1-6]|tr|blockquote)\s*>/i', ' ', $raw) ?? $raw;
    $text = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    if ($text === '') {
        return '';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($text) <= $maxLen) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, max(0, $maxLen - 3))) . '...';
    }

    if (strlen($text) <= $maxLen) {
        return $text;
    }

    return substr($text, 0, max(0, $maxLen - 3)) . '...';
}

function richPost(string $key): string
{
    return sanitizeRichText((string) ($_POST[$key] ?? ''));
}

/**
 * @return array{plain: string, html: string|null}
 */
function richEmailParts(string $html): array
{
    $rendered = renderRichText($html);
    $plain    = plainTextFromRich($html, 10000);

    return [
        'plain' => $plain !== '' ? $plain : strip_tags($html),
        'html'  => $rendered !== '' ? $rendered : null,
    ];
}

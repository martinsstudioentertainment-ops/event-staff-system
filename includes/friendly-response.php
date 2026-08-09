<?php

declare(strict_types=1);

/**
 * Production-safe friendly responses (no blank screens / fatals for direct access).
 */

function friendlyLogError(string $context, Throwable $e): void
{
    error_log(sprintf('[Olasentra][%s] %s in %s:%d', $context, $e->getMessage(), $e->getFile(), $e->getLine()));
}

function renderFriendlyHtmlPage(
    string $title,
    string $message,
    int $status = 200,
    array $actions = [],
    string $subtitle = 'Olasentra'
): void {
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Robots-Tag: noindex, nofollow');
    }

    $safeTitle   = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $safeSub     = htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8');

    $actionHtml = '';
    foreach ($actions as $action) {
        $label = htmlspecialchars((string) ($action['label'] ?? 'Continue'), ENT_QUOTES, 'UTF-8');
        $href  = htmlspecialchars((string) ($action['href'] ?? '/'), ENT_QUOTES, 'UTF-8');
        $actionHtml .= '<a class="friendly-page__btn" href="' . $href . '">' . $label . '</a>';
    }

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<title>' . $safeTitle . ' | ' . $safeSub . '</title>'
        . '<style>'
        . 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1.5rem;'
        . 'font-family:Segoe UI,system-ui,sans-serif;background:#0f172a;color:#e2e8f0;}'
        . '.friendly-page{max-width:32rem;width:100%;background:#111827;border:1px solid #334155;border-radius:16px;padding:1.5rem;}'
        . '.friendly-page h1{margin:0 0 .75rem;font-size:1.35rem;}'
        . '.friendly-page p{margin:0 0 1rem;line-height:1.55;color:#cbd5e1;}'
        . '.friendly-page__actions{display:flex;flex-wrap:wrap;gap:.65rem;}'
        . '.friendly-page__btn{display:inline-block;padding:.6rem 1rem;border-radius:8px;background:#4f46e5;color:#fff;text-decoration:none;font-weight:600;}'
        . '</style></head><body><main class="friendly-page" role="main">'
        . '<h1>' . $safeTitle . '</h1><p>' . $safeMessage . '</p>'
        . ($actionHtml !== '' ? '<div class="friendly-page__actions">' . $actionHtml . '</div>' : '')
        . '</main></body></html>';
    exit;
}

function renderFriendlyJson(array $payload, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

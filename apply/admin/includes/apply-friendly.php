<?php

declare(strict_types=1);

require_once __DIR__ . '/main-admin-bridge.php';

function apply_locate_friendly_response(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }

    $candidates = [
        dirname(__DIR__, 3) . '/includes/friendly-response.php',
        '/home/olastofx/public_html/includes/friendly-response.php',
    ];

    foreach ($candidates as $path) {
        if (is_readable($path)) {
            require_once $path;
            $loaded = true;

            return;
        }
    }

    if (!function_exists('renderFriendlyHtmlPage')) {
        function renderFriendlyHtmlPage(string $title, string $message, int $status = 200, array $actions = [], string $subtitle = 'Olasentra'): void
        {
            if (!headers_sent()) {
                http_response_code($status);
                header('Content-Type: text/html; charset=UTF-8');
            }
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>'
                . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
                . '</title></head><body><h1>'
                . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
                . '</h1><p>'
                . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
                . '</p></body></html>';
            exit;
        }
    }

    $loaded = true;
}

function apply_login_path(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));

    return str_contains($script, '/admin/admin/') ? 'login.php' : 'admin/login.php';
}

function apply_render_error_page(string $title, string $message): void
{
    apply_locate_friendly_response();
    renderFriendlyHtmlPage($title, $message, 200, [
        ['label' => 'Sign in', 'href' => apply_login_path()],
        ['label' => 'Main ERP', 'href' => 'https://admin.olasentra.com/admin/apply-portal.php'],
    ], 'Apply Admin');
}

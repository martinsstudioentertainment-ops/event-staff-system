<?php

declare(strict_types=1);

/**
 * Short registration redirect — /r.php?form=steward or /r/steward (with rewrite).
 * Built-in forms also work as /steward via .htaccess (no redirect hop).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/registration-short-links.php';

$slug = trim((string) ($_GET['form'] ?? ''));
if ($slug === '' && isset($_SERVER['PATH_INFO'])) {
    $slug = trim((string) $_SERVER['PATH_INFO'], '/');
}

try {
    $pdo    = getDB();
    $target = resolveRegistrationShortLinkTarget($pdo, $slug);
} catch (Throwable $e) {
    $target = isRegistrationBuiltinShortLinkSlug($slug)
        ? 'index.php?form=' . rawurlencode(normalizeRegistrationFormSlug($slug))
        : null;
}

if ($target === null) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Link not found</title></head><body style="font-family:system-ui,sans-serif;padding:2rem">';
    echo '<h1>Registration link not found</h1><p><a href="index.php">Choose a registration form</a></p></body></html>';
    exit;
}

header('Location: ' . $target, true, 302);
exit;

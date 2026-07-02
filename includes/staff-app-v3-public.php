<?php

declare(strict_types=1);

function staffV3PublicCssVersion(): string
{
    $path = dirname(__DIR__) . '/assets/css/staff-app-v3.css';

    return is_file($path) ? (string) filemtime($path) : '3';
}

function renderStaffV3PublicPageStart(string $pageTitle, string $siteName = 'Event Staff'): void
{
    ?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#F48221">
    <title><?= h($pageTitle) ?> — <?= h($siteName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/staff-app-v3.css?v=<?= h(staffV3PublicCssVersion()) ?>">
</head>
<body class="es-v3 es-v3--guest es-v3--public">
    <div class="es-v3__ambient" aria-hidden="true"></div>
    <main class="es-v3__main" id="es-v3-main">
    <?php
}

function renderStaffV3PublicPageEnd(): void
{
    ?>
    </main>
</body>
</html>
    <?php
}

/**
 * @param list<array{label: string, href: string, primary?: bool}> $actions
 */
function renderStaffV3ErrorScreen(
    string $title,
    string $message,
    array $actions = [],
    string $siteName = 'Event Staff'
): void {
    require_once __DIR__ . '/helpers.php';

    if ($actions === []) {
        $actions = [
            ['label' => 'Try again', 'href' => 'javascript:history.back()', 'primary' => true],
            ['label' => 'Staff app', 'href' => 'staff-app.php'],
        ];
    }

    renderStaffV3PublicPageStart($title, $siteName);
    ?>
    <div class="es-v3-error es-v3__animate-in">
        <div class="es-v3-error__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>
        </div>
        <h1 class="es-v3-error__title"><?= h($title) ?></h1>
        <p class="es-v3-error__message"><?= h($message) ?></p>
        <div class="es-v3-error__actions">
            <?php foreach ($actions as $action): ?>
                <a href="<?= h((string) $action['href']) ?>"
                   class="es-v3-error__btn<?= !empty($action['primary']) ? ' es-v3-error__btn--primary' : '' ?>">
                    <?= h((string) $action['label']) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
    renderStaffV3PublicPageEnd();
}

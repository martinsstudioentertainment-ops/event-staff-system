<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/theme.php';

header('Content-Type: text/css; charset=utf-8');
header('Cache-Control: public, max-age=300');

try {
    echo renderThemeCss(getDB());
} catch (Throwable $e) {
    echo "/* theme unavailable */\n";
}

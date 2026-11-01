<?php

declare(strict_types=1);

/**
 * Legacy URL — redirects to Staff Identity Manager.
 */
header('Location: staff-identity-manager.php' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''), true, 301);
exit;

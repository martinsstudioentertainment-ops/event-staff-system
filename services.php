<?php

declare(strict_types=1);

/**
 * Legacy URL — service/role offerings live on Roles.
 * Intentionally out of scope as a standalone page; permanent redirect only.
 */
header('Location: roles.php', true, 301);
exit;

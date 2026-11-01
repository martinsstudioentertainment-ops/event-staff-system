<?php

declare(strict_types=1);

/**
 * Root stub — do not run admin download from public_html root.
 * Real page: /admin/attendant-app-download.php
 */

header('Location: /admin/attendant-app-download.php', true, 302);
exit;

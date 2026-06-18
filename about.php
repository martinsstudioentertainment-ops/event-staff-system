<?php

declare(strict_types=1);

/**
 * Legacy URL — About content lives on How it works (CMS company_about).
 * Intentionally out of scope as a standalone page; permanent redirect only.
 */
header('Location: how-it-works.php', true, 301);
exit;

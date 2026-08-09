<?php

declare(strict_types=1);

/** Canonical login when doc root is /apply (not /apply/admin). */
header('Location: /admin/admin/login.php', true, 301);
exit;

<?php

declare(strict_types=1);

chdir(dirname(__DIR__));
$_SERVER['REQUEST_METHOD'] = 'GET';

require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/admin/settings-handler.php';

echo "settings-handler bootstrap OK\n";

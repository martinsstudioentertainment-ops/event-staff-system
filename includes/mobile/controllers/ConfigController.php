<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/MobileConfigService.php';
require_once __DIR__ . '/../mobile-response.php';
require_once __DIR__ . '/../schema/mobile-api-schema.php';

function mobileConfigControllerShow(PDO $pdo): void
{
    mobileJsonOk(mobileConfigServiceGetPublic($pdo));
}

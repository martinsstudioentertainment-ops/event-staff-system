<?php

/**
 * Copy to sso.local.php on the apply server if SSO from main admin fails.
 * Use the same value as APPLY_SSO_SECRET on the main admin config.php,
 * or leave unset on both hosts and rely on matching DB_NAME + DB_PASS hash.
 */
define('APPLY_SSO_SECRET', 'CHANGE_ME_TO_A_LONG_RANDOM_STRING');

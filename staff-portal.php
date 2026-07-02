<?php
/**
 * Legacy URL — all staff traffic goes to the staff app.
 */
require_once __DIR__ . '/config.php';
header('Location: staff-app.php', true, 302);
exit;

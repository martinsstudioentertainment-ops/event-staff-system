<?php
$file = dirname(__DIR__, 2) . '/admin/dashboard.php';
$content = file_get_contents($file);
$pos = strrpos($content, '<aside class="dash__side">');
$chunk = substr($content, $pos - 200, 200);
echo json_encode($chunk) . "\n";

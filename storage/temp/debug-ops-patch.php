<?php
$p = json_decode(file_get_contents(__DIR__ . '/dashboard-patches.json'), true);
$f = file_get_contents(dirname(__DIR__, 2) . '/admin/dashboard.php');
$old = $p[23]['old'];
echo 'old len=' . strlen($old) . "\n";
echo 'found=' . (strpos($f, $old) !== false ? 'yes' : 'no') . "\n";
$needle = '<aside class="dash__side">';
$pos = strrpos($f, $needle);
echo 'aside at ' . $pos . "\n";
echo substr($f, $pos - 120, 120);

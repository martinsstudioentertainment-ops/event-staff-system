<?php
$j = file_get_contents(__DIR__ . '/jun10-hours.json');
$d = json_decode($j, true);
echo json_last_error_msg() . "\n";
echo count($d['all_staff'] ?? []) . "\n";

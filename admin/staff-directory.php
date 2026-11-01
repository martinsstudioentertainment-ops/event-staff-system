<?php
/**
 * Legacy URL — Staff Directory is now Staff → People.
 */
$query = $_SERVER['QUERY_STRING'] ?? '';
$target = 'staff.php?tab=people';
if ($query !== '') {
    $target .= '&' . $query;
}
header('Location: ' . $target, true, 301);
exit;

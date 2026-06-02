<?php
require_once dirname(__DIR__) . '/config.php';
$pdo = getDB();
echo "=== venues ===\n";
foreach ($pdo->query('SELECT id, name, is_active FROM venues ORDER BY name') as $r) {
    echo "{$r['id']}\t{$r['name']}\tactive={$r['is_active']}\n";
}
echo "\n=== sample events ===\n";
foreach ($pdo->query('SELECT id, name, location, venue_id, venue_eircode FROM events WHERE is_active=1 AND event_date>=CURDATE() LIMIT 10') as $r) {
    echo "{$r['id']}\t{$r['name']}\tloc={$r['location']}\tvenue_id={$r['venue_id']}\n";
}
$with = (int) $pdo->query('SELECT COUNT(*) FROM events WHERE is_active=1 AND event_date>=CURDATE() AND venue_id IS NOT NULL AND venue_id>0')->fetchColumn();
$total = (int) $pdo->query('SELECT COUNT(*) FROM events WHERE is_active=1 AND event_date>=CURDATE()')->fetchColumn();
echo "\nEvents with linked venue: {$with}/{$total}\n";

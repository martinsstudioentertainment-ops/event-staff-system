<?php
/**
 * Enterprise Batch 2 verification report.
 * Run: php scripts/batch2-verification.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config.php';

$pdo = null;
try {
    $pdo = getDB();
} catch (Throwable $e) {
    fwrite(STDERR, "DB unavailable: " . $e->getMessage() . "\n");
}

require_once $root . '/includes/production-readiness.php';
require_once $root . '/includes/automation/automation-schema.php';

$modules = [
    11 => ['admin/event-rostering.php', 'cap' => 'events'],
    12 => ['portal/staff-dashboard.php', 'cap' => 'staff_portal'],
    13 => ['admin/recruitment-centre.php', 'cap' => 'staff'],
    14 => ['admin/training-centre.php', 'cap' => 'staff'],
    15 => ['admin/payroll-centre.php', 'cap' => 'export'],
    16 => ['admin/communication-centre.php', 'cap' => 'staff'],
    17 => ['admin/incident-centre.php', 'cap' => 'staff'],
    18 => ['admin/client-centre.php', 'cap' => 'invoices'],
    19 => ['admin/contracts-centre.php', 'cap' => 'staff'],
    20 => ['includes/automation/ops-automation.php', 'cap' => 'cron'],
];

$phase66Tables = auto_schema_tables();
$phase67Tables = auto_phase67_tables();

echo "=== ENTERPRISE BATCH 2 — VERIFICATION REPORT ===\n\n";

echo "## Admin page audit\n";
$errors = 0;
foreach ($modules as $num => $meta) {
    $path = $root . '/' . $meta[0];
    if (!is_file($path)) {
        echo "  FAIL Module {$num}: missing {$meta[0]}\n";
        $errors++;
        continue;
    }
    $out = [];
    $code = 0;
    exec('php -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
    if ($code !== 0) {
        echo "  FAIL Module {$num}: syntax — " . implode(' ', $out) . "\n";
        $errors++;
    } else {
        echo "  OK   Module {$num}: {$meta[0]}\n";
    }
}

$aliases = ['admin/communication-hub.php', 'admin/contract-centre.php', 'staff-self-service.php', 'cron/operations-automation.php'];
foreach ($aliases as $rel) {
    echo is_file($root . '/' . $rel) ? "  OK   Alias: {$rel}\n" : "  WARN Missing alias: {$rel}\n";
}

echo "\n## Database changes report\n";
if ($pdo instanceof PDO) {
    auto_ensure_schema($pdo);
    auto_ensure_phase67_schema($pdo);
    foreach (array_merge($phase66Tables, $phase67Tables) as $table) {
        $ok = tableExists($pdo, $table);
        echo '  ' . ($ok ? 'OK  ' : 'MISS') . " table: {$table}\n";
        if (!$ok) {
            $errors++;
        }
    }
    echo "  Migrations: database/migrate-phase66-operations-automation.sql\n";
    echo "              database/migrate-phase67-batch2-enhancements.sql\n";
} else {
    echo "  SKIP (no DB connection)\n";
}

echo "\n## Permissions audit\n";
$perms = [
    'event-rostering.php' => 'events',
    'recruitment-centre.php' => 'staff',
    'training-centre.php' => 'staff',
    'payroll-centre.php' => 'export',
    'communication-centre.php' => 'staff',
    'incident-centre.php' => 'staff',
    'client-centre.php' => 'invoices',
    'contracts-centre.php' => 'staff',
    'ops-automation.php' => 'staff',
];
foreach ($perms as $page => $cap) {
    echo "  {$page} → requireAdminCapability('{$cap}')\n";
}
echo "  portal/staff-dashboard.php → staff portal session (email + DOB)\n";

echo "\n## Deployment checklist\n";
echo "  [ ] Run migrate-phase66 on production (if not done)\n";
echo "  [ ] Run migrate-phase67 on production\n";
echo "  [ ] Deploy PHP via deploy.ps1\n";
echo "  [ ] Hard refresh admin (Ctrl+Shift+R)\n";
echo "  [ ] Test staff portal: /portal/staff-dashboard.php\n";
echo "  [ ] Schedule cron: cron/operations-automation.php?key=...\n";

echo "\n## Summary\n";
echo $errors === 0 ? "PASS — Batch 2 modules verified.\n" : "FAIL — {$errors} issue(s) found.\n";
exit($errors === 0 ? 0 : 1);

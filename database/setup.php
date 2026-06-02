<?php
/**
 * Event Staff System — One-click database setup
 * Open in browser: http://event-staff-system.test/database/setup.php
 * Or run: database/setup.bat
 */

require_once dirname(__DIR__) . '/config.php';

guardDevOnlyEndpoint('Database setup is disabled in production.');

header('Content-Type: text/html; charset=utf-8');

$messages = [];
$success  = false;

function runMigrations(PDO $pdo): array
{
    $dir = __DIR__;
    $files = [
        'migrate-phase3.sql',
        'migrate-phase4.sql',
        'migrate-phase5-attendance.sql',
        'migrate-phase5-token.sql',
        'migrate-phase6-smtp.sql',
        'migrate-phase7.sql',
        'migrate-phase8-backfill.sql',
        'migrate-phase9-unique-registration.sql',
        'migrate-phase10-theme-preset.sql',
        'migrate-phase14-registration-forms.sql',
        'migrate-phase15-contact-whatsapp.sql',
        'migrate-phase16-location.sql',
        'migrate-phase17-event-signin.sql',
        'migrate-phase18-event-venue-gps.sql',
        'migrate-phase19-event-eircode.sql',
        'migrate-phase20-privacy-consent.sql',
        'migrate-phase21-staff-needed.sql',
        'migrate-phase22-daily-reminders.sql',
        'migrate-phase23-audit-log.sql',
        'migrate-phase24-reporting-signin.sql',
        'migrate-phase25-admin-roles.sql',
        'migrate-phase26-work-hours.sql',
        'migrate-phase27-venues-registration.sql',
        'migrate-phase28-commission-invoices.sql',
        'migrate-phase29-pwa.sql',
        'migrate-phase30-staff-blacklist.sql',
        'migrate-phase31-google-sheets.sql',
        'migrate-phase32-event-times-confirmed.sql',
        'migrate-phase33-event-main-security.sql',
        'migrate-phase34-work-types.sql',
    ];

    $applied = [];
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        if (!file_exists($path)) {
            continue;
        }
        $sql = file_get_contents($path);
        if ($sql !== false && trim($sql) !== '') {
            $pdo->exec($sql);
            $applied[] = $file;
        }
    }

    return $applied;
}

function runSetup(): array
{
    $sqlFile = __DIR__ . '/database.sql';
    if (!file_exists($sqlFile)) {
        return ['success' => false, 'messages' => ['database.sql not found.']];
    }

    $sql = file_get_contents($sqlFile);
    if ($sql === false || trim($sql) === '') {
        return ['success' => false, 'messages' => ['database.sql is empty.']];
    }

    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $pdo->exec($sql);

        $pdo->exec('USE ' . DB_NAME);
        $migrations = runMigrations($pdo);
        $events = (int) $pdo->query('SELECT COUNT(*) FROM events')->fetchColumn();
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

        return [
            'success'  => true,
            'messages' => [
                'Database `' . DB_NAME . '` created successfully.',
                'Tables: ' . implode(', ', $tables),
                'Events loaded: ' . $events,
                'Migrations applied: ' . (count($migrations) ? implode(', ', $migrations) : 'none'),
                'Ready for registrations at index.php',
            ],
        ];
    } catch (PDOException $e) {
        return [
            'success'  => false,
            'messages' => [
                'Setup failed: ' . $e->getMessage(),
                'Make sure Laragon MySQL is running (Start All).',
            ],
        ];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result   = runSetup();
    $success  = $result['success'];
    $messages = $result['messages'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Setup | Event Staff System</title>
    <link rel="stylesheet" href="../assets/css/variables.css">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <main class="page-content">
        <section class="card">
            <div class="card__header">
                <h1 class="card__title">Database Setup</h1>
            <p class="card__subtitle">Creates the database, tables, and 32 events automatically.</p>
        </div>

        <?php if (!empty($messages)): ?>
            <div class="alert alert--<?= $success ? 'success' : 'error' ?> alert--visible">
                <?php foreach ($messages as $msg): ?>
                    <div><?= htmlspecialchars($msg) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post">
            <button type="submit" class="btn btn--primary btn--block">Build / Reset Database</button>
        </form>

        <p class="card__subtitle">
            After success, open <a href="../index.php">index.php</a> to register staff.
        </p>
    </section>
    </main>
</body>
</html>

<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit-log.php';
require_once __DIR__ . '/../includes/attendance-repository.php';

requireAdminCapability('audit');

$pdo     = getDB();
$logins  = getAdminLoginAuditEntries($pdo, 100);
$checkins = $pdo->query(
    "SELECT a.checked_in_at, a.checked_in_method, sr.first_name, sr.surname, sr.email,
            e.name AS event_name, e.event_date
     FROM attendance a
     INNER JOIN staff_registrations sr ON sr.id = a.registration_id
     INNER JOIN events e ON e.id = a.event_id
     ORDER BY a.checked_in_at DESC
     LIMIT 100"
)->fetchAll();

$pageTitle  = 'Login geo audits';
$activePage = 'geo-audits';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<section class="card erp-card">
    <div class="card__header">
        <h2 class="card__title">Login geo audits</h2>
        <p class="card__subtitle">Admin login IP addresses and staff check-in activity. Venue GPS is verified at sign-in time (100 m radius) but coordinates are not stored.</p>
    </div>

    <h3 class="erp-panel__title">Admin logins</h3>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>When</th>
                    <th>User</th>
                    <th>IP address</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($logins === []): ?>
                    <tr><td colspan="4" class="data-table__empty">No admin logins recorded yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($logins as $row): ?>
                        <tr>
                            <td><?= h(date('d.m.Y H:i', strtotime($row['created_at']))) ?></td>
                            <td><?= h($row['admin_username'] ?? '') ?></td>
                            <td><code><?= h($row['ip_address'] ?? '—') ?></code></td>
                            <td><?= h($row['details'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <h3 class="erp-panel__title" style="margin-top:1.5rem;">Recent staff check-ins</h3>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>When</th>
                    <th>Staff</th>
                    <th>Event</th>
                    <th>Method</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($checkins === []): ?>
                    <tr><td colspan="4" class="data-table__empty">No check-ins yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($checkins as $row): ?>
                        <tr>
                            <td><?= h(date('d.m.Y H:i', strtotime($row['checked_in_at']))) ?></td>
                            <td><?= h(trim($row['first_name'] . ' ' . $row['surname'])) ?></td>
                            <td><?= h($row['event_name'] . ' · ' . date('d.m.Y', strtotime($row['event_date']))) ?></td>
                            <td><?= h(ucfirst((string) $row['checked_in_method'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>

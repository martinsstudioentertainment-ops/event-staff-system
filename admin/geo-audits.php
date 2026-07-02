<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit-log.php';
require_once __DIR__ . '/../includes/attendance-repository.php';
require_once __DIR__ . '/../includes/signin-location-log.php';
require_once __DIR__ . '/../includes/events-repository.php';

requireAdminCapability('audit');

$pdo = getDB();

$filterDate  = trim((string) ($_GET['date'] ?? date('Y-m-d')));
$filterEvent = (int) ($_GET['event_id'] ?? 0);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDate)) {
    $filterDate = date('Y-m-d');
}

$logins   = getAdminLoginAuditEntries($pdo, 100);
$checkins = $pdo->query(
    "SELECT a.checked_in_at, a.checked_in_method, sr.first_name, sr.surname, sr.email,
            e.name AS event_name, e.event_date
     FROM attendance a
     INNER JOIN staff_registrations sr ON sr.id = a.registration_id
     INNER JOIN events e ON e.id = a.event_id
     ORDER BY a.checked_in_at DESC
     LIMIT 100"
)->fetchAll();

$locCounts = countSigninLocationVerifications($pdo, $filterEvent, $filterDate);
$locRows   = listSigninLocationVerifications($pdo, 200, $filterEvent, $filterDate);

$eventsForFilter = $pdo->query(
    'SELECT id, name, event_date FROM events ORDER BY event_date DESC, name ASC LIMIT 80'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$pageTitle  = 'Login geo audits';
$activePage = 'geo-audits';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<section class="card erp-card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Login geo audits</h2>
            <p class="card__subtitle">Venue GPS verifications at sign-in, completed check-ins, and admin login IPs.</p>
        </div>
        <a href="visitor-locations.php" class="btn btn--secondary">Website visitor locations</a>
    </div>

    <h3 class="erp-panel__title">Venue GPS verifications</h3>
    <p class="form-hint form-group--full">Logged when staff pass the venue geofence on the QR sign-in page — even if they do not finish check-in.</p>

    <form method="get" class="filter-bar filter-bar--compact" style="margin-bottom:1rem;">
        <div class="filter-bar__group">
            <label class="form-label" for="geo-date">Date</label>
            <input class="form-input" type="date" id="geo-date" name="date" value="<?= h($filterDate) ?>">
        </div>
        <div class="filter-bar__group">
            <label class="form-label" for="geo-event">Event</label>
            <select class="form-select" id="geo-event" name="event_id">
                <option value="0">All events</option>
                <?php foreach ($eventsForFilter as $ev): ?>
                    <option value="<?= (int) $ev['id'] ?>"<?= $filterEvent === (int) $ev['id'] ? ' selected' : '' ?>>
                        <?= h((string) $ev['name']) ?> · <?= h(formatEventDateLabel((string) $ev['event_date'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-bar__group filter-bar__group--actions">
            <button type="submit" class="btn btn--secondary">Filter</button>
            <a href="geo-audits.php" class="btn btn--secondary">Today</a>
        </div>
    </form>

    <div class="stat-grid stat-grid--compact">
        <div class="stat-card">
            <p class="stat-card__value"><?= (int) $locCounts['unique_visitors'] ?></p>
            <p class="stat-card__label">Unique devices verified GPS</p>
        </div>
        <div class="stat-card">
            <p class="stat-card__value"><?= (int) $locCounts['linked'] ?></p>
            <p class="stat-card__label">Linked to staff (email entered)</p>
        </div>
        <div class="stat-card">
            <p class="stat-card__value"><?= (int) $locCounts['unlinked'] ?></p>
            <p class="stat-card__label">GPS only — no email yet</p>
        </div>
        <div class="stat-card">
            <p class="stat-card__value"><?= (int) $locCounts['total'] ?></p>
            <p class="stat-card__label">Total verification pings</p>
        </div>
    </div>

    <div class="table-wrap" style="margin-top:1rem;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>When</th>
                    <th>Event</th>
                    <th>Staff</th>
                    <th>Email</th>
                    <th>Distance</th>
                    <th>Accuracy</th>
                    <th>Registration</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($locRows === []): ?>
                    <tr><td colspan="7" class="data-table__empty">No GPS verifications for this filter.</td></tr>
                <?php else: ?>
                    <?php foreach ($locRows as $row): ?>
                        <?php
                        $name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['surname'] ?? ''));
                        $regId = (int) ($row['registration_id'] ?? 0);
                        ?>
                        <tr>
                            <td><?= h(formatSystemDateTime((string) $row['verified_at'], $pdo)) ?></td>
                            <td><?= h((string) ($row['event_name'] ?? '')) ?></td>
                            <td><?= $name !== '' ? h($name) : '—' ?></td>
                            <td><?= h((string) ($row['staff_email'] ?? '')) !== '' ? h((string) $row['staff_email']) : '—' ?></td>
                            <td><?= $row['distance_m'] !== null ? h((string) $row['distance_m']) . ' m' : '—' ?></td>
                            <td><?= $row['accuracy_m'] !== null ? h((string) $row['accuracy_m']) . ' m' : '—' ?></td>
                            <td>
                                <?php if ($regId > 0): ?>
                                    <a href="view-staff.php?id=<?= $regId ?>">View registration</a>
                                <?php else: ?>
                                    <span class="form-hint">GPS only</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <h3 class="erp-panel__title" style="margin-top:1.5rem;">Admin logins</h3>
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
                            <td><?= h(formatSystemDateTime((string) $row['created_at'], $pdo)) ?></td>
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
                            <td><?= h(formatSystemDateTime((string) $row['checked_in_at'], $pdo)) ?></td>
                            <td><?= h(trim($row['first_name'] . ' ' . $row['surname'])) ?></td>
                            <td><?= h($row['event_name'] . ' · ' . formatEventDateLabel((string) $row['event_date'])) ?></td>
                            <td><?= h(ucfirst((string) $row['checked_in_method'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>

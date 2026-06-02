<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/staff-repository.php';
require_once __DIR__ . '/../includes/attendance-repository.php';
require_once __DIR__ . '/../includes/status-repository.php';
require_once __DIR__ . '/../includes/maps.php';
require_once __DIR__ . '/../includes/sensitive-data.php';
require_once __DIR__ . '/../includes/staff-blacklist.php';
require_once __DIR__ . '/../includes/staff-registration-schema.php';

requireAdminCapability('staff');

$id  = (int) ($_GET['id'] ?? 0);
$pdo = getDB();
ensureStaffRegistrationSaveSchema($pdo);
$row = $id > 0 ? getStaffRegistrationById($pdo, $id) : null;

if (!$row) {
    setAdminFlash('error', 'Registration not found.');
    header('Location: staff.php');
    exit;
}

$relatedRows        = getStaffRegistrationsByEmail($pdo, $row['email']);
$attendance         = $row['status'] === 'approved' ? getAttendanceByRegistration($pdo, (int) $row['id']) : null;
$statusToken = null;
$statusUrl   = '';
try {
    $statusToken = ensureStatusToken($pdo, (int) $row['id']);
    $statusUrl   = $statusToken ? getStatusUrl($statusToken, $pdo) : '';
} catch (Throwable $e) {
    error_log('[EventStaff] view-staff status token: ' . $e->getMessage());
}

$blacklistEntry     = null;
$consecutiveNoShows = 0;
try {
    $blacklistEntry     = getActiveBlacklistEntry($pdo, (string) $row['email']);
    $consecutiveNoShows = countConsecutiveNoShows($pdo, (string) $row['email']);
} catch (Throwable $e) {
    error_log('[EventStaff] view-staff blacklist: ' . $e->getMessage());
}
$flash              = getAdminFlash();

$pageTitle  = 'Staff Details';
$activePage = 'staff';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<?php if ($flash): ?>
    <div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title"><?= h($row['first_name'] . ' ' . $row['surname']) ?></h2>
            <p class="card__subtitle">
                Registration #<?= (int) $row['id'] ?> —
                <span class="badge badge--<?= h($row['status']) ?>"><?= h(formatStatusLabel($row['status'])) ?></span>
                <?php if ($blacklistEntry): ?>
                    <span class="badge badge--blacklist">Blacklisted</span>
                <?php elseif ($consecutiveNoShows > 0): ?>
                    <span class="badge badge--pending"><?= (int) $consecutiveNoShows ?> consecutive no-show<?= $consecutiveNoShows === 1 ? '' : 's' ?></span>
                <?php endif; ?>
            </p>
        </div>
        <a href="staff.php" class="btn btn--secondary">← Back to Staff List</a>
    </div>

    <div class="detail-grid">
        <div class="detail-group">
            <h3 class="form-section-title">Personal Details</h3>
            <dl class="detail-list">
                <div class="detail-list__row"><dt>Surname</dt><dd><?= h($row['surname']) ?></dd></div>
                <div class="detail-list__row"><dt>First Name</dt><dd><?= h($row['first_name']) ?></dd></div>
                <div class="detail-list__row"><dt>Full Address</dt><dd><?= h($row['full_address']) ?></dd></div>
                <div class="detail-list__row"><dt>Eircode</dt><dd><?= h($row['eircode']) ?></dd></div>
                <?php
                $mapLink = buildGoogleMapsLink(
                    isset($row['location_lat']) ? (float) $row['location_lat'] : null,
                    isset($row['location_lng']) ? (float) $row['location_lng'] : null
                );
                if ($mapLink !== ''): ?>
                <div class="detail-list__row"><dt>Location</dt><dd><a href="<?= h($mapLink) ?>" target="_blank" rel="noopener">View on Google Maps ↗</a> (<?= h((string) $row['location_lat']) ?>, <?= h((string) $row['location_lng']) ?>)</dd></div>
                <?php endif; ?>
                <?php
                $dobRaw = (string) ($row['date_of_birth'] ?? '');
                $dobTs  = $dobRaw !== '' ? strtotime($dobRaw) : false;
                $dobDisplay = $dobTs ? date('d.m.Y', $dobTs) : $dobRaw;
                ?>
                <div class="detail-list__row"><dt>Date of Birth</dt><dd><?= h($dobDisplay) ?></dd></div>
                <div class="detail-list__row"><dt>Gender</dt><dd><?= h(formatGenderLabel($row['gender'])) ?></dd></div>
            </dl>
        </div>

        <div class="detail-group">
            <h3 class="form-section-title">Contact &amp; Financial</h3>
            <dl class="detail-list">
                <div class="detail-list__row"><dt>Email</dt><dd><?= h($row['email']) ?></dd></div>
                <div class="detail-list__row"><dt>Mobile</dt><dd><?= h($row['mobile']) ?></dd></div>
                <div class="detail-list__row">
                    <dt>NI / PPS Number</dt>
                    <dd class="sensitive-field">
                        <span class="js-sensitive-value" data-full="<?= h($row['pps_number']) ?>"><?= h(maskPpsNumber((string) $row['pps_number'])) ?></span>
                        <button type="button" class="btn btn--small btn--secondary js-sensitive-reveal" aria-pressed="false">Reveal</button>
                    </dd>
                </div>
                <div class="detail-list__row">
                    <dt>Bank / IBAN</dt>
                    <dd class="sensitive-field">
                        <span class="js-sensitive-value" data-full="<?= h($row['bank_iban']) ?>"><?= h(maskBankIban((string) $row['bank_iban'])) ?></span>
                        <button type="button" class="btn btn--small btn--secondary js-sensitive-reveal" aria-pressed="false">Reveal</button>
                    </dd>
                </div>
                <p class="form-hint">Full IBAN is included in CSV export for company payments.</p>
            </dl>
        </div>

        <div class="detail-group detail-group--full">
            <h3 class="form-section-title">This Registration</h3>
            <dl class="detail-list">
                <div class="detail-list__row"><dt>Role</dt><dd><?= h(formatRoleLabel($row['staff_role'])) ?></dd></div>
                <div class="detail-list__row"><dt>Event</dt><dd><?= h(formatEventLabel($row)) ?></dd></div>
                <div class="detail-list__row"><dt>Registered</dt><dd><?= h(date('d.m.Y H:i', strtotime($row['created_at']))) ?></dd></div>
                <div class="detail-list__row"><dt>Exported</dt><dd><?= $row['exported_at'] ? h(date('d.m.Y H:i', strtotime($row['exported_at']))) : 'Not yet exported' ?></dd></div>
                <?php if ($row['status'] === 'approved'): ?>
                    <div class="detail-list__row">
                        <dt>Check-in</dt>
                        <dd>
                            <?php if ($attendance): ?>
                                Checked in <?= h(date('d.m.Y H:i', strtotime($attendance['checked_in_at']))) ?>
                                (<?= h(match ($attendance['checked_in_method'] ?? 'self') {
                                    'admin' => 'Admin',
                                    'scan'  => 'Scan',
                                    default => 'Self',
                                }) ?>)
                            <?php else: ?>
                                Not checked in yet
                            <?php endif; ?>
                        </dd>
                    </div>
                <?php endif; ?>
                <?php if ($statusUrl !== ''): ?>
                    <div class="detail-list__row">
                        <dt>Status Link</dt>
                        <dd>
                            <div class="copy-field">
                                <input class="form-input copy-field__input" type="text" id="staff-status-url" readonly value="<?= h($statusUrl) ?>">
                                <button type="button" class="btn btn--small btn--secondary copy-field__btn" data-copy-target="staff-status-url">Copy</button>
                            </div>
                        </dd>
                    </div>
                <?php endif; ?>
            </dl>
        </div>
    </div>

    <div class="toolbar">
        <?php if ($row['status'] === 'approved'): ?>
            <a href="qr.php?id=<?= (int) $row['id'] ?>" class="btn btn--secondary">QR Check-in</a>
        <?php endif; ?>
        <?php if ($row['status'] !== 'approved'): ?>
            <form method="post" action="update-status.php">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                <input type="hidden" name="status" value="approved">
                <input type="hidden" name="redirect" value="view-staff.php?id=<?= (int) $row['id'] ?>">
                <button type="submit" class="btn btn--success">Approve</button>
            </form>
        <?php endif; ?>
        <?php if ($row['status'] !== 'rejected'): ?>
            <form method="post" action="update-status.php">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                <input type="hidden" name="status" value="rejected">
                <input type="hidden" name="redirect" value="view-staff.php?id=<?= (int) $row['id'] ?>">
                <button type="submit" class="btn btn--danger">Reject</button>
            </form>
        <?php endif; ?>
        <?php if ($blacklistEntry): ?>
            <form method="post" action="blacklist-action.php">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="remove">
                <input type="hidden" name="email" value="<?= h((string) $row['email']) ?>">
                <input type="hidden" name="return_id" value="<?= (int) $row['id'] ?>">
                <button type="submit" class="btn btn--secondary">Remove from blacklist</button>
            </form>
        <?php else: ?>
            <form method="post" action="blacklist-action.php" onsubmit="return confirm('Block this person from registering?');">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="email" value="<?= h((string) $row['email']) ?>">
                <input type="hidden" name="return_id" value="<?= (int) $row['id'] ?>">
                <button type="submit" class="btn btn--danger">Add to blacklist</button>
            </form>
        <?php endif; ?>
        <a href="blacklist.php" class="btn btn--secondary">Blacklist</a>
    </div>
</section>

<?php if (count($relatedRows) > 1): ?>
<section class="card">
    <div class="card__header">
        <h2 class="card__title">All Events for This Person</h2>
        <p class="card__subtitle"><?= count($relatedRows) ?> registration(s) under <?= h($row['email']) ?></p>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Event</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Registered</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($relatedRows as $related): ?>
                    <tr<?= (int) $related['id'] === (int) $row['id'] ? ' class="data-table__highlight"' : '' ?>>
                        <td><?= h(formatEventLabel($related)) ?></td>
                        <td><?= h(formatRoleLabel($related['staff_role'])) ?></td>
                        <td><span class="badge badge--<?= h($related['status']) ?>"><?= h(formatStatusLabel($related['status'])) ?></span></td>
                        <td><?= h(date('d.m.Y H:i', strtotime($related['created_at']))) ?></td>
                        <td>
                            <?php if ((int) $related['id'] !== (int) $row['id']): ?>
                                <a href="view-staff.php?id=<?= (int) $related['id'] ?>" class="btn btn--small btn--secondary">View</a>
                            <?php else: ?>
                                <span class="detail-list__current">Current</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>

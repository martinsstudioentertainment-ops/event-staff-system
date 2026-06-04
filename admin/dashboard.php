<?php



require_once __DIR__ . '/../config.php';



require_once __DIR__ . '/../includes/auth.php';



require_once __DIR__ . '/../includes/staff-repository.php';



requireAdmin();



$pdo            = getDB();



$stats          = getDashboardStats($pdo);

$pendingPreview = [];
if (adminCan('staff') && (int) ($stats['pending'] ?? 0) > 0) {
    $pendingPreview = getRecentPendingRegistrations($pdo, 10);
}

$flash          = getAdminFlash();



$adminUser      = getAdminUser();



$pageTitle  = 'Dashboard';



$activePage = 'dashboard';







include __DIR__ . '/../includes/admin/layout-top.php';



?>







<?php if ($flash): ?>



    <div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>



<?php endif; ?>







<section class="erp-welcome">

    <div class="erp-welcome__main">

        <h2 class="erp-welcome__title">Welcome back, <?= h(explode(' ', trim($adminUser['name'] ?? 'Admin'))[0]) ?>.</h2>

        <p class="erp-welcome__subtitle">Workforce registrations, event attendance, and check-ins — all in one place. Use the sidebar for your role: <?= h(formatAdminRoleLabel(getAdminRole())) ?>.</p>

        <div class="erp-welcome__actions">

            <?php if (adminCan('staff')): ?>

                <a href="staff.php?status=pending&amp;page=1" class="btn btn--primary">Review pending</a>

            <?php endif; ?>

            <?php if (adminCan('apply')): ?>

                <a href="apply-portal.php" class="btn btn--secondary">Apply admin</a>

            <?php endif; ?>

            <?php if (adminCan('attendance')): ?>

                <a href="attendance.php" class="btn btn--secondary">Attendance</a>

                <a href="scan-checkin.php" class="btn btn--secondary">Scan check-in</a>

            <?php endif; ?>

            <?php if (adminCan('events')): ?>

                <a href="events.php" class="btn btn--secondary">Events</a>

            <?php endif; ?>

            <?php if (adminCan('users')): ?>

                <a href="users.php" class="btn btn--secondary">Team users</a>

            <?php endif; ?>

        </div>

    </div>

    <div class="erp-welcome__health">

        <div class="erp-welcome__health-label">Live overview</div>

        <p class="erp-live-badge">Live sync</p>

        <p class="stat-card__label" style="margin-top:0.75rem;">Pending approvals</p>

        <p class="stat-card__value" style="font-size:1.5rem;"><?= (int) $stats['pending'] ?></p>

    </div>

</section>







<?php if ($pendingPreview !== []): ?>
<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Pending approvals</h2>
            <p class="card__subtitle"><?= (int) $stats['pending'] ?> registration<?= (int) $stats['pending'] === 1 ? '' : 's' ?> waiting — open a profile to approve each shift.</p>
        </div>
        <a href="staff.php?status=pending&amp;page=1" class="btn btn--secondary">View all</a>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Event</th>
                    <th>Role</th>
                    <th>Registered</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pendingPreview as $pendingRow): ?>
                    <tr>
                        <td><?= h(trim(($pendingRow['first_name'] ?? '') . ' ' . ($pendingRow['surname'] ?? ''))) ?></td>
                        <td><?= h((string) ($pendingRow['event_name'] ?? '—')) ?></td>
                        <td><?= h(formatRoleLabel((string) ($pendingRow['staff_role'] ?? ''))) ?></td>
                        <td><?= h(date('d.m.Y H:i', strtotime((string) ($pendingRow['created_at'] ?? 'now')))) ?></td>
                        <td><a href="view-staff.php?id=<?= (int) ($pendingRow['id'] ?? 0) ?>" class="btn btn--small btn--primary">Review</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<section class="card erp-card">



    <div class="card__header card__header--row">



        <div>



            <h2 class="card__title">Dashboard overview</h2>



            <p class="card__subtitle">Live statistics from staff registrations.</p>



        </div>



        <?php if (adminCan('export')): ?>

            <a href="export-staff.php" class="btn btn--secondary">Export data</a>

        <?php endif; ?>

        <?php if (adminCan('settings')): ?>

            <a href="settings-production.php" class="btn btn--secondary">Production checklist</a>

        <?php endif; ?>



    </div>







    <div class="stat-grid">



        <div class="stat-card">



            <p class="stat-card__value"><?= (int) $stats['total_staff'] ?></p>



            <p class="stat-card__label">Total registrations</p>



        </div>



        <div class="stat-card">



            <p class="stat-card__value"><?= (int) $stats['pending'] ?></p>



            <p class="stat-card__label">Pending approvals</p>



        </div>



        <div class="stat-card">



            <p class="stat-card__value"><?= (int) $stats['approved'] ?></p>



            <p class="stat-card__label">Approved</p>



        </div>



        <div class="stat-card">



            <p class="stat-card__value"><?= (int) $stats['today_checkins'] ?></p>



            <p class="stat-card__label">Today's check-ins</p>



        </div>



    </div>



</section>



<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>


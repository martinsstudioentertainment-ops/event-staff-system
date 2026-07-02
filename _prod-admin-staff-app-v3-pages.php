<?php

declare(strict_types=1);

require_once __DIR__ . '/staff-app-v3-shell.php';
require_once __DIR__ . '/staff-app-easy.php';
require_once __DIR__ . '/system-settings.php';
require_once __DIR__ . '/mobile/services/MobileEventsService.php';

/**
 * @param array<string, mixed> $ctx
 */
function renderStaffV3HomePage(array $ctx): void
{
    $pdo         = $ctx['pdo'];
    $portalStaff = $ctx['portal_staff'];
    $metrics     = $ctx['metrics'];
    $monthly     = $ctx['monthly'];
    $todayShift  = $ctx['today_shift'];
    $checkinUrl  = (string) ($ctx['checkin_url'] ?? '');

    if ($portalStaff !== null) {
        require_once __DIR__ . '/staff-portal-shift.php';
        renderStaffPortalShiftBanner($pdo, $portalStaff);
        renderStaffProfileNeededBanner($pdo, $portalStaff);
    }

    renderStaffV3TopBar($ctx);

    $hasNoShifts = empty($metrics['has_data']);
    $openEvents  = (int) ($ctx['open_events_count'] ?? 0);
    $applyUrl    = (string) ($ctx['apply_shifts_url'] ?? 'staff-apply-shifts.php');
    if ($hasNoShifts): ?>
        <div class="es-v3__empty-card es-v3__animate-in" role="status" style="margin-bottom:1rem;">
            <?php if ($openEvents > 0): ?>
                <p><strong>No shifts assigned yet.</strong></p>
                <p><?= $openEvents === 1 ? '1 open shift is' : $openEvents . ' open shifts are' ?> available — apply now and wait for coordinator approval.</p>
                <p><a href="<?= h($applyUrl) ?>" class="es-v3__link">Browse available shifts</a></p>
            <?php else: ?>
                <p><strong>No open shifts right now.</strong></p>
                <p>When new events open, you will get a notification and can apply here.</p>
            <?php endif; ?>
        </div>
    <?php endif;

    $paidHoursDisplay = (float) ($monthly['hours_paid'] ?? 0) > 0
        ? number_format((float) $monthly['hours_paid'], 1)
        : '0';
    ?>
    <section class="es-v3__stats" aria-label="Statistics">
        <div class="es-v3__stat-card">
            <span class="es-v3__stat-val"><?= (int) ($metrics['upcoming'] ?? 0) ?></span>
            <span class="es-v3__stat-label">Upcoming</span>
        </div>
        <div class="es-v3__stat-card">
            <span class="es-v3__stat-val"><?= h((string) $monthly['hours_worked']) ?></span>
            <span class="es-v3__stat-label">Worked</span>
        </div>
        <div class="es-v3__stat-card">
            <span class="es-v3__stat-val"><?= h($paidHoursDisplay) ?></span>
            <span class="es-v3__stat-label">Paid hrs</span>
        </div>
        <div class="es-v3__stat-card">
            <span class="es-v3__stat-val"><?= (int) ($monthly['checkins'] ?? 0) ?></span>
            <span class="es-v3__stat-label">Check-ins</span>
        </div>
    </section>

    <section class="es-v3__section" aria-labelledby="es-v3-today-title">
        <h2 id="es-v3-today-title" class="es-v3__section-title">Today's shift</h2>
        <?php if ($todayShift !== null): ?>
            <?php
            $statusMeta   = getStaffV3ShiftStatusMeta($todayShift);
            $venue        = formatStaffStatusVenueLabel($todayShift);
            $todayProgress = getStaffV3ShiftTimeProgress($todayShift);
            $isPendingToday = (string) ($todayShift['status'] ?? '') === 'pending';
            ?>
            <article class="es-v3__today-card es-v3__animate-in">
                <div class="es-v3__today-card-head">
                    <h3><?= h((string) ($todayShift['event_name'] ?? 'Event')) ?></h3>
                    <span class="es-v3__badge es-v3__badge--<?= h($statusMeta['tone']) ?>"><?= h($statusMeta['label']) ?></span>
                </div>
                <?php if ($isPendingToday): ?>
                    <p class="form-hint" style="margin:0 0 0.75rem;">Your application is with the coordinator. You can check in once it is approved.</p>
                <?php endif; ?>
                <p class="es-v3__today-location">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                    <?= h($venue) ?>
                </p>
                <div class="es-v3__today-times">
                    <div>
                        <span class="es-v3__today-time-label">Start</span>
                        <span class="es-v3__today-time-val"><?= h(trim((string) ($todayShift['event_start_time'] ?? $todayShift['start_time'] ?? '—'))) ?></span>
                    </div>
                    <div class="es-v3__today-divider" aria-hidden="true"></div>
                    <div>
                        <span class="es-v3__today-time-label">End</span>
                        <span class="es-v3__today-time-val"><?= h(trim((string) ($todayShift['event_end_time'] ?? $todayShift['end_time'] ?? '—'))) ?></span>
                    </div>
                </div>
                <?php renderStaffV3ShiftProgressBar($todayProgress); ?>
            </article>
        <?php else: ?>
            <div class="es-v3__empty-card es-v3__animate-in">
                <p>No shift scheduled today.</p>
                <a href="<?= h((string) $ctx['shifts_url']) ?>" class="es-v3__link">View upcoming shifts</a>
            </div>
        <?php endif; ?>
    </section>

    <section class="es-v3__section" aria-labelledby="es-v3-actions-title">
        <h2 id="es-v3-actions-title" class="es-v3__section-title">Quick actions</h2>
        <div class="es-v3__actions-grid">
            <a href="staff-checkin.php" class="es-v3__action-card es-v3__action-card--accent">
                <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M7 12h10"/></svg>
                <span>Check In</span>
            </a>
            <a href="<?= h((string) $ctx['status_url']) ?>" class="es-v3__action-card">
                <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                <span>View Roster</span>
            </a>
            <a href="<?= h((string) $ctx['messages_url']) ?>" class="es-v3__action-card">
                <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                <span>Messages</span>
            </a>
            <a href="<?= h((string) $ctx['profile_url']) ?>#documents" class="es-v3__action-card">
                <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
                <span>Documents</span>
            </a>
            <?php if ($openEvents > 0 || $hasNoShifts): ?>
            <a href="<?= h($applyUrl) ?>" class="es-v3__action-card">
                <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                <span>Browse shifts</span>
            </a>
            <?php endif; ?>
        </div>
    </section>

    <button type="button" class="es-v3__install-row" id="staff-app-install-btn" aria-label="Add to home screen">
        <span class="es-v3__install-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24"><rect x="5" y="2" width="14" height="20" rx="2"/><path d="M12 18h.01"/></svg>
        </span>
        <span class="es-v3__install-copy">
            <strong>Add to home screen</strong>
            <span>Quick access during your shift</span>
        </span>
        <span class="es-v3__install-cta">Add</span>
    </button>
    <?php
}

/**
 * @param array<string, mixed> $ctx
 */
function renderStaffV3ApplyShiftsPage(array $ctx): void
{
    $pdo         = $ctx['pdo'];
    $portalStaff = $ctx['portal_staff'];
    if ($portalStaff === null) {
        return;
    }

    $list = mobileEventsServiceList($pdo, $portalStaff);
    $events = !empty($list['ok']) ? ($list['events'] ?? []) : [];
    $csrf   = csrfToken();
    ?>
    <header class="es-v3__page-header">
        <h1 class="es-v3__page-title">Browse shifts</h1>
        <p class="es-v3__page-sub">Open events you can apply for. Approved shifts appear under <a href="<?= h((string) $ctx['shifts_url']) ?>" class="es-v3__link">Shifts</a>.</p>
    </header>

    <section class="es-v3__shift-list" aria-label="Open shifts">
        <?php if ($events === []): ?>
            <div class="es-v3__empty-card">
                <p><strong>No open shifts right now.</strong></p>
                <p>Check back later or watch for notifications when new events open.</p>
                <a href="<?= h((string) $ctx['home_url']) ?>" class="es-v3__link">Back to home</a>
            </div>
        <?php else: ?>
            <?php foreach ($events as $event): ?>
                <?php
                $canApply = !empty($event['can_apply']);
                $status   = (string) ($event['approval_status'] ?? 'Not applied');
                $spaces   = (int) ($event['available_spaces'] ?? 0);
                ?>
                <article class="es-v3__shift-card">
                    <div class="es-v3__shift-card-top">
                        <h3 class="es-v3__shift-location"><?= h((string) ($event['event_name'] ?? 'Event')) ?></h3>
                        <?php if ($canApply): ?>
                            <span class="es-v3__badge es-v3__badge--ok">Open</span>
                        <?php elseif ($status !== 'Not applied'): ?>
                            <span class="es-v3__badge es-v3__badge--warn"><?= h($status) ?></span>
                        <?php else: ?>
                            <span class="es-v3__badge es-v3__badge--muted">Full</span>
                        <?php endif; ?>
                    </div>
                    <p class="es-v3__shift-venue"><?= h((string) ($event['venue_name'] ?? '—')) ?></p>
                    <div class="es-v3__shift-meta">
                        <span class="es-v3__shift-date"><?= h((string) ($event['event_date'] ?? '—')) ?></span>
                        <span class="es-v3__shift-time"><?= h((string) ($event['time_label'] ?? '')) ?></span>
                    </div>
                    <div class="es-v3__shift-footer">
                        <span class="es-v3__employer-badge"><?= h((string) ($event['employer'] ?? '')) ?></span>
                        <?php if ($spaces > 0): ?>
                            <span class="es-v3__shift-hours"><?= $spaces ?> space<?= $spaces === 1 ? '' : 's' ?> left</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($canApply): ?>
                        <form method="post" action="api/staff-apply-events.php" class="es-v3__apply-form" style="margin-top:0.75rem;">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="event_id" value="<?= (int) ($event['event_id'] ?? 0) ?>">
                            <button type="submit" class="btn btn--primary btn--block">Apply for this shift</button>
                        </form>
                    <?php elseif ($status === 'Pending approval'): ?>
                        <p class="form-hint" style="margin-top:0.75rem;">Application submitted — awaiting coordinator approval.</p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
    <?php
}

/**
 * @param array<string, mixed> $ctx
 */
function renderStaffV3ShiftsPage(array $ctx): void
{
    $pdo            = $ctx['pdo'];
    $rows           = $ctx['shift_rows'];
    $companyName    = (string) $ctx['company_name'];
    $filterEmployer = trim((string) ($_GET['employer'] ?? ''));
    $search         = trim((string) ($_GET['q'] ?? ''));
    $tab            = strtolower(trim((string) ($_GET['tab'] ?? 'upcoming')));
    if (!in_array($tab, ['upcoming', 'past', 'all'], true)) {
        $tab = 'upcoming';
    }

    $today = date('Y-m-d');
    $filtered = array_values(array_filter($rows, static function (array $row) use ($tab, $today, $filterEmployer, $search, $companyName): bool {
        $status    = (string) ($row['status'] ?? '');
        $eventDate = substr((string) ($row['event_date'] ?? ''), 0, 10);

        if ($tab === 'upcoming' && $eventDate < $today) {
            return false;
        }
        if ($tab === 'upcoming' && !in_array($status, ['approved', 'pending'], true)) {
            return false;
        }
        if ($tab === 'past' && ($status !== 'approved' || $eventDate >= $today)) {
            return false;
        }
        if ($filterEmployer !== '' && getStaffV3EmployerLabel($row, $companyName) !== $filterEmployer) {
            return false;
        }
        if ($search !== '') {
            $hay = strtolower(
                (string) ($row['event_name'] ?? '') . ' '
                . formatStaffStatusVenueLabel($row) . ' '
                . getStaffV3EmployerLabel($row, $companyName)
            );
            if (!str_contains($hay, strtolower($search))) {
                return false;
            }
        }

        return true;
    }));
    ?>
    <header class="es-v3__page-header">
        <h1 class="es-v3__page-title">Shifts</h1>
        <p class="es-v3__page-sub">Your assigned and pending shifts. <a href="<?= h((string) ($ctx['apply_shifts_url'] ?? 'staff-apply-shifts.php')) ?>" class="es-v3__link">Browse open shifts</a> to apply.</p>
    </header>

    <form class="es-v3__search-bar" method="get" action="staff-shifts.php" role="search">
        <input type="hidden" name="tab" value="<?= h($tab) ?>">
        <?php if ($filterEmployer !== ''): ?>
            <input type="hidden" name="employer" value="<?= h($filterEmployer) ?>">
        <?php endif; ?>
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
        <input type="search" name="q" value="<?= h($search) ?>" placeholder="Search location or event" aria-label="Search shifts">
    </form>

    <div class="es-v3__tabs" role="tablist" aria-label="Shift filters">
        <a href="staff-shifts.php?tab=upcoming<?= $search !== '' ? '&q=' . urlencode($search) : '' ?><?= $filterEmployer !== '' ? '&employer=' . urlencode($filterEmployer) : '' ?>" class="es-v3__tab<?= $tab === 'upcoming' ? ' es-v3__tab--active' : '' ?>" role="tab" <?= $tab === 'upcoming' ? 'aria-selected="true"' : '' ?>>Upcoming</a>
        <a href="staff-shifts.php?tab=past<?= $search !== '' ? '&q=' . urlencode($search) : '' ?><?= $filterEmployer !== '' ? '&employer=' . urlencode($filterEmployer) : '' ?>" class="es-v3__tab<?= $tab === 'past' ? ' es-v3__tab--active' : '' ?>" role="tab" <?= $tab === 'past' ? 'aria-selected="true"' : '' ?>>Past</a>
        <a href="staff-shifts.php?tab=all<?= $search !== '' ? '&q=' . urlencode($search) : '' ?><?= $filterEmployer !== '' ? '&employer=' . urlencode($filterEmployer) : '' ?>" class="es-v3__tab<?= $tab === 'all' ? ' es-v3__tab--active' : '' ?>" role="tab" <?= $tab === 'all' ? 'aria-selected="true"' : '' ?>>All</a>
    </div>

    <?php if ($ctx['employer_filters'] !== []): ?>
        <div class="es-v3__chip-row" aria-label="Filter by employer">
            <a href="staff-shifts.php?tab=<?= h(urlencode($tab)) ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?>" class="es-v3__chip<?= $filterEmployer === '' ? ' es-v3__chip--active' : '' ?>">All employers</a>
            <?php foreach ($ctx['employer_filters'] as $employer): ?>
                <a href="staff-shifts.php?tab=<?= h(urlencode($tab)) ?>&employer=<?= urlencode($employer) ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?>" class="es-v3__chip<?= $filterEmployer === $employer ? ' es-v3__chip--active' : '' ?>"><?= h($employer) ?></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <section class="es-v3__calendar-strip" aria-label="This month">
        <?php
        $monthLabel = date('F Y');
        $daysInMonth = (int) date('t');
        $todayDay = (int) date('j');
        ?>
        <p class="es-v3__calendar-month"><?= h($monthLabel) ?></p>
        <div class="es-v3__calendar-days">
            <?php for ($d = 1; $d <= min($daysInMonth, 14); $d++): ?>
                <?php
                $dateStr = date('Y-m-') . str_pad((string) $d, 2, '0', STR_PAD_LEFT);
                $hasShift = false;
                foreach ($rows as $row) {
                    if (substr((string) ($row['event_date'] ?? ''), 0, 10) === $dateStr) {
                        $hasShift = true;
                        break;
                    }
                }
                ?>
                <span class="es-v3__cal-day<?= $d === $todayDay ? ' es-v3__cal-day--today' : '' ?><?= $hasShift ? ' es-v3__cal-day--shift' : '' ?>"><?= $d ?></span>
            <?php endfor; ?>
        </div>
    </section>

    <section class="es-v3__shift-list" aria-label="Shift list">
        <?php if ($filtered === []): ?>
            <div class="es-v3__empty-card">
                <?php if ($rows === []): ?>
                    <p><strong>You have not applied for any shifts yet.</strong></p>
                    <p><a href="<?= h((string) ($ctx['apply_shifts_url'] ?? 'staff-apply-shifts.php')) ?>" class="es-v3__link">Browse available shifts</a></p>
                <?php else: ?>
                    <p>No shifts match your filters.</p>
                    <a href="staff-shifts.php" class="es-v3__link">Clear filters</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php foreach ($filtered as $row): ?>
                <?php renderStaffV3ShiftCard($row, $pdo, $companyName); ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
    <?php
}

/**
 * @param array<string, mixed> $ctx
 */
function renderStaffV3CheckinPage(array $ctx): void
{
    $pdo        = $ctx['pdo'];
    $todayShift = $ctx['today_shift'];
    $todayReg   = $ctx['today_registration'] ?? null;
    $history    = getStaffV3CheckinHistory($pdo, (string) $ctx['staff_email'], 12, (int) ($ctx['staff_id'] ?? 0));
    $flash      = $ctx['checkin_flash'] ?? null;
    $hasToday   = is_array($todayReg) && (string) ($todayReg['status'] ?? '') === 'approved';
    $signedInEmail = trim((string) ($ctx['staff_email'] ?? ''));
    $todayYmd   = getOperationalTodayYmd($pdo);
    $nearestApproved = null;
    foreach ($ctx['shift_rows'] ?? [] as $shiftRow) {
        if ((string) ($shiftRow['status'] ?? '') !== 'approved') {
            continue;
        }
        $eventDate = substr((string) ($shiftRow['event_date'] ?? ''), 0, 10);
        if ($eventDate === '') {
            continue;
        }
        if ($nearestApproved === null || abs(strtotime($eventDate) - strtotime($todayYmd)) < abs(strtotime((string) $nearestApproved['event_date']) - strtotime($todayYmd))) {
            $nearestApproved = ['event_date' => $eventDate, 'event_name' => (string) ($shiftRow['event_name'] ?? 'Event')];
        }
    }
    require_once __DIR__ . '/attendance-repository.php';
    $venueCoords = is_array($todayReg) ? getEventVenueCoordinates($todayReg) : null;
    $venueConfigured = $venueCoords !== null;
    $signinRadiusM = is_array($todayReg) ? (int) getEventSigninRadiusMeters($todayReg, $pdo) : 200;
    $regId         = is_array($todayReg) ? (int) ($todayReg['id'] ?? 0) : 0;
    $alreadyCheckedIn = $regId > 0 && (
        (int) ($todayReg['is_checked_in'] ?? 0) === 1
        || (is_array($todayShift) && (int) ($todayShift['is_checked_in'] ?? 0) === 1)
        || hasCheckedIn($pdo, $regId)
    );
    $checkedInAt = '';
    if ($alreadyCheckedIn && is_array($todayReg)) {
        $checkedInAt = trim((string) ($todayReg['checked_in_at'] ?? ''));
        if ($checkedInAt === '' && $regId > 0) {
            $attRow = getAttendanceByRegistration($pdo, $regId);
            $checkedInAt = is_array($attRow) ? trim((string) ($attRow['checked_in_at'] ?? '')) : '';
        }
    }
    $checkedInTimeLabel = $checkedInAt !== '' ? formatDisplayDateTime($checkedInAt, $pdo) : '';
    $showCheckinSuccess = $alreadyCheckedIn && $hasToday;
    ?>
    <header class="es-v3__page-header">
        <h1 class="es-v3__page-title">Check In</h1>
        <p class="es-v3__page-sub">On your own phone — no shared scanner or barcode</p>
    </header>

    <?php if (is_array($flash) && !empty($flash['message'])): ?>
        <div class="es-v3__alert es-v3__alert--<?= h((string) ($flash['type'] ?? 'info')) ?>" role="status">
            <?= h((string) $flash['message']) ?>
        </div>
    <?php endif; ?>

    <?php if (!$showCheckinSuccess): ?>
    <div class="es-v3__gps-status" id="es-v3-gps-status" data-gps-status="checking">
        <span class="es-v3__gps-dot" aria-hidden="true"></span>
        <span class="es-v3__gps-label">Checking GPS…</span>
    </div>
    <?php else: ?>
    <div class="es-v3__gps-status" id="es-v3-gps-status" data-gps-status="granted">
        <span class="es-v3__gps-dot" aria-hidden="true"></span>
        <span class="es-v3__gps-label">On shift — checked in<?= $checkedInTimeLabel !== '' ? ' at ' . h($checkedInTimeLabel) : '' ?></span>
    </div>
    <?php endif; ?>

    <section class="es-v3__scanner-section">
        <?php if ($showCheckinSuccess): ?>
            <div class="es-v3__scanner-btn es-v3__scanner-success es-v3__checkin-success" id="es-v3-checkin-success" role="status" aria-live="polite">
                <span class="es-v3__scanner-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>
                </span>
                <span class="es-v3__scanner-label">You're checked in</span>
                <span class="es-v3__scanner-hint">
                    <?php if ($checkedInTimeLabel !== ''): ?>
                        Checked in at <?= h($checkedInTimeLabel) ?>.
                    <?php else: ?>
                        Check-in recorded for today's shift.
                    <?php endif; ?>
                    Stay signed in on this phone during your shift.
                </span>
            </div>
        <?php elseif ($hasToday): ?>
            <form method="post" action="staff-checkin.php" class="es-v3__checkin-form" id="es-v3-checkin-form"
                data-venue-configured="<?= $venueConfigured ? '1' : '0' ?>"
                data-venue-lat="<?= $venueCoords ? h((string) $venueCoords['lat']) : '' ?>"
                data-venue-lng="<?= $venueCoords ? h((string) $venueCoords['lng']) : '' ?>"
                data-signin-radius-m="<?= (int) $signinRadiusM ?>">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="staff_app_checkin" value="1">
                <input type="hidden" name="sign_lat" id="es-v3-sign-lat" value="">
                <input type="hidden" name="sign_lng" id="es-v3-sign-lng" value="">
                <input type="hidden" name="sign_accuracy_m" id="es-v3-sign-accuracy" value="">
                <button type="submit" class="es-v3__scanner-btn es-v3__animate-in" id="es-v3-scanner-btn" disabled>
                    <span class="es-v3__scanner-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    </span>
                    <span class="es-v3__scanner-label">Check In Now</span>
                    <span class="es-v3__scanner-hint">Allow location — must be at the venue</span>
                </button>
            </form>
        <?php else: ?>
            <div class="es-v3__empty-card es-v3__scanner-empty">
                <p>No approved shift for today (<?= h(formatEventDateLabel($todayYmd)) ?>) on this account.</p>
                <?php if ($nearestApproved !== null && (string) $nearestApproved['event_date'] !== $todayYmd): ?>
                    <span>Your nearest approved shift is <strong><?= h($nearestApproved['event_name']) ?></strong> on <?= h(formatEventDateLabel((string) $nearestApproved['event_date'])) ?> — ask your supervisor to move it to today, or use the venue sign-in link for that event date.</span>
                <?php elseif ($signedInEmail !== ''): ?>
                    <span>Signed in as <strong><?= h($signedInEmail) ?></strong> — use the same Gmail you registered with.</span>
                <?php else: ?>
                    <span>Check Shifts or ask your supervisor.</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($todayShift !== null): ?>
            <p class="es-v3__checkin-today-label">Today's assignment</p>
            <?php
            $shiftCard = $todayShift;
            if ($showCheckinSuccess && is_array($todayReg)) {
                $shiftCard = array_merge($todayShift, $todayReg, ['is_checked_in' => 1]);
            }
            renderStaffV3ShiftCard($shiftCard, $pdo, (string) $ctx['company_name'], true);
            ?>
        <?php endif; ?>
    </section>

    <section class="es-v3__section" aria-labelledby="es-v3-history-title">
        <h2 id="es-v3-history-title" class="es-v3__section-title">Check-in history</h2>
        <?php if ($history === []): ?>
            <div class="es-v3__empty-card"><p>No check-ins recorded yet.</p></div>
        <?php else: ?>
            <ul class="es-v3__history-list">
                <?php foreach ($history as $item): ?>
                    <li class="es-v3__history-item es-v3__animate-in">
                        <div class="es-v3__history-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>
                        </div>
                        <div class="es-v3__history-body">
                            <strong><?= h((string) ($item['event_name'] ?? 'Event')) ?></strong>
                            <span>
                                <?php
                                $historyCheckIn = trim((string) ($item['checked_in_at'] ?? ''));
                                if ($historyCheckIn === '') {
                                    $historyCheckIn = trim((string) ($item['activated_at'] ?? $item['check_in_gps_at'] ?? ''));
                                }
                                ?>
                                <?= h($historyCheckIn !== '' ? formatSystemDateTime($historyCheckIn, $pdo) : '—') ?>
                                <?php if ((float) ($item['hours_worked'] ?? 0) > 0): ?>
                                    · <?= h(number_format((float) $item['hours_worked'], 1)) ?> hrs
                                <?php endif; ?>
                            </span>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    <?php
}

/**
 * @param array<string, mixed> $ctx
 */
function renderStaffV3ProfileHubPage(array $ctx): void
{
    $portalStaff = $ctx['portal_staff'];
    if ($portalStaff === null) {
        return;
    }

    require_once __DIR__ . '/automation/staff-portal.php';
    $docs = portal_staff_documents($ctx['pdo'], $portalStaff);
    ?>
    <header class="es-v3__page-header es-v3__page-header--profile">
        <div class="es-v3__profile-hero">
            <div class="es-v3__avatar es-v3__avatar--lg" aria-hidden="true"><?= h((string) $ctx['avatar_initials']) ?></div>
            <div>
                <h1 class="es-v3__page-title"><?= h((string) $ctx['display_name']) ?></h1>
                <p class="es-v3__page-sub"><?= h((string) $ctx['display_role']) ?></p>
            </div>
        </div>
    </header>

    <section class="es-v3__section" aria-labelledby="es-v3-personal-title">
        <h2 id="es-v3-personal-title" class="es-v3__section-title">Personal details</h2>
        <div class="es-v3__menu-card">
            <div class="es-v3__menu-row">
                <span>Email</span>
                <strong><?= h((string) ($portalStaff['email'] ?? '')) ?></strong>
            </div>
            <div class="es-v3__menu-row">
                <span>Phone</span>
                <strong><?= h(trim((string) ($portalStaff['phone'] ?? '')) !== '' ? (string) $portalStaff['phone'] : '—') ?></strong>
            </div>
            <a href="<?= h((string) $ctx['profile_edit_url']) ?>" class="es-v3__menu-link">Edit profile</a>
        </div>
    </section>

    <section class="es-v3__section" id="documents" aria-labelledby="es-v3-docs-title">
        <h2 id="es-v3-docs-title" class="es-v3__section-title">Documents &amp; certificates</h2>
        <div class="es-v3__menu-card">
            <?php if ($docs === []): ?>
                <p class="es-v3__menu-empty">No documents on file yet.</p>
            <?php else: ?>
                <?php foreach ($docs as $doc): ?>
                    <div class="es-v3__menu-row">
                        <span><?= h((string) ($doc['label'] ?? 'Document')) ?></span>
                        <span class="es-v3__badge es-v3__badge--<?= h((string) ($doc['status'] ?? 'muted')) ?>"><?= h(ucfirst((string) ($doc['status'] ?? 'valid'))) ?></span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <a href="<?= h((string) $ctx['profile_edit_url']) ?>" class="es-v3__menu-link">Upload or update</a>
        </div>
    </section>

    <section class="es-v3__section" id="settings" aria-labelledby="es-v3-settings-title">
        <h2 id="es-v3-settings-title" class="es-v3__section-title">Settings</h2>
        <div class="es-v3__menu-card">
            <a href="<?= h((string) $ctx['notif_url']) ?>" class="es-v3__menu-link">Notifications</a>
            <button type="button" class="es-v3__menu-link es-v3__menu-link--btn" id="staff-app-install-btn">Install app</button>
            <a href="<?= h((string) $ctx['register_url']) ?>" class="es-v3__menu-link">Register for events</a>
        </div>
    </section>

    <a href="staff-signout.php?return=staff-app.php" class="es-v3__logout-btn">Sign out</a>
    <?php
}

/**
 * @param list<array<string, mixed>> $notifications
 */
function renderStaffV3NotificationsPage(
    array $ctx,
    array $notifications,
    int $unreadCount,
    string $notifUrl,
    string $error = ''
): void {
    $portalStaff = $ctx['portal_staff'];
    $pdo         = $ctx['pdo'];

    if ($portalStaff !== null) {
        require_once __DIR__ . '/staff-portal-shift.php';
        renderStaffPortalShiftBanner($pdo, $portalStaff);
        renderStaffProfileNeededBanner($pdo, $portalStaff);
    }

    renderStaffV3TopBar($ctx, false);
    ?>
    <header class="es-v3__page-header es-v3__page-header--notif">
        <div>
            <h1 class="es-v3__page-title">Notifications</h1>
            <p class="es-v3__page-sub">Updates about your registrations and shifts</p>
        </div>
        <?php if ($unreadCount > 0): ?>
            <span class="es-v3__notif-badge" aria-label="<?= (int) $unreadCount ?> unread"><?= (int) $unreadCount ?></span>
        <?php endif; ?>
    </header>

    <?php if ($error !== ''): ?>
        <div class="es-v3__alert es-v3__alert--error" role="alert"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if ($unreadCount > 0): ?>
        <form method="post" action="<?= h($notifUrl) ?>" class="es-v3__notif-actions">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="mark_all_read" value="1">
            <button type="submit" class="es-v3__notif-mark-read">Mark all as read</button>
        </form>
    <?php endif; ?>

    <section class="es-v3__section" aria-labelledby="es-v3-notif-list-title">
        <h2 id="es-v3-notif-list-title" class="es-v3__section-title">Recent</h2>
        <?php
        require_once __DIR__ . '/components/notification-list.php';
        renderStaffV3NotificationList($notifications, 'No notifications yet. You will see updates here when your application is reviewed.');
        ?>
    </section>

    <section class="es-v3__section" aria-label="WhatsApp groups">
        <?php
        require_once __DIR__ . '/components/whatsapp-join.php';
        renderStaffEventWhatsappGroups($ctx['pdo'], (string) ($ctx['staff_email'] ?? ''));
        renderWhatsappGroupCard($ctx['pdo']);
        ?>
    </section>
    <?php
}

/**
 * @param list<array<string, mixed>> $messages
 */
function renderStaffV3MessagesPage(array $ctx, array $messages, string $flash, string $msgUrl): void
{
    ?>
    <header class="es-v3__page-header">
        <h1 class="es-v3__page-title">Messages</h1>
        <p class="es-v3__page-sub">Chat with your coordinator — replies appear here</p>
    </header>

    <?php if ($flash !== ''): ?>
        <div class="es-v3__alert" role="status"><?= h($flash) ?></div>
    <?php endif; ?>

    <section class="es-v3__chat-panel">
        <?php
        require_once __DIR__ . '/components/message-thread.php';
        renderMessageThread($messages, false);
        ?>
    </section>

    <form method="post" action="<?= h($msgUrl) ?>" class="es-v3__compose">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="send_message" value="1">
        <label class="es-v3__compose-label" for="body">Your message</label>
        <textarea class="es-v3__compose-input" id="body" name="body" rows="3" maxlength="4000" placeholder="Ask about shifts, payroll, or availability…" required></textarea>
        <button type="submit" class="es-v3__compose-send">Send</button>
    </form>
    <?php
}

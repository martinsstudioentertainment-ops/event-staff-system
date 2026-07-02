<?php

declare(strict_types=1);

require_once __DIR__ . '/staff-app-v3-shell.php';
require_once __DIR__ . '/staff-app-easy.php';
require_once __DIR__ . '/system-settings.php';

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

    $homeBib = (string) ($ctx['display_bib'] ?? '');
    if ($homeBib === '' && is_array($todayShift)) {
        $homeBib = resolveStaffDisplayBibNumber($todayShift);
    }
    if ($homeBib !== '') {
        renderStaffV3BibBanner($homeBib);
    }

    renderStaffV3ClockInHero($ctx, $todayShift, $pdo);

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
            <span class="es-v3__stat-label">Worked (completed)</span>
        </div>
        <div class="es-v3__stat-card">
            <span class="es-v3__stat-val"><?= h($paidHoursDisplay) ?></span>
            <span class="es-v3__stat-label">Paid hrs (completed)</span>
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
            $todayProgress = getStaffV3ShiftTimeProgress($todayShift, $pdo);
            $todayBib     = resolveStaffDisplayBibNumber($todayShift);
            ?>
            <article class="es-v3__today-card es-v3__animate-in">
                <div class="es-v3__today-card-head">
                    <h3><?= h((string) ($todayShift['event_name'] ?? 'Event')) ?></h3>
                    <span class="es-v3__badge es-v3__badge--<?= h($statusMeta['tone']) ?>"><?= h($statusMeta['label']) ?></span>
                </div>
                <?php if ($todayBib !== ''): ?>
                    <div class="es-v3__today-bib" aria-label="Your BIB number: <?= h($todayBib) ?>">
                        <span>BIB</span>
                        <strong><?= h($todayBib) ?></strong>
                    </div>
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
            <div class="es-ds__empty es-v3__animate-in">
                <span class="es-ds__empty-icon" aria-hidden="true">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                </span>
                <p class="es-ds__empty-title">No shift scheduled today</p>
                <p class="es-ds__empty-text">When you are approved for an event, it will appear here.</p>
                <a href="<?= h((string) $ctx['shifts_url']) ?>" class="es-ds__btn es-ds__btn--ghost es-ds__btn--sm">View upcoming shifts</a>
            </div>
        <?php endif; ?>
    </section>

    <section class="es-v3__section" aria-labelledby="es-v3-actions-title">
        <h2 id="es-v3-actions-title" class="es-v3__section-title">More actions</h2>
        <div class="es-v3__actions-grid">
            <a href="<?= h((string) $ctx['shifts_url']) ?>" class="es-v3__action-card">
                <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                <span>My Shifts</span>
            </a>
            <a href="<?= h((string) $ctx['status_url']) ?>" class="es-v3__action-card">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                <span>Application status</span>
            </a>
            <a href="<?= h((string) $ctx['messages_url']) ?>" class="es-v3__action-card">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                <span>Messages</span>
            </a>
            <a href="<?= h((string) $ctx['profile_url']) ?>#documents" class="es-v3__action-card">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
                <span>Documents</span>
            </a>
        </div>
    </section>

    <?php if ($portalStaff !== null && (string) ($ctx['staff_email'] ?? '') !== ''): ?>
        <?php
        require_once __DIR__ . '/components/whatsapp-join.php';
        if (function_exists('renderStaffAppWhatsappSectionBlock')) {
            renderStaffAppWhatsappSectionBlock(
                $ctx['pdo'],
                (string) $ctx['staff_email'],
                ['shift_rows' => $ctx['shift_rows'] ?? []]
            );
        }
        ?>
    <?php endif; ?>

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

        if ($tab === 'upcoming' && ($status !== 'approved' || $eventDate < $today)) {
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
        <p class="es-v3__page-sub">Calendar view of your assignments</p>
    </header>

    <?php
    $shiftsBib = (string) ($ctx['display_bib'] ?? '');
    if ($shiftsBib !== '') {
        renderStaffV3BibBanner($shiftsBib);
    }
    ?>

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
            <div class="es-ds__empty">
                <span class="es-ds__empty-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                </span>
                <p class="es-ds__empty-title">No shifts match your filters</p>
                <p class="es-ds__empty-text">Try a different tab, employer, or search term.</p>
                <a href="staff-shifts.php" class="es-ds__btn es-ds__btn--ghost es-ds__btn--sm">Clear filters</a>
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
    $history    = getStaffV3CheckinHistory($pdo, (string) $ctx['staff_email']);
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
    $assignedBib = is_array($todayReg) ? resolveStaffDisplayBibNumber($todayReg) : '';
    if ($assignedBib === '' && is_array($todayShift)) {
        $assignedBib = resolveStaffDisplayBibNumber($todayShift);
    }
    ?>
    <header class="es-v3__page-header">
        <h1 class="es-v3__page-title">Check In</h1>
        <p class="es-v3__page-sub">On your own phone — no shared scanner or barcode</p>
    </header>

    <?php if ($assignedBib !== ''): ?>
        <?php renderStaffV3BibBanner($assignedBib); ?>
    <?php endif; ?>

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
                <label class="es-v3__bib-field" for="es-v3-bib-number">
                    <span class="es-v3__bib-field-label">BIB number</span>
                    <input
                        class="es-v3__bib-field-input"
                        type="text"
                        id="es-v3-bib-number"
                        name="bib_number"
                        maxlength="20"
                        inputmode="text"
                        autocomplete="off"
                        autocapitalize="characters"
                        spellcheck="false"
                        required
                        placeholder="Enter your bib number"
                        value="<?= h($assignedBib) ?>"
                    >
                    <?php if ($assignedBib !== ''): ?>
                        <span class="es-v3__bib-field-hint">Confirm or update the number on your bib vest</span>
                    <?php else: ?>
                        <span class="es-v3__bib-field-hint">Enter the number security or your supervisor gave you today</span>
                    <?php endif; ?>
                </label>
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
                    <span>Signed in as <strong><?= h($signedInEmail) ?></strong> — use the same email you registered with.</span>
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
                                <?= h(formatSystemDateTime((string) ($item['checked_in_at'] ?? ''), $pdo)) ?>
                                <?php
                                $histBib = resolveStaffDisplayBibNumber($item);
                                if ($histBib !== ''):
                                ?>
                                    · BIB <?= h($histBib) ?>
                                <?php endif; ?>
                                <?php
                                $historyHours = formatStaffV3HistoryHoursLabel($item, $pdo);
                                if ($historyHours !== ''):
                                ?>
                                    · <?= h($historyHours) ?>
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
    $profileBib = (string) ($ctx['display_bib'] ?? '');
    ?>
    <header class="es-v3__page-header es-v3__page-header--profile">
        <div class="es-ds__profile-hero es-v3__profile-hero">
            <div class="es-v3__avatar es-v3__avatar--lg" aria-hidden="true"><?= h((string) $ctx['avatar_initials']) ?></div>
            <div class="es-ds__profile-hero-text">
                <h1 class="es-v3__page-title"><?= h((string) $ctx['display_name']) ?></h1>
                <p class="es-v3__page-sub"><?= h((string) $ctx['display_role']) ?></p>
                <p class="es-ds__profile-email"><?= h((string) ($portalStaff['email'] ?? '')) ?></p>
            </div>
        </div>
    </header>

    <?php if ($profileBib !== ''): ?>
        <?php renderStaffV3BibBanner($profileBib); ?>
    <?php endif; ?>

    <section class="es-v3__section" aria-labelledby="es-v3-personal-title">
        <h2 id="es-v3-personal-title" class="es-v3__section-title">Profile</h2>
        <div class="es-ds__card es-ds__card--menu">
            <div class="es-v3__menu-row">
                <span>Email</span>
                <strong><?= h((string) ($portalStaff['email'] ?? '')) ?></strong>
            </div>
            <div class="es-v3__menu-row">
                <span>Phone</span>
                <strong><?= h(trim((string) ($portalStaff['phone'] ?? '')) !== '' ? (string) $portalStaff['phone'] : '—') ?></strong>
            </div>
            <a href="<?= h((string) $ctx['profile_edit_url']) ?>" class="es-ds__menu-action">
                <span class="es-ds__menu-action-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                </span>
                <span>Edit profile</span>
            </a>
        </div>
    </section>

    <section class="es-v3__section" id="documents" aria-labelledby="es-v3-docs-title">
        <h2 id="es-v3-docs-title" class="es-v3__section-title">Documents &amp; certificates</h2>
        <div class="es-ds__card es-ds__card--menu">
            <?php if ($docs === []): ?>
                <div class="es-ds__empty es-ds__empty--compact">
                    <span class="es-ds__empty-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
                    </span>
                    <p class="es-ds__empty-title">No documents on file yet</p>
                    <p class="es-ds__empty-text">Upload PSA photos and certificates from your profile.</p>
                </div>
            <?php else: ?>
                <?php foreach ($docs as $doc): ?>
                    <?php $docImageUrl = trim((string) ($doc['image_url'] ?? '')); ?>
                    <div class="es-ds__doc-row">
                        <?php if ($docImageUrl !== ''): ?>
                            <a href="<?= h($docImageUrl) ?>" class="es-ds__doc-thumb-link" target="_blank" rel="noopener" aria-label="View <?= h((string) ($doc['label'] ?? 'document')) ?>">
                                <img class="es-ds__doc-thumb" src="<?= h($docImageUrl) ?>" alt="" width="52" height="52" loading="lazy" decoding="async">
                            </a>
                        <?php else: ?>
                            <span class="es-ds__doc-icon" aria-hidden="true">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
                            </span>
                        <?php endif; ?>
                        <div class="es-ds__doc-copy">
                            <strong><?= h((string) ($doc['label'] ?? 'Document')) ?></strong>
                        </div>
                        <span class="es-v3__badge es-v3__badge--<?= h((string) ($doc['status'] ?? 'muted')) ?>"><?= h(ucfirst((string) ($doc['status'] ?? 'valid'))) ?></span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <a href="<?= h((string) $ctx['profile_edit_url']) ?>" class="es-ds__menu-action">
                <span class="es-ds__menu-action-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                </span>
                <span>Upload or update</span>
            </a>
        </div>
    </section>

    <section class="es-v3__section" aria-labelledby="es-v3-settings-title">
        <h2 id="es-v3-settings-title" class="es-v3__section-title">Settings</h2>
        <div class="es-ds__card es-ds__card--menu">
            <a href="<?= h((string) $ctx['notif_url']) ?>" class="es-ds__settings-row">
                <span class="es-ds__settings-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                </span>
                <span class="es-ds__settings-copy">
                    <strong>Notifications</strong>
                    <span>Shift updates and approvals</span>
                </span>
            </a>
            <a href="<?= h((string) $ctx['register_url']) ?>" class="es-ds__settings-row">
                <span class="es-ds__settings-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                </span>
                <span class="es-ds__settings-copy">
                    <strong>Register for events</strong>
                    <span>Apply for upcoming shifts</span>
                </span>
            </a>
        </div>
    </section>

    <a href="staff-signout.php?return=staff-app.php" class="es-ds__btn es-ds__btn--danger es-ds__btn--block">Sign out</a>

    <?php if ((string) ($ctx['staff_email'] ?? '') !== ''): ?>
        <?php
        require_once __DIR__ . '/components/whatsapp-join.php';
        if (function_exists('renderStaffAppWhatsappSectionBlock')) {
            renderStaffAppWhatsappSectionBlock(
                $ctx['pdo'],
                (string) $ctx['staff_email'],
                ['shift_rows' => $ctx['shift_rows'] ?? []]
            );
        }
        ?>
    <?php endif; ?>
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
            <?php if (trim((string) ($ctx['status_token'] ?? '')) !== ''): ?>
                <input type="hidden" name="status_token" value="<?= h((string) $ctx['status_token']) ?>">
            <?php endif; ?>
            <button type="submit" class="es-ds__btn es-ds__btn--secondary es-ds__btn--block es-v3__notif-mark-read">Mark all as read</button>
        </form>
    <?php endif; ?>

    <section class="es-v3__section" aria-labelledby="es-v3-notif-list-title">
        <h2 id="es-v3-notif-list-title" class="es-v3__section-title">Recent</h2>
        <?php
        require_once __DIR__ . '/components/notification-list.php';
        renderStaffV3NotificationList($notifications, 'No notifications yet. You will see updates here when your application is reviewed.');
        ?>
    </section>

    <?php
    require_once __DIR__ . '/components/whatsapp-join.php';
    if (function_exists('renderStaffAppWhatsappSectionBlock')) {
        renderStaffAppWhatsappSectionBlock(
            $ctx['pdo'],
            (string) ($ctx['staff_email'] ?? ''),
            ['shift_rows' => $ctx['shift_rows'] ?? []]
        );
    }

    $statusToken = trim((string) ($ctx['status_token'] ?? ''));
    if ($statusToken !== ''): ?>
        <p class="es-v3__page-foot"><a href="status.php?token=<?= h(urlencode($statusToken)) ?>">← Back to application status</a></p>
    <?php endif;
    ?>
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
        <textarea class="es-ds__input es-ds__input--textarea es-v3__compose-input" id="body" name="body" rows="3" maxlength="4000" placeholder="Ask about shifts, availability, or event details…" required></textarea>
        <button type="submit" class="es-ds__btn es-ds__btn--primary es-ds__btn--block es-v3__compose-send">Send message</button>
    </form>
    <?php
}

/**
 * Application status page body (status.php) — v3 presentation only.
 *
 * @param array<string, mixed> $ctx
 * @param array<string, mixed> $view
 */
function renderStaffV3StatusPage(array $ctx, array $view): void
{
    $pdo            = $ctx['pdo'];
    $token          = (string) ($view['token'] ?? '');
    $rows           = $view['rows'] ?? [];
    $error          = (string) ($view['error'] ?? '');
    $successMsg     = (string) ($view['success_msg'] ?? '');
    $showLookup     = (bool) ($view['show_lookup'] ?? false);
    $staffRecord    = $view['staff_record'] ?? null;
    $statusFilter   = (string) ($view['status_filter'] ?? '');
    $statusMetrics  = $view['status_metrics'] ?? [];
    $displayRows    = $view['display_rows'] ?? [];
    $profilePageUrl = (string) ($view['profile_page_url'] ?? 'staff-profile.php');

    require_once __DIR__ . '/public/staff-public-shell.php';
    renderStaffFlashBroadcast($pdo);
    ?>
    <div class="es-v3__status-page">
    <header class="es-v3__page-header">
        <h1 class="es-v3__page-title">Application status</h1>
        <p class="es-v3__page-sub">Applications, shifts &amp; check-in</p>
    </header>

    <?php if ($successMsg !== ''): ?>
        <div class="es-v3__alert es-v3__alert--success" role="status"><?= h($successMsg) ?></div>
    <?php endif; ?>

    <?php if ($successMsg !== '' && !$showLookup && $rows !== []): ?>
        <?php require_once __DIR__ . '/public/registration-success-panel.php'; renderRegistrationSuccessPanel($rows); ?>
    <?php endif; ?>

    <?php if ($showLookup): ?>
        <section class="es-ds__card es-v3__status-card">
            <?php if ($error !== ''): ?>
                <div class="es-v3__alert es-v3__alert--error" role="alert"><?= h($error) ?></div>
            <?php else: ?>
                <p class="es-v3__status-intro">Enter the <strong>same email</strong> you used to register, or paste the status link from your email.</p>
            <?php endif; ?>

            <form method="post" class="es-v3__form" action="status.php">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="status_lookup" value="1">

                <label class="es-v3__field-label" for="email">Your email</label>
                <input class="es-ds__input" type="email" id="email" name="email" autocomplete="email" inputmode="email" placeholder="you@example.com" value="<?= h((string) ($_POST['email'] ?? '')) ?>" required>

                <p class="es-v3__status-or">or paste your link</p>

                <label class="es-v3__field-label" for="status_link">Status link from email</label>
                <input class="es-ds__input" type="url" id="status_link" name="status_link" autocomplete="off" placeholder="https://…/status.php?token=…" value="<?= h((string) ($_POST['status_link'] ?? '')) ?>">

                <button type="submit" class="es-ds__btn es-ds__btn--primary es-ds__btn--block">View my status</button>
            </form>

            <p class="es-v3__status-foot">New here? <a href="index.php">Register for an event</a></p>
        </section>
    <?php else: ?>
        <?php
        require_once __DIR__ . '/components/staff-status-dashboard.php';
        renderStaffStatusMetricsDashboard($token, $statusMetrics, $statusFilter);
        renderStaffStatusApplicationsList($displayRows, $pdo, $token, $statusFilter);

        $person = $rows[0];
        $personEmail = strtolower(trim((string) ($person['email'] ?? '')));
        $notifUnread = $personEmail !== '' ? countUnreadStaffNotifications($pdo, $personEmail) : 0;
        ?>
        <?php if ($personEmail !== ''): ?>
            <a href="staff-notifications.php?token=<?= h($token) ?>" class="es-ds__btn es-ds__btn--secondary es-ds__btn--block es-v3__status-notif-btn">
                Notifications<?= $notifUnread > 0 ? ' (' . (int) $notifUnread . ' new)' : '' ?>
            </a>
        <?php endif; ?>

        <?php if ($personEmail !== ''): ?>
            <?php
            require_once __DIR__ . '/components/whatsapp-join.php';
            if (function_exists('renderStaffAppWhatsappSectionBlock')) {
                renderStaffAppWhatsappSectionBlock(
                    $pdo,
                    $personEmail,
                    ['shift_rows' => $rows]
                );
            }
            ?>
        <?php endif; ?>

        <details class="es-v3__status-account">
            <summary>Account &amp; profile details</summary>
            <div class="es-v3__status-account-body">
                <dl class="es-v3__detail-list">
                    <div><dt>Name</dt><dd><?= h($person['first_name'] . ' ' . $person['surname']) ?></dd></div>
                    <div><dt>Email</dt><dd><?= h($person['email']) ?></dd></div>
                </dl>
                <a href="<?= h($profilePageUrl) ?>" class="es-ds__btn es-ds__btn--secondary es-ds__btn--block">Manage profile</a>
                <?php if ($staffRecord !== null): ?>
                    <?php
                    require_once __DIR__ . '/staff-psa.php';
                    $staff = $staffRecord;
                    $psaFlash = $successMsg;
                    if (!staffContextRequiresPsa($staff, $rows)): ?>
                        <p class="es-v3__status-psa-note" role="status">PSA licence is not required for steward roles.</p>
                    <?php elseif ($successMsg !== '' && isStaffPsaComplete($staff)): ?>
                        <p class="es-v3__status-psa-note" role="status">Your PSA licence details are on file from registration.</p>
                    <?php else:
                        include __DIR__ . '/status-psa-form.php';
                    endif; ?>
                <?php endif; ?>
            </div>
        </details>
    <?php endif; ?>

    <p class="es-v3__page-foot"><a href="staff-app.php">← Staff app home</a></p>
    </div>
    <?php
}

/**
 * Profile edit / completion page body (staff-profile.php) — v3 presentation only.
 *
 * @param array<string, mixed> $ctx
 * @param array<string, mixed> $view
 */
function renderStaffV3ProfileEditPage(array $ctx, array $view): void
{
    $staff           = $view['staff'];
    $token           = (string) ($view['token'] ?? '');
    $profileComplete = (bool) ($view['profile_complete'] ?? false);
    $editMode        = (bool) ($view['edit_mode'] ?? false);
    $formOpen        = (bool) ($view['form_open'] ?? true);
    $missingFields   = $view['missing_fields'] ?? [];
    $flash           = $view['flash'] ?? null;
    $fieldErrors     = $view['field_errors'] ?? [];
    $profileMetrics  = $view['profile_metrics'] ?? [];
    $profileStatus   = $view['profile_status'] ?? ['label' => '', 'tone' => 'muted'];
    $profileRole     = (string) ($view['profile_role'] ?? '');
    $profileStaffId  = (string) ($view['profile_staff_id'] ?? '');
    $profileAvatar   = (string) ($view['profile_avatar'] ?? '');
    $profileFullName = (string) ($view['profile_full_name'] ?? '');
    $messagesPageUrl = (string) ($view['messages_page_url'] ?? 'staff-messages.php');
    $staffMsgUnread  = (int) ($view['staff_msg_unread'] ?? 0);
    $defaultPhoneCountry = (string) ($view['default_phone_country'] ?? 'IE');
    $pdo             = $ctx['pdo'];
    require_once __DIR__ . '/registration-forms.php';
    $profileRequiresPsa = staffRoleRequiresPsa(normalizeStaffRole((string) ($staff['staff_role'] ?? '')));
    ?>
    <header class="es-v3__page-header es-v3__page-header--profile">
        <div class="es-ds__profile-hero es-v3__profile-hero">
            <div class="es-v3__avatar es-v3__avatar--lg" aria-hidden="true"><?= h($profileAvatar) ?></div>
            <div class="es-ds__profile-hero-text">
                <h1 class="es-v3__page-title"><?= h($profileFullName) ?></h1>
                <p class="es-v3__page-sub"><?= h($profileRole) ?></p>
                <?php if ($profileStaffId !== ''): ?>
                    <p class="es-ds__profile-email"><?= h($profileStaffId) ?></p>
                <?php endif; ?>
                <span class="es-v3__badge es-v3__badge--<?= h((string) ($profileStatus['tone'] ?? 'muted')) ?>"><?= h((string) ($profileStatus['label'] ?? '')) ?></span>
            </div>
        </div>
        <?php if ($editMode): ?>
            <a href="staff-app.php" class="es-ds__btn es-ds__btn--secondary es-ds__btn--sm">← Back to app</a>
        <?php endif; ?>
    </header>

    <section class="es-v3__section" aria-label="Account details">
        <h2 class="es-v3__section-title">Account details</h2>
        <div class="es-ds__card es-ds__card--menu">
            <div class="es-v3__menu-row"><span>Email</span><strong><?= h((string) $staff['email']) ?></strong></div>
            <div class="es-v3__menu-row"><span>Mobile</span><strong><?= h(trim((string) ($staff['mobile'] ?? '')) !== '' ? (string) $staff['mobile'] : '—') ?></strong></div>
            <div class="es-v3__menu-row"><span>Registration</span><strong><?= $profileComplete ? 'Profile complete' : 'Profile incomplete' ?></strong></div>
            <?php if (!empty($profileMetrics['has_data'])): ?>
                <div class="es-v3__menu-row"><span>Applications</span><strong><?= (int) $profileMetrics['total'] ?> total · <?= (int) $profileMetrics['approved'] ?> approved</strong></div>
            <?php endif; ?>
        </div>
    </section>

    <?php if (trim((string) ($staff['email'] ?? '')) !== ''): ?>
        <section class="es-v3__section" aria-label="Messages">
            <h2 class="es-v3__section-title">Messages</h2>
            <p class="es-v3__field-hint">View replies from your coordinator and send updates about shifts.</p>
            <a href="<?= h($messagesPageUrl) ?>" class="es-ds__btn es-ds__btn--primary es-ds__btn--block">
                Open messages<?= $staffMsgUnread > 0 ? ' (' . (int) $staffMsgUnread . ' unread)' : '' ?>
            </a>
        </section>
    <?php endif; ?>

    <?php if (!$profileComplete): ?>
        <div class="es-v3__alert es-v3__alert--error" role="alert">
            <strong>Profile incomplete</strong>
            <p><?= $profileRequiresPsa
                ? 'Complete all fields before you can view registration status or check in.'
                : 'Complete your personal and payroll details. PSA licence is not required for steward roles.' ?></p>
            <?php if ($missingFields !== []): ?>
                <p><strong>Still needed:</strong> <?= h(implode(', ', $missingFields)) ?></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (is_array($flash) && ($flash['message'] ?? '') !== ''): ?>
        <div class="es-v3__alert es-v3__alert--<?= h((string) ($flash['type'] ?? 'info')) ?>" role="alert"><?= h((string) $flash['message']) ?></div>
    <?php endif; ?>

    <?php if ($editMode && $profileComplete && !$formOpen): ?>
        <section class="es-ds__card es-v3__profile-summary" aria-label="Profile summary">
            <h2 class="es-v3__section-title">Personal details</h2>
            <dl class="es-v3__detail-list">
                <div><dt>Address</dt><dd><?= h((string) ($staff['full_address'] ?? '—')) ?></dd></div>
                <div><dt>PSA licence</dt><dd><?= h((string) ($staff['psa_licence'] ?? '—')) ?></dd></div>
            </dl>
            <a href="staff-profile.php?edit=1&amp;open=1" class="es-ds__btn es-ds__btn--primary es-ds__btn--block">Edit my details</a>
        </section>
    <?php else: ?>
        <form method="post" enctype="multipart/form-data" class="es-v3__form es-v3__profile-form">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">

            <h2 class="es-v3__section-title">Personal information</h2>

            <label class="es-v3__field-label" for="first_name">First name</label>
            <input type="text" name="first_name" id="first_name" class="es-ds__input" value="<?= h((string) $staff['first_name']) ?>" required>

            <label class="es-v3__field-label" for="surname">Last name</label>
            <input type="text" name="surname" id="surname" class="es-ds__input" value="<?= h((string) $staff['surname']) ?>" required>

            <label class="es-v3__field-label">Email</label>
            <input type="email" class="es-ds__input" value="<?= h((string) $staff['email']) ?>" disabled>
            <p class="es-v3__field-hint">Cannot be changed — used when you sign in.</p>

            <label class="es-v3__field-label" for="mobile_national">Mobile</label>
            <?php
            require_once __DIR__ . '/components/phone-input.php';
            renderPhoneInputField([
                'id'         => 'mobile',
                'value'      => (string) ($staff['mobile'] ?? ''),
                'defaultIso' => $defaultPhoneCountry,
                'required'   => true,
            ]);
            ?>

            <label class="es-v3__field-label" for="date_of_birth">Date of birth</label>
            <?php if (trim((string) ($staff['date_of_birth'] ?? '')) === ''): ?>
                <input type="date" name="date_of_birth" id="date_of_birth" class="es-ds__input" required>
            <?php else: ?>
                <input type="date" class="es-ds__input" value="<?= h((string) $staff['date_of_birth']) ?>" disabled>
                <p class="es-v3__field-hint">Locked after first save.</p>
            <?php endif; ?>

            <label class="es-v3__field-label" for="gender">Gender</label>
            <select name="gender" id="gender" class="es-ds__input" required>
                <option value="male" <?= $staff['gender'] === 'male' ? 'selected' : '' ?>>Male</option>
                <option value="female" <?= $staff['gender'] === 'female' ? 'selected' : '' ?>>Female</option>
                <option value="other" <?= $staff['gender'] === 'other' ? 'selected' : '' ?>>Other</option>
                <option value="prefer_not_to_say" <?= $staff['gender'] === 'prefer_not_to_say' ? 'selected' : '' ?>>Prefer not to say</option>
            </select>

            <h2 class="es-v3__section-title">Address</h2>

            <label class="es-v3__field-label" for="full_address">Full address</label>
            <textarea name="full_address" id="full_address" class="es-ds__input es-ds__input--textarea" rows="3" required><?= h((string) $staff['full_address']) ?></textarea>

            <label class="es-v3__field-label" for="eircode">Eircode</label>
            <input type="text" name="eircode" id="eircode" class="es-ds__input" value="<?= h((string) $staff['eircode']) ?>" required>

            <div class="es-v3__payroll-note" role="note">
                <strong>Signed in securely.</strong> Payroll details below are only shown on this official profile page after you sign in — never on the public sign-in screen.
            </div>

            <h2 class="es-v3__section-title">Payroll details</h2>

            <label class="es-v3__field-label" for="pps_number">PPS number</label>
            <input type="text" name="pps_number" id="pps_number" class="es-ds__input" value="<?= h((string) $staff['pps_number']) ?>" required>

            <label class="es-v3__field-label" for="bank_iban">Bank IBAN</label>
            <input type="text" name="bank_iban" id="bank_iban" class="es-ds__input" value="<?= h((string) $staff['bank_iban']) ?>" placeholder="IE29AIBK93115212345678" autocomplete="off" autocapitalize="characters" maxlength="34" required>
            <p class="es-v3__field-hint">IBAN with country code only — not a bank name.</p>

            <?php if ($profileRequiresPsa): ?>
            <h2 class="es-v3__section-title">PSA licence <span class="es-v3__badge es-v3__badge--accent">Required</span></h2>

            <label class="es-v3__field-label" for="psa_licence">PSA licence number</label>
            <input type="text" name="psa_licence" id="psa_licence" class="es-ds__input" value="<?= h((string) ($staff['psa_licence'] ?? '')) ?>" placeholder="EM123456/00" autocomplete="off" autocapitalize="characters" pattern="EM[0-9]{6}/[0-9]{2}" required>
            <p class="es-v3__field-hint">Format EM123456/00 as on your PSA card.</p>

            <label class="es-v3__field-label" for="psa_expiry_date">PSA expiry date</label>
            <input type="date" name="psa_expiry_date" id="psa_expiry_date" class="es-ds__input" value="<?= h((string) ($staff['psa_expiry_date'] ?? '')) ?>" required>

            <label class="es-v3__field-label" for="psa_front_image">PSA card — front photo</label>
            <input type="file" name="psa_front_image" id="psa_front_image" class="es-ds__input" accept="<?= h(psaImageFileAcceptAttribute()) ?>" <?= empty($staff['psa_front_image']) ? 'required' : '' ?>>
            <p class="es-v3__field-hint">JPG, PNG, or photo from your phone (max 8 MB).</p>
            <?php if (isStoredPsaImagePath($staff['psa_front_image'] ?? null)): ?>
                <?php $profilePsaFrontUrl = psaImagePublicUrl((string) $staff['psa_front_image'], $pdo); ?>
                <div class="es-v3__psa-preview">
                    <img src="<?= h($profilePsaFrontUrl) ?>" alt="PSA card front" width="72" height="54" loading="lazy" decoding="async">
                    <p class="es-v3__psa-preview-copy">Current front photo on file. <a href="<?= h($profilePsaFrontUrl) ?>" target="_blank" rel="noopener">Open full size</a></p>
                </div>
            <?php endif; ?>
            <?php if (!empty($fieldErrors['psa_front_image'])): ?>
                <span class="es-v3__field-error"><?= h($fieldErrors['psa_front_image']) ?></span>
            <?php endif; ?>

            <label class="es-v3__field-label" for="psa_back_image">PSA card — back photo</label>
            <input type="file" name="psa_back_image" id="psa_back_image" class="es-ds__input" accept="<?= h(psaImageFileAcceptAttribute()) ?>" <?= empty($staff['psa_back_image']) ? 'required' : '' ?>>
            <p class="es-v3__field-hint">JPG, PNG, or photo from your phone (max 8 MB).</p>
            <?php if (isStoredPsaImagePath($staff['psa_back_image'] ?? null)): ?>
                <?php $profilePsaBackUrl = psaImagePublicUrl((string) $staff['psa_back_image'], $pdo); ?>
                <div class="es-v3__psa-preview">
                    <img src="<?= h($profilePsaBackUrl) ?>" alt="PSA card back" width="72" height="54" loading="lazy" decoding="async">
                    <p class="es-v3__psa-preview-copy">Current back photo on file. <a href="<?= h($profilePsaBackUrl) ?>" target="_blank" rel="noopener">Open full size</a></p>
                </div>
            <?php endif; ?>
            <?php if (!empty($fieldErrors['psa_back_image'])): ?>
                <span class="es-v3__field-error"><?= h($fieldErrors['psa_back_image']) ?></span>
            <?php endif; ?>
            <?php else: ?>
            <p class="es-v3__field-hint" role="note">PSA licence and card photos are not required for steward roles.</p>
            <?php endif; ?>

            <button type="submit" class="es-ds__btn es-ds__btn--primary es-ds__btn--block">Save changes</button>
            <?php if ($editMode && $profileComplete): ?>
                <a href="staff-profile.php?edit=1" class="es-ds__btn es-ds__btn--secondary es-ds__btn--block">Cancel</a>
            <?php endif; ?>
        </form>
    <?php endif; ?>

    <?php if ($token === ''): ?>
        <a href="<?= h(staffPortalSignOutUrl('staff-app.php?signed_out=1')) ?>"
           class="es-ds__btn es-ds__btn--danger es-ds__btn--block"
           id="staff-profile-signout-btn">Sign out</a>
    <?php endif; ?>

    <p class="es-v3__page-foot">
        <a href="staff-app.php">← Staff app home</a>
        <?php if (trim((string) ($staff['email'] ?? '')) !== ''): ?>
            · <a href="<?= h($messagesPageUrl) ?>">Messages<?= $staffMsgUnread > 0 ? ' (' . (int) $staffMsgUnread . ' unread)' : '' ?></a>
        <?php endif; ?>
    </p>
    <?php
}

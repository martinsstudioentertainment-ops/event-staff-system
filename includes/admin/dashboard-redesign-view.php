<?php

declare(strict_types=1);

/** @var array<string, mixed> $dashR */
/** @var PDO $pdo */
/** @var string $dashboardSelf */
/** @var bool $gpsFlagOn */
/** @var bool $gpsCanToggle */
/** @var string $greeting */
/** @var string $firstName */
/** @var array<string, mixed>|null $flash */
/** @var string $assetBase */

$hdr   = $dashR['header'] ?? [];
$fin   = $dashR['finance'] ?? [];
$com   = $dashR['communication'] ?? [];
$hlth  = $dashR['health'] ?? ['score' => 0, 'tone' => 'ok', 'label' => 'Healthy'];
$gps   = $dashR['gps_today'] ?? [];
$reg   = $dashR['registration'] ?? [];
$bars  = $dashR['health_bars'] ?? [];
$pillars = $dashR['pillars'] ?? [];
$todayEvents = $dashR['today_events'] ?? [];

$cssPath = dirname(__DIR__, 2) . '/assets/css/dashboard-redesign.css';
$jsPath  = dirname(__DIR__, 2) . '/assets/js/dashboard-v2.js';
$cssVer  = is_file($cssPath) ? (string) filemtime($cssPath) : '4';
$jsVer   = is_file($jsPath) ? (string) filemtime($jsPath) : '2';
?>
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/dashboard-redesign.css?v=<?= h($cssVer) ?>">

<?php if (!empty($flash)): ?>
<div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<div class="odv3">

<header class="odv3-command" aria-label="Command centre header">
    <div class="odv3-command__row">
        <h1 class="odv3-command__greet"><?= h($greeting) ?>, <?= h($firstName) ?></h1>
        <time class="odv3-command__clock" id="dash-live-clock"><?= h(date('D j M · H:i')) ?></time>
    </div>
    <div class="odv3-command__row odv3-command__row--stats">
        <div class="odv3-command__stats">
            <a href="events.php"><strong data-odv3-count="<?= (int) ($hdr['upcoming_events'] ?? 0) ?>"><?= (int) ($hdr['upcoming_events'] ?? 0) ?></strong> upcoming</a>
            <a href="allocation-centre.php"><strong data-odv3-count="<?= (int) ($hdr['staff_allocated'] ?? 0) ?>"><?= (int) ($hdr['staff_allocated'] ?? 0) ?></strong> allocated</a>
            <a href="attendance.php"><strong data-odv3-count="<?= (int) ($hdr['staff_expected_today'] ?? 0) ?>"><?= (int) ($hdr['staff_expected_today'] ?? 0) ?></strong> expected today</a>
            <a href="#odv3-event-wall" class="odv3-command__alert"><strong data-odv3-count="<?= (int) ($hdr['events_need_attention'] ?? 0) ?>"><?= (int) ($hdr['events_need_attention'] ?? 0) ?></strong> need attention</a>
        </div>
        <div class="odv3-command__status">
            <span class="odv3-status-pill odv3-status-pill--<?= !empty($hdr['gps_active']) ? 'ok' : 'warn' ?>">GPS: <?= h((string) ($hdr['gps_status'] ?? 'Unknown')) ?></span>
            <span class="odv3-status-pill">Attendance: <?= h((string) ($hdr['attendance_status'] ?? 'Monitoring')) ?></span>
            <div class="odv3-gps" id="dash-gps-toggle">
                <?php if ($gpsCanToggle): ?>
                <form method="post" action="<?= h($dashboardSelf) ?>#dash-gps-toggle">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="toggle_gps_attendance">
                    <input type="hidden" name="enabled" value="<?= $gpsFlagOn ? '0' : '1' ?>">
                    <button type="submit" class="odv3-gps__btn odv3-gps__btn--<?= $gpsFlagOn ? 'on' : 'off' ?>">GPS <?= $gpsFlagOn ? 'ON' : 'OFF' ?></button>
                </form>
                <?php else: ?>
                <span class="odv3-gps__btn odv3-gps__btn--<?= $gpsFlagOn ? 'on' : 'off' ?>">GPS <?= $gpsFlagOn ? 'ON' : 'OFF' ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</header>

<section class="odv3-wall" id="odv3-event-wall" aria-labelledby="odv3-wall-title">
    <div class="odv3-wall__head">
        <h2 id="odv3-wall-title" class="odv3-section-title">Today's events</h2>
        <a href="events.php" class="odv3-link">All events →</a>
    </div>
    <?php if ($todayEvents === []): ?>
    <p class="odv3-empty">No events scheduled for today.</p>
    <?php else: ?>
    <div class="odv3-wall__track">
        <?php foreach ($todayEvents as $ev): ?>
        <a href="<?= h((string) ($ev['href'] ?? '#')) ?>" class="odv3-event-card odv3-event-card--<?= h((string) ($ev['status_tone'] ?? 'ok')) ?>">
            <div class="odv3-event-card__top">
                <strong class="odv3-event-card__name"><?= h((string) ($ev['name'] ?? '')) ?></strong>
                <span class="odv3-event-card__badge"><?= h((string) ($ev['status'] ?? '')) ?></span>
            </div>
            <span class="odv3-event-card__venue"><?= h((string) ($ev['venue'] ?? '')) ?></span>
            <div class="odv3-event-card__metrics">
                <span>Req <b><?= (int) ($ev['needed'] ?? 0) ?></b></span>
                <span>Alloc <b><?= (int) ($ev['allocated'] ?? 0) ?></b></span>
                <?php if ((int) ($ev['remaining'] ?? 0) > 0): ?>
                <span class="odv3-event-card__missing">−<b><?= (int) ($ev['remaining'] ?? 0) ?></b></span>
                <?php endif; ?>
            </div>
            <span class="odv3-bar"><span class="odv3-bar__fill" style="width:<?= (int) ($ev['progress'] ?? 0) ?>%"></span></span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>

<section class="odv3-pillars" aria-label="Executive KPIs">
    <?php foreach ($pillars as $pillar):
        if (!is_array($pillar)) continue;
    ?>
    <article class="odv3-pillar">
        <a href="<?= h((string) ($pillar['href'] ?? '#')) ?>" class="odv3-pillar__title"><?= h((string) ($pillar['label'] ?? '')) ?></a>
        <div class="odv3-pillar__metrics">
            <?php foreach (($pillar['items'] ?? []) as $item): ?>
            <a href="<?= h((string) ($item['href'] ?? '#')) ?>" class="odv3-pillar__metric" title="<?= h((string) ($item['label'] ?? '')) ?>">
                <strong><?= !empty($item['is_text']) ? h((string) ($item['value'] ?? '')) : (int) ($item['value'] ?? 0) ?></strong>
                <span><?= h((string) ($item['label'] ?? '')) ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </article>
    <?php endforeach; ?>
</section>

<div class="odv3-layout">

<div class="odv3-main">

<div class="odv3-ops-row">
<section class="odv3-panel odv3-panel--tight">
    <div class="odv3-panel__head">
        <h2 class="odv3-section-title">Operations health</h2>
        <span class="odv3-health-score odv3-health-score--<?= h((string) ($hlth['tone'] ?? 'ok')) ?>"><?= (int) ($hlth['score'] ?? 0) ?>%</span>
    </div>
    <div class="odv3-health-bars">
        <?php foreach ($bars as $bar): ?>
        <div class="odv3-hbar" title="<?= h((string) ($bar['label'] ?? '')) ?>">
            <span class="odv3-hbar__pct"><?= (int) ($bar['score'] ?? 0) ?>%</span>
            <span class="odv3-hbar__track"><span class="odv3-hbar__fill odv3-hbar__fill--<?= h((string) ($bar['tone'] ?? 'ok')) ?>" style="width:<?= (int) ($bar['score'] ?? 0) ?>%"></span></span>
            <span class="odv3-hbar__name"><?= h((string) ($bar['label'] ?? '')) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<?php if (adminCan('attendance')): ?>
<section class="odv3-panel odv3-panel--tight">
    <div class="odv3-panel__head">
        <h2 class="odv3-section-title">GPS command</h2>
        <a href="geo-audits.php" class="odv3-link">Audits →</a>
    </div>
    <div class="odv3-gps-compact">
        <div class="odv3-gps-ring" style="--odv3-ring:<?= (int) ($gps['compliance_pct'] ?? 0) ?>;--odv3-ring-color:<?= h(dash_r_ring_color((int) ($gps['compliance_pct'] ?? 0) >= 90 ? 'ok' : ((int) ($gps['compliance_pct'] ?? 0) >= 70 ? 'warn' : 'critical'))) ?>">
            <span class="odv3-gps-ring__val"><?= h(dash_r_format_pct((float) ($gps['compliance_pct'] ?? 0))) ?></span>
        </div>
        <dl class="odv3-gps-stats">
            <div><dt>Expected</dt><dd><?= (int) ($gps['expected'] ?? 0) ?></dd></div>
            <div><dt>Checked in</dt><dd><?= (int) ($gps['checked_in'] ?? 0) ?></dd></div>
            <div class="is-ok"><dt>Verified</dt><dd><?= (int) ($gps['gps_verified'] ?? 0) ?></dd></div>
            <div><dt>Override</dt><dd><?= (int) ($gps['manual_override'] ?? 0) ?></dd></div>
            <div class="is-bad"><dt>Failed</dt><dd><?= (int) ($gps['failed_gps'] ?? 0) ?></dd></div>
            <div><dt>Outside</dt><dd><?= (int) ($gps['outside_venue'] ?? 0) ?></dd></div>
        </dl>
    </div>
</section>
<?php endif; ?>
</div>

<section class="odv3-panel odv3-panel--tight">
    <div class="odv3-panel__head">
        <h2 class="odv3-section-title">Recent activity</h2>
        <?php if (adminCan('audit')): ?><a href="audit-log.php" class="odv3-link">View all →</a><?php endif; ?>
    </div>
    <?php if (($dashR['recent_activity'] ?? []) === []): ?>
    <p class="odv3-empty">No recent activity yet.</p>
    <?php else: ?>
    <ul class="odv3-timeline odv3-timeline--scroll">
        <?php foreach ($dashR['recent_activity'] as $act): ?>
        <li class="odv3-timeline__item odv3-timeline__item--<?= h((string) ($act['kind'] ?? 'update')) ?>">
            <time><?= h((string) ($act['time'] ?? '')) ?></time>
            <p><?= h((string) ($act['message'] ?? '')) ?></p>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</section>

</div>

<aside class="odv3-rail">

<section class="odv3-panel odv3-panel--tight odv3-panel--action">
    <h2 class="odv3-section-title">Action required</h2>
    <?php if (($dashR['operations'] ?? []) === []): ?>
    <p class="odv3-empty odv3-empty--ok">All clear.</p>
    <?php else: ?>
    <ul class="odv3-actions-list odv3-actions-list--scroll">
        <?php foreach ($dashR['operations'] as $op): ?>
        <li>
            <a href="<?= h((string) ($op['href'] ?? '#')) ?>" class="odv3-action-item odv3-action-item--<?= h((string) ($op['severity'] ?? 'warn')) ?>">
                <span class="odv3-action-item__head">
                    <span class="odv3-action-item__pri"><?= h((string) ($op['priority'] ?? 'Alert')) ?></span>
                    <strong><?= h((string) ($op['title'] ?? '')) ?></strong>
                </span>
                <span class="odv3-action-item__cta"><?= h((string) ($op['action'] ?? 'View')) ?> →</span>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</section>

<div class="odv3-rail-grid">
<?php if (adminCan('staff')): ?>
<section class="odv3-panel odv3-panel--tight">
    <h2 class="odv3-section-title">Registrations</h2>
    <div class="odv3-mini-stats">
        <div><strong><?= (int) ($reg['today'] ?? 0) ?></strong><span>New</span></div>
        <div class="is-warn"><strong><?= (int) ($reg['pending'] ?? 0) ?></strong><span>Pending</span></div>
        <div class="is-ok"><strong><?= (int) ($reg['approved_today'] ?? 0) ?></strong><span>Approved</span></div>
        <div><strong><?= (int) ($reg['rejected_today'] ?? 0) ?></strong><span>Rejected</span></div>
    </div>
    <a href="staff.php" class="odv3-btn odv3-btn--sm">View →</a>
</section>
<?php endif; ?>

<section class="odv3-panel odv3-panel--tight">
    <h2 class="odv3-section-title">Communications</h2>
    <div class="odv3-mini-stats">
        <div class="is-warn"><strong><?= (int) ($com['unread_messages'] ?? 0) ?></strong><span>Unread</span></div>
        <div><strong><?= (int) ($com['awaiting_reply'] ?? 0) ?></strong><span>Reply</span></div>
        <div class="is-warn"><strong><?= (int) ($com['failed_emails'] ?? 0) ?></strong><span>Failed</span></div>
        <div><strong><?= (int) ($com['broadcasts_sent_today'] ?? 0) ?></strong><span>Sent</span></div>
    </div>
    <div class="odv3-btn-row">
        <a href="staff-inbox.php" class="odv3-btn odv3-btn--sm">Inbox</a>
        <a href="staff-message.php" class="odv3-btn odv3-btn--sm odv3-btn--primary">Compose</a>
    </div>
</section>

<?php if (adminCan('invoices') && !empty($fin['available'])): ?>
<section class="odv3-panel odv3-panel--tight">
    <h2 class="odv3-section-title">Finance</h2>
    <dl class="odv3-finance odv3-finance--compact">
        <div><dt>Outstanding</dt><dd><?= (int) ($fin['outstanding_count'] ?? 0) ?></dd></div>
        <div class="is-warn"><dt>Overdue</dt><dd><?= (int) ($fin['overdue_count'] ?? 0) ?></dd></div>
        <div class="is-ok"><dt>Month</dt><dd><?= h(formatSystemCurrencyAmount((float) ($fin['revenue_month'] ?? 0), $pdo)) ?></dd></div>
        <div class="is-ok"><dt>Year</dt><dd><?= h(formatSystemCurrencyAmount((float) ($fin['revenue_year'] ?? 0), $pdo)) ?></dd></div>
    </dl>
    <a href="invoices.php" class="odv3-btn odv3-btn--sm">Finance →</a>
</section>
<?php endif; ?>
</div>

<section class="odv3-panel odv3-panel--tight">
    <h2 class="odv3-section-title">Quick actions</h2>
    <div class="odv3-quick">
        <?php
        $quick = [
            ['href' => 'event-form.php', 'label' => 'Add event', 'icon' => '+', 'cap' => 'events', 'primary' => true],
            ['href' => 'allocation-centre.php', 'label' => 'Assign', 'icon' => '◆', 'cap' => 'staff'],
            ['href' => 'staff-message.php', 'label' => 'Message', 'icon' => '✉', 'cap' => 'staff'],
            ['href' => 'invoice-form.php', 'label' => 'Invoice', 'icon' => '€', 'cap' => 'invoices'],
            ['href' => 'venues.php', 'label' => 'Venue', 'icon' => '⌂', 'cap' => 'events'],
            ['href' => 'event-rostering.php', 'label' => 'Roster', 'icon' => '☰', 'cap' => 'events'],
            ['href' => 'communication-hub.php', 'label' => 'Comms hub', 'icon' => '◎', 'cap' => 'staff'],
            ['href' => 'allocation-centre.php', 'label' => 'Allocation', 'icon' => '⇄', 'cap' => 'staff'],
        ];
        foreach ($quick as $q):
            if (!adminCan((string) $q['cap'])) continue;
        ?>
        <a href="<?= h((string) $q['href']) ?>" class="odv3-quick__btn<?= !empty($q['primary']) ? ' odv3-quick__btn--primary' : '' ?>" title="<?= h((string) $q['label']) ?>">
            <span class="odv3-quick__icon" aria-hidden="true"><?= h((string) $q['icon']) ?></span>
            <span class="odv3-quick__label"><?= h((string) $q['label']) ?></span>
        </a>
        <?php endforeach; ?>
    </div>
</section>

</aside>
</div>
</div>

<script src="<?= h($assetBase) ?>assets/js/dashboard-v2.js?v=<?= h($jsVer) ?>"></script>

<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/staff-portal-dashboard.php';
require_once dirname(__DIR__) . '/attendance-repository.php';
require_once dirname(__DIR__) . '/staff-app-v3-data.php';
require_once dirname(__DIR__) . '/date-format.php';

/**
 * @param array{total: int, approved: int, pending: int, rejected: int, upcoming: int, completed: int, checked_in: int, has_data: bool} $metrics
 */
function renderStaffStatusMetricsDashboard(string $token, array $metrics, string $activeFilter = ''): void
{
    $activeFilter = strtolower(trim($activeFilter));
    $cards = [
        ['key' => 'all',       'label' => 'Total',     'val' => $metrics['total'],     'tone' => 'total'],
        ['key' => 'approved',  'label' => 'Approved',  'val' => $metrics['approved'],  'tone' => 'approved'],
        ['key' => 'pending',   'label' => 'Pending',   'val' => $metrics['pending'],   'tone' => 'pending'],
        ['key' => 'rejected',  'label' => 'Rejected',  'val' => $metrics['rejected'],  'tone' => 'rejected'],
        ['key' => 'upcoming',  'label' => 'Upcoming',  'val' => $metrics['upcoming'],  'tone' => 'upcoming'],
        ['key' => 'completed', 'label' => 'Completed', 'val' => $metrics['completed'], 'tone' => 'completed'],
    ];
    ?>
    <section class="status-dash__metrics es-v3__section" aria-label="Status summary">
        <h2 class="es-v3__section-title status-dash__section-title">Status dashboard</h2>
        <div class="status-dash__metric-grid es-v3__stats es-v3__stats--status">
            <?php foreach ($cards as $card): ?>
                <?php
                $isActive = ($activeFilter === '' && $card['key'] === 'all')
                    || ($activeFilter === $card['key']);
                $href = buildStaffStatusPageUrl($token, $card['key'] === 'all' ? '' : $card['key']);
                ?>
                <a href="<?= h($href) ?>"
                   class="status-dash__metric es-v3__stat-card status-dash__metric--<?= h($card['tone']) ?><?= $isActive ? ' status-dash__metric--active es-v3__stat-card--active' : '' ?>"
                   <?= $isActive ? 'aria-current="true"' : '' ?>>
                    <span class="status-dash__metric-val es-v3__stat-val"><?= (int) $card['val'] ?></span>
                    <span class="status-dash__metric-label es-v3__stat-label"><?= h($card['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        <?php if ((int) ($metrics['checked_in'] ?? 0) > 0): ?>
            <p class="status-dash__checkin-note"><?= (int) $metrics['checked_in'] ?> venue check-in<?= (int) $metrics['checked_in'] === 1 ? '' : 's' ?> on record</p>
        <?php endif; ?>
    </section>
    <?php
}

/**
 * @param array<int, array<string, mixed>> $rows
 */
function renderStaffStatusApplicationsList(array $rows, PDO $pdo, string $token, string $activeFilter): void
{
    $filterLabel = match (strtolower(trim($activeFilter))) {
        'approved'  => 'Approved applications',
        'pending'   => 'Pending applications',
        'rejected'  => 'Rejected applications',
        'upcoming'  => 'Upcoming events',
        'completed' => 'Completed events',
        default     => 'All event applications',
    };
    ?>
    <section class="status-dash__applications es-v3__section" aria-label="Event applications">
        <div class="status-dash__applications-head">
            <h2 class="es-v3__section-title status-dash__section-title"><?= h($filterLabel) ?></h2>
            <?php if ($activeFilter !== '' && $activeFilter !== 'all'): ?>
                <a href="<?= h(buildStaffStatusPageUrl($token)) ?>" class="status-dash__clear-filter">← All applications</a>
            <?php endif; ?>
        </div>

        <?php if ($rows === []): ?>
            <div class="es-ds__empty status-dash__empty">
                <p>No applications match this filter.</p>
            </div>
        <?php else: ?>
            <div class="status-dash__app-list es-v3__shift-list">
                <?php foreach ($rows as $row): ?>
                    <?php $shiftOutcome = resolveStaffShiftOutcomeMeta($row); ?>
                    <article class="status-dash__app-card es-ds__card es-v3__shift-card">
                        <div class="status-dash__app-top es-v3__shift-card-top">
                            <h3 class="status-dash__app-title es-v3__shift-location"><?= h((string) ($row['event_name'] ?? 'Event')) ?></h3>
                            <span class="es-v3__badge es-v3__badge--<?= h($shiftOutcome['tone']) ?>"><?= h($shiftOutcome['label']) ?></span>
                        </div>
                        <dl class="status-dash__app-meta es-v3__status-meta">
                            <div class="es-v3__status-meta-row">
                                <dt>Shift</dt>
                                <dd><?= h(formatStaffStatusShiftLabel($row)) ?></dd>
                            </div>
                            <div class="es-v3__status-meta-row">
                                <dt>Venue</dt>
                                <dd><?= h(formatStaffStatusVenueLabel($row)) ?></dd>
                            </div>
                            <div class="es-v3__status-meta-row">
                                <dt>Date</dt>
                                <dd><?= h(!empty($row['event_date']) ? formatEventDateLabel((string) $row['event_date']) : '—') ?></dd>
                            </div>
                        </dl>
                        <div class="status-dash__app-footer">
                        <?php if ((string) ($row['status'] ?? '') === 'approved'): ?>
                            <?php if (registrationHadVenueCheckin($row)): ?>
                                <?php $checkinLabel = formatAttendanceCheckinDateTime($row, $pdo); ?>
                                <p class="status-dash__app-action status-dash__app-action--done">
                                    <span class="es-v3__badge es-v3__badge--success">Checked in</span>
                                    <?= h($checkinLabel !== '' ? $checkinLabel : formatSystemDateTime((string) ($row['checked_in_at'] ?? ''), $pdo)) ?>
                                </p>
                            <?php else: ?>
                                <?php
                                $checkinToken = ensureCheckinToken($pdo, (int) $row['id']);
                                $checkinUrl   = $checkinToken ? getCheckinUrl($checkinToken, $pdo) : '';
                                ?>
                                <?php if ($checkinUrl !== ''): ?>
                                    <a href="<?= h($checkinUrl) ?>" class="es-ds__btn es-ds__btn--primary es-ds__btn--block status-dash__checkin-btn">Check in for this event</a>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php elseif ((string) ($row['status'] ?? '') === 'pending'): ?>
                            <p class="status-dash__app-action">Awaiting admin approval</p>
                        <?php else: ?>
                            <p class="status-dash__app-action">This application was not approved</p>
                        <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php
}

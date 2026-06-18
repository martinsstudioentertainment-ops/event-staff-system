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
    <section class="status-dash__metrics" aria-label="Status summary">
        <h2 class="status-dash__section-title">Status dashboard</h2>
        <div class="status-dash__metric-grid">
            <?php foreach ($cards as $card): ?>
                <?php
                $isActive = ($activeFilter === '' && $card['key'] === 'all')
                    || ($activeFilter === $card['key']);
                $href = buildStaffStatusPageUrl($token, $card['key'] === 'all' ? '' : $card['key']);
                ?>
                <a href="<?= h($href) ?>"
                   class="status-dash__metric status-dash__metric--<?= h($card['tone']) ?><?= $isActive ? ' status-dash__metric--active' : '' ?>"
                   <?= $isActive ? 'aria-current="true"' : '' ?>>
                    <span class="status-dash__metric-val"><?= (int) $card['val'] ?></span>
                    <span class="status-dash__metric-label"><?= h($card['label']) ?></span>
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
    <section class="status-dash__applications" aria-label="Event applications">
        <div class="status-dash__applications-head">
            <h2 class="status-dash__section-title"><?= h($filterLabel) ?></h2>
            <?php if ($activeFilter !== '' && $activeFilter !== 'all'): ?>
                <a href="<?= h(buildStaffStatusPageUrl($token)) ?>" class="status-dash__clear-filter">← All applications</a>
            <?php endif; ?>
        </div>

        <?php if ($rows === []): ?>
            <p class="status-dash__empty">No applications match this filter.</p>
        <?php else: ?>
            <div class="status-dash__app-list">
                <?php foreach ($rows as $row): ?>
                    <article class="status-dash__app-card">
                        <div class="status-dash__app-top">
                            <h3 class="status-dash__app-title"><?= h((string) ($row['event_name'] ?? 'Event')) ?></h3>
                            <?php $shiftOutcome = resolveStaffShiftOutcomeMeta($row); ?>
                            <span class="badge badge--<?= h($shiftOutcome['badge']) ?>"><?= h($shiftOutcome['label']) ?></span>
                        </div>
                        <dl class="status-dash__app-meta">
                            <div><dt>Shift</dt><dd><?= h(formatStaffStatusShiftLabel($row)) ?></dd></div>
                            <div><dt>Venue</dt><dd><?= h(formatStaffStatusVenueLabel($row)) ?></dd></div>
                            <div><dt>Date</dt><dd><?= h(!empty($row['event_date']) ? formatEventDateLabel((string) $row['event_date']) : '—') ?></dd></div>
                        </dl>
                        <?php if ((string) ($row['status'] ?? '') === 'approved'): ?>
                            <?php if ((int) ($row['is_checked_in'] ?? 0) === 1): ?>
                                <p class="status-dash__app-action status-dash__app-action--done">Checked in <?= h(formatSystemDateTime((string) ($row['checked_in_at'] ?? ''), $pdo)) ?></p>
                            <?php else: ?>
                                <?php
                                $checkinToken = ensureCheckinToken($pdo, (int) $row['id']);
                                $checkinUrl   = $checkinToken ? getCheckinUrl($checkinToken, $pdo) : '';
                                ?>
                                <?php if ($checkinUrl !== ''): ?>
                                    <a href="<?= h($checkinUrl) ?>" class="btn btn--primary btn--block status-dash__checkin-btn">Check in for this event</a>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php elseif ((string) ($row['status'] ?? '') === 'pending'): ?>
                            <p class="status-dash__app-action">Awaiting admin approval</p>
                        <?php else: ?>
                            <p class="status-dash__app-action">This application was not approved</p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php
}

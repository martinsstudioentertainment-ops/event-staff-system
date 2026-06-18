<?php

declare(strict_types=1);

/**
 * Dashboard widget — Registration funnel metrics (wizard analytics).
 *
 * @param PDO|null $pdo
 * @param array{compact?: bool} $options
 */
function renderRegistrationFunnelWidget(?PDO $pdo, array $options = []): void
{
    if (!adminCan('dashboard')) {
        return;
    }

    $compact = !empty($options['compact']);

    require_once __DIR__ . '/../registration-analytics.php';

    $metrics = getRegistrationFunnelMetrics($pdo);
    $flagOn  = !empty($metrics['flag_enabled']);
    ?>
    <section class="card erp-dash-panel erp-dash-panel--wide<?= $compact ? ' erp-funnel--compact' : '' ?><?= !empty($options['mockup']) ? ' erp-funnel--mockup' : '' ?>" id="registration-funnel-widget">
        <div class="card__header card__header--row">
            <div>
                <h2 class="card__title">Registration funnel</h2>
                <p class="card__subtitle">
                    Wizard conversion metrics
                    <?php if ($metrics['updated_at'] !== ''): ?>
                        · updated <?= h(formatRelativeTime($metrics['updated_at'])) ?>
                    <?php endif; ?>
                </p>
            </div>
            <?php if (isAdminSuperUser()): ?>
                <a href="feature-flags.php" class="btn btn--secondary btn--small">Feature flags</a>
            <?php endif; ?>
        </div>

        <?php if (!$flagOn): ?>
            <p class="data-table__empty" style="padding:0.5rem 0 0">
                <code>feature_registration_wizard_v2</code> is OFF — legacy form active. Enable the flag to collect funnel analytics.
            </p>
        <?php elseif ((int) ($metrics['started'] ?? 0) === 0): ?>
            <p class="data-table__empty" style="padding:0.5rem 0 0">
                No wizard sessions yet. Metrics appear when registrants use the wizard (flag ON).
            </p>
        <?php else: ?>
            <div class="erp-dash-kpis erp-dash-kpis--compact">
                <div class="erp-dash-kpi">
                    <div class="erp-dash-kpi__body">
                        <p class="erp-dash-kpi__value"><?= (int) $metrics['started'] ?></p>
                        <p class="erp-dash-kpi__label">Started</p>
                    </div>
                </div>
                <div class="erp-dash-kpi">
                    <div class="erp-dash-kpi__body">
                        <p class="erp-dash-kpi__value"><?= (int) $metrics['submitted'] ?></p>
                        <p class="erp-dash-kpi__label">Submitted</p>
                    </div>
                </div>
                <div class="erp-dash-kpi">
                    <div class="erp-dash-kpi__body">
                        <p class="erp-dash-kpi__value"><?= (float) ($metrics['completion_rate'] ?? 0) ?>%</p>
                        <p class="erp-dash-kpi__label">Conversion</p>
                    </div>
                </div>
                <div class="erp-dash-kpi">
                    <div class="erp-dash-kpi__body">
                        <p class="erp-dash-kpi__value"><?= (int) $metrics['abandoned'] ?></p>
                        <p class="erp-dash-kpi__label">Abandoned</p>
                    </div>
                </div>
            </div>

            <?php if (!$compact): ?>
            <h3 class="card__subtitle erp-funnel__section-title">Conversion funnel</h3>
            <?php endif; ?>
            <?php
            $conv = $metrics['conversions'] ?? [];
            $barRows = [
                ['Started (wizard)', (int) ($metrics['started'] ?? 0), 100.0],
                ['Step 1 → Step 2', (int) (($conv['step1_to_step2']['count'] ?? 0)), (float) (($conv['step1_to_step2']['rate'] ?? 0))],
                ['Step 2 → Step 8', (int) (($conv['step2_to_step8']['count'] ?? 0)), (float) (($conv['step2_to_step8']['rate'] ?? 0))],
                ['Step 8 → Submit', (int) (($conv['step8_to_submit']['count'] ?? 0)), (float) (($conv['step8_to_submit']['rate'] ?? 0))],
                ['Submitted', (int) ($metrics['submitted'] ?? 0), (float) ($metrics['completion_rate'] ?? 0)],
            ];
            $maxBarCount = max(1, ...array_map(static fn ($r) => $r[1], $barRows));
            if (!empty($options['mockup'])):
            ?>
            <div class="s7-funnel-bars">
                <?php foreach ($barRows as [$label, $count, $rate]):
                    $width = min(100, max(4, (int) round(($count / $maxBarCount) * 100)));
                    ?>
                <div class="s7-funnel-bar">
                    <span class="s7-funnel-bar__label"><?= h($label) ?></span>
                    <div class="s7-funnel-bar__track"><div class="s7-funnel-bar__fill" style="width:<?= $width ?>%"></div></div>
                    <span class="s7-funnel-bar__count"><?= $count ?></span>
                    <span class="s7-funnel-bar__rate"><?= number_format($rate, 1) ?>%</span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="table-wrap erp-funnel__table-wrap">
                <table class="data-table data-table--compact">
                    <thead>
                        <tr>
                            <th>Stage</th>
                            <th>Count</th>
                            <th>Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $conv = $metrics['conversions'] ?? [];
                        $rows = [
                            ['Step 1 → Step 2', $conv['step1_to_step2'] ?? ['count' => 0, 'rate' => 0]],
                            ['Step 2 → Step 8', $conv['step2_to_step8'] ?? ['count' => 0, 'rate' => 0]],
                            ['Step 8 → Submit', $conv['step8_to_submit'] ?? ['count' => 0, 'rate' => 0]],
                        ];
                        foreach ($rows as [$label, $row]):
                            ?>
                            <tr>
                                <td><?= h($label) ?></td>
                                <td><?= (int) ($row['count'] ?? 0) ?></td>
                                <td><?= (float) ($row['rate'] ?? 0) ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php
            $returning = $metrics['returning'] ?? [];
            $returningTotal = array_sum(array_map('intval', $returning));
            if (!$compact && $returningTotal > 0):
                ?>
                <h3 class="card__subtitle erp-funnel__section-title">Returning user flow</h3>
                <div class="erp-dash-kpis erp-dash-kpis--compact">
                    <div class="erp-dash-kpi">
                        <div class="erp-dash-kpi__body">
                            <p class="erp-dash-kpi__value"><?= (int) ($returning['returning_user_detected'] ?? 0) ?></p>
                            <p class="erp-dash-kpi__label">Detected</p>
                        </div>
                    </div>
                    <div class="erp-dash-kpi">
                        <div class="erp-dash-kpi__body">
                            <p class="erp-dash-kpi__value"><?= (int) ($returning['profile_prefilled'] ?? 0) ?></p>
                            <p class="erp-dash-kpi__label">Prefilled</p>
                        </div>
                    </div>
                    <div class="erp-dash-kpi">
                        <div class="erp-dash-kpi__body">
                            <p class="erp-dash-kpi__value"><?= (int) ($returning['resume_selected'] ?? 0) ?></p>
                            <p class="erp-dash-kpi__label">Resumed</p>
                        </div>
                    </div>
                    <div class="erp-dash-kpi">
                        <div class="erp-dash-kpi__body">
                            <p class="erp-dash-kpi__value"><?= (int) ($returning['new_application_started'] ?? 0) ?></p>
                            <p class="erp-dash-kpi__label">New app</p>
                        </div>
                    </div>
                    <div class="erp-dash-kpi">
                        <div class="erp-dash-kpi__body">
                            <p class="erp-dash-kpi__value"><?= (int) ($returning['duplicate_application_prevented'] ?? 0) ?></p>
                            <p class="erp-dash-kpi__label">Dup blocked</p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!$compact && !empty($metrics['top_events'])): ?>
                <h3 class="card__subtitle erp-funnel__section-title">Most selected events</h3>
                <div class="table-wrap">
                    <table class="data-table data-table--compact">
                        <thead>
                            <tr>
                                <th>Event</th>
                                <th>Selections</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($metrics['top_events'] as $event): ?>
                                <tr>
                                    <td><?= h((string) ($event['name'] ?? '')) ?></td>
                                    <td><?= (int) ($event['count'] ?? 0) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>
    <?php
}

/**
 * @param string $iso8601
 */
function formatRelativeTime(string $iso8601): string
{
    $ts = strtotime($iso8601);
    if ($ts === false) {
        return $iso8601;
    }

    $diff = time() - $ts;
    if ($diff < 60) {
        return 'just now';
    }
    if ($diff < 3600) {
        return (int) floor($diff / 60) . 'm ago';
    }
    if ($diff < 86400) {
        return (int) floor($diff / 3600) . 'h ago';
    }

    return date('j M Y H:i', $ts);
}

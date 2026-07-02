<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/website-visitor-tracking.php';
require_once __DIR__ . '/../includes/admin-pagination.php';

requireAdminCapability('audit');

$pdo = getDB();
ensureWebsiteVisitSchema($pdo);

$filters = [
    'site_area' => trim((string) ($_GET['site_area'] ?? '')),
    'country'   => trim((string) ($_GET['country'] ?? '')),
    'q'         => trim((string) ($_GET['q'] ?? '')),
    'from'      => trim((string) ($_GET['from'] ?? '')),
    'to'        => trim((string) ($_GET['to'] ?? '')),
];

$page    = adminListPage();
$perPage = adminListPerPage();
$offset  = adminListOffset($page);
$total   = countWebsiteVisits($pdo, $filters);
$rows    = getWebsiteVisits($pdo, $filters, $perPage, $offset);
$summary = getWebsiteVisitSummary($pdo, $filters);

$paginationQuery = array_filter([
    'site_area' => $filters['site_area'] !== '' ? $filters['site_area'] : null,
    'country'   => $filters['country'] !== '' ? $filters['country'] : null,
    'q'         => $filters['q'] !== '' ? $filters['q'] : null,
    'from'      => $filters['from'] !== '' ? $filters['from'] : null,
    'to'        => $filters['to'] !== '' ? $filters['to'] : null,
]);

$pageTitle  = 'Visitor locations';
$activePage = 'visitor-locations';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<section class="card erp-card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Website visitor locations</h2>
            <p class="card__subtitle">Everyone who loaded a public page on your marketing site, registration form, or staff app. Location is estimated from IP address (not GPS).</p>
        </div>
        <a href="geo-audits.php" class="btn btn--secondary">Admin logins &amp; check-ins</a>
    </div>

    <div class="stat-grid stat-grid--compact">
        <div class="stat-card">
            <p class="stat-card__value"><?= (int) $summary['total'] ?></p>
            <p class="stat-card__label">Page views (filtered)</p>
        </div>
        <div class="stat-card">
            <p class="stat-card__value"><?= (int) $summary['unique_today'] ?></p>
            <p class="stat-card__label">Unique visitors today</p>
        </div>
    </div>

    <?php if ($summary['top_countries'] !== []): ?>
        <p class="form-hint form-group--full">
            <strong>Top countries:</strong>
            <?php
            $bits = [];
            foreach ($summary['top_countries'] as $c) {
                $bits[] = h((string) $c['country']) . ' (' . (int) $c['visits'] . ')';
            }
            echo implode(' · ', $bits);
            ?>
        </p>
    <?php endif; ?>

    <form method="get" class="filter-bar filter-bar--compact">
        <div class="filter-bar__group">
            <select name="site_area" class="form-select">
                <option value="">All sites</option>
                <option value="marketing"<?= $filters['site_area'] === 'marketing' ? ' selected' : '' ?>>Marketing (olasentra.com)</option>
                <option value="registration"<?= $filters['site_area'] === 'registration' ? ' selected' : '' ?>>Registration</option>
                <option value="staff"<?= $filters['site_area'] === 'staff' ? ' selected' : '' ?>>Staff app / portal</option>
            </select>
        </div>
        <div class="filter-bar__group">
            <input type="search" name="q" class="form-input" placeholder="Search IP, city, page…" value="<?= h($filters['q']) ?>">
        </div>
        <div class="filter-bar__group">
            <input type="text" name="country" class="form-input" placeholder="Country" value="<?= h($filters['country']) ?>">
        </div>
        <div class="filter-bar__group">
            <input type="date" name="from" class="form-input" value="<?= h($filters['from']) ?>" aria-label="From date">
        </div>
        <div class="filter-bar__group">
            <input type="date" name="to" class="form-input" value="<?= h($filters['to']) ?>" aria-label="To date">
        </div>
        <div class="filter-bar__actions">
            <button type="submit" class="btn btn--primary">Filter</button>
            <a href="visitor-locations.php" class="btn btn--secondary">Clear</a>
        </div>
    </form>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>When</th>
                    <th>Location</th>
                    <th>IP</th>
                    <th>Site</th>
                    <th>Page</th>
                    <th>Referrer</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr>
                        <td colspan="6" class="data-table__empty">
                            No visits logged yet. Views are recorded when someone opens a public page (not admin).
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= h(formatSystemDateTime((string) $row['visited_at'], $pdo)) ?></td>
                            <td><?= h(formatWebsiteVisitLocation($row)) ?></td>
                            <td><code><?= h((string) ($row['ip_address'] ?? '—')) ?></code></td>
                            <td><?= h(formatWebsiteVisitAreaLabel((string) ($row['site_area'] ?? ''))) ?></td>
                            <td class="cell-ellipsis" title="<?= h((string) ($row['request_path'] ?? '')) ?>">
                                <?= h((string) ($row['request_path'] ?? '/')) ?>
                            </td>
                            <td class="cell-ellipsis" title="<?= h((string) ($row['referrer'] ?? '')) ?>">
                                <?php
                                $ref = trim((string) ($row['referrer'] ?? ''));
                                echo $ref !== '' ? h($ref) : '—';
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php renderAdminPagination($page, $total, 'visitor-locations.php', $paginationQuery); ?>

    <p class="form-hint">Bots and admin pages are excluded. Data collection starts from when this feature was deployed.</p>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>

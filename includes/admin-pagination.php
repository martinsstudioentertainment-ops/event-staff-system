<?php

/**
 * Shared admin list pagination — 10 rows per page.
 */

const ADMIN_LIST_PER_PAGE = 10;

/** Staff list — default rows per page (override via ?per_page= on staff.php). */
const ADMIN_STAFF_LIST_PER_PAGE = 50;

/** Events list — show full summer roster without paging through many pages. */
const ADMIN_EVENTS_LIST_PER_PAGE = 50;

function adminListPage(): int
{
    return max(1, (int) ($_GET['page'] ?? 1));
}

function adminListPerPage(): int
{
    return ADMIN_LIST_PER_PAGE;
}

function adminStaffListPerPage(): int
{
    return ADMIN_STAFF_LIST_PER_PAGE;
}

function adminEventsListPerPage(): int
{
    return ADMIN_EVENTS_LIST_PER_PAGE;
}

function adminStaffListPerPageFromRequest(): int
{
    $requested = (int) ($_GET['per_page'] ?? 0);
    $allowed   = [25, 50, 100, 200];

    if (in_array($requested, $allowed, true)) {
        return $requested;
    }

    return ADMIN_STAFF_LIST_PER_PAGE;
}

/**
 * @return int[]
 */
function adminStaffListPerPageOptions(): array
{
    return [25, 50, 100, 200];
}

function adminListOffset(int $page, ?int $perPage = null): int
{
    $perPage = $perPage ?? ADMIN_LIST_PER_PAGE;

    return ($page - 1) * $perPage;
}

function adminListTotalPages(int $total, ?int $perPage = null): int
{
    $perPage = $perPage ?? ADMIN_LIST_PER_PAGE;

    if ($total <= 0) {
        return 1;
    }

    return (int) ceil($total / $perPage);
}

/**
 * @param array<string, scalar|null> $queryParams
 */
function renderAdminPagination(int $page, int $total, string $script, array $queryParams = [], ?int $perPage = null): void
{
    $perPage     = $perPage ?? ADMIN_LIST_PER_PAGE;
    $totalPages  = adminListTotalPages($total, $perPage);
    $page        = min(max(1, $page), $totalPages);
    $from        = $total === 0 ? 0 : (($page - 1) * $perPage) + 1;
    $to          = min($page * $perPage, $total);

    if ($total <= 0) {
        return;
    }

    $buildUrl = static function (int $targetPage) use ($script, $queryParams): string {
        $params           = $queryParams;
        $params['page']   = $targetPage > 1 ? $targetPage : null;
        $params           = array_filter($params, static fn ($v): bool => $v !== null && $v !== '');
        $qs               = http_build_query($params);

        return $script . ($qs !== '' ? '?' . $qs : '');
    };

    echo '<nav class="admin-pagination' . ($totalPages > 1 ? ' admin-pagination--multi' : '') . '" aria-label="List pages">';
    echo '<p class="admin-pagination__summary">Showing <strong>' . (int) $from . '–' . (int) $to . '</strong> of <strong>' . (int) $total . '</strong></p>';

    if ($totalPages <= 1) {
        echo '</nav>';

        return;
    }

    echo '<div class="admin-pagination__links">';

    if ($page > 1) {
        echo '<a href="' . htmlspecialchars($buildUrl($page - 1), ENT_QUOTES, 'UTF-8') . '" class="btn btn--small btn--secondary">← Previous</a>';
    }

    $windowStart = max(1, $page - 2);
    $windowEnd   = min($totalPages, $page + 2);
    for ($i = $windowStart; $i <= $windowEnd; $i++) {
        if ($i === $page) {
            echo '<span class="admin-pagination__current" aria-current="page">' . $i . '</span>';
        } else {
            echo '<a href="' . htmlspecialchars($buildUrl($i), ENT_QUOTES, 'UTF-8') . '" class="btn btn--small btn--secondary">' . $i . '</a>';
        }
    }

    if ($page < $totalPages) {
        echo '<a href="' . htmlspecialchars($buildUrl($page + 1), ENT_QUOTES, 'UTF-8') . '" class="btn btn--small btn--primary">Next page →</a>';
    }

    echo '</div></nav>';
}

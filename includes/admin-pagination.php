<?php

/**
 * Shared admin list pagination — 10 rows per page.
 */

const ADMIN_LIST_PER_PAGE = 10;

function adminListPage(): int
{
    return max(1, (int) ($_GET['page'] ?? 1));
}

function adminListPerPage(): int
{
    return ADMIN_LIST_PER_PAGE;
}

function adminListOffset(int $page): int
{
    return ($page - 1) * ADMIN_LIST_PER_PAGE;
}

function adminListTotalPages(int $total): int
{
    if ($total <= 0) {
        return 1;
    }

    return (int) ceil($total / ADMIN_LIST_PER_PAGE);
}

/**
 * @param array<string, scalar|null> $queryParams
 */
function renderAdminPagination(int $page, int $total, string $script, array $queryParams = []): void
{
    $perPage     = ADMIN_LIST_PER_PAGE;
    $totalPages  = adminListTotalPages($total);
    $page        = min(max(1, $page), $totalPages);
    $from        = $total === 0 ? 0 : (($page - 1) * $perPage) + 1;
    $to          = min($page * $perPage, $total);

    if ($total <= $perPage && $totalPages <= 1) {
        if ($total > 0) {
            echo '<p class="form-hint admin-pagination__summary">Showing ' . (int) $from . '–' . (int) $to . ' of ' . (int) $total . '</p>';
        }

        return;
    }

    $buildUrl = static function (int $targetPage) use ($script, $queryParams): string {
        $params           = $queryParams;
        $params['page']   = $targetPage > 1 ? $targetPage : null;
        $params           = array_filter($params, static fn ($v): bool => $v !== null && $v !== '');
        $qs               = http_build_query($params);

        return $script . ($qs !== '' ? '?' . $qs : '');
    };

    echo '<nav class="admin-pagination" aria-label="List pages">';
    echo '<p class="form-hint admin-pagination__summary">Showing ' . (int) $from . '–' . (int) $to . ' of ' . (int) $total . '</p>';
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
        echo '<a href="' . htmlspecialchars($buildUrl($page + 1), ENT_QUOTES, 'UTF-8') . '" class="btn btn--small btn--secondary">Next →</a>';
    }

    echo '</div></nav>';
}

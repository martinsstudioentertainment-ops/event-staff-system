<?php

declare(strict_types=1);

/** Default rows per list page on apply admin. */
const SECURE_LIST_DEFAULT_PER_PAGE = 10;

/** @var list<int> */
const SECURE_LIST_PER_PAGE_OPTIONS = [10, 25, 50, 100];

function secureListPage(string $pageKey = 'page'): int
{
    return max(1, (int) ($_GET[$pageKey] ?? 1));
}

function secureListPerPage(): int
{
    $requested = (int) ($_GET['per_page'] ?? SECURE_LIST_DEFAULT_PER_PAGE);
    if (!in_array($requested, SECURE_LIST_PER_PAGE_OPTIONS, true)) {
        return SECURE_LIST_DEFAULT_PER_PAGE;
    }

    return $requested;
}

function secureListOffset(int $page, ?int $perPage = null): int
{
    $perPage = $perPage ?? secureListPerPage();

    return ($page - 1) * $perPage;
}

function secureListTotalPages(int $total, ?int $perPage = null): int
{
    $perPage = $perPage ?? secureListPerPage();
    if ($total <= 0) {
        return 1;
    }

    return (int) ceil($total / $perPage);
}

/**
 * @param array<string, scalar|null> $queryParams
 */
function securePaginationQueryParams(array $queryParams = []): array
{
    $params = $queryParams;
    $page   = secureListPage();
    $per    = secureListPerPage();

    if ($page > 1) {
        $params['page'] = $page;
    }
    if ($per !== SECURE_LIST_DEFAULT_PER_PAGE) {
        $params['per_page'] = $per;
    }

    return array_filter($params, static fn ($v): bool => $v !== null && $v !== '');
}

/**
 * @param array<string, scalar|null> $queryParams
 */
function securePaginationUrl(string $script, array $queryParams, int $targetPage, string $pageKey = 'page'): string
{
    $params              = $queryParams;
    $params[$pageKey]    = $targetPage > 1 ? $targetPage : null;
    $per            = secureListPerPage();
    if ($per !== SECURE_LIST_DEFAULT_PER_PAGE) {
        $params['per_page'] = $per;
    }
    $params = array_filter($params, static fn ($v): bool => $v !== null && $v !== '');

    $qs = http_build_query($params);

    return $script . ($qs !== '' ? '?' . $qs : '');
}

/**
 * @param array<string, scalar|null> $queryParams
 */
function renderSecurePagination(int $page, int $total, string $script, array $queryParams = [], string $pageKey = 'page'): void
{
    $perPage    = secureListPerPage();
    $totalPages = secureListTotalPages($total, $perPage);
    $page       = min(max(1, $page), $totalPages);
    $from       = $total === 0 ? 0 : (($page - 1) * $perPage) + 1;
    $to         = min($page * $perPage, $total);

    echo '<nav class="secure-pagination" aria-label="List pages">';
    echo '<p class="secure-pagination__summary">Showing ' . (int) $from . '–' . (int) $to . ' of ' . (int) $total . '</p>';

    if ($totalPages > 1) {
        echo '<div class="secure-pagination__links">';
        if ($page > 1) {
            echo '<a href="' . secure_h(securePaginationUrl($script, $queryParams, $page - 1, $pageKey)) . '" class="secure-btn secure-btn--ghost secure-pagination__btn">← Previous</a>';
        }

        $windowStart = max(1, $page - 2);
        $windowEnd   = min($totalPages, $page + 2);
        for ($i = $windowStart; $i <= $windowEnd; $i++) {
            if ($i === $page) {
                echo '<span class="secure-pagination__current" aria-current="page">' . (int) $i . '</span>';
            } else {
                echo '<a href="' . secure_h(securePaginationUrl($script, $queryParams, $i, $pageKey)) . '" class="secure-btn secure-btn--ghost secure-pagination__btn">' . (int) $i . '</a>';
            }
        }

        if ($page < $totalPages) {
            echo '<a href="' . secure_h(securePaginationUrl($script, $queryParams, $page + 1, $pageKey)) . '" class="secure-btn secure-btn--ghost secure-pagination__btn">Next →</a>';
        }
        echo '</div>';
    }

    echo '</nav>';
}

/**
 * Per-page selector (preserves search/filter query params).
 *
 * @param array<string, scalar|null> $hiddenParams
 */
function renderSecurePerPageControl(string $script, array $hiddenParams = []): void
{
    $perPage = secureListPerPage();
    echo '<div class="secure-pagination__per-page">';
    echo '<label class="secure-label" for="secure-per-page">Rows per page</label>';
    echo '<form method="get" action="' . secure_h($script) . '" class="secure-pagination__per-page-form">';
    foreach ($hiddenParams as $key => $value) {
        if ($value === null || $value === '' || $key === 'page' || $key === 'per_page') {
            continue;
        }
        echo '<input type="hidden" name="' . secure_h((string) $key) . '" value="' . secure_h((string) $value) . '">';
    }
    echo '<select class="secure-select" id="secure-per-page" name="per_page" onchange="this.form.submit()">';
    foreach (SECURE_LIST_PER_PAGE_OPTIONS as $option) {
        $selected = $option === $perPage ? ' selected' : '';
        echo '<option value="' . (int) $option . '"' . $selected . '>' . (int) $option . '</option>';
    }
    echo '</select>';
    echo '</form>';
    echo '</div>';
}

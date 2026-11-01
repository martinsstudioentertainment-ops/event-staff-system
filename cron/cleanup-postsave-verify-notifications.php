<?php

declare(strict_types=1);

/**
 * Remove historical PostSave Verify test admin registration notifications.
 *
 *   ?key=CRON_KEY&dry_run=1   — list candidates (default)
 *   ?key=CRON_KEY&apply=1     — delete candidates only
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/platform/production-health.php';
require_once dirname(__DIR__) . '/includes/notification-center.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $pdo = getDB();
    $key = trim((string) ($_GET['key'] ?? ''));
    if (!productionHealthAuthorize($pdo, $key)) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_PRETTY_PRINT));
    }

    $apply = isset($_GET['apply']) && (string) $_GET['apply'] === '1';

    $selectSql = "
        SELECT id, type, title, body, related_id, is_read, created_at
        FROM app_notifications
        WHERE audience = 'admin'
          AND type = 'registration'
          AND title LIKE '%PostSave Verify%'
        ORDER BY id ASC
    ";
    $candidates = $pdo->query($selectSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $ids = array_values(array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $candidates));
    $ids = array_values(array_filter($ids, static fn(int $id): bool => $id > 0));

    $dateRange = ['earliest' => null, 'latest' => null];
    if ($candidates !== []) {
        $dates = array_column($candidates, 'created_at');
        $dateRange['earliest'] = min($dates);
        $dateRange['latest']   = max($dates);
    }

    $beforeCounts = [
        'admin_registration_total' => (int) $pdo->query(
            "SELECT COUNT(*) FROM app_notifications WHERE audience = 'admin' AND type = 'registration'"
        )->fetchColumn(),
        'admin_total' => (int) $pdo->query(
            "SELECT COUNT(*) FROM app_notifications WHERE audience = 'admin'"
        )->fetchColumn(),
        'staff_total' => (int) $pdo->query(
            "SELECT COUNT(*) FROM app_notifications WHERE audience = 'staff'"
        )->fetchColumn(),
        'unread_admin' => countUnreadAdminNotifications($pdo),
    ];

    $deleted = 0;
    if ($apply && $ids !== []) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("DELETE FROM app_notifications WHERE id IN ($placeholders) AND audience = 'admin' AND type = 'registration' AND title LIKE '%PostSave Verify%'");
        $stmt->execute($ids);
        $deleted = $stmt->rowCount();
    }

    $remaining = (int) $pdo->query(
        "SELECT COUNT(*) FROM app_notifications WHERE audience = 'admin' AND type = 'registration' AND title LIKE '%PostSave Verify%'"
    )->fetchColumn();

    $afterCounts = [
        'admin_registration_total' => (int) $pdo->query(
            "SELECT COUNT(*) FROM app_notifications WHERE audience = 'admin' AND type = 'registration'"
        )->fetchColumn(),
        'admin_total' => (int) $pdo->query(
            "SELECT COUNT(*) FROM app_notifications WHERE audience = 'admin'"
        )->fetchColumn(),
        'staff_total' => (int) $pdo->query(
            "SELECT COUNT(*) FROM app_notifications WHERE audience = 'staff'"
        )->fetchColumn(),
        'unread_admin' => countUnreadAdminNotifications($pdo),
    ];

    $genuineSample = $pdo->query(
        "SELECT id, title, created_at FROM app_notifications
         WHERE audience = 'admin' AND type = 'registration'
         ORDER BY id DESC LIMIT 8"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'ok'          => true,
        'dry_run'     => !$apply,
        'candidates'  => count($candidates),
        'ids'         => $ids,
        'date_range'  => $dateRange,
        'rows'        => $candidates,
        'deleted'     => $deleted,
        'remaining'   => $remaining,
        'before'      => $beforeCounts,
        'after'       => $afterCounts,
        'verdict'     => $apply
            ? ($remaining === 0 ? 'CLEANUP_PASS' : 'CLEANUP_FAIL')
            : 'DRY_RUN',
        'genuine_registration_notifications_sample' => $genuineSample,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
}

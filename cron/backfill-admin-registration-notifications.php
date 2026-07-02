<?php

declare(strict_types=1);

/**
 * Backfill missing admin in-app registration notifications (housekeeping).
 *
 * Does NOT re-send registration emails — only inserts app_notifications rows that
 * would have been created by notifyAdminNewRegistration() → notifyAdminInApp().
 *
 *   ?key=CRON_KEY&dry_run=1              — list candidates only (default)
 *   ?key=CRON_KEY&apply=1                — insert missing notifications
 *   ?key=CRON_KEY&apply=1&since=2026-06-27
 *   ?key=CRON_KEY&apply=1&registration_ids=677,678,679,680,681
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/platform/production-health.php';
require_once dirname(__DIR__) . '/includes/notification-center.php';
require_once dirname(__DIR__) . '/includes/staff-repository.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $pdo = getDB();
    $key = trim((string) ($_GET['key'] ?? ''));
    if (!productionHealthAuthorize($pdo, $key)) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_PRETTY_PRINT));
    }

    $apply = isset($_GET['apply']) && (string) $_GET['apply'] === '1';
    $since = trim((string) ($_GET['since'] ?? '2026-06-27 00:00:00'));
    $idsParam = trim((string) ($_GET['registration_ids'] ?? ''));

    $params = ['since' => $since];
    $idFilter = '';
    if ($idsParam !== '') {
        $ids = array_values(array_filter(array_map('intval', explode(',', $idsParam)), static fn(int $id): bool => $id > 0));
        if ($ids === []) {
            exit(json_encode(['ok' => false, 'error' => 'registration_ids invalid'], JSON_PRETTY_PRINT));
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $idFilter = " AND sr.id IN ($placeholders)";
        $params = $ids;
        $sqlSince = '';
    } else {
        $sqlSince = ' AND sr.created_at >= :since';
    }

    $sql = "
        SELECT sr.id, sr.email, sr.surname, sr.first_name, sr.created_at,
               e.name AS event_name, e.event_date, e.start_time, e.end_time, e.location
        FROM staff_registrations sr
        LEFT JOIN events e ON e.id = sr.event_id
        WHERE NOT EXISTS (
            SELECT 1 FROM app_notifications n
            WHERE n.audience = 'admin'
              AND n.type = 'registration'
              AND n.related_id = sr.id
        )
        $sqlSince
        $idFilter
        ORDER BY sr.id ASC
    ";

    $stmt = $pdo->prepare($sql);
    if ($idsParam !== '') {
        $stmt->execute($params);
    } else {
        $stmt->execute(['since' => $since]);
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $candidates = [];
    $created    = [];

    foreach ($rows as $row) {
        $regId     = (int) ($row['id'] ?? 0);
        $staffName = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['surname'] ?? ''));
        if ($staffName === '') {
            $staffName = 'New applicant';
        }
        $email     = (string) ($row['email'] ?? '');
        $eventName = formatEventLabel($row);

        $entry = [
            'registration_id' => $regId,
            'staff_name'      => $staffName,
            'email'           => $email,
            'event'           => $eventName,
            'registered_at'   => (string) ($row['created_at'] ?? ''),
        ];
        $candidates[] = $entry;

        if (!$apply || $regId <= 0) {
            continue;
        }

        $title = 'New registration — ' . $staffName;
        $body  = $staffName . ' registered for ' . $eventName . ' (' . $email . ').';
        $url   = 'view-staff.php?id=' . $regId;

        $notifId = notifyAdminInApp($pdo, 'registration', $title, $body, $url, 'Review', $regId);
        $created[] = array_merge($entry, ['notification_id' => $notifId]);
    }

    echo json_encode([
        'ok'         => true,
        'dry_run'    => !$apply,
        'since'      => $idsParam !== '' ? null : $since,
        'candidates' => count($candidates),
        'created'    => count($created),
        'rows'       => $apply ? $created : $candidates,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
}

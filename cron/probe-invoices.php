<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/platform/production-health.php';
require_once dirname(__DIR__) . '/includes/events-repository.php';
require_once dirname(__DIR__) . '/includes/commission-invoice-repository.php';
require_once dirname(__DIR__) . '/includes/work-hours-repository.php';
require_once dirname(__DIR__) . '/includes/system-settings.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $pdo = getDB();
    $key = trim((string) ($_GET['key'] ?? ''));
    if (!productionHealthAuthorize($pdo, $key)) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Forbidden']));
    }

    $month = trim((string) ($_GET['month'] ?? date('Y-m')));
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = date('Y-m');
    }

    $events = getEventsForFilter($pdo);
    $allList = getCommissionInvoicesList($pdo, 0, '', $month);
    $totals = getCommissionInvoiceAggregates($pdo, 0, '', $month);

    // Simulate template helpers used on invoices.php
    $templateChecks = [];
    foreach (array_slice($allList, 0, 2) as $row) {
        $templateChecks[] = [
            'invoice_number' => (string) $row['invoice_number'],
            'event_date_label' => formatEventDateLabel((string) $row['event_date']),
            'invoice_date' => formatSystemDate((string) $row['invoice_date'], $pdo),
            'hours' => formatHoursDecimal((float) $row['total_hours_billed']),
            'amount' => formatSystemCurrencyAmount((float) $row['total_amount'], $pdo),
            'status_label' => getCommissionInvoiceStatusOptions()[(string) $row['status']] ?? $row['status'],
        ];
    }

    require_once dirname(__DIR__) . '/includes/staff-messages.php';
    require_once dirname(__DIR__) . '/includes/notification-center.php';
    require_once dirname(__DIR__) . '/includes/platform/sidebar-ops.php';
    $sidebarOps = getPlatformOpsSidebarItems($pdo);

    echo json_encode([
        'ok' => true,
        'events_count' => count($events),
        'invoices_count' => count($allList),
        'totals' => $totals,
        'template_checks' => $templateChecks,
        'sidebar_ops_count' => count($sidebarOps),
        'admin_notif_unread' => countUnreadAdminNotifications($pdo),
        'admin_msg_unread' => countUnreadStaffMessagesForAdmin($pdo),
    ], JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ], JSON_PRETTY_PRINT);
}

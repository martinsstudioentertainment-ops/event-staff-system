<?php

declare(strict_types=1);

require_once __DIR__ . '/automation-schema.php';
require_once __DIR__ . '/../staff-portal-session.php';
require_once __DIR__ . '/../staff-portal-dashboard.php';
require_once __DIR__ . '/../workforce/workforce-analytics.php';
require_once __DIR__ . '/../workforce/compliance-repository.php';
require_once __DIR__ . '/../workforce/staff-availability.php';

/** @return list<array<string, mixed>> */
function ssp_get_assignments(PDO $pdo, string $email): array
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return [];
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT sr.*, e.name AS event_name, e.event_date, e.location, e.start_time, e.end_time,
                    a.checked_in_at, a.attendance_status
             FROM staff_registrations sr
             INNER JOIN events e ON e.id = sr.event_id
             LEFT JOIN attendance a ON a.registration_id = sr.id
             WHERE LOWER(sr.email) = :email
             ORDER BY e.event_date DESC"
        );
        $stmt->execute(['email' => $email]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function ssp_set_shift_response(PDO $pdo, int $registrationId, string $email, string $response): bool
{
    if (!auto_shift_response_available($pdo) || $registrationId < 1) {
        return false;
    }
    if (!in_array($response, ['accepted', 'declined'], true)) {
        return false;
    }

    $email = strtolower(trim($email));

    try {
        $stmt = $pdo->prepare(
            "UPDATE staff_registrations SET shift_response = :resp
             WHERE id = :id AND LOWER(email) = :email AND status = 'approved'"
        );

        return $stmt->execute(['resp' => $response, 'id' => $registrationId, 'email' => $email]);
    } catch (Throwable $e) {
        return false;
    }
}

/** @return array<string, mixed>|null */
function ssp_reliability_for_staff(PDO $pdo, int $staffId): ?array
{
    if ($staffId < 1) {
        return null;
    }

    $all = wf_list_staff_performance($pdo, '90d', [], 500, 0);
    foreach ($all as $row) {
        if ((int) ($row['id'] ?? 0) === $staffId) {
            return $row;
        }
    }

    return null;
}

/** @return list<array<string, mixed>> */
function ssp_attendance_history(PDO $pdo, string $email, int $limit = 30): array
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return [];
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT a.*, e.name AS event_name, e.event_date, sr.staff_role
             FROM attendance a
             INNER JOIN staff_registrations sr ON sr.id = a.registration_id
             INNER JOIN events e ON e.id = a.event_id
             WHERE LOWER(sr.email) = :email
             ORDER BY a.checked_in_at DESC
             LIMIT " . max(1, min($limit, 50))
        );
        $stmt->execute(['email' => $email]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function ssp_confirm_availability(PDO $pdo, int $staffId, string $date, string $status, string $notes = ''): bool
{
    auto_ensure_schema($pdo);
    wf_ensure_availability_schema($pdo);

    return wf_set_staff_availability($pdo, $staffId, $date, $status, $notes, false);
}

function ssp_request_leave(PDO $pdo, int $staffId, string $date, string $type, string $notes = ''): bool
{
    auto_ensure_schema($pdo);
    wf_ensure_availability_schema($pdo);

    $status = $type === 'holiday' ? 'holiday_requested' : 'leave_requested';

    return wf_set_staff_availability($pdo, $staffId, $date, $status, $notes, false);
}

<?php

declare(strict_types=1);

require_once __DIR__ . '/registrant-complete-purge.php';
require_once __DIR__ . '/events-repository.php';

const EVENT_DELETE_CONFIRM_PHRASE = 'DELETE';

function isEventDeletePhraseValid(string $input): bool
{
    $normalized = strtoupper(trim($input));

    return $normalized === EVENT_DELETE_CONFIRM_PHRASE;
}

/**
 * @return array{event: array<string, mixed>|null, registration_ids: list<int>, invoice_ids: list<int>, slot_ids: list<int>}
 */
function collectEventPurgeContext(PDO $pdo, int $eventId): array
{
    $event = $eventId > 0 ? getEventById($pdo, $eventId) : null;

    $registrationIds = [];
    if ($event !== null && registrantPurgeTableExists($pdo, 'staff_registrations')) {
        $stmt = $pdo->prepare('SELECT id FROM staff_registrations WHERE event_id = :event_id');
        $stmt->execute(['event_id' => $eventId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $registrationIds[] = (int) $id;
        }
    }

    $invoiceIds = [];
    if ($event !== null && registrantPurgeTableExists($pdo, 'commission_invoices')) {
        $stmt = $pdo->prepare('SELECT id FROM commission_invoices WHERE event_id = :event_id');
        $stmt->execute(['event_id' => $eventId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $invoiceIds[] = (int) $id;
        }
    }

    $slotIds = [];
    if ($event !== null && registrantPurgeTableExists($pdo, 'event_roster_slots')) {
        $stmt = $pdo->prepare('SELECT id FROM event_roster_slots WHERE event_id = :event_id');
        $stmt->execute(['event_id' => $eventId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $slotIds[] = (int) $id;
        }
    }

    return [
        'event'             => $event,
        'registration_ids'  => array_values(array_unique(array_filter($registrationIds, static fn (int $id): bool => $id > 0))),
        'invoice_ids'       => array_values(array_unique(array_filter($invoiceIds, static fn (int $id): bool => $id > 0))),
        'slot_ids'          => array_values(array_unique(array_filter($slotIds, static fn (int $id): bool => $id > 0))),
    ];
}

/**
 * @return array<string, int>
 */
function countEventPurgeImpact(PDO $pdo, int $eventId): array
{
    $ctx = collectEventPurgeContext($pdo, $eventId);
    $counts = [
        'registrations' => count($ctx['registration_ids']),
        'invoices'      => count($ctx['invoice_ids']),
        'roster_slots'  => count($ctx['slot_ids']),
    ];

    if ($ctx['event'] === null) {
        return $counts;
    }

    $countByEvent = static function (string $table, string $column = 'event_id') use ($pdo, $eventId): int {
        if (!registrantPurgeTableExists($pdo, $table) || !registrantPurgeColumnExists($pdo, $table, $column)) {
            return 0;
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = :event_id");
        $stmt->execute(['event_id' => $eventId]);

        return (int) $stmt->fetchColumn();
    };

    $counts['attendance'] = $countByEvent('attendance');
    $counts['signin_logs'] = $countByEvent('signin_location_verifications');
    $counts['waitlist'] = $countByEvent('staff_waitlist', 'preferred_event_id');
    $counts['incidents'] = $countByEvent('staff_incidents');
    $counts['sheets_queue'] = $countByEvent('google_sheets_sync_queue');
    $counts['sheets_sync_log'] = $countByEvent('platform_sheets_sync_log');
    $counts['auto_approval_log'] = $countByEvent('platform_auto_approval_log');
    $counts['offline_checkins'] = $countByEvent('platform_offline_checkins');
    $counts['emergency_log'] = $countByEvent('emergency_event_log');
    $counts['equipment_rentals'] = $countByEvent('equipment_rentals');

    if (registrantPurgeTableExists($pdo, 'staff_shift_assignment_log')) {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM staff_shift_assignment_log
             WHERE from_event_id = :from_event_id OR to_event_id = :to_event_id'
        );
        $stmt->execute(['from_event_id' => $eventId, 'to_event_id' => $eventId]);
        $counts['assignment_log'] = (int) $stmt->fetchColumn();
    }

    if ($ctx['slot_ids'] !== [] && registrantPurgeTableExists($pdo, 'event_roster_assignments')) {
        $placeholders = implode(',', array_fill(0, count($ctx['slot_ids']), '?'));
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM event_roster_assignments WHERE slot_id IN ({$placeholders})");
        $stmt->execute($ctx['slot_ids']);
        $counts['roster_assignments'] = (int) $stmt->fetchColumn();
    }

    if ($ctx['registration_ids'] !== [] && registrantPurgeTableExists($pdo, 'recruitment_pipeline')) {
        $placeholders = implode(',', array_fill(0, count($ctx['registration_ids']), '?'));
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM recruitment_pipeline WHERE registration_id IN ({$placeholders})");
        $stmt->execute($ctx['registration_ids']);
        $counts['recruitment_pipeline'] = (int) $stmt->fetchColumn();
    }

    if (registrantPurgeTableExists($pdo, 'app_notifications') && registrantPurgeColumnExists($pdo, 'app_notifications', 'related_id')) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM app_notifications WHERE related_id = :event_id');
        $stmt->execute(['event_id' => $eventId]);
        $counts['notifications'] = (int) $stmt->fetchColumn();
    }

    return $counts;
}

/**
 * @param list<int> $ids
 */
function eventPurgeDeleteIn(PDO $pdo, string $table, string $column, array $ids, array &$deleted): void
{
    if ($ids === [] || !registrantPurgeTableExists($pdo, $table) || !registrantPurgeColumnExists($pdo, $table, $column)) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("DELETE FROM `{$table}` WHERE `{$column}` IN ({$placeholders})");
    $stmt->execute($ids);
    $deleted[$table] = ($deleted[$table] ?? 0) + $stmt->rowCount();
}

function eventPurgeDeleteByEventId(PDO $pdo, string $table, int $eventId, array &$deleted, string $column = 'event_id'): void
{
    if ($eventId < 1 || !registrantPurgeTableExists($pdo, $table) || !registrantPurgeColumnExists($pdo, $table, $column)) {
        return;
    }

    $stmt = $pdo->prepare("DELETE FROM `{$table}` WHERE `{$column}` = :event_id");
    $stmt->execute(['event_id' => $eventId]);
    $deleted[$table] = ($deleted[$table] ?? 0) + $stmt->rowCount();
}

/**
 * Permanently delete an event and all related operational history.
 * Staff profiles are kept; only this event's registrations and attendance are removed.
 *
 * @return array<string, mixed>
 */
function deleteEventCompletely(PDO $pdo, int $eventId): array
{
    $ctx = collectEventPurgeContext($pdo, $eventId);
    if ($ctx['event'] === null) {
        return ['ok' => false, 'error' => 'Event not found.'];
    }

    $eventName = trim((string) ($ctx['event']['name'] ?? 'Event'));
    $impactBefore = countEventPurgeImpact($pdo, $eventId);
    $deleted = [];

    try {
        $pdo->beginTransaction();

        if ($ctx['slot_ids'] !== []) {
            eventPurgeDeleteIn($pdo, 'event_roster_assignments', 'slot_id', $ctx['slot_ids'], $deleted);
        }
        if ($ctx['registration_ids'] !== []) {
            eventPurgeDeleteIn($pdo, 'event_roster_assignments', 'registration_id', $ctx['registration_ids'], $deleted);
            eventPurgeDeleteIn($pdo, 'recruitment_pipeline', 'registration_id', $ctx['registration_ids'], $deleted);
            purgeRegistrantRegistrationIds($pdo, $ctx['registration_ids'], $deleted);
        }

        foreach ($ctx['invoice_ids'] as $invoiceId) {
            if (registrantPurgeTableExists($pdo, 'commission_invoice_lines')) {
                $stmt = $pdo->prepare('DELETE FROM commission_invoice_lines WHERE invoice_id = :id');
                $stmt->execute(['id' => $invoiceId]);
                $deleted['commission_invoice_lines'] = ($deleted['commission_invoice_lines'] ?? 0) + $stmt->rowCount();
            }
            if (registrantPurgeTableExists($pdo, 'commission_invoices')) {
                $stmt = $pdo->prepare('DELETE FROM commission_invoices WHERE id = :id');
                $stmt->execute(['id' => $invoiceId]);
                $deleted['commission_invoices'] = ($deleted['commission_invoices'] ?? 0) + $stmt->rowCount();
            }
        }

        eventPurgeDeleteByEventId($pdo, 'attendance', $eventId, $deleted);
        eventPurgeDeleteByEventId($pdo, 'signin_location_verifications', $eventId, $deleted);
        eventPurgeDeleteByEventId($pdo, 'google_sheets_sync_queue', $eventId, $deleted);
        eventPurgeDeleteByEventId($pdo, 'platform_sheets_sync_log', $eventId, $deleted);
        eventPurgeDeleteByEventId($pdo, 'platform_auto_approval_log', $eventId, $deleted);
        eventPurgeDeleteByEventId($pdo, 'platform_offline_checkins', $eventId, $deleted);
        eventPurgeDeleteByEventId($pdo, 'staff_incidents', $eventId, $deleted);
        eventPurgeDeleteByEventId($pdo, 'emergency_event_log', $eventId, $deleted);
        eventPurgeDeleteByEventId($pdo, 'equipment_rentals', $eventId, $deleted);
        eventPurgeDeleteByEventId($pdo, 'staff_waitlist', $eventId, $deleted, 'preferred_event_id');
        eventPurgeDeleteByEventId($pdo, 'event_roster_slots', $eventId, $deleted);

        if (registrantPurgeTableExists($pdo, 'staff_shift_assignment_log')
            && registrantPurgeColumnExists($pdo, 'staff_shift_assignment_log', 'from_event_id')) {
            $stmt = $pdo->prepare(
                'DELETE FROM staff_shift_assignment_log
                 WHERE from_event_id = :from_event_id OR to_event_id = :to_event_id'
            );
            $stmt->execute(['from_event_id' => $eventId, 'to_event_id' => $eventId]);
            $deleted['staff_shift_assignment_log'] = $stmt->rowCount();
        }

        if (registrantPurgeTableExists($pdo, 'saved_job_records') && registrantPurgeColumnExists($pdo, 'saved_job_records', 'event_id')) {
            $stmt = $pdo->prepare('UPDATE saved_job_records SET event_id = NULL WHERE event_id = :event_id');
            $stmt->execute(['event_id' => $eventId]);
            $deleted['saved_job_records_unlinked'] = $stmt->rowCount();
        }

        if (registrantPurgeTableExists($pdo, 'app_notifications') && registrantPurgeColumnExists($pdo, 'app_notifications', 'related_id')) {
            $stmt = $pdo->prepare('DELETE FROM app_notifications WHERE related_id = :event_id');
            $stmt->execute(['event_id' => $eventId]);
            $deleted['app_notifications'] = $stmt->rowCount();
        }

        if (registrantPurgeTableExists($pdo, 'staff_registrations')) {
            $stmt = $pdo->prepare('DELETE FROM staff_registrations WHERE event_id = :event_id');
            $stmt->execute(['event_id' => $eventId]);
            $deleted['staff_registrations'] = ($deleted['staff_registrations'] ?? 0) + $stmt->rowCount();
        }

        $stmt = $pdo->prepare('DELETE FROM events WHERE id = :id');
        $stmt->execute(['id' => $eventId]);
        if ($stmt->rowCount() < 1) {
            throw new RuntimeException('Event row could not be deleted.');
        }
        $deleted['events'] = 1;

        if (registrantPurgeTableExists($pdo, 'admin_audit_log')) {
            $stmt = $pdo->prepare(
                "DELETE FROM admin_audit_log
                 WHERE (target_type = 'event' AND target_id = :event_id)
                    OR details LIKE :name_like"
            );
            $stmt->execute([
                'event_id'  => $eventId,
                'name_like' => '%' . $eventName . '%',
            ]);
            $deleted['admin_audit_log'] = $stmt->rowCount();
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return [
            'ok'            => false,
            'error'         => $e->getMessage(),
            'event_id'      => $eventId,
            'event_name'    => $eventName,
            'impact_before' => $impactBefore,
        ];
    }

    return [
        'ok'            => true,
        'event_id'      => $eventId,
        'event_name'    => $eventName,
        'impact_before' => $impactBefore,
        'deleted'       => $deleted,
        'event_gone'    => getEventById($pdo, $eventId) === null,
    ];
}

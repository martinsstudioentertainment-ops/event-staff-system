<?php
/**
 * Admin: assign verified staff to a shift, optionally moving them off another.
 */

require_once __DIR__ . '/staff-repository.php';
require_once __DIR__ . '/staff-onboarding.php';
require_once __DIR__ . '/staff-blacklist.php';
require_once __DIR__ . '/events-repository.php';
require_once __DIR__ . '/event-capacity.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/staff-registration-schema.php';
require_once __DIR__ . '/attendance-repository.php';
// google-sheets-sync loaded lazily inside adminAssignStaffToShift()

/**
 * Active upcoming events an admin can assign staff to.
 *
 * @return list<array<string, mixed>>
 */
function getAdminAssignableEvents(PDO $pdo, ?int $excludeEventId = null): array
{
    $sql = 'SELECT * FROM events WHERE is_active = 1 AND event_date >= CURDATE() ORDER BY event_date ASC, name ASC';
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_values(array_filter(
        $rows,
        static function (array $row) use ($excludeEventId): bool {
            $eventId = (int) ($row['id'] ?? 0);
            if ($excludeEventId !== null && $eventId === $excludeEventId) {
                return false;
            }

            return isEventOpenForRegistration($row);
        }
    ));
}

/**
 * @return array<string, mixed>|null
 */
function getRegistrationByEmailAndEvent(PDO $pdo, string $email, int $eventId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM staff_registrations
         WHERE LOWER(TRIM(email)) = :email AND event_id = :event_id
         LIMIT 1'
    );
    $stmt->execute([
        'email'    => normalizeRegistrationEmail($email),
        'event_id' => $eventId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * @return array<string, mixed>
 */
function buildRegistrationDataFromStaff(array $staff, array $event): array
{
    $staffRole = resolveStaffRoleForEventRegistration((string) ($staff['staff_role'] ?? 'dsp'), $event);

    return [
        'surname'         => trim((string) ($staff['surname'] ?? '')),
        'first_name'      => trim((string) ($staff['first_name'] ?? '')),
        'full_address'    => trim((string) ($staff['full_address'] ?? '')),
        'eircode'         => trim((string) ($staff['eircode'] ?? '')),
        'location_lat'    => $staff['location_lat'] ?? null,
        'location_lng'    => $staff['location_lng'] ?? null,
        'email'           => normalizeRegistrationEmail((string) ($staff['email'] ?? '')),
        'mobile'          => trim((string) ($staff['mobile'] ?? '')),
        'date_of_birth'   => trim((string) ($staff['date_of_birth'] ?? '')),
        'gender'          => trim((string) ($staff['gender'] ?? '')),
        'pps_number'      => trim((string) ($staff['pps_number'] ?? '')),
        'bank_iban'       => trim((string) ($staff['bank_iban'] ?? '')),
        'psa_licence'     => trim((string) ($staff['psa_licence'] ?? '')),
        'psa_expiry_date' => trim((string) ($staff['psa_expiry_date'] ?? '')),
        'staff_role'      => $staffRole,
        'privacy_consent' => '1',
    ];
}

/**
 * @return array{ok: bool, registration_id: int, message: string, from_event_id: int, to_event_id: int}
 */
function adminAssignStaffToShift(
    PDO $pdo,
    int $staffId,
    int $targetEventId,
    ?int $fromRegistrationId = null,
    bool $autoApprove = true
): array {
    $fail = static function (string $message): array {
        return [
            'ok'              => false,
            'registration_id' => 0,
            'message'         => $message,
            'from_event_id'   => 0,
            'to_event_id'     => 0,
        ];
    };

    if ($staffId < 1 || $targetEventId < 1) {
        return $fail('Invalid staff or event.');
    }

    $staff = getStaffById($pdo, $staffId);
    if ($staff === null) {
        return $fail('Staff member not found.');
    }

    if ((int) ($staff['is_blacklisted'] ?? 0) === 1) {
        return $fail('This staff member is blacklisted.');
    }

    if (!isStaffOnboardingComplete($staff)) {
        return $fail('Staff profile is incomplete. Complete verification before assigning a shift.');
    }

    $targetEvent = getEventById($pdo, $targetEventId);
    if ($targetEvent === null) {
        return $fail('Target event not found.');
    }

    if ((int) ($targetEvent['is_active'] ?? 0) !== 1) {
        return $fail('Target event is not active.');
    }

    if (!isEventOpenForRegistration($targetEvent)) {
        return $fail('Target event is closed (date passed or inactive).');
    }

    $email          = normalizeRegistrationEmail((string) ($staff['email'] ?? ''));
    $fromEventId    = 0;
    $sheetEventIds  = [$targetEventId];

    if ($fromRegistrationId !== null && $fromRegistrationId > 0) {
        $fromReg = getStaffRegistrationById($pdo, $fromRegistrationId);
        if ($fromReg === null) {
            return $fail('Current shift registration not found.');
        }

        $fromEmail = normalizeRegistrationEmail((string) ($fromReg['email'] ?? ''));
        if ($fromEmail !== $email) {
            return $fail('Current shift does not belong to this staff member.');
        }

        $fromEventId = (int) ($fromReg['event_id'] ?? 0);
        if ($fromEventId === $targetEventId) {
            return $fail('Staff is already on that shift.');
        }

        $fromStatus = strtolower(trim((string) ($fromReg['status'] ?? '')));
        if ($fromStatus !== 'rejected') {
            if (!updateStaffStatus($pdo, $fromRegistrationId, 'rejected', true)) {
                return $fail('Could not cancel the current shift.');
            }
            $sheetEventIds[] = $fromEventId;
        }
    }

    $existing = getRegistrationByEmailAndEvent($pdo, $email, $targetEventId);
    $regData  = buildRegistrationDataFromStaff($staff, $targetEvent);
    $status   = ($autoApprove && isStaffOnboardingComplete($staff)) ? 'approved' : 'pending';
    $registrationId = 0;

    try {
        if ($existing !== null) {
            $existingStatus = strtolower(trim((string) ($existing['status'] ?? '')));
            if (in_array($existingStatus, ['approved', 'pending'], true)) {
                return $fail('Staff is already registered for the target shift.');
            }

            $registrationId = (int) ($existing['id'] ?? 0);
            $stmt = $pdo->prepare(
                'UPDATE staff_registrations SET
                    staff_id = :staff_id,
                    surname = :surname,
                    first_name = :first_name,
                    full_address = :full_address,
                    eircode = :eircode,
                    location_lat = :location_lat,
                    location_lng = :location_lng,
                    mobile = :mobile,
                    date_of_birth = :date_of_birth,
                    gender = :gender,
                    pps_number = :pps_number,
                    bank_iban = :bank_iban,
                    staff_role = :staff_role,
                    status = :status,
                    updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $stmt->execute([
                'staff_id'       => $staffId,
                'surname'        => $regData['surname'],
                'first_name'     => $regData['first_name'],
                'full_address'   => $regData['full_address'],
                'eircode'        => $regData['eircode'],
                'location_lat'   => $regData['location_lat'],
                'location_lng'   => $regData['location_lng'],
                'mobile'         => $regData['mobile'],
                'date_of_birth'  => $regData['date_of_birth'] !== '' ? $regData['date_of_birth'] : null,
                'gender'         => $regData['gender'],
                'pps_number'     => $regData['pps_number'],
                'bank_iban'      => $regData['bank_iban'],
                'staff_role'     => $regData['staff_role'],
                'status'         => $status,
                'id'             => $registrationId,
            ]);
        } else {
            $registrationId = saveRegistration($pdo, $regData, $targetEventId, $regData['staff_role']);
            if ($status === 'approved') {
                updateStaffStatus($pdo, $registrationId, 'approved', true);
            }
        }
    } catch (Throwable $e) {
        error_log('[EventStaff] adminAssignStaffToShift: ' . $e->getMessage());

        return $fail('Could not create registration: ' . $e->getMessage());
    }

    if ($registrationId < 1) {
        return $fail('Registration could not be saved.');
    }

    if ($status === 'approved') {
        try {
            ensureCheckinToken($pdo, $registrationId);
        } catch (Throwable $e) {
            error_log('[EventStaff] adminAssignStaffToShift checkin token: ' . $e->getMessage());
        }
    }

    $sheetNotes = [];
    require_once __DIR__ . '/google-sheets-sync.php';
    foreach (array_values(array_unique(array_filter($sheetEventIds))) as $eventId) {
        try {
            $result = googleSheetsRebuildEventTab($pdo, $eventId, null, false);
            if (!empty($result['ok'])) {
                $sheetNotes[] = 'sheet updated';
            } elseif (!empty($result['skipped'])) {
                $sheetNotes[] = 'sheet sync skipped';
            }
        } catch (Throwable $e) {
            error_log('[EventStaff] adminAssignStaffToShift sheet sync event ' . $eventId . ': ' . $e->getMessage());
        }
    }

    $message = 'Assigned to ' . formatEventLabel($targetEvent) . '.';
    if ($fromEventId > 0) {
        $message = 'Moved from previous shift and assigned to ' . formatEventLabel($targetEvent) . '.';
    }
    if ($status === 'approved') {
        $message .= ' Status: approved.';
    } else {
        $message .= ' Status: pending.';
    }

    return [
        'ok'              => true,
        'registration_id' => $registrationId,
        'message'         => $message,
        'from_event_id'   => $fromEventId,
        'to_event_id'     => $targetEventId,
    ];
}

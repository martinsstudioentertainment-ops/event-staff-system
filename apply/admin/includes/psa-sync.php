<?php

declare(strict_types=1);

/**
 * PSA sync + automatic profile_status (Verified / Pending Review / Expired PSA).
 */

function apply_is_temp_psa_licence(?string $licence): bool
{
    return str_starts_with(trim((string) $licence), 'TEMP-PSA-');
}

/**
 * Vault DB value — null when staff have not submitted a real licence yet.
 */
function apply_normalize_vault_psa_licence(?string $licence): ?string
{
    $licence = trim((string) $licence);

    if ($licence === '' || apply_is_temp_psa_licence($licence)) {
        return null;
    }

    return $licence;
}

/**
 * Google Sheets / CSV — blank until staff complete PSA details.
 */
function apply_export_psa_licence(?string $licence): string
{
    return apply_normalize_vault_psa_licence($licence) ?? '';
}

/**
 * Remove legacy TEMP-PSA-* placeholders from the vault.
 */
function apply_clear_temp_psa_licences(PDO $applyPdo): int
{
    try {
        $stmt = $applyPdo->prepare(
            "UPDATE staff_master SET psa_licence = NULL WHERE psa_licence LIKE 'TEMP-PSA-%'"
        );
        $stmt->execute();

        return $stmt->rowCount();
    } catch (Throwable $e) {
        try {
            $stmt = $applyPdo->prepare(
                "UPDATE staff_master SET psa_licence = '' WHERE psa_licence LIKE 'TEMP-PSA-%'"
            );
            $stmt->execute();

            return $stmt->rowCount();
        } catch (Throwable $fallback) {
            error_log('[ApplySync] apply_clear_temp_psa_licences: ' . $e->getMessage());

            return 0;
        }
    }
}

/**
 * @return array<string, array{
 *     psa_licence: string,
 *     psa_expiry_date: string|null,
 *     profile_completed: int
 * }>
 */
function apply_main_staff_by_email(PDO $eventPdo): array
{
    $map = [];

    try {
        $stmt = $eventPdo->query("
            SELECT email, psa_licence, psa_expiry_date, profile_completed,
                   psa_front_image, psa_back_image
            FROM staff
            WHERE email IS NOT NULL AND TRIM(email) != ''
        ");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = strtolower(trim((string) ($row['email'] ?? '')));
            if ($key === '') {
                continue;
            }
            $expiry = trim((string) ($row['psa_expiry_date'] ?? ''));
            $map[$key] = [
                'psa_licence'       => trim((string) ($row['psa_licence'] ?? '')),
                'psa_expiry_date'   => ($expiry === '' || $expiry === '0000-00-00') ? null : $expiry,
                'profile_completed' => (int) ($row['profile_completed'] ?? 0),
                'psa_front_image'   => trim((string) ($row['psa_front_image'] ?? '')),
                'psa_back_image'    => trim((string) ($row['psa_back_image'] ?? '')),
            ];
        }
    } catch (Throwable $e) {
        error_log('[ApplySync] apply_main_staff_by_email: ' . $e->getMessage());
    }

    return $map;
}

/** @deprecated Use apply_main_staff_by_email() */
function apply_main_staff_psa_by_email(PDO $eventPdo): array
{
    return apply_main_staff_by_email($eventPdo);
}

function apply_normalize_psa_expiry(?string $value): ?string
{
    $value = trim((string) $value);

    return ($value === '' || $value === '0000-00-00') ? null : $value;
}

function apply_profile_status_for_psa_expiry(string $currentStatus, ?string $expiryDate): string
{
    if ($expiryDate === null) {
        return $currentStatus;
    }

    try {
        $expiry = new DateTime($expiryDate);
        $today  = new DateTime('today');

        if ($expiry < $today) {
            return 'Expired PSA';
        }

        if ($currentStatus === 'Expired PSA') {
            return 'Pending Review';
        }
    } catch (Exception $e) {
        return $currentStatus;
    }

    return $currentStatus;
}

/**
 * @param array<string, mixed> $row staff_master or merged import row
 */
function apply_vault_profile_complete_for_verify(array $row): bool
{
    $required = [
        'first_name', 'last_name', 'email', 'phone', 'date_of_birth',
        'address', 'postcode', 'national_insurance', 'bank_iban', 'psa_licence',
    ];

    foreach ($required as $field) {
        if (trim((string) ($row[$field] ?? '')) === '') {
            return false;
        }
    }

    if (apply_normalize_vault_psa_licence((string) ($row['psa_licence'] ?? '')) === null) {
        return false;
    }

    $expiry = apply_normalize_psa_expiry((string) ($row['psa_expiry_date'] ?? ''));
    if ($expiry === null) {
        return false;
    }

    try {
        return new DateTime($expiry) >= new DateTime('today');
    } catch (Exception $e) {
        return false;
    }
}

/**
 * @param array<string, mixed> $row
 */
function apply_vault_has_minimal_identity(array $row): bool
{
    return trim((string) ($row['email'] ?? '')) !== ''
        && trim((string) ($row['first_name'] ?? '')) !== ''
        && trim((string) ($row['last_name'] ?? '')) !== '';
}

/**
 * Automatic profile_status from vault row + optional main ERP staff row.
 *
 * @param array<string, mixed> $vaultRow
 * @param array{psa_licence?: string, psa_expiry_date?: string|null, profile_completed?: int}|null $mainStaff
 */
function apply_resolve_profile_status(array $vaultRow, ?array $mainStaff = null): string
{
    $current = (string) ($vaultRow['profile_status'] ?? 'Incomplete');
    $expiry  = apply_normalize_psa_expiry((string) ($vaultRow['psa_expiry_date'] ?? ''));

    $status = apply_profile_status_for_psa_expiry($current, $expiry);
    if ($status === 'Expired PSA') {
        return $status;
    }

    if ($mainStaff !== null && (int) ($mainStaff['profile_completed'] ?? 0) === 1) {
        return 'Verified';
    }

    if (apply_vault_profile_complete_for_verify($vaultRow)) {
        return 'Verified';
    }

    if (apply_vault_has_minimal_identity($vaultRow)) {
        return 'Pending Review';
    }

    return 'Incomplete';
}

/**
 * @param array{psa_licence?: string, psa_expiry_date?: string|null, profile_completed?: int}|null $mainStaff
 */
function apply_profile_status_from_main(?array $mainStaff, ?string $expiryDate, string $currentStatus): string
{
    return apply_resolve_profile_status([
        'profile_status'  => $currentStatus,
        'psa_expiry_date' => $expiryDate,
        'psa_licence'     => $mainStaff['psa_licence'] ?? '',
        'email'           => '',
        'first_name'      => '',
        'last_name'       => '',
    ], $mainStaff);
}

/**
 * @param array<string, mixed> $registration
 * @param array{psa_licence?: string, psa_expiry_date?: string|null, profile_completed?: int}|null $mainStaff
 * @return array{licence: string, expiry: string|null}
 */
function apply_resolve_psa_from_main(array $registration, ?array $mainStaff, string $existingLicence = ''): array
{
    $licence = '';
    $expiry  = null;

    if ($mainStaff !== null) {
        $licence = trim((string) ($mainStaff['psa_licence'] ?? ''));
        $expiry  = apply_normalize_psa_expiry((string) ($mainStaff['psa_expiry_date'] ?? ''));
    }

    if ($licence === '') {
        $licence = trim((string) ($registration['psa_licence'] ?? ''));
    }
    if ($expiry === null) {
        $expiry = apply_normalize_psa_expiry((string) ($registration['psa_expiry_date'] ?? ''));
    }

    $existingLicence = trim($existingLicence);
    if (apply_normalize_vault_psa_licence($licence) === null) {
        $licence = apply_export_psa_licence($existingLicence);
    }

    return [
        'licence' => apply_export_psa_licence($licence),
        'expiry'  => $expiry,
    ];
}

/**
 * Recompute and persist profile_status for one or all vault rows.
 */
function apply_auto_refresh_vault_profile_statuses(PDO $applyPdo, ?PDO $eventPdo = null, ?int $staffId = null): int
{
    $staffMap = $eventPdo instanceof PDO ? apply_main_staff_by_email($eventPdo) : [];
    $updated  = 0;

    $sql = "SELECT * FROM staff_master WHERE email IS NOT NULL AND TRIM(email) != ''";
    if ($staffId !== null && $staffId > 0) {
        $sql .= ' AND id = ' . (int) $staffId;
    }

    $stmt = $applyPdo->query($sql);
    if ($stmt === false) {
        return 0;
    }

    require_once __DIR__ . '/psa-images.php';

    $update = $applyPdo->prepare('
        UPDATE staff_master
        SET psa_licence = :psa,
            psa_expiry_date = :expiry,
            profile_status = :status,
            verified_by = :verified_by,
            verified_at = :verified_at,
            psa_front_image = :psa_front_image,
            psa_back_image = :psa_back_image
        WHERE id = :id
    ');

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key       = strtolower(trim((string) ($row['email'] ?? '')));
        $mainStaff = $staffMap[$key] ?? null;

        $oldLicence = apply_export_psa_licence((string) ($row['psa_licence'] ?? ''));
        $oldExpiry  = apply_normalize_psa_expiry((string) ($row['psa_expiry_date'] ?? ''));
        $psaLicence = $oldLicence;
        $psaExpiry  = $oldExpiry;

        if ($mainStaff !== null) {
            $resolved   = apply_resolve_psa_from_main([], $mainStaff, $psaLicence);
            $psaLicence = $resolved['licence'] !== '' ? $resolved['licence'] : $psaLicence;
            $psaExpiry  = $resolved['expiry'] ?? $psaExpiry;
        }

        $psaLicence = apply_normalize_vault_psa_licence($psaLicence);

        $row['psa_licence']     = apply_export_psa_licence($psaLicence);
        $row['psa_expiry_date'] = $psaExpiry;

        $oldStatus = (string) ($row['profile_status'] ?? 'Incomplete');
        $status    = apply_resolve_profile_status($row, $mainStaff);

        $verifiedBy = trim((string) ($row['verified_by'] ?? ''));
        $verifiedAt = $row['verified_at'] ?? null;

        if ($status === 'Verified' && $oldStatus !== 'Verified') {
            $verifiedBy = 'Auto';
            $verifiedAt = date('Y-m-d H:i:s');
        } elseif ($status === 'Verified' && $verifiedBy === '') {
            $verifiedBy = 'Auto';
        } elseif ($status !== 'Verified') {
            $verifiedBy = '';
            $verifiedAt = null;
        }

        $imageSync = apply_vault_psa_images_to_sync_from_main($row, $mainStaff);
        $psaFront  = trim((string) ($row['psa_front_image'] ?? ''));
        $psaBack   = trim((string) ($row['psa_back_image'] ?? ''));
        if (isset($imageSync['psa_front_image'])) {
            $psaFront = $imageSync['psa_front_image'];
        }
        if (isset($imageSync['psa_back_image'])) {
            $psaBack = $imageSync['psa_back_image'];
        }
        $oldFront = trim((string) ($row['psa_front_image'] ?? ''));
        $oldBack  = trim((string) ($row['psa_back_image'] ?? ''));

        if ($status === $oldStatus
            && apply_export_psa_licence($psaLicence) === $oldLicence
            && $psaExpiry === $oldExpiry
            && $psaFront === $oldFront
            && $psaBack === $oldBack) {
            continue;
        }

        try {
            $update->execute([
                'psa'             => $psaLicence,
                'expiry'          => $psaExpiry,
                'status'          => $status,
                'verified_by'     => $verifiedBy !== '' ? $verifiedBy : null,
                'verified_at'     => $verifiedAt,
                'psa_front_image' => $psaFront !== '' ? $psaFront : null,
                'psa_back_image'  => $psaBack !== '' ? $psaBack : null,
                'id'              => (int) $row['id'],
            ]);
            ++$updated;
        } catch (Throwable $e) {
            error_log('[ApplySync] vault status id=' . (int) $row['id'] . ': ' . $e->getMessage());
        }
    }

    return $updated;
}

/** @deprecated Use apply_auto_refresh_vault_profile_statuses() */
function apply_sync_psa_from_main(PDO $eventPdo, PDO $applyPdo): int
{
    return apply_auto_refresh_vault_profile_statuses($applyPdo, $eventPdo);
}

<?php

declare(strict_types=1);

require_once __DIR__ . '/apply-urls.php';
require_once __DIR__ . '/main-admin-bridge.php';

/**
 * @return array{psa_front_image: string, psa_back_image: string}
 */
function apply_main_staff_psa_images_by_email(PDO $eventPdo, string $email): array
{
    $empty = ['psa_front_image' => '', 'psa_back_image' => ''];
    $email = strtolower(trim($email));
    if ($email === '') {
        return $empty;
    }

    try {
        $stmt = $eventPdo->prepare('
            SELECT psa_front_image, psa_back_image
            FROM staff
            WHERE LOWER(TRIM(email)) = :email
            LIMIT 1
        ');
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return $empty;
        }

        return [
            'psa_front_image' => trim((string) ($row['psa_front_image'] ?? '')),
            'psa_back_image'  => trim((string) ($row['psa_back_image'] ?? '')),
        ];
    } catch (Throwable $e) {
        error_log('[ApplyPSA] apply_main_staff_psa_images_by_email: ' . $e->getMessage());

        return $empty;
    }
}

function apply_main_public_uploads_base_url(): string
{
    static $base = null;
    if (is_string($base) && $base !== '') {
        return $base;
    }

    $base = 'https://register.olasentra.com';
    $pdo  = getMainAdminPdo();
    if ($pdo instanceof PDO) {
        $fromSettings = rtrim(apply_read_main_setting($pdo, 'registration_site_url', ''), '/');
        if ($fromSettings !== '') {
            $base = $fromSettings;
        }
    }

    return $base;
}

function apply_is_main_site_psa_path(string $storedPath): bool
{
    $normalized = ltrim(str_replace('\\', '/', trim($storedPath)), '/');

    if ($normalized === '' || $normalized === 'pending-upload') {
        return false;
    }

    if (str_starts_with($normalized, 'uploads/psa/front/')
        || str_starts_with($normalized, 'uploads/psa/back/')) {
        return false;
    }

    return str_starts_with($normalized, 'uploads/psa/');
}

function apply_is_valid_psa_image_path(?string $path): bool
{
    $path = trim((string) $path);

    return $path !== '' && $path !== 'pending-upload';
}

/**
 * Resolve a stored PSA path to a browser URL (main ERP uploads or apply-local vault uploads).
 */
function apply_psa_image_url(string $storedPath): string
{
    if (!apply_is_valid_psa_image_path($storedPath)) {
        return '';
    }

    if (preg_match('#^https?://#i', $storedPath) === 1) {
        return $storedPath;
    }

    $normalized = ltrim(str_replace('\\', '/', $storedPath), '/');
    if (apply_is_main_site_psa_path($normalized)) {
        return rtrim(apply_main_public_uploads_base_url(), '/') . '/' . $normalized;
    }

    return apply_asset_url($normalized);
}

/**
 * Merge vault PSA image fields with main ERP staff row when vault is empty.
 *
 * @param array<string, mixed> $vaultRow
 * @return array<string, mixed>
 */
function apply_merge_vault_psa_images(array $vaultRow, ?PDO $eventPdo = null): array
{
    if (!$eventPdo instanceof PDO) {
        return $vaultRow;
    }

    $front = trim((string) ($vaultRow['psa_front_image'] ?? ''));
    $back  = trim((string) ($vaultRow['psa_back_image'] ?? ''));
    if ($front !== '' && $back !== '') {
        return $vaultRow;
    }

    $main = apply_main_staff_psa_images_by_email($eventPdo, (string) ($vaultRow['email'] ?? ''));
    if ($front === '' && apply_is_valid_psa_image_path($main['psa_front_image'] ?? '')) {
        $vaultRow['psa_front_image'] = $main['psa_front_image'];
    }
    if ($back === '' && apply_is_valid_psa_image_path($main['psa_back_image'] ?? '')) {
        $vaultRow['psa_back_image'] = $main['psa_back_image'];
    }

    return $vaultRow;
}

/**
 * PSA image paths to copy from main ERP when the vault row has none.
 *
 * @param array<string, mixed> $vaultRow
 * @param array<string, mixed>|null $mainStaff
 * @return array<string, string>
 */
function apply_vault_psa_images_to_sync_from_main(array $vaultRow, ?array $mainStaff): array
{
    if ($mainStaff === null) {
        return [];
    }

    $updates = [];
    foreach (['psa_front_image', 'psa_back_image'] as $field) {
        $vaultValue = trim((string) ($vaultRow[$field] ?? ''));
        $mainValue  = trim((string) ($mainStaff[$field] ?? ''));
        if ($vaultValue === '' && apply_is_valid_psa_image_path($mainValue)) {
            $updates[$field] = $mainValue;
        }
    }

    return $updates;
}

function apply_psa_image_local_exists(string $storedPath): bool
{
    if (!apply_is_valid_psa_image_path($storedPath)) {
        return false;
    }

    if (apply_is_main_site_psa_path($storedPath)) {
        return true;
    }

    $relative = ltrim(str_replace('\\', '/', $storedPath), '/');
    $local    = dirname(__DIR__) . '/' . $relative;

    return is_file($local);
}

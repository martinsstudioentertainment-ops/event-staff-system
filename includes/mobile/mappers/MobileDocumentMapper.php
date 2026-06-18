<?php

declare(strict_types=1);

require_once __DIR__ . '/../../staff-psa.php';
require_once __DIR__ . '/../../workforce/compliance-repository.php';

/**
 * @return array<string, array{label: string, category: string, type: string}>
 */
function mobileDocumentCatalog(): array
{
    return [
        'psa_licence' => [
            'label'    => 'PSA Licence',
            'category' => 'compliance',
            'type'     => 'licence',
        ],
        'psa_front' => [
            'label'    => 'PSA Front Image',
            'category' => 'identity',
            'type'     => 'image',
        ],
        'psa_back' => [
            'label'    => 'PSA Back Image',
            'category' => 'identity',
            'type'     => 'image',
        ],
    ];
}

function mobileDocumentIsValidKey(string $key): bool
{
    return isset(mobileDocumentCatalog()[strtolower(trim($key))]);
}

/**
 * @return array<string, mixed>|null
 */
function mobileDocumentResolveFileMeta(array $staff, string $key): ?array
{
    $key = strtolower(trim($key));

    if ($key === 'psa_front') {
        $path = trim((string) ($staff['psa_front_image'] ?? ''));

        return isStoredPsaImagePath($path) ? ['path' => $path, 'field' => 'psa_front_image'] : null;
    }

    if ($key === 'psa_back') {
        $path = trim((string) ($staff['psa_back_image'] ?? ''));

        return isStoredPsaImagePath($path) ? ['path' => $path, 'field' => 'psa_back_image'] : null;
    }

    return null;
}

/**
 * @return array<string, mixed>
 */
function mobileMapDocumentItem(string $key, array $staff, string $apiBasePath = '/api/mobile/v1'): array
{
    $catalog = mobileDocumentCatalog();
    $key     = strtolower(trim($key));
    $meta    = $catalog[$key] ?? ['label' => ucfirst($key), 'category' => 'other', 'type' => 'file'];

    $licence = trim((string) ($staff['psa_licence'] ?? ''));
    $expiry  = trim((string) ($staff['psa_expiry_date'] ?? ''));

    if ($key === 'psa_licence') {
        $status = wf_psa_compliance_status($expiry, $licence);

        return [
            'key'              => $key,
            'label'            => $meta['label'],
            'category'         => $meta['category'],
            'type'             => $meta['type'],
            'expiry'           => $expiry,
            'status'           => $status,
            'approval_status'  => $licence !== '' ? 'submitted' : 'missing',
            'has_file'         => false,
            'view_url'         => '',
            'licence_number'   => $licence !== '' ? $licence : null,
        ];
    }

    $fileMeta = mobileDocumentResolveFileMeta($staff, $key);
    $hasFile  = $fileMeta !== null;
    $status   = $hasFile ? 'valid' : 'missing';

    return [
        'key'             => $key,
        'label'           => $meta['label'],
        'category'        => $meta['category'],
        'type'            => $meta['type'],
        'expiry'          => '',
        'status'          => $status,
        'approval_status' => $hasFile ? 'submitted' : 'missing',
        'has_file'        => $hasFile,
        'view_url'        => $hasFile ? rtrim($apiBasePath, '/') . '/documents/' . $key . '/file' : '',
    ];
}

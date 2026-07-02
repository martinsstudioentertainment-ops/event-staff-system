<?php

declare(strict_types=1);

require_once __DIR__ . '/../../staff-repository.php';
require_once __DIR__ . '/../../staff-psa.php';
require_once __DIR__ . '/../mobile-rate-limit.php';
require_once __DIR__ . '/../mappers/MobileDocumentMapper.php';

function mobileDocumentReadThrottle(int $staffId): void
{
    mobileThrottleOrFail('documents_read_' . $staffId, 120, 60);
}

/**
 * @return array{ok: true, documents: list, summary: array}|array{ok: false, message: string, code: string, status: int}
 */
function mobileDocumentServiceList(PDO $pdo, array $staff): array
{
    $staffId = (int) ($staff['id'] ?? 0);
    mobileDocumentReadThrottle($staffId);

    $fresh = getStaffById($pdo, $staffId);
    if ($fresh === null) {
        return [
            'ok'      => false,
            'message' => 'Staff not found.',
            'code'    => 'NOT_FOUND',
            'status'  => 404,
        ];
    }

    $documents = [];
    $valid = 0;
    $expiring = 0;
    $expired = 0;
    $missing = 0;

    foreach (array_keys(mobileDocumentCatalog()) as $key) {
        $item = mobileMapDocumentItem($key, $fresh);
        $documents[] = $item;

        $status = (string) ($item['status'] ?? '');
        if ($status === 'expiring') {
            $expiring++;
        } elseif ($status === 'expired' || $status === 'missing') {
            if ($status === 'missing') {
                $missing++;
            } else {
                $expired++;
            }
        } else {
            $valid++;
        }
    }

    return [
        'ok'        => true,
        'documents' => $documents,
        'summary'   => [
            'total'    => count($documents),
            'valid'    => $valid,
            'expiring' => $expiring,
            'expired'  => $expired,
            'missing'  => $missing,
        ],
    ];
}

/**
 * Stream document file for authenticated staff. Exits on success.
 *
 * @return array{ok: false, message: string, code: string, status: int}|null Null when file streamed.
 */
function mobileDocumentServiceStreamFile(PDO $pdo, array $staff, string $key): ?array
{
    $staffId = (int) ($staff['id'] ?? 0);
    mobileDocumentReadThrottle($staffId);

    $key = strtolower(trim($key));
    if (!mobileDocumentIsValidKey($key)) {
        return [
            'ok'      => false,
            'message' => 'Unknown document key.',
            'code'    => 'NOT_FOUND',
            'status'  => 404,
        ];
    }

    if ($key === 'psa_licence') {
        return [
            'ok'      => false,
            'message' => 'This document has no downloadable file.',
            'code'    => 'NO_FILE',
            'status'  => 404,
        ];
    }

    $fresh = getStaffById($pdo, $staffId);
    if ($fresh === null) {
        return [
            'ok'      => false,
            'message' => 'Staff not found.',
            'code'    => 'NOT_FOUND',
            'status'  => 404,
        ];
    }

    $fileMeta = mobileDocumentResolveFileMeta($fresh, $key);
    if ($fileMeta === null) {
        return [
            'ok'      => false,
            'message' => 'Document file not found.',
            'code'    => 'NOT_FOUND',
            'status'  => 404,
        ];
    }

    $absolute = psaImageFilesystemPath((string) $fileMeta['path']);
    if ($absolute === '' || !is_file($absolute) || !is_readable($absolute)) {
        return [
            'ok'      => false,
            'message' => 'Document file is unavailable.',
            'code'    => 'NOT_FOUND',
            'status'  => 404,
        ];
    }

    $mime = mobileDocumentDetectMime($absolute);
    $name = basename($absolute);

    if (isset($GLOBALS['mobile_audit']) && is_array($GLOBALS['mobile_audit'])) {
        $GLOBALS['mobile_audit']['staff_id'] = $staffId;
    }

    http_response_code(200);
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . rawurlencode($name) . '"');
    header('Content-Length: ' . (string) filesize($absolute));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');

    if (isset($GLOBALS['mobile_audit']) && is_array($GLOBALS['mobile_audit'])) {
        $ctx = $GLOBALS['mobile_audit'];
        if (($ctx['pdo'] ?? null) instanceof PDO) {
            require_once __DIR__ . '/../mobile-audit-log.php';
            mobileAuditLog(
                $ctx['pdo'],
                (string) ($ctx['path'] ?? ''),
                (string) ($ctx['method'] ?? 'GET'),
                200,
                $staffId
            );
        }
    }

    readfile($absolute);
    exit;
}

function mobileDocumentDetectMime(string $absolutePath): string
{
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $mime = (string) finfo_file($finfo, $absolutePath);
            finfo_close($finfo);
            if ($mime !== '' && $mime !== 'application/octet-stream') {
                return $mime;
            }
        }
    }

    $ext = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));

    return match ($ext) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png'         => 'image/png',
        'webp'        => 'image/webp',
        'gif'         => 'image/gif',
        'heic', 'heif'=> 'image/heic',
        'pdf'         => 'application/pdf',
        default       => 'application/octet-stream',
    };
}

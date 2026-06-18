<?php

declare(strict_types=1);

require_once __DIR__ . '/../production-readiness.php';
require_once __DIR__ . '/../staff-repository.php';

/** @return list<array{key: string, label: string, tracked: bool}> */
function wf_compliance_cert_types(): array
{
    return [
        ['key' => 'right_to_work', 'label' => 'Right to Work', 'tracked' => false],
        ['key' => 'safe_pass', 'label' => 'Safe Pass', 'tracked' => false],
        ['key' => 'manual_handling', 'label' => 'Manual Handling', 'tracked' => false],
        ['key' => 'psa', 'label' => 'PSA License', 'tracked' => true],
        ['key' => 'first_aid', 'label' => 'First Aid', 'tracked' => false],
        ['key' => 'custom', 'label' => 'Custom Certifications', 'tracked' => false],
    ];
}

/** @return 'valid'|'expiring'|'expired'|'missing' */
function wf_psa_compliance_status(?string $expiryDate, ?string $licence = null): string
{
    $licence = trim((string) $licence);
    $expiry  = trim((string) $expiryDate);

    if ($licence === '' && $expiry === '') {
        return 'missing';
    }
    if ($expiry === '') {
        return 'missing';
    }

    $today   = date('Y-m-d');
    $warnEnd = date('Y-m-d', strtotime('+30 days'));

    if ($expiry < $today) {
        return 'expired';
    }
    if ($expiry <= $warnEnd) {
        return 'expiring';
    }

    return 'valid';
}

/** @return array{valid: int, expiring: int, expired: int, missing: int, alerts: list<array<string, mixed>>} */
function wf_compliance_summary(PDO $pdo): array
{
    $summary = ['valid' => 0, 'expiring' => 0, 'expired' => 0, 'missing' => 0, 'alerts' => []];

    if (!tableExists($pdo, 'staff')) {
        return $summary;
    }

    try {
        $rows = $pdo->query(
            'SELECT id, first_name, surname, email, psa_licence, psa_expiry_date, psa_front_image, psa_back_image
             FROM staff ORDER BY surname ASC, first_name ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return $summary;
    }

    foreach ($rows as $row) {
        $status = wf_psa_compliance_status(
            (string) ($row['psa_expiry_date'] ?? ''),
            (string) ($row['psa_licence'] ?? '')
        );
        $summary[$status]++;

        if ($status !== 'valid') {
            $summary['alerts'][] = [
                'staff_id'   => (int) ($row['id'] ?? 0),
                'name'       => trim(((string) ($row['first_name'] ?? '')) . ' ' . ((string) ($row['surname'] ?? ''))),
                'email'      => (string) ($row['email'] ?? ''),
                'cert'       => 'PSA License',
                'status'     => $status,
                'expiry'     => (string) ($row['psa_expiry_date'] ?? ''),
                'licence'    => (string) ($row['psa_licence'] ?? ''),
            ];
        }
    }

    return $summary;
}

/** @return list<array<string, mixed>> */
function wf_staff_documents(PDO $pdo, array $filters = [], int $limit = 100, int $offset = 0): array
{
    if (!tableExists($pdo, 'staff')) {
        return [];
    }

    $where  = ['1=1'];
    $params = [];

    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $where[]     = '(s.first_name LIKE :q OR s.surname LIKE :q OR s.email LIKE :q)';
        $params['q'] = '%' . $q . '%';
    }

    $docType = trim((string) ($filters['doc_type'] ?? ''));
    $status  = trim((string) ($filters['status'] ?? ''));

    $sql = 'SELECT s.id, s.first_name, s.surname, s.email,
                   s.psa_licence, s.psa_expiry_date, s.psa_front_image, s.psa_back_image
            FROM staff s
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY s.surname ASC, s.first_name ASC
            LIMIT ' . max(1, min($limit, 200)) . ' OFFSET ' . max(0, $offset);

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $docs = [];
    foreach ($rows as $row) {
        $psaStatus = wf_psa_compliance_status(
            (string) ($row['psa_expiry_date'] ?? ''),
            (string) ($row['psa_licence'] ?? '')
        );

        $items = [];
        if (trim((string) ($row['psa_licence'] ?? '')) !== '') {
            $items[] = [
                'type'   => 'Licence',
                'label'  => 'PSA License',
                'expiry' => (string) ($row['psa_expiry_date'] ?? ''),
                'status' => $psaStatus,
                'files'  => array_values(array_filter([
                    (string) ($row['psa_front_image'] ?? ''),
                    (string) ($row['psa_back_image'] ?? ''),
                ])),
            ];
        }

        $missing = $items === [];
        $pending = $psaStatus === 'expiring' || $psaStatus === 'expired';

        if ($docType !== '' && $docType !== 'psa') {
            continue;
        }
        if ($status === 'missing' && !$missing) {
            continue;
        }
        if ($status === 'pending' && !$pending) {
            continue;
        }
        if ($status === 'valid' && $psaStatus !== 'valid') {
            continue;
        }

        $docs[] = [
            'staff_id' => (int) ($row['id'] ?? 0),
            'name'     => trim(((string) ($row['first_name'] ?? '')) . ' ' . ((string) ($row['surname'] ?? ''))),
            'email'    => (string) ($row['email'] ?? ''),
            'items'    => $items,
            'missing'  => $missing,
            'pending'  => $pending,
        ];
    }

    return $docs;
}

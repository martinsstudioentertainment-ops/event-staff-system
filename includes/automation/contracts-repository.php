<?php

declare(strict_types=1);

require_once __DIR__ . '/automation-schema.php';

/** @return list<array<string, mixed>> */
function contracts_list_staff(PDO $pdo, ?string $status = null, int $limit = 100): array
{
    if (!tableExists($pdo, 'staff_contracts')) {
        return contracts_staff_from_psa_placeholder($pdo);
    }

    $where  = '1=1';
    $params = [];
    if ($status !== null && $status !== '') {
        $where            = 'c.contract_status = :status';
        $params['status'] = $status;
    }

    $sql = "SELECT c.*, s.first_name, s.surname, s.email
            FROM staff_contracts c
            INNER JOIN staff s ON s.id = c.staff_id
            WHERE {$where}
            ORDER BY c.end_date ASC, c.id DESC
            LIMIT " . max(1, min($limit, 200));

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Placeholder rows from staff without formal contracts table data. */
function contracts_staff_from_psa_placeholder(PDO $pdo): array
{
    if (!tableExists($pdo, 'staff')) {
        return [];
    }

    try {
        $rows = $pdo->query(
            'SELECT id AS staff_id, first_name, surname, email, psa_expiry_date FROM staff ORDER BY surname LIMIT 100'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    return array_map(static function (array $row): array {
        $expiry = (string) ($row['psa_expiry_date'] ?? '');

        return [
            'id'                => 0,
            'staff_id'          => (int) ($row['staff_id'] ?? 0),
            'first_name'        => $row['first_name'] ?? '',
            'surname'           => $row['surname'] ?? '',
            'email'             => $row['email'] ?? '',
            'title'             => 'Employment / PSA compliance',
            'end_date'          => $expiry !== '' ? $expiry : null,
            'contract_status'   => 'active',
            'compliance_status' => $expiry === '' ? 'missing' : ($expiry < date('Y-m-d') ? 'expired' : 'valid'),
            'source'            => 'psa',
        ];
    }, $rows);
}

/** @return list<array<string, mixed>> */
function contracts_list_client(PDO $pdo, ?string $status = null, int $limit = 100): array
{
    if (!tableExists($pdo, 'client_contracts')) {
        return [];
    }

    $where  = '1=1';
    $params = [];
    if ($status !== null && $status !== '') {
        $where            = 'c.contract_status = :status';
        $params['status'] = $status;
    }

    $sql = "SELECT c.*, cl.name AS client_name
            FROM client_contracts c
            INNER JOIN clients cl ON cl.id = c.client_id
            WHERE {$where}
            ORDER BY c.end_date ASC
            LIMIT " . max(1, min($limit, 200));

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function contracts_save_staff(PDO $pdo, array $data, ?int $id = null): bool
{
    if (!tableExists($pdo, 'staff_contracts')) {
        return false;
    }

    $payload = [
        'staff_id'          => (int) ($data['staff_id'] ?? 0),
        'title'             => trim((string) ($data['title'] ?? '')),
        'start_date'        => trim((string) ($data['start_date'] ?? '')) ?: null,
        'end_date'          => trim((string) ($data['end_date'] ?? '')) ?: null,
        'contract_status'   => (string) ($data['contract_status'] ?? 'draft'),
        'signed_at'         => trim((string) ($data['signed_at'] ?? '')) ?: null,
        'document_path'     => trim((string) ($data['document_path'] ?? '')) ?: null,
        'compliance_status' => (string) ($data['compliance_status'] ?? 'missing'),
        'notes'             => trim((string) ($data['notes'] ?? '')) ?: null,
    ];

    if ($payload['staff_id'] < 1 || $payload['title'] === '') {
        return false;
    }

    try {
        if ($id !== null && $id > 0) {
            $payload['id'] = $id;

            return $pdo->prepare(
                'UPDATE staff_contracts SET staff_id=:staff_id, title=:title, start_date=:start_date, end_date=:end_date,
                 contract_status=:contract_status, signed_at=:signed_at, document_path=:document_path,
                 compliance_status=:compliance_status, notes=:notes WHERE id=:id'
            )->execute($payload);
        }

        return $pdo->prepare(
            'INSERT INTO staff_contracts (staff_id, title, start_date, end_date, contract_status, signed_at, document_path, compliance_status, notes)
             VALUES (:staff_id, :title, :start_date, :end_date, :contract_status, :signed_at, :document_path, :compliance_status, :notes)'
        )->execute($payload);
    } catch (Throwable $e) {
        return false;
    }
}

function contracts_save_client(PDO $pdo, array $data, ?int $id = null): bool
{
    if (!tableExists($pdo, 'client_contracts')) {
        return false;
    }

    $payload = [
        'client_id'       => (int) ($data['client_id'] ?? 0),
        'title'           => trim((string) ($data['title'] ?? '')),
        'start_date'      => trim((string) ($data['start_date'] ?? '')) ?: null,
        'end_date'        => trim((string) ($data['end_date'] ?? '')) ?: null,
        'contract_status' => (string) ($data['contract_status'] ?? 'draft'),
        'signed_at'       => trim((string) ($data['signed_at'] ?? '')) ?: null,
        'document_path'   => trim((string) ($data['document_path'] ?? '')) ?: null,
        'value_amount'    => ($data['value_amount'] ?? '') !== '' ? round((float) $data['value_amount'], 2) : null,
        'notes'           => trim((string) ($data['notes'] ?? '')) ?: null,
    ];

    if ($payload['client_id'] < 1 || $payload['title'] === '') {
        return false;
    }

    try {
        if ($id !== null && $id > 0) {
            $payload['id'] = $id;

            return $pdo->prepare(
                'UPDATE client_contracts SET client_id=:client_id, title=:title, start_date=:start_date, end_date=:end_date,
                 contract_status=:contract_status, signed_at=:signed_at, document_path=:document_path,
                 value_amount=:value_amount, notes=:notes WHERE id=:id'
            )->execute($payload);
        }

        return $pdo->prepare(
            'INSERT INTO client_contracts (client_id, title, start_date, end_date, contract_status, signed_at, document_path, value_amount, notes)
             VALUES (:client_id, :title, :start_date, :end_date, :contract_status, :signed_at, :document_path, :value_amount, :notes)'
        )->execute($payload);
    } catch (Throwable $e) {
        return false;
    }
}

/** @return array{expiring: int, expired: int, renewal_due: int, missing: int} */
function contracts_expiry_summary(PDO $pdo): array
{
    $summary = ['expiring' => 0, 'expired' => 0, 'renewal_due' => 0, 'missing' => 0];
    if (!tableExists($pdo, 'staff_contracts')) {
        return $summary;
    }

    try {
        $summary['expired'] = (int) $pdo->query(
            "SELECT COUNT(*) FROM staff_contracts WHERE end_date IS NOT NULL AND end_date < CURDATE()"
        )->fetchColumn();
        $summary['expiring'] = (int) $pdo->query(
            "SELECT COUNT(*) FROM staff_contracts WHERE end_date IS NOT NULL AND end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)"
        )->fetchColumn();
        $summary['renewal_due'] = (int) $pdo->query(
            "SELECT COUNT(*) FROM staff_contracts WHERE contract_status = 'renewal_due'"
        )->fetchColumn();
        $summary['missing'] = (int) $pdo->query(
            "SELECT COUNT(*) FROM staff s
             LEFT JOIN staff_contracts c ON c.staff_id = s.id AND c.contract_status IN ('active','draft')
             WHERE s.is_active = 1 AND c.id IS NULL"
        )->fetchColumn();
    } catch (Throwable $e) {
        // optional
    }

    return $summary;
}

<?php

declare(strict_types=1);

require_once __DIR__ . '/automation-schema.php';

/** @return list<array<string, mixed>> */
function clients_list(PDO $pdo, bool $activeOnly = true, int $limit = 200): array
{
    if (!tableExists($pdo, 'clients')) {
        return clients_list_from_invoices($pdo);
    }

    $where = $activeOnly ? 'WHERE is_active = 1' : '';
    try {
        return $pdo->query(
            "SELECT * FROM clients {$where} ORDER BY name ASC LIMIT " . max(1, min($limit, 500))
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** Fallback when clients table empty — derive from invoice client_name. */
function clients_list_from_invoices(PDO $pdo): array
{
    if (!tableExists($pdo, 'commission_invoices')) {
        return [];
    }

    try {
        $rows = $pdo->query(
            "SELECT client_name AS name, COUNT(*) AS invoice_count,
                    COALESCE(SUM(total_amount), 0) AS revenue_total
             FROM commission_invoices
             WHERE client_name IS NOT NULL AND TRIM(client_name) <> ''
             GROUP BY client_name ORDER BY client_name ASC LIMIT 200"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static fn (array $r): array => array_merge($r, [
            'id' => 0,
            'is_active' => 1,
            'source' => 'invoice',
        ]), $rows);
    } catch (Throwable $e) {
        return [];
    }
}

function clients_save(PDO $pdo, array $data, ?int $id = null): bool
{
    if (!tableExists($pdo, 'clients')) {
        return false;
    }

    $fields = [
        'name'         => trim((string) ($data['name'] ?? '')),
        'contact_name' => trim((string) ($data['contact_name'] ?? '')),
        'email'        => trim((string) ($data['email'] ?? '')),
        'phone'        => trim((string) ($data['phone'] ?? '')),
        'address'      => trim((string) ($data['address'] ?? '')),
        'notes'        => trim((string) ($data['notes'] ?? '')),
        'is_active'    => !empty($data['is_active']) ? 1 : 0,
    ];

    if ($fields['name'] === '') {
        return false;
    }

    try {
        if ($id !== null && $id > 0) {
            $stmt = $pdo->prepare(
                'UPDATE clients SET name=:name, contact_name=:contact_name, email=:email, phone=:phone,
                 address=:address, notes=:notes, is_active=:is_active WHERE id=:id'
            );
            $fields['id'] = $id;

            return $stmt->execute($fields);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO clients (name, contact_name, email, phone, address, notes, is_active)
             VALUES (:name, :contact_name, :email, :phone, :address, :notes, :is_active)'
        );

        return $stmt->execute($fields);
    } catch (Throwable $e) {
        return false;
    }
}

/** @return array<string, mixed>|null */
function clients_get(PDO $pdo, int $id): ?array
{
    if (!tableExists($pdo, 'clients') || $id < 1) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM clients WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/** @return list<array<string, mixed>> */
function clients_event_history(PDO $pdo, int $clientId): array
{
    if ($clientId < 1 || !tableExists($pdo, 'events')) {
        return [];
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT e.* FROM events e WHERE e.client_id = :cid ORDER BY e.event_date DESC LIMIT 50'
        );
        $stmt->execute(['cid' => $clientId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** @return list<array<string, mixed>> */
function clients_invoice_history(PDO $pdo, string $clientName): array
{
    if (!tableExists($pdo, 'commission_invoices') || trim($clientName) === '') {
        return [];
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT * FROM commission_invoices WHERE client_name = :name ORDER BY created_at DESC LIMIT 50'
        );
        $stmt->execute(['name' => $clientName]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** @return list<array<string, mixed>> */
function clients_contacts(PDO $pdo, int $clientId): array
{
    if (!tableExists($pdo, 'client_contacts') || $clientId < 1) {
        return [];
    }

    $stmt = $pdo->prepare('SELECT * FROM client_contacts WHERE client_id = :cid ORDER BY is_primary DESC, contact_name');
    $stmt->execute(['cid' => $clientId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function clients_add_contact(PDO $pdo, int $clientId, array $data): bool
{
    if (!tableExists($pdo, 'client_contacts') || $clientId < 1) {
        return false;
    }

    try {
        return $pdo->prepare(
            'INSERT INTO client_contacts (client_id, contact_name, email, phone, role_title, is_primary)
             VALUES (:cid, :name, :email, :phone, :role, :primary)'
        )->execute([
            'cid'     => $clientId,
            'name'    => trim((string) ($data['contact_name'] ?? '')),
            'email'   => trim((string) ($data['email'] ?? '')),
            'phone'   => trim((string) ($data['phone'] ?? '')),
            'role'    => trim((string) ($data['role_title'] ?? '')),
            'primary' => !empty($data['is_primary']) ? 1 : 0,
        ]);
    } catch (Throwable $e) {
        return false;
    }
}

/** @return list<array<string, mixed>> */
function clients_notes(PDO $pdo, int $clientId): array
{
    if (!tableExists($pdo, 'client_notes') || $clientId < 1) {
        return [];
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT * FROM client_notes WHERE client_id = :cid ORDER BY created_at DESC LIMIT 50'
        );
        $stmt->execute(['cid' => $clientId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function clients_add_note(PDO $pdo, int $clientId, string $noteText, ?int $adminId = null): bool
{
    if (!tableExists($pdo, 'client_notes') || $clientId < 1 || trim($noteText) === '') {
        return false;
    }

    try {
        return $pdo->prepare(
            'INSERT INTO client_notes (client_id, note_text, created_by_admin_id) VALUES (:cid, :note, :admin)'
        )->execute([
            'cid'   => $clientId,
            'note'  => trim($noteText),
            'admin' => $adminId,
        ]);
    } catch (Throwable $e) {
        return false;
    }
}

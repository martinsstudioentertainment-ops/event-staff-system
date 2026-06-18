<?php

declare(strict_types=1);

require_once __DIR__ . '/automation-schema.php';

/** @return list<string> */
function recruitment_stages(): array
{
    return [
        'application_received',
        'screening',
        'interview',
        'approved',
        'training',
        'active_staff',
        'rejected',
    ];
}

function recruitment_stage_label(string $stage): string
{
    return match ($stage) {
        'application_received' => 'Application Received',
        'screening'            => 'Screening',
        'interview'            => 'Interview',
        'approved'             => 'Approved',
        'training'             => 'Training',
        'active_staff'         => 'Active Staff',
        'rejected'             => 'Rejected',
        default                => ucwords(str_replace('_', ' ', $stage)),
    };
}

function recruitment_sync_from_registrations(PDO $pdo): int
{
    if (!tableExists($pdo, 'recruitment_pipeline')) {
        return 0;
    }

    try {
        $rows = $pdo->query(
            "SELECT sr.id AS registration_id, sr.staff_id, sr.status, sr.created_at
             FROM staff_registrations sr
             LEFT JOIN recruitment_pipeline rp ON rp.registration_id = sr.id
             WHERE rp.id IS NULL
             ORDER BY sr.created_at DESC
             LIMIT 500"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return 0;
    }

    $insert = $pdo->prepare(
        'INSERT INTO recruitment_pipeline (staff_id, registration_id, stage, stage_changed_at)
         VALUES (:staff_id, :reg_id, :stage, :changed)'
    );

    $count = 0;
    foreach ($rows as $row) {
        $status = (string) ($row['status'] ?? 'pending');
        $stage  = match ($status) {
            'approved' => 'approved',
            'rejected' => 'rejected',
            default    => 'application_received',
        };
        if ($insert->execute([
            'staff_id' => $row['staff_id'] ?: null,
            'reg_id'   => (int) ($row['registration_id'] ?? 0),
            'stage'    => $stage,
            'changed'  => (string) ($row['created_at'] ?? date('Y-m-d H:i:s')),
        ])) {
            $count++;
        }
    }

    return $count;
}

/** @return array<string, int> */
function recruitment_funnel_metrics(PDO $pdo): array
{
    $metrics = array_fill_keys(recruitment_stages(), 0);

    if (!tableExists($pdo, 'recruitment_pipeline')) {
        return $metrics;
    }

    recruitment_sync_from_registrations($pdo);

    try {
        $rows = $pdo->query(
            'SELECT stage, COUNT(*) AS cnt FROM recruitment_pipeline GROUP BY stage'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $metrics[(string) ($row['stage'] ?? '')] = (int) ($row['cnt'] ?? 0);
        }
    } catch (Throwable $e) {
        // optional
    }

    return $metrics;
}

/** @return list<array<string, mixed>> */
function recruitment_list_by_stage(PDO $pdo, ?string $stage = null, int $limit = 100): array
{
    if (!tableExists($pdo, 'recruitment_pipeline')) {
        return [];
    }

    recruitment_sync_from_registrations($pdo);

    $where  = '1=1';
    $params = [];
    if ($stage !== null && $stage !== '') {
        $where           = 'rp.stage = :stage';
        $params['stage'] = $stage;
    }

    $sql = "SELECT rp.*, sr.first_name, sr.surname, sr.email, sr.staff_role, e.name AS event_name
            FROM recruitment_pipeline rp
            LEFT JOIN staff_registrations sr ON sr.id = rp.registration_id
            LEFT JOIN events e ON e.id = sr.event_id
            WHERE {$where}
            ORDER BY rp.stage_changed_at DESC
            LIMIT " . max(1, min($limit, 200));

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function recruitment_update_stage(PDO $pdo, int $id, string $stage, string $notes = ''): bool
{
    if (!tableExists($pdo, 'recruitment_pipeline') || $id < 1) {
        return false;
    }
    if (!in_array($stage, recruitment_stages(), true)) {
        return false;
    }

    try {
        return $pdo->prepare(
            'UPDATE recruitment_pipeline SET stage = :stage, notes = :notes, stage_changed_at = NOW() WHERE id = :id'
        )->execute(['stage' => $stage, 'notes' => $notes !== '' ? $notes : null, 'id' => $id]);
    } catch (Throwable $e) {
        return false;
    }
}

function recruitment_conversion_rate(array $metrics): float
{
    $received = (int) ($metrics['application_received'] ?? 0);
    $active   = (int) ($metrics['active_staff'] ?? 0) + (int) ($metrics['approved'] ?? 0);

    return $received > 0 ? round(($active / $received) * 100, 1) : 0.0;
}

/** @return list<array<string, mixed>> */
function recruitment_interview_notes(PDO $pdo, int $pipelineId): array
{
    if (!tableExists($pdo, 'recruitment_interview_notes') || $pipelineId < 1) {
        return [];
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT * FROM recruitment_interview_notes WHERE pipeline_id = :pid ORDER BY created_at DESC'
        );
        $stmt->execute(['pid' => $pipelineId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function recruitment_add_interview_note(
    PDO $pdo,
    int $pipelineId,
    string $noteText,
    ?string $interviewDate = null,
    ?int $adminId = null
): bool {
    if (!tableExists($pdo, 'recruitment_interview_notes') || $pipelineId < 1 || trim($noteText) === '') {
        return false;
    }

    try {
        return $pdo->prepare(
            'INSERT INTO recruitment_interview_notes (pipeline_id, note_text, interview_date, created_by_admin_id)
             VALUES (:pid, :note, :idate, :admin)'
        )->execute([
            'pid'   => $pipelineId,
            'note'  => trim($noteText),
            'idate' => $interviewDate,
            'admin' => $adminId,
        ]);
    } catch (Throwable $e) {
        return false;
    }
}

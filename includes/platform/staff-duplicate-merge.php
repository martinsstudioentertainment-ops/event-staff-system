<?php

declare(strict_types=1);

require_once __DIR__ . '/data-integrity.php';
require_once dirname(__DIR__) . '/staff-repository.php';

/** @return list<string> */
function staffMergeStaffIdTables(): array
{
    return [
        'staff_registrations',
        'staff_messages',
        'mobile_refresh_tokens',
        'fcm_device_tokens',
        'mobile_offline_actions',
        'mobile_api_audit',
        'staff_rate_cards',
        'staff_availability',
        'staff_payroll_adjustments',
        'staff_contracts',
        'staff_training_records',
        'staff_incidents',
        'event_roster_assignments',
        'allocation_waitlist',
        'allocation_assignment_log',
        'recruitment_pipeline',
    ];
}

function staffMergeTableExists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    try {
        $cache[$table] = (bool) $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table))->fetchColumn();
    } catch (Throwable $e) {
        $cache[$table] = false;
    }

    return $cache[$table];
}

function staffMergeColumnExists(PDO $pdo, string $table, string $column): bool
{
    if (!staffMergeTableExists($pdo, $table)) {
        return false;
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
        );
        $stmt->execute(['t' => $table, 'c' => $column]);

        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function staffMergeNormalizeName(string $first, string $surname): string
{
    $full = strtolower(trim($first . ' ' . $surname));
    $full = preg_replace('/\s+/', ' ', $full) ?? '';

    return preg_replace('/[^a-z0-9 ]/', '', $full) ?? '';
}

function staffMergeNormalizePps(string $pps): string
{
    return strtoupper(preg_replace('/\s+/', '', trim($pps)) ?? '');
}

function staffMergePhoneKey(string $mobile): string
{
    $digits = preg_replace('/\D/', '', $mobile) ?? '';
    if (strlen($digits) >= 9) {
        return substr($digits, -9);
    }

    return $digits;
}

/** @return list<array<string, mixed>> */
function staffMergeLoadAllStaff(PDO $pdo): array
{
    try {
        return $pdo->query(
            'SELECT id, surname, first_name, email, mobile, pps_number, bank_iban,
                    psa_licence, psa_expiry_date, psa_front_image, psa_back_image,
                    staff_role, profile_completed, date_of_birth, created_at
             FROM staff ORDER BY id ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** @return list<string> */
function staffMergeTestStaffNamePatterns(): array
{
    return [
        'test user', 'demo user', 'sample staff', 'dummy staff', 'fake staff',
        'developer test', 'test staff', 'demo staff', 'save probe', 'user test',
    ];
}

function staffMergeIsTestStaffRow(array $row): bool
{
    $email = strtolower(trim((string) ($row['email'] ?? '')));
    if (dataIntegrityIsTestEmail($email)) {
        return true;
    }
    $name = strtolower(trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['surname'] ?? '')));
    foreach (staffMergeTestStaffNamePatterns() as $pattern) {
        if (str_contains($name, $pattern)) {
            return true;
        }
    }
    $pps = staffMergeNormalizePps((string) ($row['pps_number'] ?? ''));

    return dataIntegrityIsTestPsa($pps);
}

/**
 * Union-find duplicate groups across email, PPS, mobile (with corroboration), name+DOB.
 * Test/dev staff rows are excluded from merge groups.
 *
 * @return list<list<int>>
 */
function staffMergeFindDuplicateGroups(PDO $pdo): array
{
    $rows = staffMergeLoadAllStaff($pdo);
    if ($rows === []) {
        return [];
    }

    /** @var array<int, array<string, mixed>> $byId */
    $byId = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0 && !staffMergeIsTestStaffRow($row)) {
            $byId[$id] = $row;
        }
    }

    if ($byId === []) {
        return [];
    }

    $parent = [];
    $find = static function (int $id) use (&$parent, &$find): int {
        if (!isset($parent[$id])) {
            $parent[$id] = $id;
        }
        if ($parent[$id] !== $id) {
            $parent[$id] = $find($parent[$id]);
        }

        return $parent[$id];
    };
    $union = static function (int $a, int $b) use (&$parent, $find): void {
        $ra = $find($a);
        $rb = $find($b);
        if ($ra !== $rb) {
            $parent[$rb] = $ra;
        }
    };

    /** @var array<string, list<int>> $index */
    $index = ['email' => [], 'pps' => [], 'name' => [], 'phone_pps' => []];

    foreach ($byId as $id => $row) {
        $parent[$id] = $id;

        $email = strtolower(trim((string) ($row['email'] ?? '')));
        if ($email !== '') {
            $index['email'][$email][] = $id;
        }

        $pps = staffMergeNormalizePps((string) ($row['pps_number'] ?? ''));
        if ($pps !== '' && !dataIntegrityIsTestPsa($pps)) {
            $index['pps'][$pps][] = $id;
        }

        $dob = trim((string) ($row['date_of_birth'] ?? ''));
        $nameKey = staffMergeNormalizeName((string) ($row['first_name'] ?? ''), (string) ($row['surname'] ?? ''));
        if ($nameKey !== '' && $dob !== '' && $dob !== '0000-00-00') {
            $index['name'][$nameKey . '|' . $dob][] = $id;
        }

        $phone = staffMergePhoneKey((string) ($row['mobile'] ?? ''));
        if (strlen($phone) >= 9 && $pps !== '' && !dataIntegrityIsTestPsa($pps)) {
            $index['phone_pps'][$phone . '|' . $pps][] = $id;
        }
    }

    foreach ($index as $bucket) {
        foreach ($bucket as $ids) {
            if (count($ids) < 2) {
                continue;
            }
            $first = (int) $ids[0];
            for ($i = 1, $c = count($ids); $i < $c; $i++) {
                $union($first, (int) $ids[$i]);
            }
        }
    }

    /** @var array<int, list<int>> $groups */
    $groups = [];
    foreach (array_keys($parent) as $id) {
        $root = $find((int) $id);
        $groups[$root][] = (int) $id;
    }

    $out = [];
    foreach ($groups as $members) {
        $members = array_values(array_unique(array_filter($members, static fn (int $v): bool => $v > 0)));
        sort($members);
        if (count($members) > 1) {
            $out[] = $members;
        }
    }

    usort($out, static fn (array $a, array $b): int => ($a[0] ?? 0) <=> ($b[0] ?? 0));

    return $out;
}

/** Pick master: oldest id, tie-break by registration count then profile completeness. */
function staffMergePickCanonicalId(PDO $pdo, array $staffIds): int
{
    $staffIds = array_values(array_filter(array_map('intval', $staffIds), static fn (int $id): bool => $id > 0));
    if ($staffIds === []) {
        return 0;
    }
    sort($staffIds);

    $bestId = $staffIds[0];
    $bestScore = -1;

    foreach ($staffIds as $id) {
        $score = 0;
        $row = staffRecordSummary($pdo, $id);
        if ($row === []) {
            continue;
        }
        if (!empty($row['profile_completed'])) {
            $score += 50;
        }
        if (trim((string) ($row['psa_licence'] ?? '')) !== '') {
            $score += 20;
        }
        if (trim((string) ($row['mobile'] ?? '')) !== '') {
            $score += 10;
        }
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM staff_registrations WHERE staff_id = :id');
            $stmt->execute(['id' => $id]);
            $score += min(100, (int) $stmt->fetchColumn() * 5);
        } catch (Throwable $e) {
            // ignore
        }
        // Prefer oldest id when scores tie (lower id wins at equal score)
        if ($score > $bestScore || ($score === $bestScore && $id < $bestId)) {
            $bestScore = $score;
            $bestId    = $id;
        }
    }

    return $bestId;
}

/** @param array<string, mixed> $keeper @param array<string, mixed> $loser */
function staffMergeCombineProfileFields(array $keeper, array $loser): array
{
    $stringFields = [
        'surname', 'first_name', 'full_address', 'eircode', 'email', 'mobile',
        'gender', 'pps_number', 'bank_iban', 'psa_licence', 'psa_expiry_date',
        'psa_front_image', 'psa_back_image', 'staff_role',
    ];

    $merged = $keeper;
    foreach ($stringFields as $field) {
        $k = trim((string) ($keeper[$field] ?? ''));
        $l = trim((string) ($loser[$field] ?? ''));
        if ($k === '' && $l !== '') {
            $merged[$field] = $loser[$field];
        }
    }

    if (empty($keeper['profile_completed']) && !empty($loser['profile_completed'])) {
        $merged['profile_completed'] = $loser['profile_completed'];
    }

    if (empty($keeper['location_lat']) && !empty($loser['location_lat'])) {
        $merged['location_lat'] = $loser['location_lat'];
        $merged['location_lng'] = $loser['location_lng'] ?? null;
    }

    return $merged;
}

/** @return array{ok: bool, actions: list<string>, error?: string} */
function staffMergeProfiles(PDO $pdo, int $keepId, int $mergeId, bool $dryRun = true): array
{
    if ($keepId < 1 || $mergeId < 1 || $keepId === $mergeId) {
        return ['ok' => false, 'actions' => [], 'error' => 'Invalid staff IDs'];
    }

    $keepRow = null;
    $loseRow = null;
    try {
        $stmt = $pdo->prepare('SELECT * FROM staff WHERE id IN (:a, :b)');
        $stmt->execute(['a' => $keepId, 'b' => $mergeId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            if ((int) ($row['id'] ?? 0) === $keepId) {
                $keepRow = $row;
            } elseif ((int) ($row['id'] ?? 0) === $mergeId) {
                $loseRow = $row;
            }
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'actions' => [], 'error' => $e->getMessage()];
    }

    if ($keepRow === null || $loseRow === null) {
        return ['ok' => false, 'actions' => [], 'error' => 'Staff record missing'];
    }

    $actions = [];
    $actions[] = 'Merge staff #' . $mergeId . ' (' . dataIntegrityStaffLabel($loseRow) . ') → #' . $keepId . ' (' . dataIntegrityStaffLabel($keepRow) . ')';

    if ($dryRun) {
        foreach (staffMergeStaffIdTables() as $table) {
            if (!staffMergeTableExists($pdo, $table) || !staffMergeColumnExists($pdo, $table, 'staff_id')) {
                continue;
            }
            $cnt = (int) $pdo->query("SELECT COUNT(*) FROM `{$table}` WHERE staff_id = {$mergeId}")->fetchColumn();
            if ($cnt > 0) {
                $actions[] = "Would reassign {$cnt} row(s) in {$table}.staff_id → #{$keepId}";
            }
        }

        return ['ok' => true, 'actions' => $actions];
    }

    try {
        $pdo->beginTransaction();

        $combined = staffMergeCombineProfileFields($keepRow, $loseRow);
        $update = $pdo->prepare(
            'UPDATE staff SET
                surname = :surname, first_name = :first_name, full_address = :full_address,
                eircode = :eircode, mobile = :mobile, gender = :gender,
                pps_number = :pps_number, bank_iban = :bank_iban,
                psa_licence = :psa_licence, psa_expiry_date = :psa_expiry_date,
                psa_front_image = :psa_front_image, psa_back_image = :psa_back_image,
                staff_role = :staff_role, profile_completed = :profile_completed,
                location_lat = :location_lat, location_lng = :location_lng,
                updated_at = NOW()
             WHERE id = :id'
        );
        $update->execute([
            'surname'           => (string) ($combined['surname'] ?? ''),
            'first_name'        => (string) ($combined['first_name'] ?? ''),
            'full_address'      => (string) ($combined['full_address'] ?? ''),
            'eircode'           => (string) ($combined['eircode'] ?? ''),
            'mobile'            => (string) ($combined['mobile'] ?? ''),
            'gender'            => (string) ($combined['gender'] ?? 'prefer_not_to_say'),
            'pps_number'        => (string) ($combined['pps_number'] ?? ''),
            'bank_iban'         => (string) ($combined['bank_iban'] ?? ''),
            'psa_licence'       => (string) ($combined['psa_licence'] ?? ''),
            'psa_expiry_date'   => ($combined['psa_expiry_date'] ?? null) ?: null,
            'psa_front_image'   => ($combined['psa_front_image'] ?? null) ?: null,
            'psa_back_image'    => ($combined['psa_back_image'] ?? null) ?: null,
            'staff_role'        => (string) ($combined['staff_role'] ?? 'steward'),
            'profile_completed' => (int) ($combined['profile_completed'] ?? 0),
            'location_lat'      => $combined['location_lat'] ?? null,
            'location_lng'      => $combined['location_lng'] ?? null,
            'id'                => $keepId,
        ]);
        $actions[] = 'Merged profile fields into staff #' . $keepId;

        foreach (staffMergeStaffIdTables() as $table) {
            if (!staffMergeTableExists($pdo, $table) || !staffMergeColumnExists($pdo, $table, 'staff_id')) {
                continue;
            }
            $stmt = $pdo->prepare("UPDATE `{$table}` SET staff_id = :keep WHERE staff_id = :lose");
            $stmt->execute(['keep' => $keepId, 'lose' => $mergeId]);
            if ($stmt->rowCount() > 0) {
                $actions[] = "Reassigned {$stmt->rowCount()} row(s) in {$table}";
            }
        }

        if (staffMergeTableExists($pdo, 'platform_trust_scores')) {
            $pdo->prepare('DELETE FROM platform_trust_scores WHERE staff_id = :lose')->execute(['lose' => $mergeId]);
            $actions[] = 'Removed duplicate platform_trust_scores for #' . $mergeId;
        }

        $loseEmail = strtolower(trim((string) ($loseRow['email'] ?? '')));
        if ($loseEmail !== '') {
            $pdo->prepare('UPDATE staff_registrations SET staff_id = :keep WHERE LOWER(email) = :email')
                ->execute(['keep' => $keepId, 'email' => $loseEmail]);
            $pdo->prepare('UPDATE app_notifications SET staff_email = :keep_email WHERE LOWER(staff_email) = :lose_email')
                ->execute([
                    'keep_email' => strtolower(trim((string) ($keepRow['email'] ?? ''))),
                    'lose_email' => $loseEmail,
                ]);
        }

        $pdo->prepare('DELETE FROM staff WHERE id = :id')->execute(['id' => $mergeId]);
        $actions[] = 'Deleted duplicate staff #' . $mergeId;

        if (function_exists('linkStaffIdToRegistrationsByEmail')) {
            linkStaffIdToRegistrationsByEmail($pdo, strtolower(trim((string) ($keepRow['email'] ?? ''))), $keepId);
        }

        $pdo->commit();

        return ['ok' => true, 'actions' => $actions];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'actions' => $actions, 'error' => $e->getMessage()];
    }
}

/** @return list<array<string, mixed>> */
function staffMergeAuditGroups(PDO $pdo): array
{
    $groups = staffMergeFindDuplicateGroups($pdo);
    $out    = [];

    foreach ($groups as $members) {
        $canonical = staffMergePickCanonicalId($pdo, $members);
        $records   = [];
        foreach ($members as $id) {
            $records[] = staffRecordSummary($pdo, $id);
        }
        $out[] = [
            'staff_ids'   => $members,
            'canonical'   => $canonical,
            'duplicates'  => array_values(array_filter($members, static fn (int $id): bool => $id !== $canonical)),
            'records'     => $records,
            'match_types' => staffMergeDescribeGroup($pdo, $members),
        ];
    }

    return $out;
}

/** @param list<int> $ids @return list<string> */
function staffMergeDescribeGroup(PDO $pdo, array $ids): array
{
    $rows = [];
    foreach ($ids as $id) {
        try {
            $stmt = $pdo->prepare('SELECT email, mobile, pps_number, first_name, surname, date_of_birth FROM staff WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $id]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($r)) {
                $rows[] = $r;
            }
        } catch (Throwable $e) {
            continue;
        }
    }
    $reasons = [];
    $emails = [];
    $pps    = [];
    $phones = [];
    foreach ($rows as $r) {
        $emails[] = strtolower(trim((string) ($r['email'] ?? '')));
        $pps[]    = staffMergeNormalizePps((string) ($r['pps_number'] ?? ''));
        $phones[] = staffMergePhoneKey((string) ($r['mobile'] ?? ''));
    }
    if (count(array_unique(array_filter($emails))) === 1 && count(array_filter($emails)) > 0) {
        $reasons[] = 'email';
    }
    $ppsU = array_unique(array_filter($pps));
    if (count($ppsU) === 1 && $ppsU !== ['']) {
        $reasons[] = 'pps';
    }
    $phU = array_unique(array_filter($phones, static fn (string $p): bool => strlen($p) >= 9));
    if (count($phU) === 1) {
        $reasons[] = 'mobile';
    }
    if ($reasons === []) {
        $reasons[] = 'name_dob';
    }

    return $reasons;
}

/** @return array{merged: int, deleted: int, groups: int, log: list<array<string, mixed>>, errors: list<string>} */
function staffMergeExecuteAll(PDO $pdo, bool $dryRun = true): array
{
    $groups  = staffMergeAuditGroups($pdo);
    $log     = [];
    $errors  = [];
    $merged  = 0;
    $deleted = 0;

    foreach ($groups as $group) {
        $keepId = (int) ($group['canonical'] ?? 0);
        foreach ($group['duplicates'] as $loseId) {
            $loseId = (int) $loseId;
            $result = staffMergeProfiles($pdo, $keepId, $loseId, $dryRun);
            $entry  = [
                'keep_id'  => $keepId,
                'merge_id' => $loseId,
                'ok'       => $result['ok'],
                'actions'  => $result['actions'],
                'error'    => $result['error'] ?? null,
            ];
            $log[] = $entry;
            if ($result['ok']) {
                if (!$dryRun) {
                    $merged++;
                    $deleted++;
                }
            } else {
                $errors[] = 'Merge #' . $loseId . ' → #' . $keepId . ': ' . (string) ($result['error'] ?? 'failed');
            }
        }
    }

    return [
        'groups'  => count($groups),
        'merged'  => $merged,
        'deleted' => $deleted,
        'log'     => $log,
        'errors'  => $errors,
    ];
}

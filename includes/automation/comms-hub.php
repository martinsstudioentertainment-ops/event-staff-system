<?php

declare(strict_types=1);

require_once __DIR__ . '/automation-schema.php';
require_once __DIR__ . '/../staff-messaging.php';
require_once __DIR__ . '/../staff-broadcast.php';
require_once __DIR__ . '/../notification-center.php';
require_once __DIR__ . '/../workforce/workforce-analytics.php';

function comms_staff_blacklist_sql(PDO $pdo, string $alias = 's'): string
{
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM staff')->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('is_blacklisted', $cols, true)) {
            return " AND {$alias}.is_blacklisted = 0";
        }
    } catch (Throwable $e) {
        // optional column
    }

    return '';
}

/**
 * Mailable staff rows — staff directory first, then registrations fallback.
 *
 * @return list<array<string, mixed>>
 */
function comms_fetch_base_staff_pool(PDO $pdo, int $eventId, string $role = ''): array
{
    require_once __DIR__ . '/../staff-repository.php';

    if (!tableExists($pdo, 'staff')) {
        return comms_staff_pool_from_registrations($pdo, $eventId, $role);
    }

    $blacklistSql = comms_staff_blacklist_sql($pdo, 's');
    $roleSql      = '';
    $params       = [];

    if (in_array($role, ['dsp', 'static', 'steward'], true)) {
        $roleSql         = ' AND s.staff_role = :role';
        $params['role']  = $role;
    }

    try {
        if ($eventId > 0) {
            $sql = 'SELECT DISTINCT s.*
                    FROM staff s
                    INNER JOIN staff_registrations sr
                        ON sr.event_id = :eid
                        AND sr.status = \'approved\'
                        AND LOWER(TRIM(sr.email)) = LOWER(TRIM(s.email))
                    WHERE s.email IS NOT NULL AND TRIM(s.email) <> \'\''
                . $blacklistSql . $roleSql;
            $params['eid'] = $eventId;
            $stmt          = $pdo->prepare($sql);
            $stmt->execute($params);
        } else {
            $sql  = 'SELECT s.* FROM staff s
                    WHERE s.email IS NOT NULL AND TRIM(s.email) <> \'\''
                . $blacklistSql . $roleSql;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('[EventStaff] comms_fetch_base_staff_pool: ' . $e->getMessage());
        $rows = [];
    }

    if ($rows !== []) {
        return $rows;
    }

    return comms_staff_pool_from_registrations($pdo, $eventId, $role);
}

/**
 * @return list<array<string, mixed>>
 */
function comms_staff_pool_from_registrations(PDO $pdo, int $eventId, string $role = ''): array
{
    require_once __DIR__ . '/../staff-repository.php';

    try {
        if ($eventId > 0) {
            $stmt = $pdo->prepare(
                "SELECT DISTINCT LOWER(TRIM(email)) AS email
                 FROM staff_registrations
                 WHERE event_id = :eid AND status = 'approved'
                   AND email IS NOT NULL AND TRIM(email) <> ''"
            );
            $stmt->execute(['eid' => $eventId]);
        } else {
            $stmt = $pdo->query(
                "SELECT DISTINCT LOWER(TRIM(email)) AS email
                 FROM staff_registrations
                 WHERE email IS NOT NULL AND TRIM(email) <> ''"
            );
        }

        $emails = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        error_log('[EventStaff] comms_staff_pool_from_registrations: ' . $e->getMessage());

        return [];
    }

    $out = [];
    foreach ($emails as $email) {
        $email = strtolower(trim((string) $email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }

        $staffId = ensureStaffRecordForEmail($pdo, $email);
        if ($staffId === null) {
            continue;
        }

        $staff = getStaffById($pdo, $staffId);
        if ($staff === null) {
            continue;
        }

        if ($role !== '' && in_array($role, ['dsp', 'static', 'steward'], true)
            && (string) ($staff['staff_role'] ?? '') !== $role) {
            continue;
        }

        if ((int) ($staff['is_blacklisted'] ?? 0) === 1) {
            continue;
        }

        $out[$staffId] = $staff;
    }

    return array_values($out);
}

/** @return array<string, mixed> */
function comms_signin_template_name(): string
{
    return 'Sign-in guide (staff app vs venue)';
}

function comms_signin_guide_body(PDO $pdo): string
{
    require_once __DIR__ . '/../site-urls.php';

    $staffAppUrl = rtrim(getRegistrationSiteUrl($pdo), '/') . '/staff-app.php';

    return '<p>Hi everyone,</p>'
        . '<p>There are <strong>two different sign-ins</strong>. Please use the right one:</p>'
        . '<h3>1. Staff app (shifts, messages, profile)</h3>'
        . '<ul><li>Open: <a href="' . htmlspecialchars($staffAppUrl, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($staffAppUrl, ENT_QUOTES, 'UTF-8') . '</a></li>'
        . '<li>Sign in with your <strong>email</strong> and <strong>date of birth</strong>.</li>'
        . '<li>Use this to view your shifts, update your profile, and read messages from the office.</li></ul>'
        . '<h3>2. Venue sign-in (attendance at the event)</h3>'
        . '<p>This is <strong>not</strong> email + date of birth. At the venue you use:</p>'
        . '<ol><li><strong>Scan the QR code</strong> at the event entrance.</li>'
        . '<li><strong>Allow location (GPS)</strong> when asked.</li>'
        . '<li>Enter your <strong>registration email</strong> and the <strong>last 4 characters of your PPS</strong>.</li>'
        . '<li>Tap <strong>Find My Registration</strong>, then <strong>Check In Now</strong>.</li></ol>'
        . '<p><strong>Important:</strong> Your email and PPS last 4 must match your registration exactly. Sign-in only works when you are <strong>approved</strong> for the event.</p>'
        . '<h3>Need help?</h3>'
        . '<ul><li><strong>Staff app won’t open?</strong> Check email + date of birth.</li>'
        . '<li><strong>Venue sign-in fails?</strong> Check email + PPS last 4, turn GPS on, and make sure you’re at the venue.</li>'
        . '<li>Still stuck? Ask the supervisor on site or reply in the staff app.</li></ul>'
        . '<p>Thank you,<br>Olasentra Events Team</p>';
}

function comms_compose_defaults(PDO $pdo): array
{
    return [
        'channel'           => 'internal',
        'event_id'          => 0,
        'role'              => '',
        'venue'             => '',
        'risk'              => '',
        'compliance_status' => '',
        'min_reliability'   => '',
        'attendance_status' => '',
        'subject'           => 'How to sign in — staff app vs venue check-in',
        'body'              => comms_signin_guide_body($pdo),
    ];
}

/** Ensure the default internal sign-in template exists in comms_message_templates. */
function comms_ensure_signin_internal_template(PDO $pdo): ?int
{
    auto_ensure_phase67_schema($pdo);
    if (!tableExists($pdo, 'comms_message_templates')) {
        return null;
    }

    $name    = comms_signin_template_name();
    $subject = 'How to sign in — staff app vs venue check-in';
    $body    = comms_signin_guide_body($pdo);

    try {
        $stmt = $pdo->prepare('SELECT id FROM comms_message_templates WHERE name = :name AND channel = :channel LIMIT 1');
        $stmt->execute(['name' => $name, 'channel' => 'internal']);
        $existingId = (int) ($stmt->fetchColumn() ?: 0);
        if ($existingId > 0) {
            return $existingId;
        }

        if (comms_save_template($pdo, [
            'name'    => $name,
            'channel' => 'internal',
            'subject' => $subject,
            'body'    => $body,
        ])) {
            return (int) $pdo->lastInsertId();
        }
    } catch (Throwable $e) {
        error_log('[EventStaff] comms_ensure_signin_internal_template: ' . $e->getMessage());
    }

    return null;
}

/** Send the sign-in guide to every staff member’s direct inbox (no email). */
function comms_send_signin_inbox_to_all(PDO $pdo, ?int $adminId = null): array
{
    $defaults = comms_compose_defaults($pdo);

    return comms_send_campaign(
        $pdo,
        'internal',
        (string) $defaults['subject'],
        (string) $defaults['body'],
        [
            'event_id'          => 0,
            'role'              => '',
            'venue'             => '',
            'risk'              => '',
            'min_reliability'   => '',
            'attendance_status' => '',
            'compliance_status' => '',
        ],
        $adminId
    );
}

function comms_option_selected(string $current, string $value): string
{
    return $current === $value ? ' selected' : '';
}

/** @param array<string, mixed> $filters */
function comms_resolve_recipients(PDO $pdo, array $filters): array
{
    $eventId = (int) ($filters['event_id'] ?? 0);
    $role    = trim((string) ($filters['role'] ?? ''));

    $staff = comms_fetch_base_staff_pool($pdo, $eventId, $role);

    if ($staff === []) {
        return [];
    }

    $period = '30d';
    $perf   = wf_list_staff_performance($pdo, $period, [], 2000, 0);
    $perfById = [];
    foreach ($perf as $row) {
        $perfById[(int) ($row['id'] ?? 0)] = $row;
    }

    $out = [];
    foreach ($staff as $row) {
        $id    = (int) ($row['id'] ?? 0);
        $email = strtolower(trim((string) ($row['email'] ?? '')));
        if ($email === '') {
            continue;
        }

        $p = $perfById[$id] ?? null;
        if (($filters['risk'] ?? '') !== '' && ($p['risk'] ?? '') !== $filters['risk']) {
            continue;
        }
        if (($filters['min_reliability'] ?? '') !== '' && (int) ($p['score'] ?? 0) < (int) $filters['min_reliability']) {
            continue;
        }

        if (($filters['attendance_status'] ?? '') !== '') {
            $att = (string) ($filters['attendance_status'] ?? '');
            if ($att === 'high' && (int) ($p['attendance_pct'] ?? 0) < 80) {
                continue;
            }
            if ($att === 'low' && (int) ($p['attendance_pct'] ?? 0) >= 80) {
                continue;
            }
        }

        if (($filters['venue'] ?? '') !== '') {
            $venueNeedle = strtolower(trim((string) $filters['venue']));
            $staffVenue    = strtolower(trim((string) ($row['preferred_venue'] ?? $row['location'] ?? '')));
            if ($venueNeedle !== '' && !str_contains($staffVenue, $venueNeedle)) {
                continue;
            }
        }

        if (($filters['compliance_status'] ?? '') !== '') {
            $comp = (string) ($filters['compliance_status'] ?? '');
            $psa  = (string) ($p['psa_status'] ?? $row['psa_status'] ?? '');
            if ($comp === 'valid' && $psa !== 'valid') {
                continue;
            }
            if ($comp === 'expiring' && $psa !== 'expiring') {
                continue;
            }
            if ($comp === 'expired' && $psa !== 'expired') {
                continue;
            }
            if ($comp === 'missing' && $psa !== 'missing' && $psa !== '') {
                continue;
            }
        }

        $out[] = array_merge($row, $p ?? []);
    }

    return $out;
}

/** @param array<string, mixed> $filters */
function comms_send_campaign(PDO $pdo, string $channel, string $subject, string $body, array $filters, ?int $adminId = null): array
{
    auto_ensure_schema($pdo);

    $recipients = comms_resolve_recipients($pdo, $filters);
    $sent       = 0;
    $failed     = 0;

    if ($channel === 'email' && $recipients !== []) {
        require_once __DIR__ . '/../staff-messages.php';
        foreach ($recipients as $recipient) {
            $email = (string) ($recipient['email'] ?? '');
            $staffId = (int) ($recipient['id'] ?? 0);
            if ($email === '' || $staffId < 1) {
                continue;
            }
            $ok = sendStaffDirectEmail($pdo, $email, $subject, $body);
            $record = recordAdminEmailInThread($pdo, $staffId, $subject, $body, (int) ($adminId ?? 0), $ok);
            if ($ok && !empty($record['ok'])) {
                notifyStaffInApp(
                    $pdo,
                    $email,
                    'admin_reply',
                    $subject !== '' ? $subject : 'Message from management',
                    mb_strlen($body) > 180 ? mb_substr($body, 0, 177) . '…' : $body,
                    'staff-messages.php'
                );
                $sent++;
            } else {
                $failed++;
            }
        }
    } elseif ($channel === 'internal') {
        require_once __DIR__ . '/../staff-messages.php';
        foreach ($recipients as $recipient) {
            $email = (string) ($recipient['email'] ?? '');
            $staffId = (int) ($recipient['id'] ?? 0);
            if ($email === '' || $staffId < 1) {
                continue;
            }
            $result = recordAdminOutboundStaffMessage(
                $pdo,
                $staffId,
                $subject !== '' ? $subject : 'Message from management',
                $body,
                (int) ($adminId ?? 0),
                false,
                true
            );
            if (!empty($result['ok'])) {
                $sent++;
            } else {
                $failed++;
            }
        }
    } elseif ($channel === 'whatsapp') {
        // Link-only channel — record campaign; admin uses WhatsApp group link manually
        $sent = count($recipients);
    } elseif ($channel === 'sms') {
        // SMS not configured — record as draft only
        $failed = count($recipients);
    }

    if (tableExists($pdo, 'comms_campaigns')) {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO comms_campaigns (channel, subject, body, filter_json, target_count, sent_count, status, created_by_admin_id, sent_at)
                 VALUES (:channel, :subject, :body, :filters, :target, :sent, :status, :admin, NOW())'
            );
            $stmt->execute([
                'channel' => $channel,
                'subject' => $subject !== '' ? $subject : null,
                'body'    => $body,
                'filters' => json_encode($filters, JSON_THROW_ON_ERROR),
                'target'  => count($recipients),
                'sent'    => $sent,
                'status'  => $sent > 0 ? 'sent' : 'failed',
                'admin'   => $adminId,
            ]);
        } catch (Throwable $e) {
            // optional
        }
    }

    return ['target' => count($recipients), 'sent' => $sent, 'failed' => $failed];
}

/** @return list<array<string, mixed>> */
function comms_recent_campaigns(PDO $pdo, int $limit = 20): array
{
    if (!tableExists($pdo, 'comms_campaigns')) {
        return [];
    }

    try {
        return $pdo->query(
            'SELECT * FROM comms_campaigns ORDER BY created_at DESC LIMIT ' . max(1, min($limit, 50))
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function sendStaffDirectEmail(PDO $pdo, string $email, string $subject, string $body): bool
{
    require_once __DIR__ . '/../mailer.php';
    require_once __DIR__ . '/../rich-text.php';

    $parts = richEmailParts($body);

    return sendEmail($pdo, $email, $subject, $parts['plain'], $parts['html']);
}

/** @return list<array<string, mixed>> */
function comms_list_templates(PDO $pdo, ?string $channel = null): array
{
    auto_ensure_phase67_schema($pdo);
    if (!tableExists($pdo, 'comms_message_templates')) {
        return [];
    }

    $where  = '';
    $params = [];
    if ($channel !== null && $channel !== '') {
        $where           = 'WHERE channel = :channel';
        $params['channel'] = $channel;
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM comms_message_templates {$where} ORDER BY name ASC");
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function comms_save_template(PDO $pdo, array $data, ?int $id = null): bool
{
    auto_ensure_phase67_schema($pdo);
    if (!tableExists($pdo, 'comms_message_templates')) {
        return false;
    }

    $payload = [
        'name'    => trim((string) ($data['name'] ?? '')),
        'channel' => (string) ($data['channel'] ?? 'email'),
        'subject' => trim((string) ($data['subject'] ?? '')),
        'body'    => trim((string) ($data['body'] ?? '')),
    ];
    if ($payload['name'] === '' || $payload['body'] === '') {
        return false;
    }

    try {
        if ($id !== null && $id > 0) {
            $payload['id'] = $id;

            return $pdo->prepare(
                'UPDATE comms_message_templates SET name=:name, channel=:channel, subject=:subject, body=:body WHERE id=:id'
            )->execute($payload);
        }

        return $pdo->prepare(
            'INSERT INTO comms_message_templates (name, channel, subject, body) VALUES (:name, :channel, :subject, :body)'
        )->execute($payload);
    } catch (Throwable $e) {
        return false;
    }
}

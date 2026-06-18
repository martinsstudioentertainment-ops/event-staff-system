<?php

declare(strict_types=1);

require_once __DIR__ . '/staff-messages-schema.php';
require_once __DIR__ . '/staff-repository.php';
require_once __DIR__ . '/notification-center.php';
require_once __DIR__ . '/status-repository.php';
require_once __DIR__ . '/audit-log.php';

function normalizeStaffMessageEmail(string $email): string
{
    return strtolower(trim($email));
}

/**
 * Canonical staff row for inbox links (directory vs message staff_id can differ).
 */
function resolveCanonicalStaffIdForEmail(PDO $pdo, string $email): int
{
    $email = normalizeStaffMessageEmail($email);
    if ($email === '') {
        return 0;
    }

    $staff = getStaffByEmail($pdo, $email);
    if ($staff !== null) {
        return (int) ($staff['id'] ?? 0);
    }

    return (int) (ensureStaffRecordForEmail($pdo, $email) ?? 0);
}

/**
 * All emails and staff IDs that belong to one conversation mailbox.
 *
 * @return array{emails: string[], staff_ids: int[]}
 */
function resolveStaffThreadScope(PDO $pdo, int $staffId): array
{
    $emails   = [];
    $staffIds = [];

    if ($staffId < 1) {
        return ['emails' => [], 'staff_ids' => []];
    }

    $staffIds[] = $staffId;
    $staff      = getStaffById($pdo, $staffId);
    if ($staff !== null) {
        $primary = normalizeStaffMessageEmail((string) ($staff['email'] ?? ''));
        if ($primary !== '') {
            $emails[] = $primary;
        }
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT DISTINCT LOWER(TRIM(email)) AS email
             FROM staff_registrations
             WHERE staff_id = :staff_id OR LOWER(TRIM(email)) = :primary_email'
        );
        $stmt->execute([
            'staff_id'      => $staffId,
            'primary_email' => $emails[0] ?? '',
        ]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $em = normalizeStaffMessageEmail((string) ($row['email'] ?? ''));
            if ($em !== '') {
                $emails[] = $em;
            }
        }
    } catch (Throwable $e) {
        error_log('[EventStaff] resolveStaffThreadScope registrations: ' . $e->getMessage());
    }

    $emails = array_values(array_unique(array_filter($emails)));

    foreach ($emails as $email) {
        $linked = getStaffByEmail($pdo, $email);
        if ($linked !== null) {
            $staffIds[] = (int) ($linked['id'] ?? 0);
        }
        $linkedId = ensureStaffRecordForEmail($pdo, $email);
        if ($linkedId !== null && $linkedId > 0) {
            $staffIds[] = $linkedId;
        }
    }

    try {
        if ($emails !== [] || $staffIds !== []) {
            $clauses = [];
            $params  = [];
            if ($staffIds !== []) {
                $keys = [];
                foreach ($staffIds as $index => $id) {
                    $key          = 'scope_sid_' . $index;
                    $keys[]       = ':' . $key;
                    $params[$key] = $id;
                }
                $clauses[] = 'staff_id IN (' . implode(',', $keys) . ')';
            }
            if ($emails !== []) {
                $keys = [];
                foreach ($emails as $index => $email) {
                    $key          = 'scope_em_' . $index;
                    $keys[]       = ':' . $key;
                    $params[$key] = $email;
                }
                $clauses[] = 'LOWER(TRIM(staff_email)) IN (' . implode(',', $keys) . ')';
            }
            $stmt = $pdo->prepare(
                'SELECT DISTINCT staff_id, LOWER(TRIM(staff_email)) AS email
                 FROM staff_messages
                 WHERE ' . implode(' OR ', $clauses)
            );
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $messageStaffId = (int) ($row['staff_id'] ?? 0);
                if ($messageStaffId > 0) {
                    $staffIds[] = $messageStaffId;
                }
                $em = normalizeStaffMessageEmail((string) ($row['email'] ?? ''));
                if ($em !== '') {
                    $emails[] = $em;
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[EventStaff] resolveStaffThreadScope messages: ' . $e->getMessage());
    }

    $emails   = array_values(array_unique(array_filter($emails)));
    $staffIds = array_values(array_unique(array_filter(array_map('intval', $staffIds))));

    return [
        'emails'    => $emails,
        'staff_ids' => $staffIds,
    ];
}

/**
 * @param array{emails: string[], staff_ids: int[]} $scope
 * @return array<int, array<string, mixed>>
 */
function fetchStaffMessageThreadRows(PDO $pdo, array $scope): array
{
    $emails   = $scope['emails'] ?? [];
    $staffIds = $scope['staff_ids'] ?? [];

    if ($emails === [] && $staffIds === []) {
        return [];
    }

    ensureStaffMessagesSchema($pdo);

    $where  = [];
    $params = [];

    if ($staffIds !== []) {
        $keys = [];
        foreach ($staffIds as $index => $staffId) {
            $key          = 'staff_id_' . $index;
            $keys[]       = ':' . $key;
            $params[$key] = $staffId;
        }
        $where[] = 'm.staff_id IN (' . implode(',', $keys) . ')';
    }

    if ($emails !== []) {
        $keys = [];
        foreach ($emails as $index => $email) {
            $key          = 'email_' . $index;
            $keys[]       = ':' . $key;
            $params[$key] = $email;
        }
        $where[] = 'LOWER(TRIM(m.staff_email)) IN (' . implode(',', $keys) . ')';
    }

    try {
        $sql = 'SELECT m.*, u.full_name AS admin_name
                FROM staff_messages m
                LEFT JOIN admin_users u ON u.id = m.admin_user_id
                WHERE (' . implode(' OR ', $where) . ')
                ORDER BY m.created_at ASC
                LIMIT 500';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('[EventStaff] fetchStaffMessageThreadRows: ' . $e->getMessage());

        try {
            $sql = 'SELECT m.*
                    FROM staff_messages m
                    WHERE (' . implode(' OR ', $where) . ')
                    ORDER BY m.created_at ASC
                    LIMIT 500';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as &$row) {
                $row['admin_name'] = null;
            }

            return $rows;
        } catch (Throwable $inner) {
            error_log('[EventStaff] fetchStaffMessageThreadRows fallback: ' . $inner->getMessage());

            return [];
        }
    }
}

/**
 * @param array<string, mixed> $fields
 */
function insertStaffMessageRow(PDO $pdo, array $fields): int
{
    ensureStaffMessagesSchema($pdo);

    $columns = ['staff_id', 'staff_email', 'direction', 'body'];
    $values  = [
        'staff_id'    => (int) ($fields['staff_id'] ?? 0),
        'staff_email' => (string) ($fields['staff_email'] ?? ''),
        'direction'   => (string) ($fields['direction'] ?? ''),
        'body'        => (string) ($fields['body'] ?? ''),
    ];

    if (columnExists($pdo, 'staff_messages', 'subject')) {
        $columns[]         = 'subject';
        $values['subject'] = $fields['subject'] ?? null;
    }
    if (columnExists($pdo, 'staff_messages', 'delivery_status')) {
        $columns[]                  = 'delivery_status';
        $values['delivery_status']  = $fields['delivery_status'] ?? null;
    }
    if (columnExists($pdo, 'staff_messages', 'recipient_email')) {
        $columns[]                    = 'recipient_email';
        $values['recipient_email']    = $fields['recipient_email'] ?? null;
    }
    if (columnExists($pdo, 'staff_messages', 'admin_user_id')) {
        $columns[]               = 'admin_user_id';
        $values['admin_user_id'] = $fields['admin_user_id'] ?? null;
    }

    $placeholders = [];
    $params       = [];
    foreach ($columns as $column) {
        $placeholders[]   = ':' . $column;
        $params[$column]  = $values[$column];
    }

    $sql = 'INSERT INTO staff_messages (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int) $pdo->lastInsertId();
}

function countUnreadStaffMessagesForAdmin(PDO $pdo): int
{
    ensureStaffMessagesSchema($pdo);

    try {
        return (int) $pdo->query(
            "SELECT COUNT(*) FROM staff_messages WHERE direction = 'staff_to_admin' AND is_read = 0"
        )->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function countUnreadAdminRepliesForStaff(PDO $pdo, string $email): int
{
    $email = normalizeStaffMessageEmail($email);
    if ($email === '') {
        return 0;
    }

    ensureStaffMessagesSchema($pdo);

    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM staff_messages
             WHERE LOWER(staff_email) = :email AND direction = 'admin_to_staff' AND is_read = 0"
        );
        $stmt->execute(['email' => $email]);

        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * @return array<int, array<string, mixed>>
 */
function listStaffInboxThreads(PDO $pdo, int $limit = 50): array
{
    ensureStaffMessagesSchema($pdo);
    $limit = max(1, min($limit, 100));

    try {
        $sql = "
            SELECT COALESCE(
                       (SELECT s2.id FROM staff s2 WHERE LOWER(TRIM(s2.email)) = LOWER(TRIM(m.staff_email)) ORDER BY s2.id ASC LIMIT 1),
                       MIN(m.staff_id)
                   ) AS staff_id,
                   LOWER(TRIM(m.staff_email)) AS staff_email,
                   s.first_name,
                   s.surname,
                   MAX(m.created_at) AS last_at,
                   SUM(CASE WHEN m.direction = 'staff_to_admin' AND m.is_read = 0 THEN 1 ELSE 0 END) AS unread_count,
                   SUBSTRING_INDEX(GROUP_CONCAT(m.body ORDER BY m.created_at DESC SEPARATOR '\x1e'), '\x1e', 1) AS last_body,
                   SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(m.subject, '') ORDER BY m.created_at DESC SEPARATOR '\x1e'), '\x1e', 1) AS last_subject
            FROM staff_messages m
            LEFT JOIN staff s ON LOWER(TRIM(s.email)) = LOWER(TRIM(m.staff_email))
            GROUP BY LOWER(TRIM(m.staff_email)), s.first_name, s.surname
            ORDER BY last_at DESC
            LIMIT {$limit}
        ";

        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('[EventStaff] listStaffInboxThreads: ' . $e->getMessage());

        return [];
    }
}

/**
 * @return array<int, array<string, mixed>>
 */
function getStaffMessageThreadForEmail(PDO $pdo, string $email): array
{
    $email = normalizeStaffMessageEmail($email);
    if ($email === '') {
        return [];
    }

    $staffId = resolveCanonicalStaffIdForEmail($pdo, $email);
    $scope   = ['emails' => [$email], 'staff_ids' => $staffId > 0 ? [$staffId] : []];

    if ($staffId > 0) {
        $scope = resolveStaffThreadScope($pdo, $staffId);
        if (!in_array($email, $scope['emails'], true)) {
            $scope['emails'][] = $email;
        }
    }

    $messages = fetchStaffMessageThreadRows($pdo, $scope);
    if ($messages !== []) {
        return $messages;
    }

    return fetchStaffMessageThreadRows($pdo, ['emails' => [$email], 'staff_ids' => []]);
}

/**
 * @return array<int, array<string, mixed>>
 */
function getStaffMessageThread(PDO $pdo, int $staffId): array
{
    if ($staffId < 1) {
        return [];
    }

    $staff = getStaffById($pdo, $staffId);
    if ($staff !== null) {
        $email = normalizeStaffMessageEmail((string) ($staff['email'] ?? ''));
        if ($email !== '') {
            $messages = getStaffMessageThreadForEmail($pdo, $email);
            if ($messages !== []) {
                return $messages;
            }
        }
    }

    return fetchStaffMessageThreadRows($pdo, resolveStaffThreadScope($pdo, $staffId));
}

function markStaffMessagesReadForAdmin(PDO $pdo, int $staffId): void
{
    $scope = resolveStaffThreadScope($pdo, $staffId);
    if ($scope['emails'] === [] && $scope['staff_ids'] === []) {
        return;
    }

    ensureStaffMessagesSchema($pdo);

    try {
        $where  = ["direction = 'staff_to_admin'", 'is_read = 0'];
        $params = [];
        $parts  = [];

        if ($scope['staff_ids'] !== []) {
            $keys = [];
            foreach ($scope['staff_ids'] as $index => $id) {
                $key          = 'sid_' . $index;
                $keys[]       = ':' . $key;
                $params[$key] = $id;
            }
            $parts[] = 'staff_id IN (' . implode(',', $keys) . ')';
        }

        if ($scope['emails'] !== []) {
            $keys = [];
            foreach ($scope['emails'] as $index => $email) {
                $key          = 'em_' . $index;
                $keys[]       = ':' . $key;
                $params[$key] = $email;
            }
            $parts[] = 'LOWER(staff_email) IN (' . implode(',', $keys) . ')';
        }

        if ($parts === []) {
            return;
        }

        $sql = 'UPDATE staff_messages SET is_read = 1 WHERE ' . implode(' AND ', $where) . ' AND (' . implode(' OR ', $parts) . ')';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    } catch (Throwable $e) {
        error_log('[EventStaff] markStaffMessagesReadForAdmin: ' . $e->getMessage());
    }
}

function markAdminRepliesReadForStaff(PDO $pdo, string $email): void
{
    $email = normalizeStaffMessageEmail($email);
    if ($email === '') {
        return;
    }

    ensureStaffMessagesSchema($pdo);

    try {
        $stmt = $pdo->prepare(
            "UPDATE staff_messages SET is_read = 1
             WHERE LOWER(staff_email) = :email AND direction = 'admin_to_staff' AND is_read = 0"
        );
        $stmt->execute(['email' => $email]);
    } catch (Throwable $e) {
        error_log('[EventStaff] markAdminRepliesReadForStaff: ' . $e->getMessage());
    }
}

/**
 * @return array{ok: bool, message: string, id?: int}
 */
function sendStaffMessageToAdmin(PDO $pdo, string $email, string $body, string $subject = ''): array
{
    $email   = normalizeStaffMessageEmail($email);
    $body    = trim($body);
    $subject = trim($subject);

    if ($email === '') {
        return ['ok' => false, 'message' => 'Email is required.'];
    }

    if ($body === '') {
        return ['ok' => false, 'message' => 'Please enter a message.'];
    }

    if (mb_strlen($body) > 4000) {
        return ['ok' => false, 'message' => 'Message is too long (max 4000 characters).'];
    }

    if ($subject !== '' && mb_strlen($subject) > 255) {
        return ['ok' => false, 'message' => 'Subject is too long (max 255 characters).'];
    }

    $staffId = ensureStaffRecordForEmail($pdo, $email);
    if ($staffId === null || $staffId < 1) {
        return ['ok' => false, 'message' => 'No registration found for this email.'];
    }

    ensureStaffMessagesSchema($pdo);

    try {
        $messageId = insertStaffMessageRow($pdo, [
            'staff_id'        => $staffId,
            'staff_email'     => $email,
            'direction'       => 'staff_to_admin',
            'subject'         => $subject !== '' ? $subject : null,
            'body'            => $body,
            'delivery_status' => 'received',
            'recipient_email' => $email,
        ]);
    } catch (Throwable $e) {
        error_log('[EventStaff] sendStaffMessageToAdmin: ' . $e->getMessage());

        return ['ok' => false, 'message' => 'Could not send message. Please try again.'];
    }

    $notifyStaffId = resolveCanonicalStaffIdForEmail($pdo, $email);
    if ($notifyStaffId < 1) {
        $notifyStaffId = $staffId;
    }

    $staff     = getStaffByEmail($pdo, $email);
    $staffName = trim((string) (($staff['first_name'] ?? '') . ' ' . ($staff['surname'] ?? '')));
    if ($staffName === '') {
        $staffName = $email;
    }

    $preview = mb_strlen($body) > 180 ? mb_substr($body, 0, 177) . '…' : $body;
    $title   = $subject !== '' ? $subject : 'Message from ' . $staffName;

    notifyAdminInApp(
        $pdo,
        'staff_message',
        $title,
        $preview,
        'staff-inbox-thread.php?staff_id=' . $notifyStaffId,
        'Reply',
        $notifyStaffId
    );

    return ['ok' => true, 'message' => 'Message sent to the coordinator.', 'id' => $messageId];
}

/**
 * Record an admin outbound message, optionally send SMTP, notify staff in-app.
 *
 * @return array{ok: bool, message: string, id?: int, delivery_status?: string}
 */
function recordAdminOutboundStaffMessage(
    PDO $pdo,
    int $staffId,
    string $subject,
    string $body,
    int $adminUserId,
    bool $sendSmtp = true,
    bool $notifyInApp = true
): array {
    $subject = trim($subject);
    $body    = trim($body);

    if ($staffId < 1) {
        return ['ok' => false, 'message' => 'Invalid staff member.'];
    }

    if ($body === '') {
        return ['ok' => false, 'message' => 'Please enter a message.'];
    }

    if (mb_strlen($body) > 8000) {
        return ['ok' => false, 'message' => 'Message is too long (max 8000 characters).'];
    }

    if ($subject === '') {
        return ['ok' => false, 'message' => 'Subject is required.'];
    }

    if (mb_strlen($subject) > 255) {
        return ['ok' => false, 'message' => 'Subject is too long (max 255 characters).'];
    }

    $staff = getStaffById($pdo, $staffId);
    if ($staff === null) {
        return ['ok' => false, 'message' => 'Staff member not found.'];
    }

    $email = normalizeStaffMessageEmail((string) ($staff['email'] ?? ''));
    if ($email === '') {
        return ['ok' => false, 'message' => 'Staff has no email on file.'];
    }

    $canonicalStaffId = resolveCanonicalStaffIdForEmail($pdo, $email);
    if ($canonicalStaffId > 0) {
        $staffId = $canonicalStaffId;
    }

    ensureStaffMessagesSchema($pdo);

    $deliveryStatus = $sendSmtp ? 'pending' : 'internal';

    try {
        $messageId = insertStaffMessageRow($pdo, [
            'staff_id'        => $staffId,
            'staff_email'     => $email,
            'direction'       => 'admin_to_staff',
            'subject'         => $subject,
            'body'            => $body,
            'admin_user_id'   => $adminUserId > 0 ? $adminUserId : null,
            'delivery_status' => $deliveryStatus,
            'recipient_email' => $email,
        ]);
    } catch (Throwable $e) {
        error_log('[EventStaff] recordAdminOutboundStaffMessage: ' . $e->getMessage());

        return ['ok' => false, 'message' => 'Could not save message. Please try again.'];
    }

    $emailSent = false;
    if ($sendSmtp) {
        require_once __DIR__ . '/mailer.php';
        require_once __DIR__ . '/rich-text.php';
        $parts     = richEmailParts($body);
        $emailSent = sendEmail($pdo, $email, $subject, $parts['plain'], $parts['html']);
        $deliveryStatus = $emailSent ? 'sent' : 'failed';
        try {
            $pdo->prepare('UPDATE staff_messages SET delivery_status = :status WHERE id = :id')
                ->execute(['status' => $deliveryStatus, 'id' => $messageId]);
        } catch (Throwable $e) {
            error_log('[EventStaff] recordAdminOutboundStaffMessage status update: ' . $e->getMessage());
        }
    }

    logAdminAudit(
        $pdo,
        'staff_message_sent',
        'staff_messages',
        $messageId,
        sprintf(
            'To %s | Subject: %s | Status: %s',
            $email,
            $subject,
            $deliveryStatus
        )
    );

    if ($notifyInApp) {
        require_once __DIR__ . '/site-urls.php';

        $base   = getRegistrationSiteUrl($pdo);
        $token  = resolveStatusTokenByEmail($pdo, $email) ?? '';
        $action = $token !== ''
            ? $base . '/staff-messages.php?token=' . rawurlencode($token)
            : $base . '/staff-messages.php';

        require_once __DIR__ . '/rich-text.php';
        $preview = plainTextFromRich($body, 180);
        if ($preview === '') {
            $preview = mb_strlen($body) > 180 ? mb_substr(strip_tags($body), 0, 177) . '…' : strip_tags($body);
        }

        notifyStaffInApp(
            $pdo,
            $email,
            'admin_reply',
            $subject,
            $preview,
            $action,
            'View message',
            $staffId
        );
    }

    if ($sendSmtp && !$emailSent) {
        return [
            'ok'              => true,
            'message'         => 'Message saved to the thread but email delivery failed — check mail settings.',
            'id'              => $messageId,
            'delivery_status' => $deliveryStatus,
        ];
    }

    return [
        'ok'              => true,
        'message'         => $sendSmtp ? 'Email sent and saved to the conversation.' : 'Message saved to the conversation.',
        'id'              => $messageId,
        'delivery_status' => $deliveryStatus,
    ];
}

/**
 * @return array{ok: bool, message: string, id?: int}
 */
function sendAdminReplyToStaff(PDO $pdo, int $staffId, string $body, int $adminUserId, string $subject = ''): array
{
    $subject = trim($subject);
    if ($subject === '') {
        $subject = 'Message from coordinator';
    }

    return recordAdminOutboundStaffMessage($pdo, $staffId, $subject, $body, $adminUserId, true, true);
}

/**
 * Record outbound email in thread without duplicating SMTP (used by Communication Hub after send).
 *
 * @return array{ok: bool, message: string, id?: int}
 */
function recordAdminEmailInThread(
    PDO $pdo,
    int $staffId,
    string $subject,
    string $body,
    int $adminUserId,
    bool $emailSent
): array {
    $staff = getStaffById($pdo, $staffId);
    if ($staff === null) {
        return ['ok' => false, 'message' => 'Staff member not found.'];
    }

    $email = normalizeStaffMessageEmail((string) ($staff['email'] ?? ''));
    if ($email === '') {
        return ['ok' => false, 'message' => 'Staff has no email on file.'];
    }

    ensureStaffMessagesSchema($pdo);

    try {
        $messageId = insertStaffMessageRow($pdo, [
            'staff_id'        => resolveCanonicalStaffIdForEmail($pdo, $email) ?: $staffId,
            'staff_email'     => $email,
            'direction'       => 'admin_to_staff',
            'subject'         => trim($subject) !== '' ? trim($subject) : 'Message from management',
            'body'            => trim($body),
            'admin_user_id'   => $adminUserId > 0 ? $adminUserId : null,
            'delivery_status' => $emailSent ? 'sent' : 'failed',
            'recipient_email' => $email,
        ]);
    } catch (Throwable $e) {
        error_log('[EventStaff] recordAdminEmailInThread: ' . $e->getMessage());

        return ['ok' => false, 'message' => 'Could not save message to thread.'];
    }

    logAdminAudit(
        $pdo,
        'staff_message_sent',
        'staff_messages',
        $messageId,
        sprintf('Bulk/campaign to %s | Subject: %s', $email, $subject)
    );

    return ['ok' => true, 'message' => 'Recorded in thread.', 'id' => $messageId];
}

function getStaffDisplayNameFromRow(array $row): string
{
    $name = trim((string) (($row['first_name'] ?? '') . ' ' . ($row['surname'] ?? '')));

    return $name !== '' ? $name : (string) ($row['staff_email'] ?? 'Staff');
}

function formatStaffMessageDeliveryLabel(string $status): string
{
    return match ($status) {
        'sent'     => 'Email sent',
        'failed'   => 'Email failed',
        'pending'  => 'Sending…',
        'received' => 'Received',
        'internal' => 'In-app only',
        default    => '',
    };
}

function countAllStaffMessages(PDO $pdo): int
{
    ensureStaffMessagesSchema($pdo);
    if (!tableExists($pdo, 'staff_messages')) {
        return 0;
    }

    try {
        return (int) $pdo->query('SELECT COUNT(*) FROM staff_messages')->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Permanently remove every staff inbox message (admin and staff threads).
 *
 * @return array{ok: bool, deleted: int, archive_cleared: int, message: string}
 */
function deleteAllStaffMessages(PDO $pdo): array
{
    ensureStaffMessagesSchema($pdo);
    if (!tableExists($pdo, 'staff_messages')) {
        return ['ok' => true, 'deleted' => 0, 'archive_cleared' => 0, 'message' => 'No messages table.'];
    }

    $archiveCleared = 0;
    try {
        require_once __DIR__ . '/platform/platform-schema.php';
        ensurePlatformMaturitySchema($pdo);
        if (tableExists($pdo, 'platform_inbox_archive')) {
            $stmt = $pdo->prepare("DELETE FROM platform_inbox_archive WHERE source_type = 'message'");
            $stmt->execute();
            $archiveCleared = $stmt->rowCount();
        }
    } catch (Throwable $e) {
        error_log('[EventStaff] deleteAllStaffMessages archive: ' . $e->getMessage());
    }

    try {
        $deleted = (int) $pdo->exec('DELETE FROM staff_messages');
    } catch (Throwable $e) {
        error_log('[EventStaff] deleteAllStaffMessages: ' . $e->getMessage());

        return ['ok' => false, 'deleted' => 0, 'archive_cleared' => $archiveCleared, 'message' => 'Could not delete messages.'];
    }

    logAdminAudit($pdo, 'staff_messages_purge_all', 'staff_messages', null, sprintf('Deleted %d message(s)', $deleted));

    return [
        'ok'              => true,
        'deleted'         => $deleted,
        'archive_cleared' => $archiveCleared,
        'message'         => sprintf('Deleted %d message(s).', $deleted),
    ];
}

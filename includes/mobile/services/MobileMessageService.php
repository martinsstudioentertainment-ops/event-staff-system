<?php

declare(strict_types=1);

require_once __DIR__ . '/../../staff-messages.php';
require_once __DIR__ . '/../../staff-repository.php';
require_once __DIR__ . '/../mobile-rate-limit.php';
require_once __DIR__ . '/../mappers/MobileNotificationMapper.php';

function mobileMessageReadThrottle(int $staffId): void
{
    mobileThrottleOrFail('messages_read_' . $staffId, 120, 60);
}

function mobileMessageWriteThrottle(int $staffId): void
{
    mobileThrottleOrFail('messages_write_' . $staffId, 30, 60);
}

/**
 * @return array{ok: bool, message?: string, code?: string, status?: int, subject?: string, body?: string}
 */
function mobileMessageValidateSendBody(array $body): array
{
    $messageBody = trim((string) ($body['body'] ?? ''));
    $subject     = trim((string) ($body['subject'] ?? ''));

    if ($messageBody === '') {
        return ['ok' => false, 'message' => 'Please enter a message.', 'code' => 'VALIDATION_ERROR', 'status' => 422];
    }

    if (mb_strlen($messageBody) > 4000) {
        return ['ok' => false, 'message' => 'Message is too long (max 4000 characters).', 'code' => 'VALIDATION_ERROR', 'status' => 422];
    }

    if ($subject !== '' && mb_strlen($subject) > 255) {
        return ['ok' => false, 'message' => 'Subject is too long (max 255 characters).', 'code' => 'VALIDATION_ERROR', 'status' => 422];
    }

    return ['ok' => true, 'body' => $messageBody, 'subject' => $subject];
}

/**
 * @param array<string, mixed> $query
 * @return array{ok: true, thread: list, inbox: list, sent: list, unread_count: int}|array{ok: false, message: string, code: string, status: int}
 */
function mobileMessageServiceList(PDO $pdo, array $staff, array $query): array
{
    $staffId = (int) ($staff['id'] ?? 0);
    $email   = strtolower(trim((string) ($staff['email'] ?? '')));

    mobileMessageReadThrottle($staffId);

    if ($email === '') {
        return [
            'ok'      => false,
            'message' => 'Staff email is required.',
            'code'    => 'FORBIDDEN',
            'status'  => 403,
        ];
    }

    $limit = max(1, min(200, (int) ($query['limit'] ?? 100)));
    $rows  = getStaffMessageThread($pdo, $staffId);
    if ($rows === []) {
        $rows = getStaffMessageThreadForEmail($pdo, $email);
    }

    if (count($rows) > $limit) {
        $rows = array_slice($rows, -$limit);
    }

    $thread = [];
    $inbox  = [];
    $sent   = [];

    foreach ($rows as $row) {
        $mapped = mobileMapMessageRow($row);
        $thread[] = $mapped;
        if (($mapped['folder'] ?? '') === 'sent') {
            $sent[] = $mapped;
        } else {
            $inbox[] = $mapped;
        }
    }

    return [
        'ok'           => true,
        'thread'       => $thread,
        'inbox'        => $inbox,
        'sent'         => $sent,
        'unread_count' => countUnreadAdminRepliesForStaff($pdo, $email),
    ];
}

/**
 * @return array{ok: true, message: array, id: int}|array{ok: false, message: string, code: string, status: int}
 */
function mobileMessageServiceSend(PDO $pdo, array $staff, array $body): array
{
    $staffId = (int) ($staff['id'] ?? 0);
    $email   = strtolower(trim((string) ($staff['email'] ?? '')));

    mobileMessageWriteThrottle($staffId);

    if ($staffId < 1 || $email === '') {
        return [
            'ok'      => false,
            'message' => 'Staff account is required.',
            'code'    => 'FORBIDDEN',
            'status'  => 403,
        ];
    }

    $validation = mobileMessageValidateSendBody($body);
    if (!$validation['ok']) {
        return $validation;
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

    $messageBody = (string) $validation['body'];
    $subject     = (string) ($validation['subject'] ?? '');

    ensureStaffMessagesSchema($pdo);

    try {
        $messageId = insertStaffMessageRow($pdo, [
            'staff_id'        => $staffId,
            'staff_email'     => $email,
            'direction'       => 'staff_to_admin',
            'subject'         => $subject !== '' ? $subject : null,
            'body'            => $messageBody,
            'delivery_status' => 'received',
            'recipient_email' => $email,
        ]);
    } catch (Throwable $e) {
        error_log('[MobileAPI] insertStaffMessageRow: ' . $e->getMessage());

        return [
            'ok'      => false,
            'message' => 'Could not send message. Please try again.',
            'code'    => 'SEND_FAILED',
            'status'  => 500,
        ];
    }

    $staffName = trim(((string) ($fresh['first_name'] ?? '')) . ' ' . ((string) ($fresh['surname'] ?? '')));
    if ($staffName === '') {
        $staffName = $email;
    }

    $preview = mb_strlen($messageBody) > 180 ? mb_substr($messageBody, 0, 177) . '…' : $messageBody;
    $title   = $subject !== '' ? $subject : 'Message from ' . $staffName;

    notifyAdminInApp(
        $pdo,
        'staff_message',
        $title,
        $preview,
        'staff-inbox-thread.php?staff_id=' . $staffId,
        'Reply',
        $staffId
    );

    $mapped = mobileMapMessageRow([
        'id'              => $messageId,
        'direction'       => 'staff_to_admin',
        'subject'         => $subject,
        'body'            => $messageBody,
        'is_read'         => 1,
        'delivery_status' => 'received',
        'created_at'      => date('Y-m-d H:i:s'),
        'admin_name'      => null,
    ]);

    return [
        'ok'   => true,
        'id'   => $messageId,
        'sent' => $mapped,
    ];
}

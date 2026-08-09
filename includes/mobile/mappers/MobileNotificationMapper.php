<?php

declare(strict_types=1);

/**
 * Maps raw app_notifications.type values to mobile category labels.
 *
 * @return array<string, array{category: string, label: string}>
 */
function mobileNotificationTypeCatalog(): array
{
    return [
        ['category' => 'shift_assigned', 'label' => 'Shift Assigned'],
        ['category' => 'shift_updated', 'label' => 'Shift Updated'],
        ['category' => 'shift_cancelled', 'label' => 'Shift Cancelled'],
        ['category' => 'event_reminder', 'label' => 'Event Reminder'],
        ['category' => 'check_in_reminder', 'label' => 'Check-In Reminder'],
        ['category' => 'message_received', 'label' => 'Message Received'],
        ['category' => 'document_expiry', 'label' => 'Document Expiry'],
        ['category' => 'approval_status', 'label' => 'Approval Status'],
        ['category' => 'system_announcement', 'label' => 'System Announcement'],
    ];
}

/**
 * @return array{category: string, label: string}
 */
function mobileNotificationCategoryFromType(string $type): array
{
    $type = strtolower(trim($type));

    $map = [
        'new_event'        => 'shift_assigned',
        'open_shift'       => 'shift_assigned',
        'status_approved'  => 'approval_status',
        'status_pending'   => 'approval_status',
        'status_rejected'  => 'approval_status',
        'shift_assigned'   => 'shift_assigned',
        'shift_updated'    => 'shift_updated',
        'shift_cancelled'  => 'shift_cancelled',
        'event_reminder'   => 'event_reminder',
        'check_in_reminder'=> 'check_in_reminder',
        'checkin_reminder' => 'check_in_reminder',
        'admin_reply'      => 'message_received',
        'staff_message'    => 'message_received',
        'message_received' => 'message_received',
        'document_expiry'  => 'document_expiry',
        'psa_expiry'       => 'document_expiry',
        'broadcast'        => 'system_announcement',
        'announcement'     => 'system_announcement',
        'profile_created'  => 'system_announcement',
        'system'           => 'system_announcement',
    ];

    $category = $map[$type] ?? 'system_announcement';

    if (str_starts_with($type, 'status_')) {
        $category = 'approval_status';
    }

    $catalog = mobileNotificationTypeCatalog();
    foreach ($catalog as $entry) {
        if (($entry['category'] ?? '') === $category) {
            return $entry;
        }
    }

    return ['category' => $category, 'label' => ucfirst(str_replace('_', ' ', $category))];
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function mobileMapNotificationRow(array $row): array
{
    $type = (string) ($row['type'] ?? '');
    $cat  = mobileNotificationCategoryFromType($type);

    return [
        'id'            => (int) ($row['id'] ?? 0),
        'type'          => $type,
        'category'      => $cat['category'],
        'category_label'=> $cat['label'],
        'title'         => (string) ($row['title'] ?? ''),
        'body'          => (string) ($row['body'] ?? ''),
        'action_url'    => (string) ($row['action_url'] ?? ''),
        'action_label'  => (string) ($row['action_label'] ?? ''),
        'related_id'    => isset($row['related_id']) ? (int) $row['related_id'] : null,
        'is_read'       => (int) ($row['is_read'] ?? 0) === 1,
        'created_at'    => (string) ($row['created_at'] ?? ''),
    ];
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function mobileMapMessageRow(array $row): array
{
    $direction = (string) ($row['direction'] ?? '');
    $isSent    = $direction === 'staff_to_admin';

    return [
        'id'              => (int) ($row['id'] ?? 0),
        'direction'       => $direction,
        'folder'          => $isSent ? 'sent' : 'inbox',
        'subject'         => (string) ($row['subject'] ?? ''),
        'body'            => (string) ($row['body'] ?? ''),
        'is_read'         => (int) ($row['is_read'] ?? 0) === 1,
        'delivery_status' => (string) ($row['delivery_status'] ?? ''),
        'created_at'      => (string) ($row['created_at'] ?? ''),
        'sender_label'    => $isSent
            ? 'You'
            : (trim((string) ($row['admin_name'] ?? '')) !== '' ? (string) $row['admin_name'] : 'Coordinator'),
        'attachments'     => [],
    ];
}

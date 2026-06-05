<?php

declare(strict_types=1);

require_once __DIR__ . '/notification-center-schema.php';
require_once __DIR__ . '/settings-repository.php';
require_once __DIR__ . '/mailer.php';

function notifyAdminInApp(
    PDO $pdo,
    string $type,
    string $title,
    string $body,
    ?string $actionUrl = null,
    ?string $actionLabel = null,
    ?int $relatedId = null
): ?int {
    ensureNotificationCenterSchema($pdo);

    try {
        $stmt = $pdo->prepare("
            INSERT INTO app_notifications (audience, type, title, body, action_url, action_label, related_id)
            VALUES ('admin', :type, :title, :body, :action_url, :action_label, :related_id)
        ");
        $stmt->execute([
            'type'         => $type,
            'title'        => $title,
            'body'         => $body,
            'action_url'   => $actionUrl,
            'action_label' => $actionLabel,
            'related_id'   => $relatedId,
        ]);

        return (int) $pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log('[EventStaff] notifyAdminInApp: ' . $e->getMessage());

        return null;
    }
}

function notifyStaffInApp(
    PDO $pdo,
    string $email,
    string $type,
    string $title,
    string $body,
    ?string $actionUrl = null,
    ?string $actionLabel = null,
    ?int $relatedId = null
): ?int {
    $email = strtolower(trim($email));
    if ($email === '') {
        return null;
    }

    ensureNotificationCenterSchema($pdo);

    try {
        $stmt = $pdo->prepare("
            INSERT INTO app_notifications (audience, staff_email, type, title, body, action_url, action_label, related_id)
            VALUES ('staff', :email, :type, :title, :body, :action_url, :action_label, :related_id)
        ");
        $stmt->execute([
            'email'        => $email,
            'type'         => $type,
            'title'        => $title,
            'body'         => $body,
            'action_url'   => $actionUrl,
            'action_label' => $actionLabel,
            'related_id'   => $relatedId,
        ]);

        return (int) $pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log('[EventStaff] notifyStaffInApp: ' . $e->getMessage());

        return null;
    }
}

function isAdminEmailAlertEnabled(PDO $pdo, string $key): bool
{
    return getSetting($pdo, $key, '0') === '1';
}

function getAdminAlertEmail(PDO $pdo): string
{
    $email = trim(getSetting($pdo, 'notify_admin_email', ''));
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $email;
    }

    $company = trim(getSetting($pdo, 'company_email', ''));

    return filter_var($company, FILTER_VALIDATE_EMAIL) ? $company : '';
}

function sendAdminAlertEmail(PDO $pdo, string $subject, string $bodyHtml): void
{
    $to = getAdminAlertEmail($pdo);
    if ($to === '') {
        return;
    }

    $siteName = getSiteName($pdo);
    sendEmail($to, '[' . $siteName . '] ' . $subject, $bodyHtml);
}

function notifyAdminNewRegistration(PDO $pdo, string $staffName, string $email, int $registrationId, string $eventName): void
{
    $title = 'New registration — ' . $staffName;
    $body  = $staffName . ' registered for ' . $eventName . ' (' . $email . ').';
    $url   = 'view-staff.php?id=' . $registrationId;

    notifyAdminInApp($pdo, 'registration', $title, $body, $url, 'Review', $registrationId);

    if (!isAdminEmailAlertEnabled($pdo, 'notify_admin_on_registration')) {
        return;
    }

    $adminRoot = rtrim(getSetting($pdo, 'admin_site_url', ''), '/');
    if ($adminRoot === '') {
        $adminRoot = 'https://admin.olasentra.com';
    }
    $link = $adminRoot . '/admin/staff.php';

    sendAdminAlertEmail(
        $pdo,
        'New staff registration',
        '<p><strong>' . htmlspecialchars($staffName, ENT_QUOTES, 'UTF-8') . '</strong> registered for '
        . htmlspecialchars($eventName, ENT_QUOTES, 'UTF-8') . '.</p>'
        . '<p>Email: ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">Open staff queue</a></p>'
    );
}

function notifyStaffStatusInApp(PDO $pdo, string $email, string $status, int $registrationId, string $eventName): void
{
    require_once __DIR__ . '/status-repository.php';

    $label = match ($status) {
        'approved' => 'Approved',
        'rejected' => 'Not approved',
        default    => ucfirst($status),
    };

    $title = 'Application ' . $label;
    $body  = 'Your registration for ' . $eventName . ' is now: ' . $label . '.';

    $statusToken = ensureStatusToken($pdo, $registrationId);
    $url         = $statusToken !== null
        ? 'staff-notifications.php?token=' . urlencode($statusToken)
        : 'staff-notifications.php';

    notifyStaffInApp($pdo, $email, 'status_' . $status, $title, $body, $url, 'View update', $registrationId);
}

/**
 * @return list<array<string, mixed>>
 */
function getAdminNotifications(PDO $pdo, int $limit = 50, bool $unreadOnly = false): array
{
    ensureNotificationCenterSchema($pdo);

    $sql = "SELECT * FROM app_notifications WHERE audience = 'admin'";
    if ($unreadOnly) {
        $sql .= ' AND is_read = 0';
    }
    $sql .= ' ORDER BY created_at DESC LIMIT ' . max(1, min($limit, 200));

    try {
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * @return list<array<string, mixed>>
 */
function getStaffNotifications(PDO $pdo, string $email, int $limit = 50, bool $unreadOnly = false): array
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return [];
    }

    ensureNotificationCenterSchema($pdo);

    $sql = "SELECT * FROM app_notifications WHERE audience = 'staff' AND LOWER(staff_email) = :email";
    if ($unreadOnly) {
        $sql .= ' AND is_read = 0';
    }
    $sql .= ' ORDER BY created_at DESC LIMIT ' . max(1, min($limit, 200));

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['email' => $email]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return [];
    }
}

function countUnreadAdminNotifications(PDO $pdo): int
{
    ensureNotificationCenterSchema($pdo);

    try {
        return (int) $pdo->query("SELECT COUNT(*) FROM app_notifications WHERE audience = 'admin' AND is_read = 0")->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

function countUnreadStaffNotifications(PDO $pdo, string $email): int
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return 0;
    }

    ensureNotificationCenterSchema($pdo);

    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM app_notifications WHERE audience = 'staff' AND LOWER(staff_email) = :email AND is_read = 0");
        $stmt->execute(['email' => $email]);

        return (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

function markNotificationRead(PDO $pdo, int $id, string $audience, ?string $staffEmail = null): bool
{
    ensureNotificationCenterSchema($pdo);

    if ($audience === 'staff') {
        $email = strtolower(trim((string) $staffEmail));
        $stmt  = $pdo->prepare("UPDATE app_notifications SET is_read = 1 WHERE id = :id AND audience = 'staff' AND LOWER(staff_email) = :email");
        $stmt->execute(['id' => $id, 'email' => $email]);

        return $stmt->rowCount() > 0;
    }

    $stmt = $pdo->prepare("UPDATE app_notifications SET is_read = 1 WHERE id = :id AND audience = 'admin'");
    $stmt->execute(['id' => $id]);

    return $stmt->rowCount() > 0;
}

function markAllNotificationsRead(PDO $pdo, string $audience, ?string $staffEmail = null): int
{
    ensureNotificationCenterSchema($pdo);

    if ($audience === 'staff') {
        $email = strtolower(trim((string) $staffEmail));
        $stmt  = $pdo->prepare("UPDATE app_notifications SET is_read = 1 WHERE audience = 'staff' AND LOWER(staff_email) = :email AND is_read = 0");
        $stmt->execute(['email' => $email]);

        return $stmt->rowCount();
    }

    return (int) $pdo->exec("UPDATE app_notifications SET is_read = 1 WHERE audience = 'admin' AND is_read = 0");
}

function formatNotificationTime(string $createdAt): string
{
    $ts = strtotime($createdAt);

    return $ts !== false ? date('d M Y, H:i', $ts) : $createdAt;
}

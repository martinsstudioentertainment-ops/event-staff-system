<?php

declare(strict_types=1);

require_once __DIR__ . '/notification-center-schema.php';
require_once __DIR__ . '/settings-repository.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/site-urls.php';

function resolveStaffNotificationActionUrl(?PDO $pdo, string $url): string
{
    $url = trim($url);
    if ($url === '' || $url === '#') {
        return '';
    }

    if (preg_match('#^https?://#i', $url) === 1 || str_starts_with($url, 'mailto:')) {
        return $url;
    }

    $base = rtrim(getRegistrationSiteUrl($pdo), '/');
    if (str_starts_with($url, '/')) {
        return $base . $url;
    }

    return $base . '/' . ltrim($url, '/');
}

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

    if ($actionUrl !== null && $actionUrl !== '') {
        $actionUrl = resolveStaffNotificationActionUrl($pdo, $actionUrl);
    }

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

/**
 * @return list<string>
 */
function parseNotifyEmailList(string $raw): array
{
    $raw = str_replace(["\r\n", "\r", "\n", ';'], ',', $raw);
    $emails = [];
    foreach (explode(',', $raw) as $part) {
        $email = strtolower(trim($part));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[] = $email;
        }
    }

    return array_values(array_unique($emails));
}

/**
 * @return list<string>
 */
function getAdminAlertEmails(PDO $pdo): array
{
    $emails = parseNotifyEmailList(trim(getSetting($pdo, 'notify_admin_email', '')));
    if ($emails !== []) {
        return $emails;
    }

    $company = strtolower(trim(getSetting($pdo, 'company_email', '')));
    if ($company !== '' && filter_var($company, FILTER_VALIDATE_EMAIL)) {
        return [$company];
    }

    return [];
}

function getAdminAlertEmail(PDO $pdo): string
{
    $emails = getAdminAlertEmails($pdo);

    return $emails[0] ?? '';
}

function sendAdminAlertEmail(PDO $pdo, string $subject, string $bodyHtml): void
{
    $recipients = getAdminAlertEmails($pdo);
    if ($recipients === []) {
        return;
    }

    $siteName = getSiteName($pdo);
    $plain    = trim(preg_replace('/\s+/u', ' ', strip_tags(str_replace(
        ['<br>', '<br/>', '<br />', '</p>', '</li>'],
        ["\n", "\n", "\n", "\n", "\n"],
        $bodyHtml
    ))));

    foreach ($recipients as $to) {
        sendEmail($pdo, $to, '[' . $siteName . '] ' . $subject, $plain !== '' ? $plain : $subject, $bodyHtml);
    }
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

    require_once __DIR__ . '/email-layout.php';

    sendAdminAlertEmail(
        $pdo,
        'New staff registration',
        buildEmailNotificationCard(
            $pdo,
            'New staff registration',
            '<p style="margin:0 0 8px;"><strong>' . emailEsc($staffName) . '</strong> registered for '
            . emailEsc($eventName) . '.</p>'
            . '<p style="margin:0;">Email: ' . emailEsc($email) . '</p>',
            $link,
            'Open staff queue'
        )
    );
}

function notifyAdminStaffCheckin(PDO $pdo, int $registrationId, string $method = 'self'): void
{
    if (!isAdminEmailAlertEnabled($pdo, 'notify_ops_on_checkin')) {
        return;
    }

    require_once __DIR__ . '/staff-repository.php';
    require_once __DIR__ . '/email-copy.php';

    $row = getStaffRegistrationById($pdo, $registrationId);
    if ($row === null) {
        return;
    }

    $stmt = $pdo->prepare(
        'SELECT checked_in_at FROM attendance WHERE registration_id = :id ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute(['id' => $registrationId]);
    $attendance  = $stmt->fetch();
    $checkedInAt = $attendance ? (string) ($attendance['checked_in_at'] ?? '') : date('Y-m-d H:i:s');

    $staffName   = trim((string) $row['first_name'] . ' ' . (string) $row['surname']);
    $event       = formatEventLabelForEmail($row);
    $role        = formatRoleLabel($row['staff_role']);
    $times       = formatEventTimeRangeLabelForEmail($row);
    $location    = formatEventLocationLabelForEmail($row);
    $dateLabel   = formatSystemDateTime($checkedInAt, $pdo);
    $methodLabel = match ($method) {
        'admin', 'admin_manual' => 'Admin manual',
        'scan'                  => 'QR scan',
        default                 => 'Venue sign-in',
    };

    $adminRoot = rtrim(getSetting($pdo, 'admin_site_url', ''), '/');
    if ($adminRoot === '') {
        $adminRoot = 'https://admin.olasentra.com';
    }
    $link = $adminRoot . '/admin/view-staff.php?id=' . $registrationId;

    notifyAdminInApp(
        $pdo,
        'checkin',
        'Staff signed in — ' . $staffName,
        $staffName . ' checked in for ' . $event . ' at ' . $dateLabel . '.',
        'view-staff.php?id=' . $registrationId,
        'View staff',
        $registrationId
    );

    require_once __DIR__ . '/email-layout.php';

    sendAdminAlertEmail(
        $pdo,
        'Staff signed in — ' . $staffName,
        buildEmailNotificationCard(
            $pdo,
            'Staff signed in',
            '<p style="margin:0 0 8px;"><strong>' . emailEsc($staffName) . '</strong> signed in for '
            . emailEsc($event) . '.</p>'
            . '<p style="margin:0;">Role: ' . emailEsc($role)
            . '<br>Shift: ' . emailEsc($times)
            . '<br>Venue: ' . emailEsc($location)
            . '<br>Checked in: ' . emailEsc($dateLabel)
            . '<br>Method: ' . emailEsc($methodLabel) . '</p>',
            $link,
            'Open staff record'
        )
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
        ? getStatusUrl($statusToken, $pdo)
        : getRegistrationSiteUrl($pdo) . '/status.php';

    notifyStaffInApp($pdo, $email, 'status_' . $status, $title, $body, $url, 'View status', $registrationId);
}

function notifyStaffEventCancelledInApp(
    PDO $pdo,
    string $email,
    int $registrationId,
    string $eventName,
    string $reason = ''
): void {
    require_once __DIR__ . '/status-repository.php';

    $reason = trim($reason);
    $title  = 'Event cancelled';
    $body   = 'The shift for ' . $eventName . ' has been cancelled. You are no longer required to attend.';
    if ($reason !== '') {
        $body .= ' Reason: ' . $reason;
    }

    $statusToken = ensureStatusToken($pdo, $registrationId);
    $url         = $statusToken !== null
        ? getStatusUrl($statusToken, $pdo)
        : getRegistrationSiteUrl($pdo) . '/status.php';

    notifyStaffInApp($pdo, $email, 'event_cancelled', $title, $body, $url, 'View status', $registrationId);
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

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['action_url'] = resolveStaffNotificationActionUrl(
                $pdo,
                (string) ($row['action_url'] ?? '')
            );
        }
        unset($row);

        return $rows;
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

function countAllNotifications(PDO $pdo, string $scope = 'all'): int
{
    ensureNotificationCenterSchema($pdo);
    if (!tableExists($pdo, 'app_notifications')) {
        return 0;
    }

    $where = match ($scope) {
        'admin' => "audience = 'admin'",
        'staff' => "audience = 'staff'",
        default => '1=1',
    };

    try {
        return (int) $pdo->query("SELECT COUNT(*) FROM app_notifications WHERE {$where}")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Permanently delete notifications. Scope: admin, staff, or all.
 */
function clearAllNotifications(PDO $pdo, string $scope = 'all'): int
{
    ensureNotificationCenterSchema($pdo);
    if (!tableExists($pdo, 'app_notifications')) {
        return 0;
    }

    $archiveCleared = 0;
    try {
        require_once __DIR__ . '/platform/platform-schema.php';
        ensurePlatformMaturitySchema($pdo);
        if (tableExists($pdo, 'platform_inbox_archive')) {
            $stmt = $pdo->prepare("DELETE FROM platform_inbox_archive WHERE source_type = 'notification'");
            $stmt->execute();
            $archiveCleared = $stmt->rowCount();
        }
    } catch (Throwable $e) {
        error_log('[EventStaff] clearAllNotifications archive: ' . $e->getMessage());
    }

    $sql = match ($scope) {
        'admin' => "DELETE FROM app_notifications WHERE audience = 'admin'",
        'staff' => "DELETE FROM app_notifications WHERE audience = 'staff'",
        default => 'DELETE FROM app_notifications',
    };

    try {
        $deleted = (int) $pdo->exec($sql);
    } catch (Throwable $e) {
        error_log('[EventStaff] clearAllNotifications: ' . $e->getMessage());

        return 0;
    }

    if ($deleted > 0 || $archiveCleared > 0) {
        require_once __DIR__ . '/audit-log.php';
        logAdminAudit(
            $pdo,
            'notifications_purge',
            'app_notifications',
            null,
            sprintf('Cleared %d notification(s) (%s scope)', $deleted, $scope)
        );
    }

    return $deleted;
}

function formatNotificationTime(string $createdAt, ?PDO $pdo = null): string
{
    if (!function_exists('formatSystemDateTime')) {
        require_once __DIR__ . '/system-settings.php';
    }

    return formatSystemDateTime($createdAt, $pdo);
}

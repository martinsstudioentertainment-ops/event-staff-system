<?php

declare(strict_types=1);

require_once __DIR__ . '/settings-repository.php';
require_once __DIR__ . '/notification-center.php';
require_once __DIR__ . '/staff-repository.php';
require_once __DIR__ . '/site-urls.php';

/**
 * @return array{message: string, updated_at: string}|null
 */
function getActiveStaffFlashBroadcast(PDO $pdo): ?array
{
    if (getSetting($pdo, 'staff_flash_broadcast_active', '0') !== '1') {
        return null;
    }

    $message = trim(getSetting($pdo, 'staff_flash_broadcast', ''));
    if ($message === '') {
        return null;
    }

    return [
        'message'    => $message,
        'updated_at' => trim(getSetting($pdo, 'staff_flash_broadcast_updated_at', '')),
    ];
}

/**
 * @return list<string>
 */
function listStaffEmailsForBroadcast(PDO $pdo): array
{
    try {
        $rows = $pdo->query(
            "SELECT DISTINCT LOWER(TRIM(email)) AS email
             FROM staff
             WHERE email IS NOT NULL AND TRIM(email) <> '' AND is_blacklisted = 0"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('[EventStaff] listStaffEmailsForBroadcast: ' . $e->getMessage());

        return [];
    }

    $emails = [];
    foreach ($rows as $row) {
        $email = strtolower(trim((string) ($row['email'] ?? '')));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[] = $email;
        }
    }

    return array_values(array_unique($emails));
}

/**
 * @return array{ok: bool, message: string, notified?: int}
 */
function publishStaffFlashBroadcast(PDO $pdo, string $body, int $adminUserId = 0): array
{
    $body = trim($body);

    if ($body === '') {
        return ['ok' => false, 'message' => 'Please enter an announcement message.'];
    }

    if (mb_strlen($body) > 2000) {
        return ['ok' => false, 'message' => 'Announcement is too long (max 2000 characters).'];
    }

    $updatedAt = gmdate('Y-m-d H:i:s');

    saveSettings($pdo, [
        'staff_flash_broadcast'           => $body,
        'staff_flash_broadcast_active'    => '1',
        'staff_flash_broadcast_updated_at' => $updatedAt,
    ]);

    $emails   = listStaffEmailsForBroadcast($pdo);
    $preview  = mb_strlen($body) > 180 ? mb_substr($body, 0, 177) . '…' : $body;
    $appUrl   = getRegistrationSiteUrl($pdo) . '/staff-app.php';
    $notified = 0;

    foreach ($emails as $email) {
        if (notifyStaffInApp(
            $pdo,
            $email,
            'broadcast',
            'Announcement from coordinator',
            $preview,
            $appUrl,
            'Open staff app'
        ) !== null) {
            $notified++;
        }
    }

    return [
        'ok'       => true,
        'message'  => 'Flash announcement published to all staff (' . count($emails) . ' people, ' . $notified . ' in-app alerts).',
        'notified' => $notified,
    ];
}

function clearStaffFlashBroadcast(PDO $pdo): void
{
    saveSettings($pdo, [
        'staff_flash_broadcast_active' => '0',
    ]);
}

/**
 * @return array<int, array<string, mixed>>
 */
function searchStaffForAdminMessaging(PDO $pdo, string $query, int $limit = 25): array
{
    $query = trim($query);
    if ($query === '') {
        return [];
    }

    return getStaffWithFilters($pdo, ['q' => $query, 'blacklisted' => false], max(1, min($limit, 50)), 0);
}

function renderStaffFlashBroadcast(PDO $pdo): void
{
    static $flashCssLoaded = false;

    $broadcast = getActiveStaffFlashBroadcast($pdo);
    if ($broadcast === null) {
        return;
    }

    if (!$flashCssLoaded) {
        $cssPath = __DIR__ . '/../assets/css/staff-flash.css';
        $cssVer  = is_file($cssPath) ? (string) filemtime($cssPath) : '1';
        echo '<link rel="stylesheet" href="assets/css/staff-flash.css?v=' . htmlspecialchars($cssVer, ENT_QUOTES, 'UTF-8') . '">';
        $flashCssLoaded = true;
    }

    $broadcastId = (string) ($broadcast['updated_at'] !== '' ? $broadcast['updated_at'] : md5($broadcast['message']));
    ?>
    <div class="staff-flash-broadcast" id="staff-flash-broadcast" data-broadcast-id="<?= h($broadcastId) ?>" role="alert">
        <div class="staff-flash-broadcast__inner">
            <span class="staff-flash-broadcast__icon" aria-hidden="true">📢</span>
            <div class="staff-flash-broadcast__content">
                <span class="staff-flash-broadcast__label">Coordinator announcement</span>
                <p class="staff-flash-broadcast__text"><?= nl2br(h($broadcast['message'])) ?></p>
            </div>
            <button type="button" class="staff-flash-broadcast__dismiss" aria-label="Dismiss announcement">&times;</button>
        </div>
    </div>
    <script>
    (function () {
        var el = document.getElementById('staff-flash-broadcast');
        if (!el) return;
        var id = el.getAttribute('data-broadcast-id') || '';
        try {
            if (id && localStorage.getItem('staff_flash_dismissed') === id) {
                el.remove();
                return;
            }
        } catch (e) {}
        var btn = el.querySelector('.staff-flash-broadcast__dismiss');
        if (!btn) return;
        btn.addEventListener('click', function () {
            try {
                if (id) localStorage.setItem('staff_flash_dismissed', id);
            } catch (e) {}
            el.remove();
        });
    })();
    </script>
    <?php
}

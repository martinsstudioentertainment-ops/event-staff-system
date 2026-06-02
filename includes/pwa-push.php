<?php

require_once __DIR__ . '/settings-repository.php';

/**
 * Generate VAPID key pair (P-256) for Web Push.
 *
 * @return array{publicKey: string, privateKey: string}|null Base64url-encoded keys
 */
function generateVapidKeyPair(): ?array
{
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
        if (class_exists(\Minishlink\WebPush\VAPID::class)) {
            try {
                $keys = \Minishlink\WebPush\VAPID::createVapidKeys();
                return [
                    'publicKey'  => (string) ($keys['publicKey'] ?? ''),
                    'privateKey' => (string) ($keys['privateKey'] ?? ''),
                ];
            } catch (Throwable $e) {
                // Fall through to OpenSSL.
            }
        }
    }

    if (!function_exists('openssl_pkey_new')) {
        return null;
    }

    $key = openssl_pkey_new([
        'curve_name'       => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC,
    ]);

    if ($key === false) {
        return null;
    }

    $details = openssl_pkey_get_details($key);
    if ($details === false || empty($details['ec']['x']) || empty($details['ec']['y']) || empty($details['ec']['d'])) {
        return null;
    }

    $publicRaw = "\x04" . $details['ec']['x'] . $details['ec']['y'];

    return [
        'publicKey'  => base64UrlEncode($publicRaw),
        'privateKey' => base64UrlEncode($details['ec']['d']),
    ];
}

function getVapidPublicKey(PDO $pdo): string
{
    return trim(getSetting($pdo, 'pwa_vapid_public_key', ''));
}

function getVapidPrivateKey(PDO $pdo): string
{
    return trim(getSetting($pdo, 'pwa_vapid_private_key', ''));
}

function isPwaPushConfigured(PDO $pdo): bool
{
    return getVapidPublicKey($pdo) !== '' && getVapidPrivateKey($pdo) !== '';
}

function isPwaPushEnabled(PDO $pdo): bool
{
    return getSetting($pdo, 'pwa_push_enabled', '1') === '1' && isPwaPushConfigured($pdo);
}

function ensureVapidKeys(PDO $pdo): bool
{
    if (isPwaPushConfigured($pdo)) {
        return true;
    }

    $keys = generateVapidKeyPair();
    if ($keys === null) {
        return false;
    }

    saveSettings($pdo, [
        'pwa_vapid_public_key'  => $keys['publicKey'],
        'pwa_vapid_private_key' => $keys['privateKey'],
    ]);

    return true;
}

/**
 * @param array<string, mixed> $subscription Row from push_subscriptions
 */
function sendPushToSubscription(PDO $pdo, array $subscription, string $title, string $body, ?string $url = null): bool
{
    if (!isPwaPushEnabled($pdo)) {
        return false;
    }

    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        return false;
    }

    require_once $autoload;

    if (!class_exists(\Minishlink\WebPush\WebPush::class)) {
        return false;
    }

    $auth = [
        'VAPID' => [
            'subject'    => 'mailto:' . getCompanyEmailForPush($pdo),
            'publicKey'  => getVapidPublicKey($pdo),
            'privateKey' => getVapidPrivateKey($pdo),
        ],
    ];

    $webPush = new \Minishlink\WebPush\WebPush($auth);

    $payload = json_encode([
        'title' => $title,
        'body'  => $body,
        'url'   => $url ?? '',
    ], JSON_UNESCAPED_UNICODE);

    $pushSubscription = \Minishlink\WebPush\Subscription::create([
        'endpoint'  => $subscription['endpoint'],
        'publicKey' => $subscription['p256dh'],
        'authToken' => $subscription['auth'],
    ]);

    try {
        $report = $webPush->sendOneNotification($pushSubscription, $payload !== false ? $payload : '{}');
        if ($report->isSuccess()) {
            return true;
        }

        if ($report->isSubscriptionExpired()) {
            deletePushSubscriptionByEndpoint($pdo, (string) $subscription['endpoint']);
        }
    } catch (Throwable $e) {
        return false;
    }

    return false;
}

function getCompanyEmailForPush(PDO $pdo): string
{
    require_once __DIR__ . '/company.php';
    $email = getCompanyEmail($pdo);

    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : 'noreply@event-staff.local';
}

function deletePushSubscriptionByEndpoint(PDO $pdo, string $endpoint): void
{
    ensurePwaSchema($pdo);
    $stmt = $pdo->prepare('DELETE FROM push_subscriptions WHERE endpoint = :endpoint');
    $stmt->execute(['endpoint' => $endpoint]);
}

/**
 * @return list<array<string, mixed>>
 */
function getPushSubscriptionsForRegistration(PDO $pdo, int $registrationId): array
{
    ensurePwaSchema($pdo);

    try {
        $stmt = $pdo->prepare('SELECT * FROM push_subscriptions WHERE registration_id = :id');
        $stmt->execute(['id' => $registrationId]);

        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('[EventStaff] getPushSubscriptionsForRegistration: ' . $e->getMessage());

        return [];
    }
}

/**
 * @return list<array<string, mixed>>
 */
function getPushSubscriptionsForStatusToken(PDO $pdo, string $statusToken): array
{
    ensurePwaSchema($pdo);

    $stmt = $pdo->prepare('SELECT * FROM push_subscriptions WHERE status_token = :token');
    $stmt->execute(['token' => $statusToken]);

    return $stmt->fetchAll();
}

function savePushSubscription(
    PDO $pdo,
    string $endpoint,
    string $p256dh,
    string $auth,
    ?int $registrationId = null,
    ?string $statusToken = null,
    ?string $userAgent = null
): bool
{
    ensurePwaSchema($pdo);

    $stmt = $pdo->prepare(
        'INSERT INTO push_subscriptions (registration_id, status_token, endpoint, p256dh, auth, user_agent)
         VALUES (:registration_id, :status_token, :endpoint, :p256dh, :auth, :user_agent)
         ON DUPLICATE KEY UPDATE
            registration_id = VALUES(registration_id),
            status_token = VALUES(status_token),
            p256dh = VALUES(p256dh),
            auth = VALUES(auth),
            user_agent = VALUES(user_agent),
            updated_at = CURRENT_TIMESTAMP'
    );

    return $stmt->execute([
        'registration_id' => $registrationId,
        'status_token'    => $statusToken,
        'endpoint'        => $endpoint,
        'p256dh'          => $p256dh,
        'auth'            => $auth,
        'user_agent'      => $userAgent,
    ]);
}

function notifyRegistrationPush(PDO $pdo, int $registrationId, string $title, string $body, ?string $url = null): int
{
    $sent = 0;
    foreach (getPushSubscriptionsForRegistration($pdo, $registrationId) as $sub) {
        if (sendPushToSubscription($pdo, $sub, $title, $body, $url)) {
            $sent++;
        }
    }

    return $sent;
}

function linkPushSubscriptionsToRegistration(PDO $pdo, string $statusToken, int $registrationId): void
{
    ensurePwaSchema($pdo);
    $stmt = $pdo->prepare(
        'UPDATE push_subscriptions SET registration_id = :registration_id
         WHERE status_token = :token AND (registration_id IS NULL OR registration_id = :registration_id2)'
    );
    $stmt->execute([
        'registration_id'  => $registrationId,
        'token'            => $statusToken,
        'registration_id2' => $registrationId,
    ]);
}

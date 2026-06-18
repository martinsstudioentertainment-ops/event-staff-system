<?php

declare(strict_types=1);

/**
 * Sprint 5 mobile API tests — run: php scripts/test-mobile-api-sprint5.php
 */

$root = dirname(__DIR__);
require_once $root . '/includes/mobile/mappers/MobileNotificationMapper.php';
require_once $root . '/includes/mobile/services/MobileMessageService.php';
require_once $root . '/includes/mobile/services/MobileNotificationService.php';
require_once $root . '/includes/mobile/services/MobilePushService.php';
require_once $root . '/includes/mobile/mobile-request.php';

class MobileTestPdoStub extends PDO
{
    public function __construct()
    {
    }
}

$passed = 0;
$failed = 0;

function assertTrue(bool $cond, string $label): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  PASS  {$label}\n";
    } else {
        $failed++;
        echo "  FAIL  {$label}\n";
    }
}

function assertEquals(mixed $expected, mixed $actual, string $label): void
{
    assertTrue($expected === $actual, $label . ' (expected ' . json_encode($expected) . ', got ' . json_encode($actual) . ')');
}

echo "Mobile API Sprint 5 tests\n\n";

echo "Notification categories\n";

assertEquals('approval_status', mobileNotificationCategoryFromType('status_approved')['category'], 'status_approved category');
assertEquals('message_received', mobileNotificationCategoryFromType('admin_reply')['category'], 'admin_reply category');
assertEquals('shift_assigned', mobileNotificationCategoryFromType('open_shift')['category'], 'open_shift category');
assertEquals('system_announcement', mobileNotificationCategoryFromType('broadcast')['category'], 'broadcast category');
assertEquals(9, count(mobileNotificationTypeCatalog()), 'nine notification categories documented');

$mapped = mobileMapNotificationRow([
    'id'         => 10,
    'type'       => 'status_approved',
    'title'      => 'Approved',
    'body'       => 'You are approved',
    'action_url' => '/status.php',
    'is_read'    => 0,
    'created_at' => '2026-06-12 10:00:00',
]);
assertEquals(10, $mapped['id'], 'maps notification id');
assertEquals('approval_status', $mapped['category'], 'maps category on row');
assertTrue($mapped['is_read'] === false, 'maps is_read boolean');

echo "\nMessage mapping\n";

$msg = mobileMapMessageRow([
    'id'         => 5,
    'direction'  => 'admin_to_staff',
    'subject'    => 'Hello',
    'body'       => 'Reply text',
    'is_read'    => 0,
    'created_at' => '2026-06-12 11:00:00',
    'admin_name' => 'Coordinator',
]);
assertEquals('inbox', $msg['folder'], 'admin message in inbox');
assertEquals([], $msg['attachments'], 'attachments placeholder empty');

$sent = mobileMapMessageRow([
    'id'        => 6,
    'direction' => 'staff_to_admin',
    'body'      => 'My question',
    'is_read'   => 1,
    'created_at'=> '2026-06-12 12:00:00',
]);
assertEquals('sent', $sent['folder'], 'staff message in sent');

echo "\nMessage send validation\n";

$empty = mobileMessageValidateSendBody(['body' => '']);
assertTrue($empty['ok'] === false && ($empty['code'] ?? '') === 'VALIDATION_ERROR', 'empty body rejected');

$long = mobileMessageValidateSendBody(['body' => str_repeat('x', 4001)]);
assertTrue($long['ok'] === false, 'overlong body rejected');

$ok = mobileMessageValidateSendBody(['body' => 'Hello coordinator', 'subject' => 'Shift question']);
assertTrue($ok['ok'] === true, 'valid message accepted');

echo "\nPush device validation\n";

assertTrue(mobileValidateDeviceId('postman-test-device-001'), 'valid device id');
assertTrue(!mobileValidateDeviceId('short'), 'invalid device id rejected');

echo "\nOwnership and authorization (no DB)\n";

$pdo = new MobileTestPdoStub();
$staff = ['id' => 5, 'email' => 'staff@example.com'];

$invalidNotif = mobileNotificationServiceMarkRead($pdo, $staff, 0);
assertTrue($invalidNotif['ok'] === false && ($invalidNotif['code'] ?? '') === 'VALIDATION_ERROR', 'invalid notification id rejected');

$noEmail = mobileNotificationServiceList($pdo, ['id' => 5, 'email' => ''], []);
assertTrue($noEmail['ok'] === false && ($noEmail['code'] ?? '') === 'FORBIDDEN', 'notification list requires email');

$noStaffMsg = mobileMessageServiceList($pdo, ['id' => 5, 'email' => ''], []);
assertTrue($noStaffMsg['ok'] === false && ($noStaffMsg['code'] ?? '') === 'FORBIDDEN', 'message list requires email');

$pushForbidden = mobilePushServiceRegister($pdo, [], ['device_id' => 'postman-test-device-001', 'fcm_token' => 'test-token']);
assertTrue($pushForbidden['ok'] === false && ($pushForbidden['code'] ?? '') === 'FORBIDDEN', 'push register requires staff id');

$pushInvalid = mobilePushServiceRegister($pdo, $staff, ['device_id' => 'short', 'fcm_token' => 'test-token']);
assertTrue($pushInvalid['ok'] === false && ($pushInvalid['code'] ?? '') === 'VALIDATION_ERROR', 'push register rejects invalid device_id');

$pushNoToken = mobilePushServiceRegister($pdo, $staff, ['device_id' => 'postman-test-device-001', 'fcm_token' => '']);
assertTrue($pushNoToken['ok'] === false && ($pushNoToken['code'] ?? '') === 'VALIDATION_ERROR', 'push register rejects empty token');

$unregisterInvalid = mobilePushServiceUnregister($pdo, $staff, 'bad');
assertTrue($unregisterInvalid['ok'] === false && ($unregisterInvalid['code'] ?? '') === 'VALIDATION_ERROR', 'push unregister rejects invalid device_id');

echo "\n";
echo "Results: {$passed} passed, {$failed} failed\n";

exit($failed > 0 ? 1 : 0);

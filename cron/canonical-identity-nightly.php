<?php



declare(strict_types=1);



/**

 * Nightly canonical identity integrity check + safe auto-normalization.

 *

 * Schedule via cPanel cron (02:30 daily):

 *   curl -sS "https://admin.olasentra.com/cron/canonical-identity-nightly.php?key=CRON_KEY&apply=1"

 *

 * Audit only:

 *   /cron/canonical-identity-nightly.php?key=...

 *

 * Manual-review conflicts are alerted only — never auto-modified.

 */



require_once dirname(__DIR__) . '/config.php';

require_once dirname(__DIR__) . '/includes/settings-repository.php';

require_once dirname(__DIR__) . '/includes/platform/production-health.php';

require_once dirname(__DIR__) . '/includes/platform/canonical-identity.php';

require_once dirname(__DIR__) . '/includes/apply-remote-sync.php';



header('Content-Type: application/json; charset=UTF-8');



try {

    if (function_exists('set_time_limit')) {

        @set_time_limit(600);

    }



    $pdo = getDB();

    $key = trim((string) ($_GET['key'] ?? ''));

    if (!productionHealthAuthorize($pdo, $key)) {

        http_response_code(403);

        exit(json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_PRETTY_PRINT));

    }



    $apply = isset($_GET['apply']) && (string) $_GET['apply'] === '1';



    $bypassSince = (int) $pdo->query(

        "SELECT COUNT(*) FROM canonical_identity_bypass_log

         WHERE gateway_active = 0 AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"

    )->fetchColumn();



    $result = canonicalIdentityApplySafeNormalization($pdo, $apply);



    $sheets = null;

    $sheetsFailed = false;

    if ($apply && (($result['registrations_updated'] ?? 0) > 0)) {

        triggerApplyPortalSyncAsync($pdo, true);

        $applyUrl = rtrim(getSetting($pdo, 'apply_site_base_url', 'https://apply.olasentra.com'), '/')

            . '/admin/cron/sheets-cleanup-production.php?key=' . urlencode($key)

            . '&phase=sync&apply=1';

        $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 300, 'ignore_errors' => true]]);

        $body = @file_get_contents($applyUrl, false, $ctx);

        $sheets = is_string($body) ? json_decode($body, true) : null;

        $sheetsFailed = !is_array($sheets) || empty($sheets['ok']);

        productionHealthRecordSetting($pdo, 'canonical_identity_last_nightly_at', gmdate('Y-m-d H:i:s'));

    } elseif ($apply) {

        productionHealthRecordSetting($pdo, 'canonical_identity_last_nightly_at', gmdate('Y-m-d H:i:s'));

    }



    $auditAfter = $result['audit_after'] ?? canonicalIdentityAuditIntegrity($pdo);

    $pass       = !empty($auditAfter['pass']);

    $manualReviewCount = count($result['manual_review'] ?? []);



    $alertSent = canonicalIdentitySendIntegrityAlerts($pdo, $auditAfter, [

        'manual_review_count' => $manualReviewCount,

        'bypass_since_last'   => $bypassSince,

        'sheets_sync_failed'  => $sheetsFailed,

    ]);



    canonicalIdentityRecordNightlyRun($pdo, [

        'integrity_pass'        => $pass,

        'registrations_updated' => (int) ($result['registrations_updated'] ?? 0),

        'manual_review_count'   => $manualReviewCount,

        'bypass_attempts'       => $bypassSince,

        'audit'                 => $auditAfter,

        'alert_sent'            => $alertSent,

    ]);



    echo json_encode([

        'ok'                          => true,

        'applied'                     => $apply,

        'registrations_updated'       => (int) ($result['registrations_updated'] ?? 0),

        'alias_registrations_rejected'=> (int) ($result['alias_registrations_rejected'] ?? 0),

        'manual_review_pending'       => $manualReviewCount,

        'bypass_logged_24h'           => $bypassSince,

        'alert_sent'                  => $alertSent,

        'audit'                       => $auditAfter,

        'normalization'               => $result,

        'google_sheets'               => $sheets,

        'integrity_pass'              => $pass,

        'ready_message'               => $pass && $manualReviewCount === 0

            ? 'MASTER STAFF IDENTITY AUDIT PASS ✅'

            : 'Review required — open Staff Identity Manager',

    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);

}


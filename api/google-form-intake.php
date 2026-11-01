<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/google-form-intake.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Intake-Key');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only.']);
    exit;
}

$raw = file_get_contents('php://input');
$body = json_decode(is_string($raw) ? $raw : '', true);
if (!is_array($body)) {
    $body = $_POST;
}
if (!is_array($body)) {
    $body = [];
}

$key = trim((string) (
    $body['key']
    ?? $_SERVER['HTTP_X_INTAKE_KEY']
    ?? ''
));

try {
    $pdo = getDB();

    if (!googleFormIntakeKeyValid($pdo, $key)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden.']);
        exit;
    }

    $formUrl = trim((string) ($body['form_url'] ?? ''));
    if ($formUrl !== '' && filter_var($formUrl, FILTER_VALIDATE_URL)) {
        setSetting($pdo, 'google_form_aviva_url', $formUrl);
    }

    if (!empty($body['ensure_event'])) {
        $event = googleFormIntakeEnsureAvivaEvent($pdo);
        echo json_encode([
            'ok'         => true,
            'action'     => 'event_ready',
            'event'      => (string) ($event['name'] ?? GOOGLE_FORM_AVIVA_EVENT_NAME),
            'event_date' => (string) ($event['event_date'] ?? GOOGLE_FORM_AVIVA_EVENT_DATE),
            'event_id'   => (int) ($event['id'] ?? 0),
            'form_url'   => $formUrl !== '' ? $formUrl : trim((string) getSetting($pdo, 'google_form_aviva_url', '')),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if (!empty($body['psa_staff_census'])) {
        echo json_encode(
            googleFormIntakeStaffCensus($pdo),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }

    if (!empty($body['psa_reverify_all'])) {
        $limit = (int) ($body['limit'] ?? 80);
        $offset = (int) ($body['offset'] ?? 0);
        echo json_encode(
            googleFormIntakeReverifyExistingLicences($pdo, $limit, $offset),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }

    if (!empty($body['psa_not_found_staff_email'])) {
        echo json_encode(
            googleFormIntakeEmailNotFoundLicences($pdo),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }

    if (!empty($body['psa_lookup_only'])) {
        $licence = normalizePsaLicence(googleFormIntakePick($body, [
            'psa_licence', 'PSA licence number', 'licence',
        ]));
        $holderName = trim(googleFormIntakePick($body, ['holder_name', 'name', 'employee_name']));
        if ($holderName === '') {
            $holderName = trim(
                googleFormIntakePick($body, ['first_name', 'First name'])
                . ' '
                . googleFormIntakePick($body, ['surname', 'Surname'])
            );
        }
        $lookup = lookupPsaLicenceOnRegister($licence, $holderName !== '' ? $holderName : null);
        echo json_encode($lookup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if (!empty($body['sync_aviva_sheet'])) {
        echo json_encode(
            googleFormIntakeSyncFromAvivaSheet($pdo),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }

    if (!empty($body['sync_google_form_sheets'])) {
        echo json_encode(
            googleFormIntakeSyncAllSheets($pdo),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }

    $result = processGoogleFormStaffIntake($pdo, $body);
    if (empty($result['ok'])) {
        http_response_code(422);
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('[EventStaff] google-form-intake: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not save this registration.']);
}

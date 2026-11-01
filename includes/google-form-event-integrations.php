<?php

declare(strict_types=1);

require_once __DIR__ . '/google-form-event-integrations-schema.php';
require_once __DIR__ . '/google-sheets-sync.php';
require_once __DIR__ . '/events-repository.php';
require_once __DIR__ . '/settings-repository.php';

function parseGoogleFormId(string $urlOrId): ?string
{
    $urlOrId = trim($urlOrId);
    if ($urlOrId === '') {
        return null;
    }
    if (preg_match('#/forms/d/e/#', $urlOrId)) {
        return null;
    }
    if (preg_match('#/forms/d/([a-zA-Z0-9-_]+)#', $urlOrId, $m)) {
        return $m[1];
    }
    if (preg_match('#^[a-zA-Z0-9-_]{20,}$#', $urlOrId)) {
        return $urlOrId;
    }

    return null;
}

function googleFormIntakeIsPublishedFormUrl(string $urlOrId): bool
{
    return (bool) preg_match('#/forms/d/e/#', trim($urlOrId));
}

function googleFormEventIntegrationSheetUrl(string $sheetId): string
{
    return 'https://docs.google.com/spreadsheets/d/' . $sheetId . '/edit';
}

function googleFormEventIntegrationFormUrl(string $formId): string
{
    return 'https://docs.google.com/forms/d/' . $formId . '/edit';
}

function googleFormEventIntegrationStaffUrl(array $row): string
{
    $live = trim((string) ($row['responder_uri'] ?? ''));
    if ($live !== '') {
        return $live;
    }
    $formId = trim((string) ($row['google_form_id'] ?? ''));
    if ($formId !== '') {
        return 'https://docs.google.com/forms/d/' . $formId . '/viewform';
    }
    $url = trim((string) ($row['google_form_url'] ?? ''));
    if ($url !== '' && !str_contains($url, '/edit')) {
        return $url;
    }
    if ($formId !== '') {
        return googleFormEventIntegrationFormUrl($formId);
    }

    return $url;
}

function googleFormEventIntegrationServiceAccountEmail(): string
{
    $email = getGoogleServiceAccountClientEmail();

    return $email !== '' ? $email : 'event-staff-sheets@event-staff-system-498114.iam.gserviceaccount.com';
}

/**
 * @return array{token: string, label: string}|null
 */
function googleFormIntakeGetReadAuth(PDO $pdo): ?array
{
    require_once __DIR__ . '/google-drive-oauth.php';

    $sa = loadGoogleServiceAccount();
    if ($sa !== null) {
        $token = (string) (googleSheetsGetAccessToken($sa, [
            'https://www.googleapis.com/auth/spreadsheets.readonly',
            'https://www.googleapis.com/auth/drive.readonly',
        ]) ?? '');
        if ($token !== '') {
            return ['token' => $token, 'label' => 'service_account'];
        }
    }

    try {
        $gmail = googleDriveGetUserAccessToken($pdo);
        if ($gmail !== '') {
            return ['token' => $gmail, 'label' => 'gmail'];
        }
    } catch (Throwable $e) {
        // fall through
    }

    return null;
}

/**
 * @return array<string, mixed>|null
 */
function googleFormEventIntegrationById(PDO $pdo, int $id): ?array
{
    ensureGoogleFormEventIntegrationsSchema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM google_form_event_integrations WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * @return array<string, mixed>|null
 */
function googleFormEventIntegrationBySheetId(PDO $pdo, string $sheetId): ?array
{
    ensureGoogleFormEventIntegrationsSchema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM google_form_event_integrations WHERE google_sheet_id = :sheet_id LIMIT 1');
    $stmt->execute(['sheet_id' => $sheetId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * @return array<string, mixed>|null
 */
function googleFormEventIntegrationByEventId(PDO $pdo, int $eventId): ?array
{
    ensureGoogleFormEventIntegrationsSchema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM google_form_event_integrations WHERE event_id = :event_id ORDER BY id DESC LIMIT 1');
    $stmt->execute(['event_id' => $eventId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * @return list<array<string, mixed>>
 */
function googleFormEventIntegrationsList(PDO $pdo): array
{
    ensureGoogleFormEventIntegrationsSchema($pdo);
    $sql = "SELECT i.*, e.name AS event_name, e.event_date AS event_date, e.is_active AS event_active
            FROM google_form_event_integrations i
            LEFT JOIN events e ON e.id = i.event_id
            ORDER BY i.enabled DESC, e.event_date DESC, i.id DESC";

    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return list<array<string, mixed>>
 */
function googleFormEventIntegrationsEnabled(PDO $pdo): array
{
    ensureGoogleFormEventIntegrationsSchema($pdo);
    $sql = "SELECT i.*, e.name AS event_name, e.event_date AS event_date
            FROM google_form_event_integrations i
            INNER JOIN events e ON e.id = i.event_id
            WHERE i.enabled = 1
            ORDER BY i.id ASC";

    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function googleFormEventIntegrationIsLocked(array $row): bool
{
    if ((int) ($row['is_locked'] ?? 0) === 1) {
        return true;
    }
    $sheetId = (string) ($row['google_sheet_id'] ?? '');

    return defined('GOOGLE_FORM_AVIVA_SHEET_ID') && $sheetId === GOOGLE_FORM_AVIVA_SHEET_ID;
}

/**
 * @return array{ok: bool, titles?: list<string>, title?: string, error?: string, http?: int, hint?: string}
 */
function googleFormEventIntegrationVerifySheet(PDO $pdo, string $sheetId, string $preferredTab = ''): array
{
    $auth = googleFormIntakeGetReadAuth($pdo);
    if ($auth === null) {
        return ['ok' => false, 'error' => 'Google is not connected for sheet reads.'];
    }

    $url = 'https://sheets.googleapis.com/v4/spreadsheets/'
        . rawurlencode($sheetId)
        . '?fields=spreadsheetId,properties.title,sheets.properties.title';
    $response = googleSheetsHttpRequest('GET', $url, googleSheetsAuthHeaders($auth['token'], null, false));
    if ($response['code'] !== 200) {
        $sa = googleFormEventIntegrationServiceAccountEmail();
        $hint = $response['code'] === 403 || $response['code'] === 404
            ? 'Share the Responses Sheet as Viewer with ' . $sa . '.'
            : substr(trim(strip_tags($response['body'])), 0, 180);

        return [
            'ok'    => false,
            'error' => 'Could not read this Google Sheet.',
            'http'  => $response['code'],
            'hint'  => $hint,
        ];
    }

    $data = json_decode($response['body'], true);
    $titles = [];
    foreach (($data['sheets'] ?? []) as $sheet) {
        $title = trim((string) ($sheet['properties']['title'] ?? ''));
        if ($title !== '') {
            $titles[] = $title;
        }
    }
    $tab = trim($preferredTab);
    if ($tab !== '' && $titles !== [] && !in_array($tab, $titles, true)) {
        $tab = '';
    }
    if ($tab === '') {
        if (in_array('Form Responses 1', $titles, true)) {
            $tab = 'Form Responses 1';
        } elseif ($titles !== []) {
            $tab = $titles[0];
        } else {
            $tab = 'Form Responses 1';
        }
    }

    return [
        'ok'     => true,
        'title'  => (string) ($data['properties']['title'] ?? ''),
        'titles' => $titles,
        'tab'    => $tab,
        'auth'   => $auth['label'],
    ];
}

/**
 * @return array{ok: bool, sheet_id?: string, error?: string}
 */
function googleFormEventIntegrationDiscoverLinkedSheet(PDO $pdo, string $formId): array
{
    $sa = loadGoogleServiceAccount();
    if ($sa === null) {
        return ['ok' => false, 'error' => 'Service account is not configured.'];
    }
    $token = (string) (googleSheetsGetAccessToken($sa, [
        'https://www.googleapis.com/auth/forms.responses.readonly',
        'https://www.googleapis.com/auth/forms.body.readonly',
        'https://www.googleapis.com/auth/drive.readonly',
    ]) ?? '');
    if ($token === '') {
        return ['ok' => false, 'error' => 'Could not request Forms read access.'];
    }

    $url = 'https://forms.googleapis.com/v1/forms/' . rawurlencode($formId) . '?fields=linkedSheetId,responderUri,info.title';
    $response = googleSheetsHttpRequest('GET', $url, googleSheetsAuthHeaders($token, null, false));
    if ($response['code'] !== 200) {
        return [
            'ok'    => false,
            'error' => 'Could not read the Form. Paste the Responses Sheet URL instead, and share it as Viewer with '
                . googleFormEventIntegrationServiceAccountEmail() . '.',
            'http'  => $response['code'],
        ];
    }
    $data = json_decode($response['body'], true);
    $sheetId = trim((string) ($data['linkedSheetId'] ?? ''));
    if ($sheetId === '') {
        return [
            'ok'    => false,
            'error' => 'This Form has no Responses Sheet yet. In Google Forms open Responses → Link to Sheets, then paste that Sheet URL here.',
        ];
    }

    return ['ok' => true, 'sheet_id' => $sheetId];
}

/**
 * @param array<string, mixed> $input
 * @return array{ok: bool, id?: int, error?: string, verify?: array<string, mixed>}
 */
function googleFormEventIntegrationSave(PDO $pdo, array $input): array
{
    ensureGoogleFormEventIntegrationsSchema($pdo);

    $id = (int) ($input['id'] ?? 0);
    $existing = $id > 0 ? googleFormEventIntegrationById($pdo, $id) : null;
    if ($id > 0 && $existing === null) {
        return ['ok' => false, 'error' => 'Integration not found.'];
    }

    $eventId = (int) ($input['event_id'] ?? 0);
    $event = $eventId > 0 ? getEventById($pdo, $eventId) : null;
    if ($event === null) {
        return ['ok' => false, 'error' => 'Select a valid Olasentra event.'];
    }

    $avivaEvent = function_exists('googleFormIntakeFindAvivaEvent') ? googleFormIntakeFindAvivaEvent($pdo) : null;
    $avivaEventId = (int) ($avivaEvent['id'] ?? 0);

    $formRaw = trim((string) ($input['google_form_url'] ?? ''));
    $sheetRaw = trim((string) ($input['google_sheet_url'] ?? ''));
    $tabIn = trim((string) ($input['google_sheet_tab'] ?? ''));

    if ($existing !== null && googleFormEventIntegrationIsLocked($existing)) {
        $eventId = (int) $existing['event_id'];
        $sheetId = (string) $existing['google_sheet_id'];
        $formId = parseGoogleFormId($formRaw) ?: (string) ($existing['google_form_id'] ?? '');
        $formUrl = $formRaw !== '' ? $formRaw : (string) ($existing['google_form_url'] ?? '');
        $tab = $tabIn !== '' ? $tabIn : (string) ($existing['google_sheet_tab'] ?? 'Form Responses 1');
        $sheetUrl = googleFormEventIntegrationSheetUrl($sheetId);
    } else {
        $formId = null;
        $formUrl = '';
        if ($formRaw !== '') {
            if (googleFormIntakeIsPublishedFormUrl($formRaw)) {
                $formUrl = $formRaw;
            } else {
                $formId = parseGoogleFormId($formRaw);
                if ($formId === null) {
                    return ['ok' => false, 'error' => 'That Google Form URL is not valid. Use the Form edit URL, or paste the Responses Sheet URL.'];
                }
                $formUrl = googleFormEventIntegrationFormUrl($formId);
            }
        }

        $sheetId = parseGoogleSpreadsheetId($sheetRaw);
        if ($sheetId === null && $formId !== null) {
            $discovered = googleFormEventIntegrationDiscoverLinkedSheet($pdo, $formId);
            if (!empty($discovered['ok']) && !empty($discovered['sheet_id'])) {
                $sheetId = (string) $discovered['sheet_id'];
            }
        }
        if ($sheetId === null) {
            return ['ok' => false, 'error' => 'Paste the Responses Sheet URL (Form → Responses → Link to Sheets). Share it as Viewer with ' . googleFormEventIntegrationServiceAccountEmail() . '.'];
        }

        if ($avivaEventId > 0 && $eventId === $avivaEventId
            && defined('GOOGLE_FORM_AVIVA_SHEET_ID')
            && $sheetId !== GOOGLE_FORM_AVIVA_SHEET_ID) {
            return ['ok' => false, 'error' => 'The Aviva mapping is locked. Connect a different event, or use Create Google Form on a new event.'];
        }

        $other = googleFormEventIntegrationBySheetId($pdo, $sheetId);
        if ($other !== null && (int) $other['id'] !== $id) {
            return ['ok' => false, 'error' => 'That Responses Sheet is already mapped to another event.'];
        }

        $tab = $tabIn !== '' ? $tabIn : 'Form Responses 1';
        $sheetUrl = googleFormEventIntegrationSheetUrl($sheetId);
    }

    $verify = googleFormEventIntegrationVerifySheet($pdo, $sheetId, $tab);
    if (empty($verify['ok'])) {
        return [
            'ok'     => false,
            'error'  => (string) ($verify['hint'] ?? $verify['error'] ?? 'Could not read the sheet.'),
            'verify' => $verify,
        ];
    }
    $tab = (string) ($verify['tab'] ?? $tab);
    $enabled = isset($input['enabled']) ? ((int) $input['enabled'] === 1 ? 1 : 0) : 1;
    if ($existing !== null && googleFormEventIntegrationIsLocked($existing)) {
        $enabled = 1;
    }

    if ($existing !== null) {
        $pdo->prepare(
            'UPDATE google_form_event_integrations
             SET event_id = :event_id,
                 google_form_id = :google_form_id,
                 google_form_url = :google_form_url,
                 google_sheet_id = :google_sheet_id,
                 google_sheet_url = :google_sheet_url,
                 google_sheet_tab = :google_sheet_tab,
                 enabled = :enabled,
                 last_permission_ok = 1,
                 last_error = NULL
             WHERE id = :id'
        )->execute([
            'event_id'         => $eventId,
            'google_form_id'   => $formId !== null && $formId !== '' ? $formId : null,
            'google_form_url'  => $formUrl !== '' ? $formUrl : null,
            'google_sheet_id'  => $sheetId,
            'google_sheet_url' => $sheetUrl,
            'google_sheet_tab' => $tab,
            'enabled'          => $enabled,
            'id'               => (int) $existing['id'],
        ]);

        return ['ok' => true, 'id' => (int) $existing['id'], 'verify' => $verify];
    }

    $pdo->prepare(
        'INSERT INTO google_form_event_integrations (
            event_id, google_form_id, google_form_url, google_sheet_id, google_sheet_url,
            google_sheet_tab, enabled, processed_rows, last_permission_ok, is_locked, source
        ) VALUES (
            :event_id, :google_form_id, :google_form_url, :google_sheet_id, :google_sheet_url,
            :google_sheet_tab, :enabled, 0, 1, 0, :source
        )'
    )->execute([
        'event_id'         => $eventId,
        'google_form_id'   => $formId !== null && $formId !== '' ? $formId : null,
        'google_form_url'  => $formUrl !== '' ? $formUrl : null,
        'google_sheet_id'  => $sheetId,
        'google_sheet_url' => $sheetUrl,
        'google_sheet_tab' => $tab,
        'enabled'          => $enabled,
        'source'           => 'admin',
    ]);

    return ['ok' => true, 'id' => (int) $pdo->lastInsertId(), 'verify' => $verify];
}

function googleFormEventIntegrationSetEnabled(PDO $pdo, int $id, bool $enabled): bool
{
    $row = googleFormEventIntegrationById($pdo, $id);
    if ($row === null) {
        return false;
    }
    if (googleFormEventIntegrationIsLocked($row) && !$enabled) {
        return false;
    }
    $pdo->prepare('UPDATE google_form_event_integrations SET enabled = :enabled WHERE id = :id')
        ->execute(['enabled' => $enabled ? 1 : 0, 'id' => $id]);

    return true;
}

function googleFormEventIntegrationDelete(PDO $pdo, int $id): array
{
    $row = googleFormEventIntegrationById($pdo, $id);
    if ($row === null) {
        return ['ok' => false, 'error' => 'Integration not found.'];
    }
    if (googleFormEventIntegrationIsLocked($row)) {
        return ['ok' => false, 'error' => 'The Aviva mapping cannot be deleted.'];
    }
    $pdo->prepare('DELETE FROM google_form_event_integrations WHERE id = :id')->execute(['id' => $id]);

    return ['ok' => true];
}

/**
 * @param array<string, mixed> $stats
 */
function googleFormEventIntegrationRecordSync(PDO $pdo, int $id, array $stats): void
{
    $pdo->prepare(
        'UPDATE google_form_event_integrations
         SET last_sync_at = :last_sync_at,
             last_success_at = IF(:keep_success = 1, last_success_at, :last_success_at),
             last_error = :last_error,
             last_http_code = :last_http_code,
             last_total = :last_total,
             last_imported = :last_imported,
             last_skipped = :last_skipped,
             last_failed = :last_failed,
             last_permission_ok = :last_permission_ok,
             processed_rows = :processed_rows
         WHERE id = :id'
    )->execute([
        'last_sync_at'        => date('Y-m-d H:i:s'),
        'last_success_at'     => !empty($stats['ok']) ? date('Y-m-d H:i:s') : null,
        'keep_success'        => !empty($stats['ok']) ? 0 : 1,
        'last_error'          => isset($stats['error']) ? substr((string) $stats['error'], 0, 500) : null,
        'last_http_code'      => isset($stats['http']) ? (int) $stats['http'] : null,
        'last_total'          => (int) ($stats['total'] ?? 0),
        'last_imported'       => (int) ($stats['imported'] ?? 0),
        'last_skipped'        => (int) ($stats['skipped'] ?? 0),
        'last_failed'         => (int) ($stats['failed_count'] ?? 0),
        'last_permission_ok'  => !empty($stats['permission_ok']) || !empty($stats['ok']) ? 1 : 0,
        'processed_rows'      => (int) ($stats['processed_rows'] ?? 0),
        'id'                  => $id,
    ]);
}

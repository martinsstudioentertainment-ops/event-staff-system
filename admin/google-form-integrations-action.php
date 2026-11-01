<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/google-form-intake.php';
require_once __DIR__ . '/../includes/events-repository.php';
require_once __DIR__ . '/../includes/system-settings.php';
require_once __DIR__ . '/../includes/audit-log.php';

requireAdminCapability('events');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf($_POST['csrf_token'] ?? null)) {
    setAdminFlash('error', 'Invalid request.');
    header('Location: google-form-integrations.php');
    exit;
}

$pdo = getDB();
$action = trim((string) ($_POST['action'] ?? ''));
$id = (int) ($_POST['id'] ?? 0);
$redirect = 'google-form-integrations.php';
$eventPrefill = (int) ($_POST['event_id'] ?? 0);
if ($eventPrefill > 0 && $action === 'save') {
    $redirect .= '?event_id=' . $eventPrefill;
}

try {
    googleFormIntakeSeedAvivaIntegration($pdo);

    if ($action === 'save') {
        $result = googleFormEventIntegrationSave($pdo, $_POST);
        if (empty($result['ok'])) {
            setAdminFlash('error', (string) ($result['error'] ?? 'Could not save this connection.'));
        } else {
            logAdminAudit($pdo, 'google_form_integration_save', 'event', (int) ($_POST['event_id'] ?? 0), 'Connected Google Form Responses Sheet');
            setAdminFlash('success', 'Google Form connected. Automatic import is on for that event.');
            $redirect = 'google-form-integrations.php';
        }
    } elseif ($action === 'enable' || $action === 'disable') {
        $ok = googleFormEventIntegrationSetEnabled($pdo, $id, $action === 'enable');
        if (!$ok) {
            setAdminFlash('error', $action === 'disable' ? 'The Aviva mapping stays enabled.' : 'Could not update that connection.');
        } else {
            logAdminAudit($pdo, 'google_form_integration_' . $action, 'google_form_integration', $id, '');
            setAdminFlash('success', $action === 'enable' ? 'Automatic import enabled.' : 'Automatic import paused.');
        }
    } elseif ($action === 'delete') {
        $result = googleFormEventIntegrationDelete($pdo, $id);
        if (empty($result['ok'])) {
            setAdminFlash('error', (string) ($result['error'] ?? 'Could not remove that connection.'));
        } else {
            logAdminAudit($pdo, 'google_form_integration_delete', 'google_form_integration', $id, '');
            setAdminFlash('success', 'Google Form connection removed. Existing staff records were kept.');
        }
    } elseif ($action === 'verify') {
        $row = googleFormEventIntegrationById($pdo, $id);
        if ($row === null) {
            setAdminFlash('error', 'Integration not found.');
        } else {
            $verify = googleFormEventIntegrationVerifySheet(
                $pdo,
                (string) $row['google_sheet_id'],
                (string) $row['google_sheet_tab']
            );
            $pdo->prepare(
                'UPDATE google_form_event_integrations
                 SET last_permission_ok = :ok,
                     last_error = :last_error,
                     last_http_code = :http,
                     last_sync_at = :last_sync_at
                 WHERE id = :id'
            )->execute([
                'ok'           => !empty($verify['ok']) ? 1 : 0,
                'last_error'   => empty($verify['ok']) ? substr((string) ($verify['hint'] ?? $verify['error'] ?? ''), 0, 500) : null,
                'http'         => (int) ($verify['http'] ?? (!empty($verify['ok']) ? 200 : 0)),
                'last_sync_at' => date('Y-m-d H:i:s'),
                'id'           => $id,
            ]);
            if (empty($verify['ok'])) {
                setAdminFlash('error', (string) ($verify['hint'] ?? $verify['error'] ?? 'Could not read the sheet.'));
            } else {
                setAdminFlash('success', 'Sheet is readable as Viewer. Tab: ' . (string) ($verify['tab'] ?? ''));
            }
        }
    } elseif ($action === 'sync') {
        $row = googleFormEventIntegrationById($pdo, $id);
        $auth = googleFormIntakeGetReadAuth($pdo);
        if ($row === null) {
            setAdminFlash('error', 'Integration not found.');
        } elseif ($auth === null) {
            setAdminFlash('error', 'Google is not connected for sheet reads.');
        } else {
            $result = googleFormIntakeSyncIntegration($pdo, $row, $auth['token'], $auth['label']);
            logAdminAudit($pdo, 'google_form_integration_sync', 'event', (int) $row['event_id'], json_encode([
                'imported' => (int) ($result['imported'] ?? 0),
                'skipped'  => (int) ($result['skipped'] ?? 0),
                'failed'   => (int) ($result['failed_count'] ?? 0),
            ]));
            if (empty($result['ok'])) {
                setAdminFlash('error', (string) ($result['hint'] ?? $result['error'] ?? 'Sync failed.'));
            } else {
                setAdminFlash(
                    'success',
                    sprintf(
                        'Sync finished — imported %d, skipped %d, failed %d.',
                        (int) ($result['imported'] ?? 0),
                        (int) ($result['skipped'] ?? 0),
                        (int) ($result['failed_count'] ?? count($result['failed'] ?? []))
                    )
                );
            }
        }
    } elseif ($action === 'sync_all') {
        $result = googleFormIntakeSyncAllSheets($pdo);
        logAdminAudit($pdo, 'google_form_integration_sync_all', 'system', 0, 'count=' . (int) ($result['count'] ?? 0));
        setAdminFlash('success', 'Ran the Google Form watcher for every enabled event.');
    } elseif ($action === 'create' || $action === 'quick') {
        require_once __DIR__ . '/../includes/google-form-create.php';
        $eventId = (int) ($_POST['event_id'] ?? 0);
        if ($action === 'quick' || $eventId < 1) {
            $result = googleFormCreateQuick(
                $pdo,
                (string) ($_POST['event_name'] ?? ''),
                (string) ($_POST['event_date'] ?? ''),
                [
                    'include_pps' => !empty($_POST['include_pps']),
                    'include_dob' => !empty($_POST['include_dob']),
                ]
            );
            $eventId = (int) ($result['event_id'] ?? 0);
        } else {
            $result = googleFormCreateForEvent($pdo, $eventId, [
                'include_pps' => !empty($_POST['include_pps']),
                'include_dob' => !empty($_POST['include_dob']),
            ]);
        }
        $redirect = 'google-form-integrations.php#create';
        if (!empty($result['needs_reauth'])) {
            setAdminFlash('error', (string) ($result['error'] ?? 'Click Connect Google account once, then generate the link again.'));
        } elseif (empty($result['ok'])) {
            setAdminFlash('error', (string) ($result['error'] ?? 'Could not create the Google Form.')
                . (!empty($result['hint']) ? ' ' . $result['hint'] : ''));
        } else {
            $staffUrl = trim((string) ($result['responder_uri'] ?? ''));
            if ($staffUrl === '' && !empty($result['google_form_id'])) {
                $staffUrl = 'https://docs.google.com/forms/d/' . $result['google_form_id'] . '/viewform';
            }
            $_SESSION['google_form_staff_link'] = $staffUrl;
            $_SESSION['google_form_staff_event'] = (string) ($result['event'] ?? '');
            logAdminAudit($pdo, 'google_form_integration_create', 'event', $eventId, json_encode([
                'form_id'  => (string) ($result['google_form_id'] ?? ''),
                'sheet_id' => (string) ($result['google_sheet_id'] ?? ''),
            ]));
            setAdminFlash(
                'success',
                !empty($result['already'])
                    ? 'This event already has a Form. Copy the staff link below.'
                    : 'Form ready. Copy the staff link below and send it.'
            );
            $redirect = 'google-form-integrations.php#staff-link';
        }
    } else {
        setAdminFlash('error', 'Unknown action.');
    }
} catch (Throwable $e) {
    error_log('[EventStaff] google-form-integrations-action: ' . $e->getMessage());
    setAdminFlash('error', 'Could not complete that action.');
}

header('Location: ' . $redirect);
exit;

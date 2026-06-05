<?php

/**

 * Event Staff System — Registration Form Handler

 * Saves staff registration to MySQL — one row per selected event.

 */



require_once __DIR__ . '/config.php';
initSecureSession();

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/validation.php';
require_once __DIR__ . '/includes/registration-forms.php';
require_once __DIR__ . '/includes/registration-options-repository.php';
require_once __DIR__ . '/includes/staff-repository.php';
require_once __DIR__ . '/includes/notifications.php';
require_once __DIR__ . '/includes/system-settings.php';
require_once __DIR__ . '/includes/staff-blacklist.php';
require_once __DIR__ . '/includes/google-sheets-sync.php';
require_once __DIR__ . '/includes/staff-registration-schema.php';
require_once __DIR__ . '/includes/status-repository.php';
require_once __DIR__ . '/includes/staff-psa.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdoBoot = getDB();
        ensureStaffRegistrationSaveSchema($pdoBoot);
        enforceMaintenanceMode($pdoBoot);
    } catch (Throwable $e) {
        // allow if DB unavailable
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
    $_SESSION['registration_errors'] = ['form' => 'Your session expired. Please try again.'];
    header('Location: index.php?error=validation');
    exit;
}

$data     = $_POST;
$formSlug = strtolower(trim((string) ($data['form_slug'] ?? '')));

if ($formSlug !== '') {
    try {
        $pdoForm = getDB();
        $formDef = getRegistrationForm($pdoForm, $formSlug);
        if ($formDef !== null) {
            $data['staff_role'] = normalizeStaffRole((string) $formDef['staff_role']);
            $data['form_slug']  = $formSlug;
        }
    } catch (Throwable $e) {
        // validated below
    }
}

$data['staff_role']     = normalizeStaffRole((string) ($data['staff_role'] ?? ''));
$data['date_of_birth']  = normalizeDateOfBirthForDb((string) ($data['date_of_birth'] ?? ''));
prepareMobileFromRequest($data);
$errors                 = validateRegistration($data);
$eventIds = normalizeEventIds($data);

$existingStaff = null;
try {
    $pdoLookup = getDB();
    $existingStaff = getStaffByEmail($pdoLookup, normalizeRegistrationEmail((string) ($data['email'] ?? '')));
} catch (Throwable $e) {
    $existingStaff = null;
}
$errors = array_merge($errors, validateRegistrationPsa($data, $existingStaff, $_FILES));

if (empty($errors)) {
    try {
        $pdo = getDB();
        $errors = array_merge($errors, validateRegistrationFormContext($pdo, $data));

        $blacklistError = validateStaffNotBlacklisted(
            $pdo,
            normalizeRegistrationEmail((string) ($data['email'] ?? ''))
        );
        if ($blacklistError !== null) {
            $errors['email'] = $blacklistError;
        }
    } catch (Throwable $e) {
        $errors['form_slug'] = 'Unable to validate registration form.';
    }
}

if (empty($errors)) {

    try {

        $pdo = getDB();



        $invalidIds = getInvalidEventIds($pdo, $eventIds);

        if ($invalidIds !== []) {

            $errors['event_ids'] = 'One or more selected shifts are no longer open (event finished, full, or date passed).';

        } else {

            $formDef = null;
            if ($formSlug !== '') {
                $formDef = getRegistrationForm($pdo, $formSlug);
            }

            if ($formDef !== null) {
                $ineligible = getIneligibleEventIdsForForm($pdo, $eventIds, $formDef);
                if ($ineligible !== []) {
                    $errors['event_ids'] = 'One or more selected shifts are not available on this registration form.';
                }
            }

            if (empty($errors)) {

            $email       = normalizeRegistrationEmail((string) ($data['email'] ?? ''));

            $duplicates  = getAlreadyRegisteredEvents($pdo, $email, $eventIds);

            $duplicateIds = array_map(static fn(array $row): int => (int) $row['event_id'], $duplicates);

            $newEventIds  = array_values(array_filter(

                $eventIds,

                static fn(int $id): bool => !in_array($id, $duplicateIds, true)

            ));



            if ($newEventIds === []) {

                $errors['event_ids'] = formatDuplicateEventsMessage($duplicates);
                $statusUrl = getRegistrationStatusUrlAfterSave($pdo, [], $email);
                $_SESSION['registration_status_message'] = $errors['event_ids'];

                if (isAjaxRequest()) {
                    jsonResponse([
                        'success'    => false,
                        'message'    => $errors['event_ids'],
                        'status_url' => $statusUrl,
                        'errors'     => $errors,
                    ], 422);
                }

                header('Location: ' . $statusUrl);
                exit;

            } else {

                $ids = saveRegistrations($pdo, $data, $newEventIds, $_FILES);

                try {
                    notifyStaffRegistrationSubmitted($pdo, $data, $newEventIds, $ids);
                } catch (Throwable $notifyErr) {
                    error_log('[EventStaff] Registration email failed: ' . $notifyErr->getMessage());
                }

                try {
                    require_once __DIR__ . '/includes/notification-center.php';
                    $staffName = trim((string) ($data['first_name'] ?? '') . ' ' . (string) ($data['surname'] ?? ''));
                    foreach ($ids as $regId) {
                        $row = getStaffRegistrationById($pdo, (int) $regId);
                        if ($row === null) {
                            continue;
                        }
                        notifyAdminNewRegistration(
                            $pdo,
                            $staffName !== '' ? $staffName : 'New applicant',
                            $email,
                            (int) $regId,
                            formatEventLabel($row)
                        );
                    }
                } catch (Throwable $adminNotifyErr) {
                    error_log('[EventStaff] Admin notification failed: ' . $adminNotifyErr->getMessage());
                }

                try {
                    $sheetStats = syncRegistrationsToGoogleSheets($pdo, $ids);
                    if ($sheetStats['failed'] > 0) {
                        error_log('[EventStaff] Google Sheets sync failed for ' . $sheetStats['failed'] . ' registration(s). See storage/logs/google-sheets.log');
                    }
                } catch (Throwable $sheetErr) {
                    error_log('[EventStaff] Google Sheets sync error: ' . $sheetErr->getMessage());
                }

                $count = count($ids);



                $message = $count === 1

                    ? 'Registration submitted successfully for 1 event! Your application is pending approval.'

                    : 'Registration submitted successfully for ' . $count . ' events! Your applications are pending approval.';



                if ($duplicates !== []) {

                    $message .= ' Already registered (skipped): ' . implode(', ', array_map(

                        static fn(array $row): string => formatEventLabel($row),

                        $duplicates

                    )) . '.';

                }



                $_SESSION['registration_status_message'] = $message;
                $redirectUrl = getRegistrationStatusUrlAfterSave($pdo, $ids, $email);

                if (isAjaxRequest()) {

                    jsonResponse([

                        'success'    => true,

                        'message'    => (string) $_SESSION['registration_status_message'],

                        'count'      => $count,

                        'status_url' => $redirectUrl,

                    ]);

                }

                header('Location: ' . $redirectUrl);

                exit;

            }

            }

        }

    } catch (PDOException $e) {

        error_log('[EventStaff] Database error: ' . $e->getMessage());

        if (str_contains($e->getMessage(), 'uq_staff_email_event') || str_contains($e->getMessage(), 'Duplicate entry')) {

            $errors['event_ids'] = 'You are already registered for one or more selected events.';

        } elseif (str_contains($e->getMessage(), 'staff_role') || str_contains($e->getMessage(), 'Data truncated')) {

            $errors['form_slug'] = 'Could not save your role. Please try DSP or Static only, or contact support.';

            if (isAjaxRequest()) {
                jsonResponse([
                    'success' => false,
                    'message' => $errors['form_slug'],
                    'errors'  => $errors,
                ], 422);
            }

        } else {

            if (isAjaxRequest()) {

                jsonResponse([

                    'success' => false,

                    'message' => 'We could not save your registration. Please try again in a few minutes.',

                    'errors'  => $errors,

                ], 500);

            }



            header('Location: ' . registrationFormRedirectPath(['error' => 'db'], $formSlug));

            exit;

        }

    } catch (RuntimeException $e) {

        $errors['event_ids'] = 'You are already registered for one or more selected events.';

    } catch (Throwable $e) {

        error_log('[EventStaff] Registration save failed: ' . $e->getMessage());

        if (isAjaxRequest()) {
            jsonResponse([
                'success' => false,
                'message' => 'We could not save your registration. Please try again in a few minutes.',
                'errors'  => $errors,
            ], 500);
        }

        header('Location: ' . registrationFormRedirectPath(['error' => 'db'], $formSlug));
        exit;

    }

}



if (isAjaxRequest()) {

    jsonResponse([

        'success' => false,

        'message' => 'Please correct the highlighted fields before submitting.',

        'errors'  => $errors,

    ], 422);

}



initSecureSession();

$_SESSION['registration_errors'] = $errors;

$_SESSION['registration_old']    = $data;

header('Location: ' . registrationFormRedirectPath(['error' => 1], $formSlug));

exit;


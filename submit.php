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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        enforceMaintenanceMode(getDB());
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

$data['staff_role'] = normalizeStaffRole((string) ($data['staff_role'] ?? ''));
$errors             = validateRegistration($data);
$eventIds = normalizeEventIds($data);

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

            $errors['event_ids'] = 'One or more selected shifts are no longer open for registration (event finished or date passed).';

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

            } else {

                $ids   = saveRegistrations($pdo, $data, $newEventIds);

                notifyStaffRegistrationSubmitted($pdo, $data, $newEventIds, $ids);

                $sheetStats = syncRegistrationsToGoogleSheets($pdo, $ids);
                if ($sheetStats['failed'] > 0) {
                    error_log('[EventStaff] Google Sheets sync failed for ' . $sheetStats['failed'] . ' registration(s). See storage/logs/google-sheets.log');
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



                if (isAjaxRequest()) {

                    jsonResponse([

                        'success' => true,

                        'message' => $message,

                        'count'   => $count,

                    ]);

                }

                header('Location: ' . registrationFormRedirectPath(['registered' => $count], $formSlug));

                exit;

            }

            }

        }

    } catch (PDOException $e) {

        error_log('[EventStaff] Database error: ' . $e->getMessage());



        if (str_contains($e->getMessage(), 'uq_staff_email_event') || str_contains($e->getMessage(), 'Duplicate entry')) {

            $errors['event_ids'] = 'You are already registered for one or more selected events.';

        } else {

            if (isAjaxRequest()) {

                jsonResponse([

                    'success' => false,

                    'message' => 'We could not save your registration. Please try again in a few minutes.',

                ], 500);

            }



            header('Location: ' . registrationFormRedirectPath(['error' => 'db'], $formSlug));

            exit;

        }

    } catch (RuntimeException $e) {

        $errors['event_ids'] = 'You are already registered for one or more selected events.';

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


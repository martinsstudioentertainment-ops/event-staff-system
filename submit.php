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
require_once __DIR__ . '/includes/staff-profile-gate.php';
require_once __DIR__ . '/includes/registration-post-save.php';
require_once __DIR__ . '/includes/staff-allocation.php';
require_once __DIR__ . '/includes/staff-google-oauth.php';

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
$waitlistMode = isWaitlistRegistrationRequest($data);

try {
    $pdoGoogleCheck = getDB();
    if (isRegistrationGoogleRequired($pdoGoogleCheck)) {
        $verifiedGoogleCheck = normalizeRegistrationEmail((string) ($_POST['registration_verified_google_email'] ?? ''));
        if ($verifiedGoogleCheck === '') {
            $verifiedGoogleCheck = getRegistrationVerifiedGoogleEmail() ?? '';
        }
        if ($verifiedGoogleCheck === '') {
            $_SESSION['registration_google_error'] = 'Verify your email first (Continue with Google or email verification code).';
            $_SESSION['registration_errors']       = ['email' => 'Verify your email before submitting.'];
            $_SESSION['registration_old']          = $_POST;
            header('Location: index.php?error=validation');
            exit;
        }
    }
} catch (Throwable $e) {
    // validated below
}

$verifiedGoogle = normalizeRegistrationEmail((string) ($_POST['registration_verified_google_email'] ?? ''));
if ($verifiedGoogle === '') {
    $verifiedGoogle = getRegistrationVerifiedGoogleEmail() ?? '';
}
if ($verifiedGoogle !== '') {
    $submittedEmail = normalizeRegistrationEmail((string) ($data['email'] ?? ''));
    if ($submittedEmail === '') {
        $data['email'] = $verifiedGoogle;
    } elseif ($submittedEmail !== $verifiedGoogle) {
        $_SESSION['registration_errors'] = ['email' => 'Email must match the address you verified.'];
        $_SESSION['registration_old']      = $data;
        header('Location: ' . registrationFormRedirectPath(['error' => 1], $formSlug));
        exit;
    }
}

$errors   = $waitlistMode ? validateWaitlistRegistration($data) : validateRegistration($data);
$eventIds = normalizeEventIds($data);

$existingStaff = null;
try {
    $pdoLookup = getDB();
    $existingStaff = getStaffByEmail($pdoLookup, normalizeRegistrationEmail((string) ($data['email'] ?? '')));
} catch (Throwable $e) {
    $existingStaff = null;
}
$errors = array_merge($errors, validateRegistrationPsa($data, $existingStaff, $_FILES));

if (empty($errors) && ($eventIds !== [] || $waitlistMode)) {
    if (!registrationFormReadyForShiftSelection($data, $_FILES, $existingStaff)) {
        $errors['form'] = 'Complete all personal, financial, and PSA details (including card photos) before selecting shifts.';
    }
}

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

        if ($waitlistMode && $eventIds === []) {
            $email = normalizeRegistrationEmail((string) ($data['email'] ?? ''));
            $waitlistResult = saveWaitlistRegistration($pdo, $data, $_FILES);
            if (!($waitlistResult['ok'] ?? false)) {
                $errors['form'] = (string) ($waitlistResult['error'] ?? 'Could not save waiting list registration.');
            } else {
                $allocationType = normalizeWaitlistAllocationType($data);
                $message = buildWaitlistSuccessMessage($allocationType, (int) ($data['preferred_event_id'] ?? 0));
                $_SESSION['registration_status_message'] = $message;
                $redirectUrl = getRegistrationStatusUrlAfterSave($pdo, [], $email);

                if (isAjaxRequest()) {
                    registrationFlushResponse('', [
                        'success'    => true,
                        'message'    => $message,
                        'count'      => 0,
                        'waitlist'   => true,
                        'status_url' => $redirectUrl,
                    ]);
                    exit;
                }

                registrationFlushResponse($redirectUrl);
                exit;
            }
        } else {
        $split       = splitEventIdsByAvailability($pdo, $eventIds);
        $closedIds   = $split['closed'];
        $fullIds     = $split['full'];
        $availableIds = $split['available'];

        if ($closedIds !== []) {
            $errors['event_ids'] = 'One or more selected shifts are no longer open (event finished or date passed).';
        } else {
            $eventIds = $availableIds;

            $formDef = null;
            if ($formSlug !== '') {
                $formDef = getRegistrationForm($pdo, $formSlug);
            }

            if ($formDef !== null && $eventIds !== []) {
                $ineligible = getIneligibleEventIdsForForm($pdo, $eventIds, $formDef);
                if ($ineligible !== []) {
                    $errors['event_ids'] = 'One or more selected shifts are not available on this registration form.';
                }
            }

            if (empty($errors)) {

            $email       = normalizeRegistrationEmail((string) ($data['email'] ?? ''));

            $duplicates  = $eventIds !== [] ? getAlreadyRegisteredEvents($pdo, $email, $eventIds) : [];

            $duplicateIds = array_map(static fn(array $row): int => (int) $row['event_id'], $duplicates);

            $newEventIds  = array_values(array_filter(
                $eventIds,
                static fn(int $id): bool => !in_array($id, $duplicateIds, true)
            ));

            $waitlistSaved = 0;
            foreach ($fullIds as $fullEventId) {
                $waitData = $data;
                $waitData['preferred_event_id'] = $fullEventId;
                if (!registrationExistsForEmail($pdo, $email, $fullEventId)) {
                    $wl = saveWaitlistRegistration($pdo, $waitData, $_FILES);
                    if ($wl['ok'] ?? false) {
                        $waitlistSaved++;
                    }
                }
            }

            if ($newEventIds === [] && $waitlistSaved === 0 && $fullIds === []) {

                $errors['event_ids'] = $duplicates !== []
                    ? formatDuplicateEventsMessage($duplicates)
                    : 'Please select at least one shift or event.';
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

            } elseif ($newEventIds === [] && $waitlistSaved > 0) {

                $message = buildWaitlistSuccessMessage(normalizeWaitlistAllocationType($data), (int) ($fullIds[0] ?? 0));
                if ($duplicates !== []) {
                    $message .= ' Already registered (skipped): ' . implode(', ', array_map(static fn(array $row): string => formatEventLabel($row), $duplicates)) . '.';
                }
                $_SESSION['registration_status_message'] = $message;
                $redirectUrl = getRegistrationStatusUrlAfterSave($pdo, [], $email);

                if (isAjaxRequest()) {
                    registrationFlushResponse('', [
                        'success'    => true,
                        'message'    => $message,
                        'count'      => 0,
                        'waitlist'   => true,
                        'status_url' => $redirectUrl,
                    ]);
                    exit;
                }

                registrationFlushResponse($redirectUrl);
                exit;

            } else {

                $ids = $newEventIds !== [] ? saveRegistrations($pdo, $data, $newEventIds, $_FILES) : [];

                $count = count($ids);
                $message = buildRegistrationSuccessMessage($count, 0, $duplicates);
                if ($waitlistSaved > 0) {
                    $message .= ' You were also added to the waiting list for ' . $waitlistSaved . ' full shift(s).';
                }

                $_SESSION['registration_status_message'] = $message;
                $redirectUrl = getRegistrationStatusUrlAfterSave($pdo, $ids, $email);

                if (isAjaxRequest()) {
                    registrationFlushResponse('', [
                        'success'    => true,
                        'message'    => $message,
                        'count'      => $count,
                        'status_url' => $redirectUrl,
                    ]);
                    runRegistrationPostSaveSafely($pdo, $data, $ids, $newEventIds, $email);
                    exit;
                }

                registrationFlushResponse($redirectUrl);
                runRegistrationPostSaveSafely($pdo, $data, $ids, $newEventIds, $email);
                exit;

            }

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



            if (!headers_sent()) {
                header('Location: ' . registrationFormRedirectPath(['error' => 'db'], $formSlug));
            }

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

        if (!headers_sent()) {
            header('Location: ' . registrationFormRedirectPath(['error' => 'db'], $formSlug));
        }
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


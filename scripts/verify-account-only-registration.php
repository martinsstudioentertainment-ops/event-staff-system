<?php
/**
 * CLI verification — account-only registration rules (no DB writes).
 *   php scripts/verify-account-only-registration.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/includes/validation.php';
require_once $root . '/includes/staff-psa.php';
require_once $root . '/includes/registration-forms.php';

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "PASS  $label" . ($detail !== '' ? " — $detail" : '') . PHP_EOL;
    } else {
        $fail++;
        echo "FAIL  $label" . ($detail !== '' ? " — $detail" : '') . PHP_EOL;
    }
}

function baseData(string $role): array
{
    return [
        'surname'           => 'Verify',
        'first_name'        => 'Test',
        'full_address'      => '1 Test Street',
        'eircode'           => 'D02 X285',
        'email'             => 'verify-' . bin2hex(random_bytes(4)) . '@example.com',
        'mobile'            => '+353851234567',
        'date_of_birth'     => '1990-01-01',
        'gender'            => 'Male',
        'pps_number'        => '1234567T',
        'bank_iban'         => 'IE29AIBK93115212345678',
        'staff_role'        => $role,
        'psa_licence'       => '',
        'psa_expiry_date'   => '',
        'privacy_consent'   => '1',
        'registration_mode' => 'profile_only',
        'event_ids'         => [99],
        'join_waiting_list' => '1',
    ];
}

$norm = normalizePortalRegistrationPost(baseData('dsp'));
check('Portal post strips event_ids', normalizeEventIds($norm) === []);
check('Portal post strips waitlist flag', !isset($norm['join_waiting_list']));
check('Portal post forces profile_only', ($norm['registration_mode'] ?? '') === 'profile_only');

$stewardData = normalizePortalRegistrationPost(baseData('steward'));
$stewardErrs = validateWaitlistRegistration($stewardData);
$stewardPsa = validateRegistrationPsa($stewardData, null, []);
check(
    'Steward — no PSA field errors',
    !isset($stewardErrs['psa_licence'], $stewardErrs['psa_expiry_date'])
);
check('Steward — no PSA upload errors', $stewardPsa === []);

$dspData = normalizePortalRegistrationPost(baseData('dsp'));
$dspErrs = validateWaitlistRegistration($dspData);
$dspPsa = validateRegistrationPsa($dspData, null, []);
check(
    'DSP — PSA licence required',
    isset($dspErrs['psa_licence']) || isset($dspPsa['psa_licence'])
);
check(
    'DSP — PSA expiry required',
    isset($dspErrs['psa_expiry_date']) || isset($dspPsa['psa_expiry_date'])
);
check(
    'DSP — PSA uploads required',
    isset($dspPsa['psa_front_image'], $dspPsa['psa_back_image'])
);

$staticData = normalizePortalRegistrationPost(baseData('static'));
$staticPsa = validateRegistrationPsa($staticData, null, []);
check('Static — PSA validation enforced', $staticPsa !== []);

$dspOk = baseData('dsp');
$dspOk['psa_licence'] = 'EM123456/00';
$dspOk['psa_expiry_date'] = '2030-12-31';
$dspOk = normalizePortalRegistrationPost($dspOk);
$dspOkErrs = validateWaitlistRegistration($dspOk);
$dspOkPsa = validateRegistrationPsa($dspOk, null, []);
check(
    'DSP with licence+expiry — no licence/expiry field errors',
    !isset($dspOkErrs['psa_licence'], $dspOkErrs['psa_expiry_date'])
        && !isset($dspOkPsa['psa_licence'], $dspOkPsa['psa_expiry_date'])
);

check('validateWaitlistRegistration never requires event_ids', !isset(validateWaitlistRegistration($stewardData)['event_ids']));

echo PHP_EOL . "Summary: $pass passed, $fail failed" . PHP_EOL;
exit($fail > 0 ? 1 : 0);

<?php

declare(strict_types=1);

require_once __DIR__ . '/financial-field-validation.php';

/**
 * PSA Ireland register search (no public API — manual register lookup).
 */
function psaRegisterEmployeeSearchUrl(string $licence): string
{
    return 'https://www.psa-gov.ie/psa-registered-employees/';
}

/**
 * @return array{ok: bool, format_ok: bool, message: string, register_url: string, licence: string}
 */
function verifyPsaLicenceBasic(string $licence, ?string $holderName = null): array
{
    $licence = normalizePsaLicence($licence);
    $registerUrl = psaRegisterEmployeeSearchUrl($licence);

    if ($licence === '') {
        return [
            'ok'          => false,
            'format_ok'   => false,
            'message'     => 'Enter your PSA licence number.',
            'register_url'=> $registerUrl,
            'licence'     => '',
        ];
    }

    if (!isValidPsaLicenceFormat($licence)) {
        return [
            'ok'          => false,
            'format_ok'   => false,
            'message'     => 'Licence must match PSA format EM123456/00.',
            'register_url'=> $registerUrl,
            'licence'     => $licence,
        ];
    }

    $message = 'Format looks valid. Confirm it matches your name on the official PSA register (we cannot auto-verify originality without a PSA API).';
    if ($holderName !== null && trim($holderName) !== '') {
        $message .= ' Search for "' . trim($holderName) . '" or licence ' . $licence . ' on the register.';
    }

    return [
        'ok'          => true,
        'format_ok'   => true,
        'message'     => $message,
        'register_url'=> $registerUrl,
        'licence'     => $licence,
    ];
}

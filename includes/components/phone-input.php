<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/phone-numbers.php';

if (!function_exists('h')) {
    /**
     * @param string|null $value
     */
    function h($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Country code selector + national number (stored as E.164 in hidden mobile field).
 *
 * @param array{
 *   id?: string,
 *   name?: string,
 *   value?: string,
 *   defaultIso?: string,
 *   required?: bool,
 *   errorId?: string,
 *   hint?: string,
 *   readonly?: bool,
 *   inputClass?: string,
 *   selectClass?: string,
 *   wrapperClass?: string,
 *   variant?: 'default'|'secure',
 *   fieldName?: string
 * } $options
 */
function renderPhoneInputField(array $options = []): void
{
    $variant     = (string) ($options['variant'] ?? 'default');
    $isSecure    = $variant === 'secure';
    $fieldId     = (string) ($options['id'] ?? ($isSecure ? 'phone' : 'mobile'));
    $name        = (string) ($options['name'] ?? ($isSecure ? 'phone' : 'mobile'));
    $value       = (string) ($options['value'] ?? '');
    $defaultIso  = (string) ($options['defaultIso'] ?? defaultPhoneCountryIso());
    $required    = ($options['required'] ?? true) === true;
    $errorId     = (string) ($options['errorId'] ?? $fieldId . '-error');
    $hint        = array_key_exists('hint', $options)
        ? (string) $options['hint']
        : ($isSecure ? '' : 'We pre-select your country from your connection. Change it if your number is from elsewhere.');
    $readonly    = ($options['readonly'] ?? false) === true;
    $inputClass  = (string) ($options['inputClass'] ?? ($isSecure ? 'secure-input' : 'form-input'));
    $selectClass = (string) ($options['selectClass'] ?? ($isSecure ? 'secure-input' : 'form-select'));
    $wrapperClass = trim('phone-input ' . (string) ($options['wrapperClass'] ?? ''));
    $nationalId  = (string) ($options['fieldName'] ?? 'mobile') . '_national';
    if ($isSecure) {
        $nationalId = 'phone_national';
    } elseif (!isset($options['fieldName'])) {
        $nationalId = 'mobile_national';
    }

    $parts   = splitMobileNumber($value, $defaultIso);
    $codes   = phoneCountryDialCodes();
    $labels  = phoneCountryLabels();
    $reqAttr = $required ? ' required' : '';
    $roAttr  = $readonly ? ' readonly disabled' : '';

    $preferred = ['IE', 'GB', 'PL', 'RO', 'LT', 'LV', 'EE', 'HU', 'DE', 'FR', 'ES', 'IT', 'PT', 'NL', 'US'];
    $ordered   = [];
    foreach ($preferred as $iso) {
        if (isset($codes[$iso])) {
            $ordered[$iso] = $codes[$iso];
        }
    }
    foreach ($codes as $iso => $dial) {
        if (!isset($ordered[$iso])) {
            $ordered[$iso] = $dial;
        }
    }
    ?>
    <div class="<?= h($wrapperClass) ?>" data-phone-input>
        <select
            class="<?= h($selectClass) ?> phone-input__country"
            id="phone_country"
            name="phone_country"
            aria-label="Country code"
            autocomplete="tel-country-code"
            data-phone-country
            <?= $roAttr ?>
        >
            <?php foreach ($ordered as $iso => $dial): ?>
                <?php
                $label = $labels[$iso] ?? $iso;
                $text  = $dial . ' ' . $label;
                ?>
                <option value="<?= h($iso) ?>"<?= $parts['iso'] === $iso ? ' selected' : '' ?>><?= h($text) ?></option>
            <?php endforeach; ?>
        </select>
        <input
            class="<?= h($inputClass) ?> phone-input__number"
            type="tel"
            id="<?= h($nationalId) ?>"
            name="<?= h($isSecure ? 'phone_national' : 'mobile_national') ?>"
            value="<?= h($parts['national']) ?>"
            placeholder="87 123 4567"
            autocomplete="tel-national"
            inputmode="tel"
            data-phone-national
            <?= $reqAttr ?>
            <?= $roAttr ?>
        >
        <input type="hidden" name="<?= h($name) ?>" id="<?= h($fieldId) ?>" value="<?= h($parts['e164'] !== '' ? $parts['e164'] : $value) ?>" data-phone-e164>
    </div>
    <?php if ($hint !== ''): ?>
        <p class="<?= $isSecure ? 'secure-hint' : 'form-hint' ?>"><?= h($hint) ?></p>
    <?php endif; ?>
    <?php if (!$isSecure): ?>
        <span class="form-error" id="<?= h($errorId) ?>"></span>
    <?php endif; ?>
    <?php
}

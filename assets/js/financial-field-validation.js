/**
 * PSA licence (EM000000/00) and IBAN validation — mirrors includes/financial-field-validation.php
 */
(function (global) {
    'use strict';

    var IBAN_LENGTHS = {
        AD: 24, AE: 23, AL: 28, AT: 20, AZ: 28, BA: 20, BE: 16, BG: 22, BH: 22, BR: 29,
        BY: 28, CH: 21, CR: 22, CY: 28, CZ: 24, DE: 22, DK: 18, DO: 28, EE: 20, EG: 29,
        ES: 24, FI: 18, FO: 18, FR: 27, GB: 22, GE: 22, GI: 23, GL: 18, GR: 27, GT: 28,
        HR: 21, HU: 28, IE: 22, IL: 23, IS: 26, IT: 27, JO: 30, KW: 30, KZ: 20, LB: 28,
        LC: 32, LI: 21, LT: 20, LU: 20, LV: 21, MC: 27, MD: 24, ME: 22, MK: 19, MR: 27,
        MT: 31, MU: 30, NL: 18, NO: 15, PK: 24, PL: 28, PS: 29, PT: 25, QA: 29, RO: 24,
        RS: 22, SA: 24, SE: 24, SI: 19, SK: 24, SM: 27, TN: 24, TR: 26, UA: 29, VG: 24,
        XK: 20
    };

    function normalizePsaLicence(value) {
        return String(value || '').trim().toUpperCase().replace(/\s+/g, '');
    }

    function normalizeBankIban(value) {
        return String(value || '').trim().toUpperCase().replace(/\s+/g, '');
    }

    function isValidPsaLicence(value) {
        return /^EM\d{6}\/\d{2}$/.test(normalizePsaLicence(value));
    }

    function ibanMod97(iban) {
        var rearranged = iban.slice(4) + iban.slice(0, 4);
        var numeric = '';
        var i;
        for (i = 0; i < rearranged.length; i++) {
            var ch = rearranged.charAt(i);
            numeric += /[A-Z]/.test(ch) ? String(ch.charCodeAt(0) - 55) : ch;
        }
        var remainder = 0;
        for (i = 0; i < numeric.length; i += 7) {
            remainder = parseInt(String(remainder) + numeric.slice(i, i + 7), 10) % 97;
        }
        return remainder;
    }

    function isValidBankIban(value) {
        var iban = normalizeBankIban(value);
        if (!iban) return false;
        if (!/^[A-Z]{2}[0-9]{2}[A-Z0-9]+$/.test(iban)) return false;
        if (iban.length < 15 || iban.length > 34) return false;
        var country = iban.slice(0, 2);
        if (IBAN_LENGTHS[country] && iban.length !== IBAN_LENGTHS[country]) return false;
        if (/[A-Z]{5,}/.test(iban.slice(4))) return false;
        return ibanMod97(iban) === 1;
    }

    function psaLicenceError(value, required) {
        if (!String(value || '').trim()) {
            return required ? 'PSA licence number is required.' : null;
        }
        if (!isValidPsaLicence(value)) {
            return 'PSA licence must be format EM123456/00 (EM, 6 digits, /, 2 digits).';
        }
        return null;
    }

    function bankIbanError(value, required) {
        if (!String(value || '').trim()) {
            return required ? 'Bank IBAN is required.' : null;
        }
        if (!isValidBankIban(value)) {
            return 'Enter a valid IBAN with country code (e.g. IE29AIBK93115212345678). Do not enter a bank name.';
        }
        return null;
    }

    function formatIbanInput(el) {
        if (!el) return;
        el.value = normalizeBankIban(el.value);
    }

    function formatPsaInput(el) {
        if (!el) return;
        el.value = normalizePsaLicence(el.value);
    }

    function showInputError(el, message) {
        if (!el) return;
        el.setCustomValidity(message || '');
        el.reportValidity();
    }

    function clearInputError(el) {
        if (!el) return;
        el.setCustomValidity('');
    }

    function validateFinancialInputs(form) {
        var valid = true;

        form.querySelectorAll('input[name="bank_iban"], #bank_iban').forEach(function (el) {
            if (el.offsetParent === null && !el.required) return;
            var err = bankIbanError(el.value, el.required);
            if (err) {
                showInputError(el, err);
                valid = false;
            } else {
                clearInputError(el);
                formatIbanInput(el);
            }
        });

        form.querySelectorAll('input[name="invoice_bank_iban"], #invoice_bank_iban').forEach(function (el) {
            if (!String(el.value || '').trim()) {
                clearInputError(el);
                return;
            }
            var err = bankIbanError(el.value, false);
            if (err) {
                showInputError(el, err);
                valid = false;
            } else {
                clearInputError(el);
                formatIbanInput(el);
            }
        });

        form.querySelectorAll('input[name="psa_licence"], #psa_licence, #status_psa_licence').forEach(function (el) {
            if (el.offsetParent === null && !el.required) return;
            var err = psaLicenceError(el.value, el.required);
            if (err) {
                showInputError(el, err);
                valid = false;
            } else {
                clearInputError(el);
                formatPsaInput(el);
            }
        });

        return valid;
    }

    function bindFinancialFields(root) {
        root = root || document;
        root.querySelectorAll('input[name="bank_iban"], #bank_iban, input[name="invoice_bank_iban"], #invoice_bank_iban').forEach(function (el) {
            el.setAttribute('autocapitalize', 'characters');
            el.setAttribute('spellcheck', 'false');
            el.addEventListener('blur', function () { formatIbanInput(el); });
        });
        root.querySelectorAll('input[name="psa_licence"], #psa_licence, #status_psa_licence').forEach(function (el) {
            el.setAttribute('autocapitalize', 'characters');
            el.setAttribute('spellcheck', 'false');
            el.addEventListener('blur', function () { formatPsaInput(el); });
        });

        root.querySelectorAll('form').forEach(function (form) {
            if (form.dataset.financialValidationBound === '1') return;
            if (!form.querySelector('input[name="bank_iban"], #bank_iban, input[name="invoice_bank_iban"], #invoice_bank_iban, input[name="psa_licence"], #psa_licence, #status_psa_licence')) {
                return;
            }
            form.dataset.financialValidationBound = '1';
            form.addEventListener('submit', function (e) {
                if (!validateFinancialInputs(form)) {
                    e.preventDefault();
                }
            });
        });
    }

    global.normalizePsaLicence = normalizePsaLicence;
    global.normalizeBankIban = normalizeBankIban;
    global.isValidPsaLicence = isValidPsaLicence;
    global.isValidBankIban = isValidBankIban;
    global.psaLicenceError = psaLicenceError;
    global.bankIbanError = bankIbanError;
    global.bindFinancialFields = bindFinancialFields;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { bindFinancialFields(document); });
    } else {
        bindFinancialFields(document);
    }
})(typeof window !== 'undefined' ? window : globalThis);

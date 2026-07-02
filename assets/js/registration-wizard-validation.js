/**
 * Registration wizard — per-step client-side validation (feature_registration_wizard_v2)
 */
(function () {
    'use strict';

    if (document.body.dataset.wizardMode !== '1') {
        return;
    }

    var lastValidationErrors = {};

    function showError(fieldId, message) {
        lastValidationErrors[fieldId] = message;
        var field = document.getElementById(fieldId);
        var errorEl = document.getElementById(fieldId + '-error');

        if (field) {
            if (field.tagName === 'SELECT') {
                field.classList.add('form-select--error');
            } else {
                field.classList.add('form-input--error');
            }
        }

        if (fieldId === 'event_ids') {
            var list = document.getElementById('shift-picker-list');
            if (list) {
                list.classList.add('shift-picker-list--error');
            }
        }

        if (fieldId === 'gender' && !errorEl) {
            errorEl = document.getElementById('gender-error');
        }

        if (errorEl) {
            errorEl.textContent = message;
            errorEl.classList.add('form-error--visible');
        }
    }

    function clearStepErrors(step) {
        lastValidationErrors = {};
        var panel = document.querySelector('.reg-wizard__step[data-step="' + step + '"]');
        if (!panel) {
            return;
        }

        panel.querySelectorAll('.form-input--error, .form-select--error').forEach(function (el) {
            el.classList.remove('form-input--error', 'form-select--error');
        });
        panel.querySelectorAll('.form-error--visible').forEach(function (el) {
            el.classList.remove('form-error--visible');
            el.textContent = '';
        });

        if (step === 2) {
            var list = document.getElementById('shift-picker-list');
            if (list) {
                list.classList.remove('shift-picker-list--error');
            }
        }
    }

    function fieldVal(id) {
        var el = document.getElementById(id);
        return el ? String(el.value || '').trim() : '';
    }

    function getEffectiveRegistrationEmail() {
        var email = fieldVal('email');
        if (email) {
            return email;
        }
        var hidden = document.getElementById('registration_verified_email')
            || document.getElementById('registration_verified_google_email');
        if (hidden && String(hidden.value || '').trim() !== '') {
            return String(hidden.value || '').trim();
        }
        return String(document.body.dataset.registrationGoogleEmail || '').trim();
    }

    function getShiftPickerList() {
        return document.getElementById('shift-picker-list');
    }

    function countValidSelectedEvents() {
        var list = getShiftPickerList();
        if (!list) {
            return 0;
        }
        return list.querySelectorAll('input[name="event_ids[]"]:checked:not(:disabled)').length;
    }

    function countPickableShifts() {
        var list = getShiftPickerList();
        if (!list) {
            return 0;
        }
        return list.querySelectorAll('input[name="event_ids[]"]:not(:disabled)').length;
    }

    function hasPickableShifts() {
        return countPickableShifts() > 0;
    }

    function hasValidEventSelection() {
        return countValidSelectedEvents() > 0;
    }

    function requireValidEventSelection(message) {
        if (!window.SHIFT_PICKER_READY || !hasPickableShifts()) {
            return true;
        }
        if (hasValidEventSelection()) {
            return true;
        }
        showError('event_ids', message || 'Please select at least one event opportunity.');
        return false;
    }

    function validateConsent() {
        var consent = document.querySelector('input[name="privacy_consent"]');
        if (consent && !consent.checked) {
            showError('privacy_consent', 'You must agree to the privacy notice before registering.');
            return false;
        }
        return true;
    }

    function validateFastTrackSubmit() {
        clearStepErrors(2);
        clearStepErrors(3);
        var ok = true;
        if (window.SHIFT_PICKER_READY && hasPickableShifts() && !requireValidEventSelection('Please select at least one open event opportunity before submitting.')) {
            ok = false;
        }
        var email = fieldVal('email');
        if (!email) {
            showError('email', 'Email address is required.');
            ok = false;
        } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            showError('email', 'Please enter a valid email address.');
            ok = false;
        }
        if (!validateConsent()) {
            ok = false;
        }
        return ok;
    }

    function validateStep(step, opts) {
        opts = opts || {};
        var fastTrack = !!opts.fastTrack;
        clearStepErrors(step);
        var ok = true;

        if (fastTrack && step >= 4 && step <= 7) {
            return true;
        }

        if (step === 1) {
            var select = document.getElementById('form_slug');
            var hidden = document.querySelector('input[type="hidden"][name="form_slug"]');
            if (select && select.tagName === 'SELECT' && !select.value) {
                showError('form_slug', 'Please select your role.');
                ok = false;
            } else if (!select && !hidden) {
                showError('form_slug', 'Please select your role.');
                ok = false;
            }
        }

        if (step === 2) {
            if (window.SHIFT_PICKER_READY && hasPickableShifts() && !requireValidEventSelection('Please select at least one event opportunity.')) {
                ok = false;
            }
            if (fastTrack && !validateConsent()) {
                ok = false;
            }
        }

        if (step === 3) {
            if (document.body.dataset.shiftFirstFlow === '1' && getEffectiveRegistrationEmail()) {
                return true;
            }
            var email = getEffectiveRegistrationEmail();
            if (!email) {
                showError('email', 'Email address is required.');
                ok = false;
            } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                showError('email', 'Please enter a valid email address.');
                ok = false;
            }
        }

        if (step === 4) {
            var required = [
                ['surname', 'Surname'],
                ['first_name', 'First name'],
                ['full_address', 'Full address'],
                ['eircode', 'Eircode'],
                ['date_of_birth', 'Date of birth'],
            ];
            required.forEach(function (pair) {
                if (!fieldVal(pair[0])) {
                    showError(pair[0], pair[1] + ' is required.');
                    ok = false;
                }
            });
            if (fieldVal('eircode') && typeof isValidEircode === 'function' && !isValidEircode(fieldVal('eircode'))) {
                showError('eircode', 'Please enter a valid Eircode (e.g. D02 X285).');
                ok = false;
            }
            if (!document.querySelector('input[name="gender"]:checked')) {
                showError('gender', 'Please select a gender.');
                ok = false;
            }
        }

        if (step === 5) {
            if (typeof syncPhoneInputMobile === 'function') {
                syncPhoneInputMobile(document.getElementById('registration-form'));
            }
            var mobileNational = document.getElementById('mobile_national');
            var mobile = fieldVal('mobile');
            if (mobileNational && !mobileNational.value.trim()) {
                showError('mobile', 'Mobile number is required.');
                ok = false;
            } else if (mobile && typeof isValidE164Mobile === 'function' && !isValidE164Mobile(mobile)) {
                showError('mobile', 'Please enter a valid mobile number with country code.');
                ok = false;
            } else if (!mobile && !mobileNational) {
                showError('mobile', 'Mobile number is required.');
                ok = false;
            }
        }

        if (step === 6) {
            if (!fieldVal('pps_number')) {
                showError('pps_number', 'NI / PPS number is required.');
                ok = false;
            }
            var iban = fieldVal('bank_iban');
            if (!iban) {
                showError('bank_iban', 'Bank IBAN is required.');
                ok = false;
            } else if (typeof bankIbanError === 'function') {
                var ibanErr = bankIbanError(iban, true);
                if (ibanErr) {
                    showError('bank_iban', ibanErr);
                    ok = false;
                }
            }
        }

        if (step === 7) {
            if (document.body.dataset.psaRequired === '0') {
                return ok;
            }
            if (!fieldVal('psa_licence')) {
                showError('psa_licence', 'PSA licence number is required.');
                ok = false;
            } else if (typeof psaLicenceError === 'function') {
                var psaErr = psaLicenceError(fieldVal('psa_licence'), true);
                if (psaErr) {
                    showError('psa_licence', psaErr);
                    ok = false;
                }
            }
            if (!fieldVal('psa_expiry_date')) {
                showError('psa_expiry_date', 'PSA expiry date is required.');
                ok = false;
            }
            var front = document.getElementById('psa_front_image');
            var back = document.getElementById('psa_back_image');
            if (front && front.required && (!front.files || !front.files.length)) {
                showError('psa_front_image', 'PSA front photo is required.');
                ok = false;
            }
            if (back && back.required && (!back.files || !back.files.length)) {
                showError('psa_back_image', 'PSA back photo is required.');
                ok = false;
            }
        }

        if (step === 8) {
            var regEmail = getEffectiveRegistrationEmail();
            if (!regEmail) {
                if (document.body.dataset.googleRegistrationRequired === '1') {
                    showError('email', 'Sign in with Google first — your Gmail is required to register.');
                } else {
                    showError('email', 'Email address is required.');
                }
                ok = false;
            } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(regEmail)) {
                showError('email', 'Please enter a valid email address.');
                ok = false;
            } else {
                var emailInput = document.getElementById('email');
                if (emailInput && String(emailInput.value || '').trim() === '') {
                    emailInput.value = regEmail;
                }
            }
            if (!requireValidEventSelection('Please select at least one open event opportunity before submitting.')) {
                ok = false;
            }
            var consent = document.querySelector('input[name="privacy_consent"]');
            if (consent && !consent.checked) {
                showError('privacy_consent', 'You must agree to the privacy notice before registering.');
                ok = false;
            }
        }

        return ok;
    }

    window.RegistrationWizardValidation = {
        validateStep: validateStep,
        validateFastTrackSubmit: validateFastTrackSubmit,
        clearStepErrors: clearStepErrors,
        showError: showError,
        getLastValidationErrors: function () {
            return Object.assign({}, lastValidationErrors);
        },
        getEffectiveRegistrationEmail: getEffectiveRegistrationEmail,
        hasValidEventSelection: hasValidEventSelection,
        hasPickableShifts: hasPickableShifts,
        countPickableShifts: countPickableShifts,
        countValidSelectedEvents: countValidSelectedEvents,
    };
})();

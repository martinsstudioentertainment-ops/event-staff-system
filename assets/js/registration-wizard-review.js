/**
 * Registration wizard - Step 8 review summary (feature_registration_wizard_v2)
 */
(function () {
    'use strict';

    if (document.body.dataset.wizardMode !== '1') {
        return;
    }

    var container = document.getElementById('reg-wizard-review-summary');
    if (!container) {
        return;
    }

    var SECTION_META = {
        role: { title: 'Role', step: 1, fields: ['form_slug', 'staff_role'] },
        events: { title: 'Selected opportunities', step: 2, fields: ['event_ids', 'venue_id'] },
        contact: { title: 'Contact', step: 3, fields: ['email'] },
        personal: { title: 'Personal details', step: 4, fields: ['surname', 'first_name', 'date_of_birth', 'gender'] },
        address: { title: 'Contact details', step: 5, fields: ['full_address', 'eircode', 'mobile'] },
        payroll: { title: 'Payroll (for contractor / organiser)', step: 6, fields: ['pps_number', 'bank_iban'] },
        psa: { title: 'PSA compliance', step: 7, fields: ['psa_licence', 'psa_expiry_date', 'psa_front_image', 'psa_back_image'] },
        consent: { title: 'Consent', step: 8, fields: ['privacy_consent', 'form'] },
    };

    function val(id) {
        var el = document.getElementById(id);
        if (!el) {
            return '';
        }
        if (el.type === 'file') {
            return el.files && el.files.length > 0 ? el.files[0].name : '';
        }
        return String(el.value || '').trim();
    }

    function radioVal(name) {
        var checked = document.querySelector('input[name="' + name + '"]:checked');
        if (!checked) {
            return '';
        }
        var label = checked.closest('label');
        return label ? String(label.textContent || '').trim() : checked.value;
    }

    function selectLabel(id) {
        var el = document.getElementById(id);
        if (!el || el.tagName !== 'SELECT') {
            return val(id);
        }
        var opt = el.options[el.selectedIndex];
        return opt ? String(opt.textContent || '').trim() : '';
    }

    function maskIban(iban) {
        iban = String(iban || '').replace(/\s+/g, '');
        if (iban.length < 8) {
            return iban || 'Not provided';
        }
        return '****' + iban.slice(-4);
    }

    function maskPps(pps) {
        pps = String(pps || '').trim();
        if (pps.length < 4) {
            return pps || 'Not provided';
        }
        return '***' + pps.slice(-2);
    }

    function escapeHtml(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function getServerErrors() {
        if (typeof window.SERVER_FORM_ERRORS !== 'object' || !window.SERVER_FORM_ERRORS) {
            return null;
        }
        var keys = Object.keys(window.SERVER_FORM_ERRORS);
        return keys.length ? window.SERVER_FORM_ERRORS : null;
    }

    function sectionHasError(sectionKey, errors) {
        if (!errors) {
            return false;
        }
        var meta = SECTION_META[sectionKey];
        if (!meta) {
            return false;
        }
        return meta.fields.some(function (field) {
            return errors[field];
        });
    }

    function getSelectedEventIds() {
        var ids = [];
        document.querySelectorAll('#shift-picker-list input[name="event_ids[]"]:checked').forEach(function (input) {
            ids.push(String(input.value));
        });
        if (ids.length) {
            return ids;
        }
        var list = document.getElementById('shift-picker-list');
        if (!list) {
            return [];
        }
        try {
            return JSON.parse(list.dataset.selected || '[]').map(String);
        } catch (e) {
            return [];
        }
    }

    function getSelectedEvents() {
        var names = [];
        var idToName = {};

        document.querySelectorAll('#shift-picker-list input[name="event_ids[]"]:checked').forEach(function (input) {
            var card = input.closest('.reg-event-card, .event-checkbox');
            var name = '';
            if (card) {
                var title = card.querySelector('.reg-event-card__title, .event-checkbox__title');
                name = title ? String(title.textContent || '').trim() : '';
                if (!name) {
                    name = card.getAttribute('data-event-name') || '';
                }
            }
            if (!name) {
                name = 'Event #' + input.value;
            }
            idToName[String(input.value)] = name;
            names.push(name);
        });

        if (names.length) {
            return names;
        }

        getSelectedEventIds().forEach(function (id) {
            var card = document.querySelector('#shift-picker-list [data-event-id="' + id + '"]');
            var name = card ? (card.getAttribute('data-event-name') || '') : '';
            if (!name && card) {
                var title = card.querySelector('.reg-event-card__title, .event-checkbox__title');
                name = title ? String(title.textContent || '').trim() : '';
            }
            names.push(name || ('Event #' + id));
        });

        return names;
    }

    function consentStatus() {
        var consent = document.querySelector('input[name="privacy_consent"]');
        if (!consent) {
            return 'Not confirmed';
        }
        return consent.checked ? 'Agreed' : 'Not agreed';
    }

    function psaPhotoOnFile(fieldId) {
        var el = document.getElementById(fieldId);
        return !!(el && el.dataset.psaOnFile === '1' && !el.required);
    }

    function psaPhotoStatus(fieldId, errors) {
        var fileName = val(fieldId);
        if (fileName) {
            return 'Attached (' + fileName + ')';
        }
        if (psaPhotoOnFile(fieldId)) {
            return 'On file';
        }
        if (errors && errors[fieldId]) {
            return 'Re-attach required';
        }
        return 'Not attached';
    }

    function syncVerifiedGoogleEmail() {
        var googleEmail = String(document.body.dataset.registrationGoogleEmail || '').trim();
        if (!googleEmail) {
            return;
        }
        var emailEl = document.getElementById('email');
        var hiddenEl = document.getElementById('registration_verified_google_email');
        if (emailEl && String(emailEl.value || '').trim() === '') {
            emailEl.value = googleEmail;
        }
        if (hiddenEl && String(hiddenEl.value || '').trim() === '') {
            hiddenEl.value = googleEmail;
        }
    }

    function collectMissingFieldErrors() {
        var errors = {};
        var email = getEffectiveRegistrationEmail();
        if (!email) {
            if (document.body.dataset.googleRegistrationRequired === '1') {
                errors.email = 'Verify your email first (Google or email verification code).';
            } else {
                errors.email = 'Email address is required.';
            }
        } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            errors.email = 'Please enter a valid email address.';
        }

        [
            ['surname', 'Surname is required.'],
            ['first_name', 'First name is required.'],
            ['full_address', 'Full address is required.'],
            ['eircode', 'Eircode is required.'],
            ['date_of_birth', 'Date of birth is required.'],
        ].forEach(function (pair) {
            if (!val(pair[0])) {
                errors[pair[0]] = pair[1];
            }
        });

        if (val('eircode') && typeof isValidEircode === 'function' && !isValidEircode(val('eircode'))) {
            errors.eircode = 'Please enter a valid Eircode (e.g. D02 X285).';
        }
        if (!document.querySelector('input[name="gender"]:checked')) {
            errors.gender = 'Please select a gender.';
        }

        var mobile = val('mobile') || val('mobile_national');
        if (!mobile) {
            errors.mobile = 'Mobile number is required.';
        } else if (typeof isValidE164Mobile === 'function' && val('mobile') && !isValidE164Mobile(val('mobile'))) {
            errors.mobile = 'Please enter a valid mobile number with country code.';
        }

        if (!val('pps_number')) {
            errors.pps_number = 'NI / PPS number is required.';
        }
        var iban = val('bank_iban');
        if (!iban) {
            errors.bank_iban = 'Bank IBAN is required.';
        } else if (typeof bankIbanError === 'function') {
            var ibanErr = bankIbanError(iban, true);
            if (ibanErr) {
                errors.bank_iban = ibanErr;
            }
        }

        var requiresPsa = typeof registrationRoleRequiresPsa === 'function'
            ? registrationRoleRequiresPsa()
            : true;
        if (requiresPsa) {
            if (!val('psa_licence')) {
                errors.psa_licence = 'PSA licence number is required.';
            }
            if (!val('psa_expiry_date')) {
                errors.psa_expiry_date = 'PSA expiry date is required.';
            }
            var front = document.getElementById('psa_front_image');
            var back = document.getElementById('psa_back_image');
            if (front && front.required && (!front.files || !front.files.length) && !psaPhotoOnFile('psa_front_image')) {
                errors.psa_front_image = 'PSA front photo is required.';
            }
            if (back && back.required && (!back.files || !back.files.length) && !psaPhotoOnFile('psa_back_image')) {
                errors.psa_back_image = 'PSA back photo is required.';
            }
        }

        var consent = document.querySelector('input[name="privacy_consent"]');
        if (consent && !consent.checked) {
            errors.privacy_consent = 'You must agree to the privacy notice before registering.';
        }

        return errors;
    }

    function mergeReviewErrors() {
        var errors = Object.assign({}, getServerErrors() || {}, collectMissingFieldErrors());
        var validation = window.RegistrationWizardValidation;
        if (validation && typeof validation.getLastValidationErrors === 'function') {
            errors = Object.assign({}, errors, validation.getLastValidationErrors());
        }
        if (isRegisterWithoutShiftFlow() && errors.event_ids) {
            delete errors.event_ids;
        }
        if (isWaitlistRegistrationFlow() && errors.event_ids) {
            delete errors.event_ids;
        }
        return errors;
    }

    function fixStepForSection(sectionKey, meta) {
        if (!meta) {
            return 1;
        }
        if (sectionKey === 'contact'
            && document.body.dataset.shiftFirstFlow === '1'
            && document.body.dataset.googleRegistrationRequired === '1') {
            return 1;
        }
        return meta.step;
    }

    function addSection(sectionKey, title, rows, errors) {
        var hasError = sectionHasError(sectionKey, errors);
        var meta = SECTION_META[sectionKey];
        var html = '<section class="reg-review-summary__section' + (hasError ? ' reg-review-summary__section--error' : '') + '">';
        html += '<div class="reg-review-summary__section-head">';
        html += '<h4 class="reg-review-summary__heading">' + escapeHtml(title) + '</h4>';
        if (hasError && meta && window.RegistrationWizard) {
            html += '<button type="button" class="reg-review-summary__fix" data-goto-step="' + fixStepForSection(sectionKey, meta) + '">Fix</button>';
        }
        html += '</div>';
        if (hasError && errors && meta) {
            html += '<ul class="reg-review-summary__errors">';
            meta.fields.forEach(function (field) {
                if (errors[field]) {
                    html += '<li>' + escapeHtml(errors[field]) + '</li>';
                }
            });
            html += '</ul>';
        }
        html += '<dl class="reg-review-summary__list">';
        rows.forEach(function (row) {
            if (!row) {
                return;
            }
            var label = row[0] || '';
            var value = row[1];
            if (value === undefined || value === null || value === '') {
                value = 'Not provided';
            }
            html += '<dt>' + escapeHtml(label) + '</dt><dd>' + escapeHtml(String(value)) + '</dd>';
        });
        html += '</dl></section>';
        return html;
    }

    function hasValidEventSelection() {
        var validation = window.RegistrationWizardValidation;
        if (validation && typeof validation.hasValidEventSelection === 'function') {
            return validation.hasValidEventSelection();
        }
        return getSelectedEvents().length > 0;
    }

    function isWaitlistRegistrationFlow() {
        var validation = window.RegistrationWizardValidation;
        if (validation && typeof validation.isWaitlistRegistrationFlow === 'function') {
            return validation.isWaitlistRegistrationFlow();
        }
        var joinCb = document.getElementById('join_waiting_list');
        return !!(joinCb && joinCb.checked);
    }

    function isRegisterWithoutShiftFlow() {
        var validation = window.RegistrationWizardValidation;
        if (validation && typeof validation.isRegisterWithoutShiftFlow === 'function') {
            return validation.isRegisterWithoutShiftFlow();
        }
        return !hasPickableShifts();
    }

    function hasPickableShifts() {
        var validation = window.RegistrationWizardValidation;
        if (validation && typeof validation.countPickableShifts === 'function') {
            return validation.countPickableShifts() > 0;
        }
        return !!window.SHIFT_PICKER_READY
            && validation
            && typeof validation.hasPickableShifts === 'function'
            && validation.hasPickableShifts();
    }

    function renderNoShiftBanner() {
        if (document.body.dataset.registrationAccountOnly === '1') {
            return '<div class="reg-review-summary__shift-banner reg-review-summary__shift-banner--none" role="status">' +
                '<p class="reg-review-summary__shift-banner-title">Account registration only</p>' +
                '<p class="reg-review-summary__shift-banner-text">Your staff account will be created now. ' +
                'After you sign in to the staff app, you can view and apply for available shifts.</p>' +
                '</div>';
        }
        return '<div class="reg-review-summary__shift-banner reg-review-summary__shift-banner--none" role="status">' +
            '<p class="reg-review-summary__shift-banner-title">No shift selected</p>' +
            '<p class="reg-review-summary__shift-banner-text">There are currently no shifts available. ' +
            'Your account will be created without a shift assignment. When new shifts open, sign in and apply.</p>' +
            '</div>';
    }

    function renderShiftRequiredBanner() {
        return '<div class="reg-review-summary__shift-banner" role="alert">' +
            '<p class="reg-review-summary__shift-banner-title">Event selection required</p>' +
            '<p class="reg-review-summary__shift-banner-text">You must pick at least one open event opportunity before you can submit. ' +
            'Use <strong>Fix</strong> below or go back to Step 2.</p>' +
            '</div>';
    }

    function renderErrorBanner(errors) {
        if (!errors) {
            return '';
        }
        var keys = Object.keys(errors);
        if (!keys.length) {
            return '';
        }
        var html = '<div class="reg-review-summary__error-banner" role="alert">';
        html += '<p class="reg-review-summary__error-title">Please fix the following before you submit:</p>';
        html += '<ul class="reg-review-summary__error-list">';
        keys.forEach(function (field) {
            html += '<li><strong>' + escapeHtml(field.replace(/_/g, ' ')) + ':</strong> ' + escapeHtml(errors[field]) + '</li>';
        });
        html += '</ul>';
        if (errors.psa_front_image || errors.psa_back_image) {
            html += '<p class="reg-review-summary__error-note">PSA photos cannot be kept after a failed submit. Re-attach your card photos on the PSA step.</p>';
        }
        html += '</div>';
        return html;
    }

    function getEffectiveRegistrationEmail() {
        var email = val('email');
        if (email) {
            return email;
        }
        var hidden = document.getElementById('registration_verified_google_email');
        if (hidden && String(hidden.value || '').trim() !== '') {
            return String(hidden.value || '').trim();
        }
        return String(document.body.dataset.registrationGoogleEmail || '').trim();
    }

    function getValidSelectedEventNames() {
        var names = [];
        var seen = {};
        document.querySelectorAll('#shift-picker-list input[name="event_ids[]"]:checked:not(:disabled)').forEach(function (input) {
            var id = String(input.value);
            if (seen[id]) {
                return;
            }
            seen[id] = true;
            var card = input.closest('.reg-event-card, .event-checkbox');
            var name = '';
            if (card) {
                var title = card.querySelector('.reg-event-card__title, .event-checkbox__title');
                name = title ? String(title.textContent || '').trim() : '';
                if (!name) {
                    name = card.getAttribute('data-event-name') || '';
                }
            }
            names.push(name || ('Event #' + input.value));
        });
        return names;
    }

    function renderFastTrackEvents() {
        var mount = document.getElementById('reg-fast-track-events');
        if (!mount) {
            return;
        }
        if (document.body.dataset.registrationAccountOnly === '1') {
            mount.innerHTML = '<p class="reg-fast-track-events__empty reg-fast-track-events__empty--none">Your saved profile will be used. Complete registration below, then sign in to apply for shifts.</p>';
            return;
        }
        if (window.RegistrationWizard && typeof window.RegistrationWizard.isFastTrack === 'function' && !window.RegistrationWizard.isFastTrack()) {
            mount.innerHTML = '';
            return;
        }
        var validation = window.RegistrationWizardValidation;
        var noPickable = !!window.SHIFT_PICKER_READY
            && validation
            && typeof validation.isRegisterWithoutShiftFlow === 'function'
            && validation.isRegisterWithoutShiftFlow();
        if (noPickable) {
            mount.innerHTML = '<p class="reg-fast-track-events__empty reg-fast-track-events__empty--none">No open shifts right now — you can register your account and apply when new shifts open.</p>';
            return;
        }
        var events = hasValidEventSelection() ? getValidSelectedEventNames() : [];
        if (!events.length) {
            mount.innerHTML = '<p class="reg-fast-track-events__empty">No shifts selected — you can register now and apply when shifts open.</p>';
            return;
        }
        var html = '<p class="reg-fast-track-events__title">Registering for:</p><ul class="reg-fast-track-events__list">';
        events.forEach(function (name) {
            html += '<li>' + escapeHtml(name) + '</li>';
        });
        html += '</ul>';
        mount.innerHTML = html;
    }

    function render() {
        if (window.RegistrationWizard && typeof window.RegistrationWizard.isFastTrack === 'function' && window.RegistrationWizard.isFastTrack()) {
            renderFastTrackEvents();
            return;
        }
        syncVerifiedGoogleEmail();
        var errors = mergeReviewErrors();
        var noShiftSelected = !hasValidEventSelection();
        var events = hasValidEventSelection() ? getValidSelectedEventNames() : [];
        var html = renderErrorBanner(errors);

        if (noShiftSelected || document.body.dataset.registrationAccountOnly === '1') {
            html += renderNoShiftBanner();
            if (errors && errors.event_ids) {
                delete errors.event_ids;
            }
        }

        html += '<div class="reg-review-summary__intro">';
        html += '<p class="reg-review-summary__title">Review your application</p>';
        html += '<p class="reg-review-summary__lead">Check everything below before you submit. ';
        html += 'Olasentra is a registration and opportunity platform only - not your employer, payroll provider, or contracting party.</p>';
        html += '</div>';

        var roleLabel = '';
        var roleEl = document.getElementById('form_slug');
        if (roleEl && roleEl.tagName === 'SELECT') {
            roleLabel = selectLabel('form_slug');
        }
        if (!roleLabel) {
            roleLabel = val('staff_role') || 'Security staff';
        }

        html += addSection('role', 'Role', [
            ['Applying as', roleLabel],
        ], errors);

        var eventRows;
        if (document.body.dataset.registrationAccountOnly === '1') {
            eventRows = [['Shifts', 'Apply after sign-in to the staff app']];
        } else if (events.length > 0) {
            eventRows = events.map(function (name) { return ['Shift', name]; });
        } else {
            eventRows = [['Shifts', noShiftSelected && isWaitlistRegistrationFlow()
                ? 'Waiting list — no shift selected'
                : 'None selected — optional at registration']];
        }
        if (document.body.dataset.registrationAccountOnly !== '1') {
            html += addSection('events', 'Selected opportunities', eventRows, errors);
        }

        var reviewEmail = getEffectiveRegistrationEmail();
        html += addSection('contact', 'Contact', [
            ['Email', reviewEmail || (document.body.dataset.googleRegistrationRequired === '1'
                ? 'Not signed in — use Google at Step 1'
                : 'Not provided')],
        ], errors);

        html += addSection('personal', 'Personal details', [
            ['Name', (val('first_name') + ' ' + val('surname')).trim()],
            ['Date of birth', val('date_of_birth')],
            ['Gender', radioVal('gender')],
        ], errors);

        html += addSection('address', 'Contact details', [
            ['Address', val('full_address')],
            ['Eircode', val('eircode')],
            ['Mobile', val('mobile') || val('mobile_national')],
        ], errors);

        html += addSection('payroll', 'Payroll (for contractor / organiser)', [
            ['NI / PPS', maskPps(val('pps_number'))],
            ['Bank IBAN', maskIban(val('bank_iban'))],
        ], errors);

        html += addSection('psa', 'PSA compliance', [
            ['Licence number', val('psa_licence')],
            ['Expiry date', val('psa_expiry_date')],
            ['Front photo', psaPhotoStatus('psa_front_image', errors)],
            ['Back photo', psaPhotoStatus('psa_back_image', errors)],
        ], errors);

        html += addSection('consent', 'Consent', [
            ['Privacy notice', consentStatus()],
        ], errors);

        container.innerHTML = html;

        container.querySelectorAll('[data-goto-step]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var step = parseInt(btn.getAttribute('data-goto-step'), 10);
                if (window.RegistrationWizard && typeof window.RegistrationWizard.showStep === 'function' && step >= 1) {
                    window.RegistrationWizard.showStep(step);
                }
            });
        });
    }

    var form = document.getElementById('registration-form');
    if (form) {
        form.addEventListener('input', render);
        form.addEventListener('change', render);
    }

    document.addEventListener('shiftPickerReady', render);

    window.RegistrationWizardReview = {
        render: render,
        renderFastTrackEvents: renderFastTrackEvents,
    };

    render();
})();

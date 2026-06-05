/**
 * Returning registrant — prefill form when email already exists in the system.
 */
(function () {
    'use strict';

    if (document.body.dataset.registrationPage !== 'true') {
        return;
    }

    var lookupTimer = null;
    var lastLookupEmail = '';

    function isValidEmail(value) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(value || '').trim());
    }

    function setFieldValue(id, value) {
        var el = document.getElementById(id);
        if (!el || value === undefined || value === null) {
            return;
        }
        el.value = String(value);
        el.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function setGender(value) {
        if (!value) return;
        var input = document.querySelector('input[name="gender"][value="' + value + '"]');
        if (input) {
            input.checked = true;
        }
    }

    function setFormSlug(slug) {
        if (!slug) return;

        var radio = document.querySelector('input[name="form_slug"][value="' + slug + '"]');
        if (radio && !radio.disabled) {
            radio.checked = true;
            radio.dispatchEvent(new Event('change', { bubbles: true }));
            if (radio.dataset.role) {
                var roleInput = document.getElementById('staff_role');
                if (roleInput) {
                    roleInput.value = radio.dataset.role;
                }
            }
            return;
        }

        var select = document.getElementById('form_slug');
        if (select && select.tagName === 'SELECT' && !select.disabled) {
            select.value = slug;
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }

        var roleInput = document.getElementById('staff_role');
        if (roleInput && select && select.selectedOptions[0]) {
            roleInput.value = select.selectedOptions[0].dataset.role || roleInput.value;
        }
    }

    function showReturningNotice(message, registeredEvents) {
        var alertEl = document.getElementById('form-alert');
        if (!alertEl) return;

        var extra = '';
        if (Array.isArray(registeredEvents) && registeredEvents.length > 0) {
            extra = ' You are already registered for ' + registeredEvents.length + ' event(s) — those are greyed out below.';
        }

        alertEl.textContent = message + extra;
        alertEl.className = 'alert alert--success alert--visible';
    }

    function applyProfile(profile, payload) {
        setFieldValue('surname', profile.surname);
        setFieldValue('first_name', profile.first_name);
        setFieldValue('full_address', profile.full_address);
        setFieldValue('eircode', profile.eircode);
        if (typeof setPhoneInputValue === 'function') {
            setPhoneInputValue(profile.mobile);
        } else {
            setFieldValue('mobile', profile.mobile);
        }
        setFieldValue('date_of_birth', profile.date_of_birth);
        setFieldValue('pps_number', profile.pps_number);
        setFieldValue('bank_iban', profile.bank_iban);
        setFieldValue('psa_licence', profile.psa_licence);
        setFieldValue('psa_expiry_date', profile.psa_expiry_date);
        setFieldValue('staff_role', profile.staff_role);

        var psaFront = document.getElementById('psa_front_image');
        var psaBack = document.getElementById('psa_back_image');
        if (psaFront) {
            psaFront.required = !profile.has_psa_front;
        }
        if (psaBack) {
            psaBack.required = !profile.has_psa_back;
        }
        setFormSlug(profile.form_slug);
        setGender(profile.gender);

        if (typeof setRegisteredEventIds === 'function') {
            setRegisteredEventIds(payload.registered_event_ids || []);
        }

        showReturningNotice(payload.message || 'Welcome back! Your details are loaded.', payload.registered_events);
    }

    function lookupEmail(email) {
        email = String(email || '').trim().toLowerCase();
        if (!isValidEmail(email) || email === lastLookupEmail) {
            return;
        }

        lastLookupEmail = email;

        fetch('api/registrant-lookup.php?email=' + encodeURIComponent(email), {
            headers: { 'Accept': 'application/json' }
        })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data || !data.found || !data.profile) {
                    if (typeof setRegisteredEventIds === 'function') {
                        setRegisteredEventIds([]);
                    }
                    return;
                }
                applyProfile(data.profile, data);
            })
            .catch(function () {
                // Silent fail — user can still fill the form manually.
            });
    }

    function scheduleLookup(email) {
        clearTimeout(lookupTimer);
        lookupTimer = setTimeout(function () {
            lookupEmail(email);
        }, 450);
    }

    function initReturningRegistrant() {
        var emailInput = document.getElementById('email');
        if (!emailInput) return;

        emailInput.addEventListener('blur', function () {
            lookupEmail(emailInput.value);
        });

        emailInput.addEventListener('input', function () {
            if (emailInput.value.trim().toLowerCase() !== lastLookupEmail) {
                lastLookupEmail = '';
            }
            scheduleLookup(emailInput.value);
        });

        if (isValidEmail(emailInput.value)) {
            lookupEmail(emailInput.value);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initReturningRegistrant);
    } else {
        initReturningRegistrant();
    }
})();

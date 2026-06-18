/**
 * Lock shift picker until registration details above are complete.
 */
(function () {
    'use strict';

    if (document.body.dataset.registrationPage !== 'true') {
        return;
    }

    if (document.body.dataset.wizardMode === '1') {
        var wizardWrap = document.getElementById('event-selection-wrap');
        if (wizardWrap) {
            wizardWrap.classList.remove('shift-picker-locked');
            wizardWrap.setAttribute('aria-disabled', 'false');
        }
        return;
    }

    var wrap = document.getElementById('event-selection-wrap');
    var notice = document.getElementById('shift-gate-notice');
    if (!wrap) {
        return;
    }

    var textFields = [
        'email', 'surname', 'first_name', 'full_address', 'eircode',
        'date_of_birth', 'pps_number', 'bank_iban', 'psa_licence', 'psa_expiry_date'
    ];

    function fieldValue(id) {
        var el = document.getElementById(id);
        return el ? String(el.value || '').trim() : '';
    }

    function genderSelected() {
        return !!document.querySelector('input[name="gender"]:checked');
    }

    function mobileFilled() {
        if (typeof getPhoneInputValue === 'function') {
            return String(getPhoneInputValue() || '').trim() !== '';
        }
        return fieldValue('mobile') !== '';
    }

    function psaFilesReady() {
        var front = document.getElementById('psa_front_image');
        var back = document.getElementById('psa_back_image');
        var frontOk = front && (!front.required || (front.files && front.files.length > 0));
        var backOk = back && (!back.required || (back.files && back.files.length > 0));
        return frontOk && backOk;
    }

    function detailsComplete() {
        for (var i = 0; i < textFields.length; i++) {
            if (fieldValue(textFields[i]) === '') {
                return false;
            }
        }

        if (!genderSelected() || !mobileFilled()) {
            return false;
        }

        return psaFilesReady();
    }

    function setLocked(locked) {
        wrap.classList.toggle('shift-picker-locked', locked);
        wrap.setAttribute('aria-disabled', locked ? 'true' : 'false');

        var inputs = wrap.querySelectorAll('input, button, select, textarea');
        inputs.forEach(function (el) {
            if (locked) {
                el.setAttribute('data-shift-gate-disabled', '1');
                el.disabled = true;
            } else if (el.getAttribute('data-shift-gate-disabled') === '1') {
                el.disabled = false;
                el.removeAttribute('data-shift-gate-disabled');
            }
        });

        if (notice) {
            notice.hidden = !locked;
        }
    }

    function refresh() {
        setLocked(!detailsComplete());
    }

    function bind() {
        textFields.forEach(function (id) {
            var el = document.getElementById(id);
            if (el) {
                el.addEventListener('input', refresh);
                el.addEventListener('change', refresh);
            }
        });

        document.querySelectorAll('input[name="gender"]').forEach(function (el) {
            el.addEventListener('change', refresh);
        });

        ['psa_front_image', 'psa_back_image'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) {
                el.addEventListener('change', refresh);
            }
        });

        var mobileNational = document.getElementById('mobile_national');
        if (mobileNational) {
            mobileNational.addEventListener('input', refresh);
            mobileNational.addEventListener('change', refresh);
        }

        refresh();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bind);
    } else {
        bind();
    }
})();

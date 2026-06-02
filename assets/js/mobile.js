/**
 * Mobile UX — scroll validation errors into view on registration
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        if (document.body.dataset.registrationPage !== 'true') {
            return;
        }

        var errors = document.querySelectorAll('.form-error--visible');
        if (!errors.length && typeof window.SERVER_FORM_ERRORS === 'object') {
            return;
        }

        var firstError = document.querySelector('.form-error--visible');
        if (!firstError) {
            return;
        }

        var fieldId = (firstError.id || '').replace(/-error$/, '');
        var field = fieldId ? document.getElementById(fieldId) : null;
        if (field && typeof field.scrollIntoView === 'function') {
            setTimeout(function () {
                field.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }, 100);
        }
    });
})();

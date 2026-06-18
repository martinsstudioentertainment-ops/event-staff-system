/**
 * Registration wizard — server validation error restore (feature_registration_wizard_v2)
 * Always returns to Step 8; review summary highlights affected sections.
 */
(function () {
    'use strict';

    var body = document.body;
    if (!body || body.dataset.wizardMode !== '1') {
        return;
    }

    var RESTORE_STEP = 8;

    function showAlert(message, type) {
        var alertEl = document.getElementById('form-alert');
        if (!alertEl) {
            return;
        }
        alertEl.textContent = message;
        alertEl.className = 'alert alert--' + type + ' alert--visible';
    }

    function applyFieldErrors(errors) {
        var validation = window.RegistrationWizardValidation;
        if (!validation || typeof validation.showError !== 'function') {
            return;
        }
        Object.keys(errors).forEach(function (field) {
            validation.showError(field, errors[field]);
        });
    }

    function scrollToReviewErrors() {
        var banner = document.querySelector('.reg-review-summary__error-banner');
        if (banner) {
            banner.scrollIntoView({ behavior: 'smooth', block: 'start' });
            return;
        }
        var section = document.querySelector('.reg-review-summary__section--error');
        if (section) {
            section.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    function initFlash() {
        var flash = body.dataset.flash;
        if (!flash) {
            return;
        }

        if (flash === 'success') {
            var count = parseInt(body.dataset.registeredCount || '1', 10);
            var autoApproved = parseInt(body.dataset.autoApprovedCount || '0', 10) > 0;
            var message = autoApproved
                ? (count === 1
                    ? 'Registration submitted successfully for 1 event! Your application has been approved.'
                    : 'Registration submitted successfully for ' + count + ' events! Your applications have been approved.')
                : (count === 1
                    ? 'Registration submitted successfully for 1 event! Your application is pending approval.'
                    : 'Registration submitted successfully for ' + count + ' events! Your applications are pending approval.');
            showAlert(message, 'success');
        } else if (flash === 'db') {
            showAlert('We could not save your registration. Please try again in a few minutes.', 'error');
        } else if (flash === 'validation') {
            showAlert('Some details need correction. Review the highlighted sections below, then use Fix to update a section.', 'error');
        }
    }

    var serverErrors = (typeof window.SERVER_FORM_ERRORS === 'object' && window.SERVER_FORM_ERRORS)
        ? window.SERVER_FORM_ERRORS
        : null;

    function resetSubmitButton() {
        var form = document.getElementById('registration-form');
        if (!form) {
            return;
        }
        form.dataset.submitting = '';
        var btn = document.getElementById('reg-wizard-submit') || form.querySelector('[type="submit"]');
        if (btn) {
            btn.disabled = false;
            if (!btn.textContent || btn.textContent.indexOf('Submitting') !== -1) {
                btn.textContent = 'Submit registration';
            }
        }
    }

    function finishRestore() {
        resetSubmitButton();
        initFlash();

        if (!serverErrors || !Object.keys(serverErrors).length) {
            return;
        }

        applyFieldErrors(serverErrors);

        if (serverErrors.event_ids) {
            showAlert(String(serverErrors.event_ids), 'error');
        } else if (serverErrors.form) {
            showAlert(String(serverErrors.form), 'error');
        }

        if (window.RegistrationWizardReview && typeof window.RegistrationWizardReview.render === 'function') {
            window.RegistrationWizardReview.render();
        }

        if (window.RegistrationWizard && typeof window.RegistrationWizard.showStep === 'function') {
            window.RegistrationWizard.showStep(RESTORE_STEP);
        }

        if (window.RegistrationWizardAnalytics && typeof window.RegistrationWizardAnalytics.trackEvent === 'function') {
            window.RegistrationWizardAnalytics.trackEvent('server_error_restore', {
                step: RESTORE_STEP,
                fields: Object.keys(serverErrors).join(','),
            });
        }

        window.setTimeout(scrollToReviewErrors, 400);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', finishRestore);
    } else {
        finishRestore();
    }

    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            resetSubmitButton();
        }
    });
})();

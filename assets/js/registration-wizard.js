/**
 * Registration wizard v2 — step navigation + validation gate (feature_registration_wizard_v2)
 */
(function () {
    'use strict';

    var body = document.body;
    if (body.dataset.wizardMode !== '1') {
        return;
    }

    var TOTAL = 8;
    var FAST_TRACK_TOTAL = 3;
    var FAST_TRACK_LABELS = { 1: 'Welcome', 3: 'Email', 2: 'Pick shift' };
    var STEP_NAMES = {
        1: 'Welcome',
        2: 'Your gigs',
        3: 'Email',
        4: 'About you',
        5: 'Contact',
        6: 'Payroll',
        7: 'PSA',
        8: 'Review',
    };

    var current = 1;
    var steps = [];
    var form = document.getElementById('registration-form');
    var btnBack = document.getElementById('reg-wizard-back');
    var btnNext = document.getElementById('reg-wizard-next');
    var btnSubmit = document.getElementById('reg-wizard-submit');
    var btnHome = document.getElementById('reg-wizard-home');
    var labelEl = document.getElementById('reg-wizard-step-label');
    var nameEl = document.getElementById('reg-wizard-step-name');
    var barEl = document.getElementById('reg-wizard-progress-bar');
    var fillEl = document.getElementById('reg-wizard-progress-fill');
    var fastTrackFooter = document.getElementById('reg-fast-track-footer');
    var consentHome = document.getElementById('reg-consent-home');
    var consentMount = document.getElementById('reg-fast-track-consent-mount');

    var fastTrackActive = false;
    var shiftFirstFlow = body.dataset.shiftFirstFlow === '1';

    function collectSteps() {
        steps = Array.prototype.slice.call(document.querySelectorAll('.reg-wizard__step[data-step]'));
    }

    function trackStep(n) {
        if (window.RegistrationWizardAnalytics && typeof window.RegistrationWizardAnalytics.trackStep === 'function') {
            window.RegistrationWizardAnalytics.trackStep(n);
        }
    }

    function persistAutosave() {
        if (window.RegistrationWizardAutosave && typeof window.RegistrationWizardAutosave.save === 'function') {
            window.RegistrationWizardAutosave.save(current);
        }
    }

    function validateCurrentStep() {
        if (window.RegistrationWizardValidation && typeof window.RegistrationWizardValidation.validateStep === 'function') {
            return window.RegistrationWizardValidation.validateStep(current, {
                fastTrack: fastTrackActive,
                profileEdit: false,
            });
        }
        return true;
    }

    function fastTrackDisplayStep(n) {
        if (n === 1) return 1;
        if (n === 3) return 2;
        if (n === 2) return 3;
        return 3;
    }

    function mountFastTrackConsent() {
        if (!consentMount || !consentHome) return;
        var group = consentHome.querySelector('.form-group--full');
        if (group && group.parentNode !== consentMount) {
            consentMount.appendChild(group);
        }
    }

    function restoreConsentHome() {
        if (!consentMount || !consentHome) return;
        var group = consentMount.querySelector('.form-group--full');
        if (group && group.parentNode !== consentHome) {
            consentHome.appendChild(group);
        }
    }

    function syncFastTrackUi() {
        body.classList.toggle('registration-page--wizard-fast-track', fastTrackActive);
        if (fastTrackFooter) {
            fastTrackFooter.hidden = !(fastTrackActive && current === 2);
        }
        if (fastTrackActive) {
            mountFastTrackConsent();
        } else {
            restoreConsentHome();
        }
    }

    function shiftPickerHasNoPickableShifts() {
        var validation = window.RegistrationWizardValidation;
        return !!window.SHIFT_PICKER_READY
            && validation
            && typeof validation.hasPickableShifts === 'function'
            && !validation.hasPickableShifts();
    }

    function hasSelectedEventsForSubmit() {
        var validation = window.RegistrationWizardValidation;
        return !!(validation
            && typeof validation.hasValidEventSelection === 'function'
            && validation.hasValidEventSelection());
    }

    function updateChrome() {
        var onReview = current === TOTAL && !fastTrackActive;
        var onFastSubmit = fastTrackActive && current === 2;
        var showSubmit = onReview || onFastSubmit;
        var showHomeInstead = showSubmit && shiftPickerHasNoPickableShifts() && !hasSelectedEventsForSubmit();
        var displayTotal = fastTrackActive ? FAST_TRACK_TOTAL : TOTAL;
        var displayCurrent = fastTrackActive ? fastTrackDisplayStep(current) : current;
        var pct = Math.round((displayCurrent / displayTotal) * 100);

        if (labelEl) {
            labelEl.textContent = 'Step ' + displayCurrent + ' of ' + displayTotal;
        }
        if (nameEl) {
            nameEl.textContent = fastTrackActive
                ? (FAST_TRACK_LABELS[current] || '')
                : (STEP_NAMES[current] || '');
        }
        if (fillEl) {
            fillEl.style.width = pct + '%';
        }
        if (barEl) {
            barEl.setAttribute('aria-valuemax', String(displayTotal));
            barEl.setAttribute('aria-valuenow', String(displayCurrent));
        }

        document.querySelectorAll('[data-step-dot]').forEach(function (dot) {
            var n = parseInt(dot.getAttribute('data-step-dot'), 10);
            var active = fastTrackActive
                ? ((n === 1 && current === 1) || (n === 3 && current === 3) || (n === 2 && current === 2))
                : (n === current);
            var done = fastTrackActive
                ? ((n === 1 && (current === 3 || current === 2)) || (n === 3 && current === 2))
                : (n < current);
            dot.classList.toggle('reg-wizard__dot--active', active);
            dot.classList.toggle('reg-wizard__dot--done', done);
            dot.classList.toggle('reg-wizard__dot--fast-hidden', fastTrackActive && n >= 4);
        });

        body.classList.toggle('registration-page--wizard-review', onReview);
        body.classList.toggle('registration-page--wizard-fast-submit', onFastSubmit);
        body.classList.toggle('registration-page--wizard-submit-mode', showSubmit && !showHomeInstead);
        body.classList.toggle('registration-page--wizard-no-shifts', showHomeInstead);

        if (btnBack) {
            btnBack.hidden = current <= 1;
        }
        if (btnNext) {
            btnNext.hidden = showSubmit;
        }
        if (btnSubmit) {
            btnSubmit.hidden = !showSubmit || showHomeInstead;
        }
        if (btnHome) {
            btnHome.hidden = !showHomeInstead;
        }

        if (showHomeInstead && window.RegistrationWizardValidation && typeof window.RegistrationWizardValidation.clearStepErrors === 'function') {
            window.RegistrationWizardValidation.clearStepErrors(2);
        }

        syncFastTrackUi();
    }

    function resolveNextStep(n) {
        if (fastTrackActive) {
            if (n === 1) return 3;
            if (n === 3) return 2;
            return n;
        }
        if (shiftFirstFlow) {
            if (n === 1) return 2;
            if (n === 2) return 4;
            if (n >= 4 && n < 8) return n + 1;
            return n;
        }
        return n < TOTAL ? n + 1 : n;
    }

    function resolvePrevStep(n) {
        if (fastTrackActive) {
            if (n === 2) return 3;
            if (n === 3) return 1;
            return n > 1 ? n - 1 : 1;
        }
        if (shiftFirstFlow) {
            if (n === 8) return 7;
            if (n === 7) return 6;
            if (n === 6) return 5;
            if (n === 5) return 4;
            if (n === 4) return 2;
            if (n === 2) return 1;
            return n > 1 ? n - 1 : 1;
        }
        return n > 1 ? n - 1 : 1;
    }

    function showStep(n, skipTrack) {
        n = Math.max(1, Math.min(TOTAL, n));
        if (fastTrackActive && n > 3 && n !== 2) {
            n = 2;
        }

        steps.forEach(function (el) {
            var stepNum = parseInt(el.getAttribute('data-step'), 10);
            var active = stepNum === n;
            el.classList.toggle('reg-wizard__step--active', active);
            el.hidden = !active;
        });

        current = n;
        updateChrome();

        if (!skipTrack) {
            trackStep(n);
        }

        persistAutosave();

        if (n === 2 && typeof window.refreshShiftPicker === 'function') {
            window.refreshShiftPicker(window.REGISTERED_EVENT_IDS || []);
        }
        if (n === 8 && !fastTrackActive) {
            applyVerifiedGoogleEmail();
            if (window.RegistrationWizardReview && typeof window.RegistrationWizardReview.render === 'function') {
                window.RegistrationWizardReview.render();
            }
        }
        if (n === 2 && fastTrackActive && window.RegistrationWizardReview && typeof window.RegistrationWizardReview.renderFastTrackEvents === 'function') {
            window.RegistrationWizardReview.renderFastTrackEvents();
        }

        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function setFastTrack(active) {
        fastTrackActive = !!active;
        updateChrome();
    }

    function redirectToStep2ForMissingEvents(message) {
        var validation = window.RegistrationWizardValidation;
        if (validation && typeof validation.showError === 'function') {
            validation.showError('event_ids', message || 'Please select at least one open event opportunity.');
        }
        showStep(2);
    }

    function goNext() {
        if (!validateCurrentStep()) {
            var panel = document.querySelector('.reg-wizard__step--active .form-error--visible');
            if (panel) {
                panel.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            return;
        }

        if (fastTrackActive && current === 2) {
            return;
        }

        if (current === TOTAL - 1 && !fastTrackActive) {
            var validation = window.RegistrationWizardValidation;
            if (validation && typeof validation.hasValidEventSelection === 'function' && !validation.hasValidEventSelection()) {
                redirectToStep2ForMissingEvents('Please select at least one open event opportunity before review.');
                return;
            }
        }

        var next = resolveNextStep(current);
        if (next !== current) {
            showStep(next);
        }
    }

    function goBack() {
        if (current > 1) {
            showStep(resolvePrevStep(current));
        }
    }

    if (btnNext) {
        btnNext.addEventListener('click', goNext);
    }
    if (btnBack) {
        btnBack.addEventListener('click', goBack);
    }

    document.addEventListener('shiftPickerReady', function () {
        updateChrome();
        if (fastTrackActive && current === 2 && window.RegistrationWizardReview && typeof window.RegistrationWizardReview.renderFastTrackEvents === 'function') {
            window.RegistrationWizardReview.renderFastTrackEvents();
        }
    });

    if (form) {
        form.addEventListener('submit', function (e) {
            applyVerifiedGoogleEmail();
            var validation = window.RegistrationWizardValidation;
            var valid;

            if (shiftPickerHasNoPickableShifts()) {
                e.preventDefault();
                window.location.href = (btnHome && btnHome.getAttribute('href')) || 'staff-app.php';
                return;
            }

            if (fastTrackActive && validation && typeof validation.validateFastTrackSubmit === 'function') {
                valid = validation.validateFastTrackSubmit();
            } else if (validation && typeof validation.validateStep === 'function') {
                valid = validation.validateStep(8, { fastTrack: false, profileEdit: false });
            } else {
                valid = validateCurrentStep();
            }

            if (!valid) {
                e.preventDefault();
                if (validation && typeof validation.hasValidEventSelection === 'function' && !validation.hasValidEventSelection()) {
                    redirectToStep2ForMissingEvents('Please select at least one open event opportunity before submitting.');
                } else if (!fastTrackActive) {
                    showStep(8);
                    if (window.RegistrationWizardReview && typeof window.RegistrationWizardReview.render === 'function') {
                        window.RegistrationWizardReview.render();
                    }
                }
            } else if (window.RegistrationWizardAutosave) {
                window.RegistrationWizardAutosave.clear();
            }
        });
    }

    function applyVerifiedGoogleEmail() {
        var googleEmail = String(body.dataset.registrationGoogleEmail || '').trim();
        if (!googleEmail) {
            return;
        }
        var emailEl = document.getElementById('email');
        var hiddenEl = document.getElementById('registration_verified_google_email');
        if (emailEl && String(emailEl.value || '').trim() === '') {
            emailEl.value = googleEmail;
            emailEl.dispatchEvent(new Event('change', { bubbles: true }));
        }
        if (hiddenEl && String(hiddenEl.value || '').trim() === '') {
            hiddenEl.value = googleEmail;
        }
    }

    applyVerifiedGoogleEmail();

    collectSteps();
    if (steps.length === 0) {
        return;
    }

    var startStep = 1;
    if (window.REG_WIZARD_RESTORE_STEP) {
        startStep = Math.max(1, Math.min(8, parseInt(window.REG_WIZARD_RESTORE_STEP, 10) || 1));
    } else if (shiftFirstFlow) {
        startStep = body.dataset.lockedRole ? 2 : 1;
    }

    showStep(startStep, true);
    if (shiftFirstFlow && startStep === 2 && typeof window.refreshShiftPicker === 'function') {
        window.refreshShiftPicker(window.REGISTERED_EVENT_IDS || []);
    }
    trackStep(startStep);

    window.RegistrationWizard = {
        showStep: showStep,
        getCurrentStep: function () { return current; },
        setFastTrack: setFastTrack,
        isFastTrack: function () { return fastTrackActive; },
    };
})();

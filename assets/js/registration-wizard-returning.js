/**
 * Registration wizard - returning user UX (feature_registration_wizard_v2)
 */
(function () {
    'use strict';

    if (document.body.dataset.wizardMode !== '1') {
        return;
    }

    var PLATFORM_NOTE = 'Olasentra connects people with opportunities. Employment, pay, contracts and working conditions are handled by employers and event organisers.';
    var SHIFT_LOOKUP_LOST_MSG = 'Your previous shift selection is no longer available (you may already be registered for those events). Please pick at least one new open opportunity below.';

    var lookupTimer = null;
    var lastLookupEmail = '';
    var lastPayload = null;
    var prefillTracked = false;

    function track(eventName, extra) {
        if (window.RegistrationWizardAnalytics && typeof window.RegistrationWizardAnalytics.trackEvent === 'function') {
            window.RegistrationWizardAnalytics.trackEvent(eventName, extra || {});
        }
    }

    function isValidEmail(value) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(value || '').trim());
    }

    function markUserEdited(el) {
        if (el) {
            el.dataset.userEdited = '1';
        }
    }

    function bindUserEditedGuards() {
        var form = document.getElementById('registration-form');
        if (!form || form.dataset.returningGuards === '1') {
            return;
        }
        form.dataset.returningGuards = '1';
        form.addEventListener('input', function (e) {
            if (e.target && (e.target.matches('input, select, textarea'))) {
                markUserEdited(e.target);
            }
        }, true);
    }

    function setFieldIfEmpty(id, value) {
        if (value === undefined || value === null || String(value).trim() === '') {
            return false;
        }
        var el = document.getElementById(id);
        if (!el || el.dataset.userEdited === '1') {
            return false;
        }
        if (String(el.value || '').trim() !== '') {
            return false;
        }
        el.value = String(value);
        el.dispatchEvent(new Event('change', { bubbles: true }));
        return true;
    }

    function setGenderIfEmpty(value) {
        if (!value) {
            return false;
        }
        var checked = document.querySelector('input[name="gender"]:checked');
        if (checked && checked.dataset.userEdited === '1') {
            return false;
        }
        if (checked) {
            return false;
        }
        var input = document.querySelector('input[name="gender"][value="' + value + '"]');
        if (input) {
            input.checked = true;
            return true;
        }
        return false;
    }

    function setPhoneIfEmpty(mobile) {
        if (!mobile) {
            return false;
        }
        var national = document.getElementById('mobile_national');
        if (national && national.dataset.userEdited === '1') {
            return false;
        }
        if (national && String(national.value || '').trim() !== '') {
            return false;
        }
        if (typeof setPhoneInputValue === 'function') {
            setPhoneInputValue(mobile);
            return true;
        }
        return setFieldIfEmpty('mobile', mobile);
    }

    function setFormSlugIfEmpty(slug) {
        if (!slug) {
            return false;
        }
        var select = document.getElementById('form_slug');
        if (select && select.tagName === 'SELECT' && select.disabled) {
            return false;
        }
        if (select && select.dataset.userEdited === '1') {
            return false;
        }
        if (select && select.tagName === 'SELECT' && select.value) {
            return false;
        }
        if (select && select.tagName === 'SELECT') {
            select.value = slug;
            select.dispatchEvent(new Event('change', { bubbles: true }));
            return true;
        }
        return false;
    }

    function clearPsaOnFileState() {
        ['psa_front_image', 'psa_back_image'].forEach(function (id) {
            var input = document.getElementById(id);
            if (!input) {
                return;
            }
            delete input.dataset.psaOnFile;
            input.required = true;
        });
        if (window.RegistrationWizardPsa && typeof window.RegistrationWizardPsa.refreshAll === 'function') {
            window.RegistrationWizardPsa.refreshAll();
        }
    }

    function applyPsaOnFileState(profile) {
        var psaFront = document.getElementById('psa_front_image');
        var psaBack = document.getElementById('psa_back_image');
        if (psaFront) {
            psaFront.required = !profile.has_psa_front;
            if (profile.has_psa_front) {
                psaFront.dataset.psaOnFile = '1';
            } else {
                delete psaFront.dataset.psaOnFile;
            }
        }
        if (psaBack) {
            psaBack.required = !profile.has_psa_back;
            if (profile.has_psa_back) {
                psaBack.dataset.psaOnFile = '1';
            } else {
                delete psaBack.dataset.psaOnFile;
            }
        }
        if (window.RegistrationWizardPsa && typeof window.RegistrationWizardPsa.refreshAll === 'function') {
            window.RegistrationWizardPsa.refreshAll();
        }
        if (window.RegistrationWizardReview && typeof window.RegistrationWizardReview.render === 'function') {
            window.RegistrationWizardReview.render();
        }
    }

    function smartPrefill(profile, payload) {
        var filled = 0;
        if (setFieldIfEmpty('surname', profile.surname)) filled++;
        if (setFieldIfEmpty('first_name', profile.first_name)) filled++;
        if (setFieldIfEmpty('full_address', profile.full_address)) filled++;
        if (setFieldIfEmpty('eircode', profile.eircode)) filled++;
        if (setFieldIfEmpty('date_of_birth', profile.date_of_birth)) filled++;
        if (setPhoneIfEmpty(profile.mobile)) filled++;
        if (setFieldIfEmpty('pps_number', profile.pps_number)) filled++;
        if (setFieldIfEmpty('bank_iban', profile.bank_iban)) filled++;
        if (setFieldIfEmpty('psa_licence', profile.psa_licence)) filled++;
        if (setFieldIfEmpty('psa_expiry_date', profile.psa_expiry_date)) filled++;
        if (setGenderIfEmpty(profile.gender)) filled++;
        if (setFormSlugIfEmpty(profile.form_slug)) filled++;

        applyPsaOnFileState(profile);

        var validation = window.RegistrationWizardValidation;
        var hadSelection = validation && typeof validation.hasValidEventSelection === 'function'
            ? validation.hasValidEventSelection()
            : false;

        if (typeof setRegisteredEventIds === 'function') {
            setRegisteredEventIds(payload.registered_event_ids || [], payload.registered_event_dates || []);
        }

        handleShiftSelectionAfterLookup(hadSelection);

        if (filled > 0 && !prefillTracked) {
            prefillTracked = true;
            track('profile_prefilled', { fields_filled: filled });
        }

        return filled;
    }

    function handleShiftSelectionAfterLookup(hadSelectionBefore) {
        if (document.body.dataset.registrationAccountOnly === '1') {
            var validation = window.RegistrationWizardValidation;
            if (validation && typeof validation.clearEventShiftErrors === 'function') {
                validation.clearEventShiftErrors();
            }
            return;
        }
        var validation = window.RegistrationWizardValidation;
        var wizard = window.RegistrationWizard;
        if (!validation || !wizard) {
            return;
        }
        if (typeof validation.shouldRequireEventSelection === 'function' && !validation.shouldRequireEventSelection()) {
            if (typeof validation.clearEventShiftErrors === 'function') {
                validation.clearEventShiftErrors();
            }
            return;
        }
        if (validation.isRegisterWithoutShiftFlow && validation.isRegisterWithoutShiftFlow()) {
            return;
        }
        if (typeof validation.hasValidEventSelection === 'function' && validation.hasValidEventSelection()) {
            return;
        }

        var current = typeof wizard.getCurrentStep === 'function' ? wizard.getCurrentStep() : 1;
        if (current < 3) {
            return;
        }

        validation.showError('event_ids', SHIFT_LOOKUP_LOST_MSG);
        wizard.showStep(2);
        track('returning_shift_selection_lost', {
            had_selection: !!hadSelectionBefore,
            step: current,
        });
    }

    function statusClass(key) {
        return 'reg-profile-card__status--' + String(key || 'incomplete').replace(/_/g, '-');
    }

    function renderProfileCard(payload) {
        var panel = document.getElementById('reg-returning-panel');
        if (!panel) {
            return;
        }

        var profile = payload.profile || {};
        var events = payload.registered_events || [];
        var pct = payload.profile_completion_pct != null ? payload.profile_completion_pct : 0;
        var statusKey = payload.profile_status || 'incomplete';
        var statusLabel = payload.profile_status_label || 'Incomplete';

        var eventsHtml = '';
        if (events.length > 0) {
            eventsHtml = '<ul class="reg-profile-card__events">';
            events.slice(0, 5).forEach(function (ev) {
                var status = ev.status ? ' <span class="reg-profile-card__event-status">(' + ev.status + ')</span>' : '';
                eventsHtml += '<li>' + escapeHtml(ev.name || 'Event') + (ev.date ? ' | ' + escapeHtml(ev.date) : '') + status + '</li>';
            });
            if (events.length > 5) {
                eventsHtml += '<li class="reg-profile-card__events-more">+' + (events.length - 5) + ' more</li>';
            }
            eventsHtml += '</ul>';
        } else {
            eventsHtml = '<p class="reg-profile-card__muted">No previous event applications on file.</p>';
        }

        var isComplete = !!payload.profile_complete;

        if (isComplete) {
            panel.innerHTML =
                '<div class="reg-profile-card reg-profile-card--compact">' +
                    '<p class="reg-profile-card__welcome">Welcome back</p>' +
                    '<p class="reg-profile-card__lead">Your saved profile will be used. Pick a new shift — no need to re-enter your details.</p>' +
                '</div>';
        } else {
            panel.innerHTML =
                '<div class="reg-profile-card">' +
                    '<p class="reg-profile-card__welcome">Welcome back</p>' +
                    '<p class="reg-profile-card__lead">We found your email. Continue below to complete any missing details, then pick shifts.</p>' +
                    '<div class="reg-profile-card__status-row">' +
                        '<span class="reg-profile-card__status-label">Profile status</span>' +
                        '<span class="reg-profile-card__status ' + statusClass(statusKey) + '">' + escapeHtml(statusLabel) + '</span>' +
                    '</div>' +
                '</div>';
        }

        panel.hidden = false;

        if (window.RegistrationWizard && typeof window.RegistrationWizard.setFastTrack === 'function') {
            var pickable = window.RegistrationWizardValidation
                && typeof window.RegistrationWizardValidation.countPickableShifts === 'function'
                ? window.RegistrationWizardValidation.countPickableShifts()
                : null;
            window.RegistrationWizard.setFastTrack(isComplete && pickable !== 0);
        }

        if (isComplete && window.RegistrationWizard && typeof window.RegistrationWizard.showStep === 'function') {
            var cur = typeof window.RegistrationWizard.getCurrentStep === 'function'
                ? window.RegistrationWizard.getCurrentStep()
                : 3;
            if (cur === 3) {
                track('returning_fast_track', { auto_advance: true });
                window.RegistrationWizard.showStep(
                    document.body.dataset.registrationAccountOnly === '1' ? 8 : 2
                );
            }
        }
    }

    function escapeHtml(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function lookupEmail(email) {
        email = String(email || '').trim().toLowerCase();
        if (!isValidEmail(email) || email === lastLookupEmail) {
            return;
        }
        lastLookupEmail = email;

        var csrf = (document.body && document.body.dataset.analyticsCsrf) || '';
        var lookupUrl = 'api/registrant-lookup.php?email=' + encodeURIComponent(email);
        if (csrf) {
            lookupUrl += '&csrf_token=' + encodeURIComponent(csrf);
        }

        fetch(lookupUrl, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var panel = document.getElementById('reg-returning-panel');
                if (!data || !data.found || !data.profile) {
                    if (panel) {
                        panel.hidden = true;
                        panel.innerHTML = '';
                    }
                    if (window.RegistrationWizard && typeof window.RegistrationWizard.setFastTrack === 'function') {
                        window.RegistrationWizard.setFastTrack(false);
                    }
                    clearPsaOnFileState();
                    if (typeof setRegisteredEventIds === 'function') {
                        setRegisteredEventIds([]);
                    }
                    return;
                }

                lastPayload = data;
                track('returning_user_detected', {
                    profile_status: data.profile_status || '',
                    completion_pct: data.profile_completion_pct || 0,
                });

                renderProfileCard(data);
                smartPrefill(data.profile, data);
            })
            .catch(function () {
                // silent
            });
    }

    function scheduleLookup(email) {
        clearTimeout(lookupTimer);
        lookupTimer = setTimeout(function () {
            lookupEmail(email);
        }, 400);
    }

    function renderResumePrompt(draft) {
        var el = document.getElementById('reg-wizard-resume-prompt');
        if (!el || !draft) {
            return;
        }

        var step = Math.max(1, Math.min(8, parseInt(draft.step, 10) || 1));
        var autosave = window.RegistrationWizardAutosave || {};
        var when = typeof autosave.formatSavedTime === 'function' && draft.savedAt
            ? autosave.formatSavedTime(draft.savedAt)
            : 'recently';
        var stepName = typeof autosave.stepLabel === 'function'
            ? autosave.stepLabel(step)
            : ('Step ' + step);
        var eventCount = Array.isArray(draft.fields && draft.fields['event_ids[]'])
            ? draft.fields['event_ids[]'].length
            : 0;
        var eventHint = eventCount > 0
            ? ' · ' + eventCount + ' shift' + (eventCount === 1 ? '' : 's') + ' selected'
            : '';

        el.innerHTML =
            '<div class="reg-resume-prompt__card">' +
                '<p class="reg-resume-prompt__badge">Saved on this device</p>' +
                '<p class="reg-resume-prompt__title">Resume your application?</p>' +
                '<p class="reg-resume-prompt__text">Last saved <strong>' + when + '</strong>. You reached <strong>' + stepName + '</strong> (step ' + step + ' of 8)' + eventHint + '.</p>' +
                '<p class="reg-resume-prompt__note">Progress is stored locally on this phone or browser. PSA photos are not saved in drafts — you will re-attach them on the PSA step.</p>' +
                '<div class="reg-resume-prompt__actions">' +
                    '<button type="button" class="btn btn--primary" id="reg-resume-continue">Resume application</button>' +
                    '<button type="button" class="btn btn--secondary" id="reg-resume-fresh">Start fresh</button>' +
                '</div>' +
            '</div>';
        el.hidden = false;

        document.getElementById('reg-resume-continue').addEventListener('click', function () {
            track('resume_selected', { step: step });
            if (window.RegistrationWizardAutosave && typeof window.RegistrationWizardAutosave.applyDraft === 'function') {
                window.RegistrationWizardAutosave.applyDraft(draft);
            }
            el.hidden = true;
            if (window.RegistrationWizard && typeof window.RegistrationWizard.showStep === 'function') {
                window.RegistrationWizard.showStep(step);
            }
            if (typeof autosave.setSaveStatus === 'function') {
                autosave.setSaveStatus('saved');
            }
        });

        document.getElementById('reg-resume-fresh').addEventListener('click', function () {
            track('new_application_started', { previous_step: step });
            if (window.RegistrationWizardAutosave && typeof window.RegistrationWizardAutosave.clear === 'function') {
                window.RegistrationWizardAutosave.clear();
            }
            var form = document.getElementById('registration-form');
            if (form) {
                form.reset();
            }
            lastLookupEmail = '';
            lastPayload = null;
            var panel = document.getElementById('reg-returning-panel');
            if (panel) {
                panel.hidden = true;
                panel.innerHTML = '';
            }
            el.hidden = true;
            clearPsaOnFileState();
            if (typeof setRegisteredEventIds === 'function') {
                setRegisteredEventIds([]);
            }
            if (window.RegistrationWizard && typeof window.RegistrationWizard.showStep === 'function') {
                window.RegistrationWizard.showStep(1);
            }
        });
    }

    function bindDuplicateProtection() {
        var list = document.getElementById('shift-picker-list');
        if (!list || list.dataset.duplicateGuard === '1') {
            return;
        }
        list.dataset.duplicateGuard = '1';
        list.addEventListener('click', function (e) {
            var card = e.target.closest('.reg-event-card--registered');
            if (!card) {
                return;
            }
            e.preventDefault();
            e.stopPropagation();
            track('duplicate_application_prevented', {
                event_id: card.getAttribute('data-event-id') || '',
            });
            var tip = document.getElementById('reg-duplicate-tip');
            if (!tip) {
                tip = document.createElement('p');
                tip.id = 'reg-duplicate-tip';
                tip.className = 'reg-duplicate-tip';
                tip.setAttribute('role', 'status');
                list.parentNode.insertBefore(tip, list.nextSibling);
            }
            tip.textContent = 'Already Registered for this event - pick a different opportunity.';
            tip.hidden = false;
        }, true);
    }

    function init() {
        bindUserEditedGuards();
        bindDuplicateProtection();

        if (window.REG_WIZARD_DRAFT) {
            renderResumePrompt(window.REG_WIZARD_DRAFT);
        }

        var emailInput = document.getElementById('email');
        if (!emailInput) {
            return;
        }

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
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.RegistrationWizardReturning = {
        lookupEmail: lookupEmail,
        getLastPayload: function () { return lastPayload; },
    };
})();

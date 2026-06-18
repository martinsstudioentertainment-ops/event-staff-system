/**
 * Registration wizard — localStorage autosave + save status UI (feature_registration_wizard_v2)
 */
(function () {
    'use strict';

    if (document.body.dataset.wizardMode !== '1') {
        return;
    }

    var TTL_MS = 7 * 24 * 60 * 60 * 1000;
    var form = document.getElementById('registration-form');
    if (!form) {
        return;
    }

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

    var saveStatusEl = document.getElementById('reg-wizard-save-status');
    var saveTextEl = document.getElementById('reg-wizard-save-text');
    var lastSavedAt = null;
    var savePending = false;

    window.REG_WIZARD_STEP_NAMES = STEP_NAMES;

    function storageKey() {
        var slug = (typeof window.REGISTRATION_FORM_SLUG !== 'undefined' && window.REGISTRATION_FORM_SLUG)
            ? String(window.REGISTRATION_FORM_SLUG)
            : 'default';
        return 'olasentra_reg_wizard_' + slug.replace(/[^a-z0-9_-]/gi, '_');
    }

    function formatSavedTime(ts) {
        if (!ts) {
            return '';
        }
        return new Date(ts).toLocaleString('en-IE', {
            day: 'numeric',
            month: 'short',
            hour: '2-digit',
            minute: '2-digit',
        });
    }

    function stepLabel(step) {
        step = Math.max(1, Math.min(8, parseInt(step, 10) || 1));
        return STEP_NAMES[step] || ('Step ' + step);
    }

    function setSaveStatus(mode) {
        if (!saveStatusEl || !saveTextEl) {
            return;
        }

        if (mode === 'hidden') {
            saveStatusEl.hidden = true;
            saveStatusEl.classList.remove(
                'reg-wizard__save-status--saving',
                'reg-wizard__save-status--saved',
                'reg-wizard__save-status--error'
            );
            return;
        }

        saveStatusEl.hidden = false;
        saveStatusEl.classList.remove(
            'reg-wizard__save-status--saving',
            'reg-wizard__save-status--saved',
            'reg-wizard__save-status--error'
        );

        if (mode === 'saving') {
            saveStatusEl.classList.add('reg-wizard__save-status--saving');
            saveTextEl.textContent = 'Saving draft…';
        } else if (mode === 'saved') {
            saveStatusEl.classList.add('reg-wizard__save-status--saved');
            saveTextEl.textContent = lastSavedAt
                ? 'Draft saved · Last saved ' + formatSavedTime(lastSavedAt)
                : 'Draft saved on this device';
        } else if (mode === 'error') {
            saveStatusEl.classList.add('reg-wizard__save-status--error');
            saveTextEl.textContent = 'Could not save draft on this device';
        }
    }

    function collectFieldData() {
        var data = {};
        form.querySelectorAll('input, select, textarea').forEach(function (el) {
            var name = el.name;
            if (!name || name === 'csrf_token') {
                return;
            }
            if (el.type === 'file') {
                return;
            }
            if (el.type === 'checkbox') {
                if (name === 'privacy_consent') {
                    data[name] = el.checked ? '1' : '';
                }
                return;
            }
            if (el.type === 'radio') {
                if (el.checked) {
                    data[name] = el.value;
                }
                return;
            }
            if (name.endsWith('[]')) {
                if (!data[name]) {
                    data[name] = [];
                }
                if (el.type === 'checkbox' && el.checked) {
                    data[name].push(el.value);
                }
                return;
            }
            data[name] = el.value;
        });

        var list = document.getElementById('shift-picker-list');
        if (list) {
            var ids = [];
            list.querySelectorAll('input[name="event_ids[]"]:checked:not(:disabled)').forEach(function (input) {
                ids.push(input.value);
            });
            data['event_ids[]'] = ids;
            data.venue_id = (document.getElementById('venue_id') || {}).value || '';
        }

        var googleEmail = String(document.body.dataset.registrationGoogleEmail || '').trim();
        if (googleEmail && String(data.email || '').trim() === '') {
            data.email = googleEmail;
        }

        return data;
    }

    function save(step) {
        savePending = false;
        try {
            var payload = {
                v: 1,
                savedAt: Date.now(),
                step: step || 1,
                fields: collectFieldData(),
            };
            localStorage.setItem(storageKey(), JSON.stringify(payload));
            lastSavedAt = payload.savedAt;
            setSaveStatus('saved');
            return payload;
        } catch (e) {
            setSaveStatus('error');
            return null;
        }
    }

    function load() {
        try {
            var raw = localStorage.getItem(storageKey());
            if (!raw) {
                return null;
            }
            var payload = JSON.parse(raw);
            if (!payload || !payload.savedAt || Date.now() - payload.savedAt > TTL_MS) {
                localStorage.removeItem(storageKey());
                return null;
            }
            lastSavedAt = payload.savedAt;
            return payload;
        } catch (e) {
            return null;
        }
    }

    function applyFields(fields) {
        if (!fields || typeof fields !== 'object') {
            return;
        }

        Object.keys(fields).forEach(function (name) {
            if (name === 'event_ids[]') {
                return;
            }
            var value = fields[name];
            if (name === 'privacy_consent') {
                var consent = form.querySelector('input[name="privacy_consent"]');
                if (consent) {
                    consent.checked = value === '1' || value === true;
                }
                return;
            }
            if (name === 'gender') {
                var genderInput = form.querySelector('input[name="gender"][value="' + value + '"]');
                if (genderInput) {
                    genderInput.checked = true;
                }
                return;
            }
            var el = form.querySelector('[name="' + name + '"]');
            if (el && el.type !== 'checkbox' && el.type !== 'radio') {
                el.value = value;
                el.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });

        if (Array.isArray(fields['event_ids[]']) && fields['event_ids[]'].length) {
            window.REG_WIZARD_RESTORE_EVENT_IDS = fields['event_ids[]'].map(String);
            var venue = document.getElementById('venue_id');
            if (venue && fields.venue_id) {
                venue.value = fields.venue_id;
            }
        }

        var googleEmail = String(document.body.dataset.registrationGoogleEmail || '').trim();
        if (googleEmail) {
            var emailEl = document.getElementById('email');
            var hiddenEl = document.getElementById('registration_verified_google_email');
            if (emailEl) {
                emailEl.value = googleEmail;
            }
            if (hiddenEl) {
                hiddenEl.value = googleEmail;
            } else if (emailEl) {
                var hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'registration_verified_google_email';
                hidden.id = 'registration_verified_google_email';
                hidden.value = googleEmail;
                form.insertBefore(hidden, form.firstChild);
            }
        }
    }

    function clear() {
        try {
            localStorage.removeItem(storageKey());
        } catch (e) {
            // ignore
        }
        lastSavedAt = null;
        setSaveStatus('hidden');
    }

    function hasMeaningfulDraft(payload) {
        if (!payload || !payload.fields) {
            return false;
        }
        var step = parseInt(payload.step, 10) || 1;
        if (step > 1) {
            return true;
        }
        var fields = payload.fields;
        var keys = ['email', 'surname', 'first_name', 'mobile', 'pps_number'];
        for (var i = 0; i < keys.length; i++) {
            if (String(fields[keys[i]] || '').trim() !== '') {
                return true;
            }
        }
        if (Array.isArray(fields['event_ids[]']) && fields['event_ids[]'].length > 0) {
            return true;
        }
        return false;
    }

    var registered = parseInt(document.body.dataset.registeredCount || '0', 10);
    if (registered > 0) {
        clear();
        return;
    }

    var restored = load();
    if (restored && hasMeaningfulDraft(restored)) {
        window.REG_WIZARD_DRAFT = restored;
        setSaveStatus('saved');
    } else if (restored) {
        applyFields(restored.fields);
        if (document.body.dataset.serverErrorRestore !== '1') {
            window.REG_WIZARD_RESTORE_STEP = Math.max(1, Math.min(8, parseInt(restored.step, 10) || 1));
        }
        setSaveStatus('saved');
    }

    var debounceTimer = null;
    function scheduleSave() {
        if (!savePending) {
            savePending = true;
            setSaveStatus('saving');
        }
        if (debounceTimer) {
            clearTimeout(debounceTimer);
        }
        debounceTimer = setTimeout(function () {
            var step = window.RegistrationWizard && typeof window.RegistrationWizard.getCurrentStep === 'function'
                ? window.RegistrationWizard.getCurrentStep()
                : (window.REG_WIZARD_RESTORE_STEP || 1);
            save(step);
        }, 600);
    }

    form.addEventListener('input', scheduleSave);
    form.addEventListener('change', scheduleSave);

    function applyDraft(draft) {
        if (!draft) {
            return;
        }
        applyFields(draft.fields || {});
        lastSavedAt = draft.savedAt || lastSavedAt;
        setSaveStatus('saved');
    }

    window.RegistrationWizardAutosave = {
        save: save,
        clear: clear,
        applyDraft: applyDraft,
        restoreEventIds: function () {
            return window.REG_WIZARD_RESTORE_EVENT_IDS || null;
        },
        getLastSavedAt: function () {
            return lastSavedAt;
        },
        formatSavedTime: formatSavedTime,
        stepLabel: stepLabel,
        setSaveStatus: setSaveStatus,
    };
})();

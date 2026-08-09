/**

 * Event Staff System — Registration shift picker (multi-select checkboxes)

 */



const EVENTS_FALLBACK = [];



let registrationOptions = null;



function getFormSlug() {

    if (typeof window !== 'undefined' && window.REGISTRATION_FORM_SLUG) {

        return String(window.REGISTRATION_FORM_SLUG);

    }



    const checkedRole = document.querySelector('input[name="form_slug"]:checked');

    if (checkedRole && checkedRole.value) {

        return String(checkedRole.value);

    }

    const formSlugEl = document.getElementById('form_slug');

    if (formSlugEl && formSlugEl.value) {

        return String(formSlugEl.value);

    }

    const hidden = document.querySelector('input[type="hidden"][name="form_slug"]');

    if (hidden && hidden.value) {

        return String(hidden.value);

    }



    return 'dsp';

}



function getApiBase() {

    return (typeof window !== 'undefined' && window.EVENT_STAFF_BASE) ? window.EVENT_STAFF_BASE : '';

}



function todayYmdLocal() {

    const d = new Date();

    const y = d.getFullYear();

    const m = String(d.getMonth() + 1).padStart(2, '0');

    const day = String(d.getDate()).padStart(2, '0');

    return y + '-' + m + '-' + day;

}



function filterOpenRegistrationShifts(events) {

    const today = todayYmdLocal();



    return (Array.isArray(events) ? events : []).filter(function (ev) {

        const sortDate = String(ev.sortDate || '').slice(0, 10);

        if (!sortDate || sortDate < today) {

            return false;

        }

        if (ev.openForRegistration === false) {

            return false;

        }

        return true;

    });

}



async function loadRegistrationOptions(formSlug) {

    const slug = formSlug || getFormSlug();

    const url  = getApiBase() + 'api/registration-options.php?form=' + encodeURIComponent(slug)

        + '&_=' + Date.now();



    try {

        const response = await fetch(url);

        if (!response.ok) {

            throw new Error('Failed to load registration options');

        }

        const data = await response.json();

        if (data && data.venues) {

            registrationOptions = data;

            window.REGISTRATION_OPTIONS = data;

            return data;

        }

    } catch (err) {

        console.warn('[EventStaff] Registration options unavailable.', err.message);

    }



    registrationOptions = {

        form: { slug: slug },

        venues: [],

        eventsByVenue: {},

    };

    window.REGISTRATION_OPTIONS = registrationOptions;

    return registrationOptions;

}



function getRegistrationOptions() {

    return registrationOptions || window.REGISTRATION_OPTIONS || {

        venues: [],

        eventsByVenue: {},

    };

}



function getAllOpenShifts(options) {

    const data = options || getRegistrationOptions();

    const map  = data.eventsByVenue || {};

    const all  = [];

    const seen = {};



    Object.keys(map).forEach(function (venueKey) {

        filterOpenRegistrationShifts(map[venueKey]).forEach(function (ev) {

            const id = String(ev.id);

            if (seen[id]) {

                return;

            }

            seen[id] = true;

            all.push(ev);

        });

    });



    all.sort(function (a, b) {

        const dateCmp = String(a.sortDate || '').localeCompare(String(b.sortDate || ''));

        if (dateCmp !== 0) {

            return dateCmp;

        }

        return String(a.name || '').localeCompare(String(b.name || ''));

    });



    return all;

}



function formatShiftDateHeading(sortDate) {

    const parts = String(sortDate || '').split('-');

    if (parts.length !== 3) {

        return sortDate;

    }

    const d = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));

    if (isNaN(d.getTime())) {

        return sortDate;

    }

    return d.toLocaleDateString('en-IE', {

        weekday: 'long',

        day: 'numeric',

        month: 'long',

        year: 'numeric',

    });

}



function isPlaceholderShiftTime(timeStr) {
    const t = String(timeStr || '').trim();
    return t === '09:00 – 23:00' || t === '09:00 - 23:00';
}

function appendShiftCheckboxContent(textEl, event, venueName, isRegistered, isDateBlocked) {
    const title = document.createElement('span');
    title.className = 'event-checkbox__title';
    title.textContent = event.name || 'Event';
    textEl.appendChild(title);

    const employer = String(event.mainSecurityCompany || '').trim();
    if (employer) {
        const employerEl = document.createElement('span');
        employerEl.className = 'event-checkbox__employer';
        employerEl.textContent = 'Listed contractor (info only): ' + employer;
        textEl.appendChild(employerEl);
    }

    const rolesLabel = String(event.rolesLabel || '').trim();
    if (rolesLabel) {
        const rolesEl = document.createElement('span');
        rolesEl.className = 'event-checkbox__roles';
        const rolesPrefix = (typeof document !== 'undefined' && document.body && document.body.dataset.rolesOnShiftLabel)
            ? document.body.dataset.rolesOnShiftLabel + ': '
            : 'Roles on this shift: ';
        rolesEl.textContent = rolesPrefix + rolesLabel;
        textEl.appendChild(rolesEl);
    }

    const metaParts = [];
    if (event.date) {
        metaParts.push(event.date);
    }
    if (event.time && !isPlaceholderShiftTime(event.time)) {
        metaParts.push(event.time);
    }
    const venueLabel = venueName || String(event.venueName || '').trim() || String(event.location || '').trim();
    if (venueLabel) {
        metaParts.push(venueLabel);
    }

    if (metaParts.length > 0) {
        const meta = document.createElement('span');
        meta.className = 'event-checkbox__meta';
        meta.textContent = metaParts.join(' · ');
        textEl.appendChild(meta);
    }

    if (isRegistered) {
        const note = document.createElement('span');
        note.className = 'event-checkbox__note';
        note.textContent = 'Already Registered';
        textEl.appendChild(note);
    } else if (isDateBlocked) {
        const note = document.createElement('span');
        note.className = 'event-checkbox__note';
        note.textContent = 'You already have a shift on this date';
        textEl.appendChild(note);
    }
}



function getVenueNameForEvent(event, options) {

    const data = options || getRegistrationOptions();

    const venues = Array.isArray(data.venues) ? data.venues : [];

    const venueId = String(event.venueId != null ? event.venueId : '');

    if (venueId && venueId !== '0') {

        for (let i = 0; i < venues.length; i++) {

            if (String(venues[i].id) === venueId) {

                return venues[i].name;

            }

        }

    }



    if (event.venueName) {

        return String(event.venueName).trim();

    }



    if (event.location) {

        return event.location;

    }



    return '';

}



function getShiftPickerList() {

    return document.getElementById('shift-picker-list');

}



function parseSelectedEventIds(container) {

    if (!container) {

        return [];

    }



    try {

        return JSON.parse(container.dataset.selected || '[]').map(String);

    } catch (err) {

        return [];

    }

}



function getSelectedShiftIds(container) {

    if (!container) {

        return [];

    }



    return Array.from(container.querySelectorAll('input[name="event_ids[]"]:checked:not(:disabled)'))

        .map(function (input) { return parseInt(input.value, 10); })

        .filter(function (id) { return !isNaN(id) && id > 0; });

}



function syncVenueFromShiftSelection(container) {

    const venueInput = document.getElementById('venue_id');

    if (!venueInput || !container) {

        return;

    }



    const first = container.querySelector('input[name="event_ids[]"]:checked:not(:disabled)');

    venueInput.value = first ? (first.dataset.venueId || '0') : '0';

}



function updateShiftSelectionSummary(container) {

    const summary = document.getElementById('shift-picker-summary');

    if (!summary || !container) {

        return;

    }



    const count = getSelectedShiftIds(container).length;
    const pickable = container.querySelectorAll('input[name="event_ids[]"]:not(:disabled)').length;
    const hasShifts = container.querySelectorAll('input[name="event_ids[]"]').length > 0;

    if (hasShifts && pickable === 0) {
        summary.textContent = 'No new shifts available for you';
        summary.classList.remove('shift-picker-summary--active');
        return;
    }

    summary.textContent = count === 0

        ? '0 shifts selected'

        : (count === 1 ? '1 shift selected' : count + ' shifts selected');

    summary.classList.toggle('shift-picker-summary--active', count > 0);

}



function enforceOneShiftPerDay(container, changedInput) {

    if (!container || !changedInput || !changedInput.checked) {

        return;

    }



    const day = changedInput.dataset.sortDate || '';

    if (!day) {

        return;

    }



    container.querySelectorAll('input[name="event_ids[]"]').forEach(function (input) {

        if (input !== changedInput && input.checked && input.dataset.sortDate === day) {

            input.checked = false;

        }

    });

}



function persistShiftSelection(container) {

    if (!container) {

        return;

    }



    container.dataset.selected = JSON.stringify(getSelectedShiftIds(container));

    syncVenueFromShiftSelection(container);

    updateShiftSelectionSummary(container);

}



function isRegistrationWizardMode() {
    return typeof document !== 'undefined'
        && document.body
        && document.body.dataset.wizardMode === '1';
}

function extractCountyFromEvent(event, venueName) {
    const loc = String(event.location || '').trim();
    if (loc) {
        const parts = loc.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
        if (parts.length >= 2) {
            return parts[parts.length - 1];
        }
        return loc;
    }
    const venue = String(venueName || event.venueName || '').trim();
    if (venue) {
        const parts = venue.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
        if (parts.length >= 2) {
            return parts[parts.length - 1];
        }
    }
    return '—';
}

function applyRestoredEventSelection(container) {
    const ids = window.REG_WIZARD_RESTORE_EVENT_IDS;
    if (!container || !ids || !ids.length) {
        return;
    }
    ids.forEach(function (id) {
        const input = container.querySelector('input[name="event_ids[]"][value="' + id + '"]:not(:disabled)');
        if (input) {
            input.checked = true;
        }
    });
    delete window.REG_WIZARD_RESTORE_EVENT_IDS;
    enforceOneShiftPerDayOnLoad(container);
    persistShiftSelection(container);
}

function getRegisteredEventDates() {
    return (window.REGISTERED_EVENT_DATES || []).map(function (d) {
        return String(d).slice(0, 10);
    });
}

function getShiftRegistrationBlock(eventId, dayKey, blockedIds) {
    const id = String(eventId);
    const day = String(dayKey || '').slice(0, 10);
    const ids = (blockedIds || window.REGISTERED_EVENT_IDS || []).map(String);

    if (ids.includes(id)) {
        return { blocked: true, reason: 'registered' };
    }
    if (day && getRegisteredEventDates().includes(day)) {
        return { blocked: true, reason: 'date' };
    }

    return { blocked: false, reason: null };
}

function buildWizardEventCard(event, venueName, blockInfo) {
    const id = String(event.id);
    const dayKey = String(event.sortDate || '').slice(0, 10);
    const block = blockInfo || getShiftRegistrationBlock(id, dayKey);
    const isBlocked = block.blocked;
    const county = extractCountyFromEvent(event, venueName);
    const roles = String(event.rolesLabel || event.workTypeLabel || 'Security').trim();
    const statusText = block.reason === 'registered'
        ? 'Already Registered'
        : (block.reason === 'date' ? 'Shift already chosen for this date' : 'Open for registration');
    const statusClass = isBlocked ? 'reg-event-card__status--registered' : 'reg-event-card__status--open';

    const label = document.createElement('label');
    label.className = 'reg-event-card' + (isBlocked ? ' reg-event-card--registered' : '');
    label.setAttribute('data-event-id', id);
    label.setAttribute('data-event-name', String(event.name || 'Event'));

    const input = document.createElement('input');
    input.type = 'checkbox';
    input.className = 'reg-event-card__input';
    input.name = 'event_ids[]';
    input.value = id;
    input.dataset.sortDate = dayKey;
    input.dataset.venueId = String(event.venueId != null ? event.venueId : '0');
    if (isBlocked) {
        input.disabled = true;
    }

    const body = document.createElement('div');
    body.className = 'reg-event-card__body';

    const title = document.createElement('h4');
    title.className = 'reg-event-card__title';
    title.textContent = event.name || 'Event';
    body.appendChild(title);

    const grid = document.createElement('dl');
    grid.className = 'reg-event-card__meta';

    function addRow(term, value) {
        if (!value || value === '—') {
            return;
        }
        const dt = document.createElement('dt');
        dt.textContent = term;
        const dd = document.createElement('dd');
        dd.textContent = value;
        grid.appendChild(dt);
        grid.appendChild(dd);
    }

    addRow('Venue', venueName || String(event.venueName || '').trim() || String(event.location || '').trim());
    addRow('Date', event.date || formatShiftDateHeading(dayKey));
    addRow('County', county !== '—' ? county : null);
    addRow('Roles', roles);
    body.appendChild(grid);

    const status = document.createElement('span');
    status.className = 'reg-event-card__status ' + statusClass;
    status.textContent = statusText;
    body.appendChild(status);

    const mark = document.createElement('span');
    mark.className = 'reg-event-card__check';
    mark.setAttribute('aria-hidden', 'true');

    label.appendChild(input);
    label.appendChild(body);
    label.appendChild(mark);

    return { label: label, input: input };
}

function populateWizardEventCards(container, registeredIds, events, options) {
    if (!container) {
        return;
    }

    const list = filterOpenRegistrationShifts(Array.isArray(events) ? events : []);
    const blocked = (registeredIds || window.REGISTERED_EVENT_IDS || []).map(String);
    const selected = parseSelectedEventIds(container);

    container.innerHTML = '';
    container.classList.remove('shift-picker-list--error');
    container.classList.add('shift-picker-list--wizard-cards');

    if (list.length === 0) {
        const role = (document.getElementById('staff_role') || {}).value || '';
        const emptyMsg = role === 'static'
            ? 'No static opportunities open right now.'
            : 'No event opportunities open right now.';
        container.innerHTML = '<p class="reg-event-cards__empty">' + emptyMsg + '</p>';
        updateShiftSelectionSummary(container);
        updateWaitlistOffer(0);
        updateNoShiftsRegisterOffer(0);
        dispatchShiftPickerReady(container, 0, 0);
        return;
    }

    const wrap = document.createElement('div');
    wrap.className = 'reg-event-cards';

    list.forEach(function (event) {
        const id = String(event.id);
        const dayKey = String(event.sortDate || '').slice(0, 10);
        const block = getShiftRegistrationBlock(id, dayKey, blocked);
        const venueName = getVenueNameForEvent(event, options);
        const card = buildWizardEventCard(event, venueName, block);
        if (!block.blocked && selected.includes(id)) {
            card.input.checked = true;
        }
        wrap.appendChild(card.label);
    });

    container.appendChild(wrap);
    applyRestoredEventSelection(container);
    if (!window.REG_WIZARD_RESTORE_EVENT_IDS) {
        enforceOneShiftPerDayOnLoad(container);
    }
    persistShiftSelection(container);

    if (window.RegistrationWizardReview && typeof window.RegistrationWizardReview.render === 'function') {
        window.RegistrationWizardReview.render();
    }
    const pickable = wrap.querySelectorAll('input[name="event_ids[]"]:not(:disabled)').length;
    dispatchShiftPickerReady(container, list.length, pickable);
    updateWaitlistOffer(list.length, pickable);
    updateNoShiftsRegisterOffer(pickable);
}

function dispatchShiftPickerReady(container, total, pickable) {
    window.SHIFT_PICKER_READY = true;
    window.SHIFT_PICKER_TOTAL_COUNT = total;
    window.SHIFT_PICKER_PICKABLE_COUNT = pickable;
    try {
        document.dispatchEvent(new CustomEvent('shiftPickerReady', {
            detail: {
                count: total,
                pickable: pickable,
            },
        }));
    } catch (e) {
        // IE11 fallback not required
    }
}

function populateShiftPicker(container, registeredIds, events, options) {

    if (!container) {

        return;

    }

    if (isRegistrationWizardMode()) {
        populateWizardEventCards(container, registeredIds, events, options);
        return;
    }

    const list     = filterOpenRegistrationShifts(Array.isArray(events) ? events : []);

    const blocked  = (registeredIds || window.REGISTERED_EVENT_IDS || []).map(String);

    const selected = parseSelectedEventIds(container);



    container.innerHTML = '';

    container.classList.remove('shift-picker-list--error');



    if (list.length === 0) {
        const role = (document.getElementById('staff_role') || {}).value || '';
        const emptyMsg = role === 'static'
            ? 'No static shifts are open right now.'
            : 'No shifts open for registration right now.';
        container.innerHTML = '<p class="form-hint">' + emptyMsg + '</p>';
        updateShiftSelectionSummary(container);
        updateWaitlistOffer(0, 0);
        updateNoShiftsRegisterOffer(0);
        dispatchShiftPickerReady(container, 0, 0);
        return;
    }



    let currentDay = '';



    list.forEach(function (event) {

        const dayKey = String(event.sortDate || '').slice(0, 10);



        if (dayKey !== currentDay) {

            currentDay = dayKey;

            const heading = document.createElement('p');

            heading.className = 'shift-picker-list__date';

            heading.textContent = formatShiftDateHeading(dayKey);

            container.appendChild(heading);

        }



        const id = String(event.id);

        const block = getShiftRegistrationBlock(id, dayKey, blocked);

        const isBlocked = block.blocked;

        const isRegistered = block.reason === 'registered';

        const isDateBlocked = block.reason === 'date';



        const label = document.createElement('label');
        label.className = 'event-checkbox' + (isBlocked ? ' event-checkbox--registered' : '');



        const input = document.createElement('input');

        input.type = 'checkbox';

        input.name = 'event_ids[]';

        input.value = id;

        input.dataset.sortDate = dayKey;

        input.dataset.venueId = String(event.venueId != null ? event.venueId : '0');



        if (isBlocked) {

            input.disabled = true;

        } else if (selected.includes(id)) {

            input.checked = true;

        }



        const text = document.createElement('span');
        text.className = 'event-checkbox__text';
        appendShiftCheckboxContent(
            text,
            event,
            getVenueNameForEvent(event, options),
            isRegistered,
            isDateBlocked
        );

        label.appendChild(input);
        label.appendChild(text);

        container.appendChild(label);

    });



    enforceOneShiftPerDayOnLoad(container);

    persistShiftSelection(container);

    const pickable = container.querySelectorAll('input[name="event_ids[]"]:not(:disabled)').length;
    dispatchShiftPickerReady(container, list.length, pickable);
    updateWaitlistOffer(list.length, pickable);
    updateNoShiftsRegisterOffer(pickable);

}



function enforceOneShiftPerDayOnLoad(container) {

    const byDay = {};

    container.querySelectorAll('input[name="event_ids[]"]:checked:not(:disabled)').forEach(function (input) {

        const day = input.dataset.sortDate || '';

        if (!day) {

            return;

        }

        if (byDay[day]) {

            input.checked = false;

        } else {

            byDay[day] = input;

        }

    });

}



function onShiftCheckboxChange(event) {

    const container = getShiftPickerList();

    if (!container) {

        return;

    }



    const input = event.target;

    if (input && input.name === 'event_ids[]') {

        enforceOneShiftPerDay(container, input);

    }



    persistShiftSelection(container);



    const errorEl = document.getElementById('event_ids-error');

    if (errorEl) {

        errorEl.classList.remove('form-error--visible');

        errorEl.textContent = '';

    }

    container.classList.remove('shift-picker-list--error');

    syncWizardCardSelectedState(container);

}

function syncWizardCardSelectedState(container) {
    if (!container || !isRegistrationWizardMode()) {
        return;
    }
    container.querySelectorAll('.reg-event-card').forEach(function (card) {
        const input = card.querySelector('.reg-event-card__input');
        card.classList.toggle('reg-event-card--selected', !!(input && input.checked && !input.disabled));
    });
}



function refreshShiftPicker(registeredIds) {

    const container = getShiftPickerList();

    if (!container) {

        return;

    }

    window.SHIFT_PICKER_READY = false;
    window.SHIFT_PICKER_PICKABLE_COUNT = 0;
    window.SHIFT_PICKER_TOTAL_COUNT = 0;

    const options = getRegistrationOptions();

    populateShiftPicker(container, registeredIds, getAllOpenShifts(options), options);

}



function setRegisteredEventIds(ids, dates) {

    window.REGISTERED_EVENT_IDS = Array.isArray(ids) ? ids.map(String) : [];
    window.REGISTERED_EVENT_DATES = Array.isArray(dates)
        ? dates.map(function (d) { return String(d).slice(0, 10); })
        : [];

    refreshShiftPicker(window.REGISTERED_EVENT_IDS);

}



function updateRegistrationRoleBanner() {

    const roleInput = document.getElementById('staff_role');

    const picked = document.querySelector('input[name="form_slug"]:checked');

    const formSlugEl = document.getElementById('form_slug');

    let role = '';

    if (picked && picked.dataset.role) {

        role = picked.dataset.role;

    } else if (formSlugEl && formSlugEl.tagName === 'SELECT' && formSlugEl.selectedOptions[0]) {

        role = formSlugEl.selectedOptions[0].dataset.role || '';

    }

    if (roleInput && role) {

        roleInput.value = role;

    }

    if (typeof applyRegistrationPsaFieldRequirements === 'function') {
        applyRegistrationPsaFieldRequirements();
    }

    const nameEl = document.getElementById('registration-role-banner-name');

    const detailEl = document.getElementById('registration-role-banner-detail');

    if (!nameEl || !detailEl) {

        return;

    }

    if (picked) {

        nameEl.textContent = picked.dataset.label || picked.value;

        detailEl.textContent = picked.dataset.detail || '';

        return;

    }

    if (formSlugEl && formSlugEl.tagName === 'SELECT') {

        const option = formSlugEl.selectedOptions[0];

        if (option) {

            nameEl.textContent = option.dataset.label || option.textContent.trim();

            detailEl.textContent = option.dataset.detail || '';

        }

    }

}



async function initShiftSelection() {

    if (document.body.dataset.registrationAccountOnly === '1') {
        return;
    }

    const container  = getShiftPickerList();

    const formSlugEl = document.getElementById('form_slug');



    if (!container) {

        return;

    }



    updateRegistrationRoleBanner();

    await loadRegistrationOptions(getFormSlug());

    refreshShiftPicker(window.REGISTERED_EVENT_IDS);



    container.addEventListener('change', onShiftCheckboxChange);



    const roleInputs = document.querySelectorAll('input[name="form_slug"]');

    roleInputs.forEach(function (input) {

        input.addEventListener('change', async function () {

            window.REGISTRATION_FORM_SLUG = input.value;

            updateRegistrationRoleBanner();

            container.dataset.selected = '[]';

            await loadRegistrationOptions(input.value);

            refreshShiftPicker(window.REGISTERED_EVENT_IDS);

        });

    });

    if (formSlugEl && formSlugEl.tagName === 'SELECT') {

        formSlugEl.addEventListener('change', async function () {

            window.REGISTRATION_FORM_SLUG = formSlugEl.value;

            updateRegistrationRoleBanner();

            container.dataset.selected = '[]';

            await loadRegistrationOptions(formSlugEl.value);

            refreshShiftPicker(window.REGISTERED_EVENT_IDS);

        });

    }

}



async function initVenueEventSelection() {

    return initShiftSelection();

}



function populateEventCheckboxes(containerEl, registeredIds, events) {

    const container = containerEl || getShiftPickerList();

    if (container) {

        populateShiftPicker(container, registeredIds, events, getRegistrationOptions());

    }

}



function getShiftPickerPickableCount() {
    if (window.SHIFT_PICKER_READY && typeof window.SHIFT_PICKER_PICKABLE_COUNT === 'number') {
        return window.SHIFT_PICKER_PICKABLE_COUNT;
    }
    const list = document.getElementById('shift-picker-list');
    if (!list) {
        return 0;
    }
    return list.querySelectorAll('input[name="event_ids[]"]:not(:disabled)').length;
}

function syncWaitlistRegistrationMode() {
    const modeInput = document.getElementById('registration_mode');
    const joinCb = document.getElementById('join_waiting_list');
    if (!modeInput || !joinCb) {
        return;
    }
    if (joinCb.checked) {
        modeInput.value = 'waitlist';
    } else if (modeInput.value === 'waitlist') {
        modeInput.value = '';
        ensureProfileOnlyRegistrationMode();
    }
}

function ensureProfileOnlyRegistrationMode() {
    const modeInput = document.getElementById('registration_mode');
    const joinCb = document.getElementById('join_waiting_list');
    if (!modeInput || (joinCb && joinCb.checked)) {
        return;
    }
    const list = document.getElementById('shift-picker-list');
    const selected = list
        ? list.querySelectorAll('input[name="event_ids[]"]:checked:not(:disabled)').length
        : 0;
    if (selected === 0) {
        modeInput.value = 'profile_only';
    } else if (modeInput.value === 'profile_only') {
        modeInput.value = '';
    }
    const label = document.getElementById('shift-picker-label');
    if (label) {
        label.classList.remove('form-label--required');
    }
}

function updateWaitlistOffer(openShiftCount, pickableCount) {
    const offer = document.getElementById('waitlist-offer');
    const modeInput = document.getElementById('registration_mode');
    const joinCb = document.getElementById('join_waiting_list');
    const pickable = typeof pickableCount === 'number' ? pickableCount : getShiftPickerPickableCount();
    if (!offer) {
        updateNoShiftsRegisterOffer(pickable);
        return;
    }
    const show = openShiftCount === 0;
    offer.style.display = show ? 'block' : 'none';
    if (modeInput && joinCb && joinCb.checked) {
        modeInput.value = show ? 'waitlist' : '';
    } else if (modeInput && modeInput.value === 'waitlist') {
        modeInput.value = '';
    }
    if (joinCb && !joinCb.checked) {
        updateNoShiftsRegisterOffer(pickable);
    }
    if (joinCb && !joinCb.dataset.bound) {
        joinCb.dataset.bound = '1';
        joinCb.addEventListener('change', function () {
            if (modeInput) {
                if (joinCb.checked) {
                    modeInput.value = 'waitlist';
                } else {
                    modeInput.value = '';
                    updateNoShiftsRegisterOffer(getShiftPickerPickableCount());
                }
            }
            if (window.RegistrationWizard && typeof window.RegistrationWizard.getCurrentStep === 'function') {
                var onShiftStep = window.RegistrationWizard.getCurrentStep() === 2;
                if (onShiftStep && typeof window.RegistrationWizard.updateChrome === 'function') {
                    window.RegistrationWizard.updateChrome();
                }
            }
        });
    }
}

function updateNoShiftsRegisterOffer(pickableCount) {
    const offer = document.getElementById('no-shifts-register-offer');
    const modeInput = document.getElementById('registration_mode');
    const joinCb = document.getElementById('join_waiting_list');
    const label = document.getElementById('shift-picker-label');
    const waitlistChecked = !!(joinCb && joinCb.checked);
    const pickable = typeof pickableCount === 'number' ? pickableCount : getShiftPickerPickableCount();
    const show = pickable === 0 && window.SHIFT_PICKER_READY && !waitlistChecked;

    if (offer) {
        offer.style.display = show ? 'block' : 'none';
        offer.hidden = !show;
    }

    if (modeInput) {
        if (show) {
            modeInput.value = 'profile_only';
        } else if (modeInput.value === 'profile_only') {
            modeInput.value = waitlistChecked ? 'waitlist' : '';
        }
    }

    if (label) {
        label.classList.remove('form-label--required');
    }
}




function getEventsList() {

    return getAllOpenShifts();

}



async function loadEventsFromApi() {

    await loadRegistrationOptions(getFormSlug());

    return getEventsList();

}



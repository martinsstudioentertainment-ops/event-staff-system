/**

 * Event Staff System — Registration shift picker (multi-select checkboxes)

 */



const EVENTS_FALLBACK = [];



let registrationOptions = null;



function getFormSlug() {

    if (typeof window !== 'undefined' && window.REGISTRATION_FORM_SLUG) {

        return String(window.REGISTRATION_FORM_SLUG);

    }



    const formSlugEl = document.getElementById('form_slug');

    if (formSlugEl && formSlugEl.value) {

        return String(formSlugEl.value);

    }



    const hidden = document.querySelector('input[name="form_slug"]');

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

function appendShiftCheckboxContent(textEl, event, venueName, isRegistered) {
    const title = document.createElement('span');
    title.className = 'event-checkbox__title';
    title.textContent = event.name || 'Event';
    textEl.appendChild(title);

    const employer = String(event.mainSecurityCompany || '').trim();
    const employerEl = document.createElement('span');
    employerEl.className = 'event-checkbox__employer' + (employer ? '' : ' event-checkbox__employer--pending');
    employerEl.textContent = employer
        ? ('Working for: ' + employer)
        : 'Working for: to be confirmed (office will update)';
    textEl.appendChild(employerEl);

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
        note.textContent = 'Already registered';
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



function populateShiftPicker(container, registeredIds, events, options) {

    if (!container) {

        return;

    }



    const list     = filterOpenRegistrationShifts(Array.isArray(events) ? events : []);

    const blocked  = (registeredIds || window.REGISTERED_EVENT_IDS || []).map(String);

    const selected = parseSelectedEventIds(container);



    container.innerHTML = '';

    container.classList.remove('shift-picker-list--error');



    if (list.length === 0) {

        container.innerHTML = '<p class="form-hint">No shifts open for registration right now — check back later.</p>';

        updateShiftSelectionSummary(container);

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

        const isRegistered = blocked.includes(id);



        const label = document.createElement('label');
        label.className = 'event-checkbox' + (isRegistered ? ' event-checkbox--registered' : '');



        const input = document.createElement('input');

        input.type = 'checkbox';

        input.name = 'event_ids[]';

        input.value = id;

        input.dataset.sortDate = dayKey;

        input.dataset.venueId = String(event.venueId != null ? event.venueId : '0');



        if (isRegistered) {

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
            isRegistered
        );

        label.appendChild(input);
        label.appendChild(text);

        container.appendChild(label);

    });



    enforceOneShiftPerDayOnLoad(container);

    persistShiftSelection(container);

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

}



function refreshShiftPicker(registeredIds) {

    const container = getShiftPickerList();

    if (!container) {

        return;

    }



    const options = getRegistrationOptions();

    populateShiftPicker(container, registeredIds, getAllOpenShifts(options), options);

}



function setRegisteredEventIds(ids) {

    window.REGISTERED_EVENT_IDS = Array.isArray(ids) ? ids.map(String) : [];

    refreshShiftPicker(window.REGISTERED_EVENT_IDS);

}



async function initShiftSelection() {

    const container  = getShiftPickerList();

    const formSlugEl = document.getElementById('form_slug');



    if (!container) {

        return;

    }



    await loadRegistrationOptions(getFormSlug());

    refreshShiftPicker(window.REGISTERED_EVENT_IDS);



    container.addEventListener('change', onShiftCheckboxChange);



    if (formSlugEl && formSlugEl.tagName === 'SELECT') {

        formSlugEl.addEventListener('change', async function () {

            window.REGISTRATION_FORM_SLUG = formSlugEl.value;

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



function refreshEventListForVenue(venueId, registeredIds) {

    refreshShiftPicker(registeredIds);

}



function getEventsList() {

    return getAllOpenShifts();

}



async function loadEventsFromApi() {

    await loadRegistrationOptions(getFormSlug());

    return getEventsList();

}



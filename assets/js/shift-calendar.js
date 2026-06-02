/**
 * Shift picker — date-grouped list, one shift per day (professional roster style).
 */

(function (global) {
    'use strict';

    const state = {
        events: [],
        selected: new Map(),
        blocked: new Set(),
    };

    function formatDayKey(sortDate) {
        return String(sortDate || '').slice(0, 10);
    }

    function formatDateHeading(sortDate) {
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

    function groupByDay(events) {
        const map = {};
        (events || []).forEach(function (event) {
            const key = formatDayKey(event.sortDate);
            if (!key) {
                return;
            }
            if (!map[key]) {
                map[key] = [];
            }
            map[key].push(event);
        });

        return Object.keys(map)
            .sort()
            .map(function (key) {
                map[key].sort(function (a, b) {
                    return String(a.name).localeCompare(String(b.name));
                });
                return { key: key, events: map[key] };
            });
    }

    function syncHiddenInputs(containerEl) {
        const holder = containerEl.querySelector('.shift-picker__inputs');
        if (!holder) {
            return;
        }
        holder.innerHTML = '';
        state.selected.forEach(function (eventId) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'event_ids[]';
            input.value = String(eventId);
            holder.appendChild(input);
        });
        containerEl.dataset.selected = JSON.stringify(Array.from(state.selected.values()));
    }

    function updateFooter(containerEl) {
        const footer = containerEl.querySelector('.shift-picker__footer');
        if (!footer) {
            return;
        }

        const n = state.selected.size;
        if (n === 0) {
            footer.textContent = 'No shifts selected.';
            footer.classList.remove('shift-picker__footer--active');
            return;
        }

        const lines = [];
        state.selected.forEach(function (id) {
            const ev = state.events.find(function (e) { return String(e.id) === String(id); });
            if (ev) {
                lines.push(ev.date + ' — ' + ev.name);
            }
        });
        lines.sort();
        footer.textContent = n === 1
            ? '1 shift: ' + lines[0]
            : n + ' shifts: ' + lines.join(' · ');
        footer.classList.add('shift-picker__footer--active');
    }

    function clearFieldError(containerEl) {
        containerEl.classList.remove('event-list--error');
        const errorEl = document.getElementById('event_ids-error');
        if (errorEl) {
            errorEl.classList.remove('form-error--visible');
            errorEl.textContent = '';
        }
    }

    function selectEvent(containerEl, event) {
        const id = String(event.id);
        const dayKey = formatDayKey(event.sortDate);

        if (state.blocked.has(id)) {
            return;
        }

        if (state.selected.has(id)) {
            state.selected.delete(id);
        } else {
            state.selected.forEach(function (selectedId, key) {
                const other = state.events.find(function (e) {
                    return String(e.id) === String(selectedId);
                });
                if (other && formatDayKey(other.sortDate) === dayKey) {
                    state.selected.delete(key);
                }
            });
            state.selected.set(id, id);
        }

        clearFieldError(containerEl);
        renderList(containerEl);
    }

    function renderList(containerEl) {
        const listEl = containerEl.querySelector('.shift-picker__days');
        if (!listEl) {
            return;
        }

        const groups = groupByDay(state.events);
        listEl.innerHTML = '';

        if (groups.length === 0) {
            listEl.innerHTML = '<p class="form-hint">No shifts available at this venue.</p>';
            return;
        }

        groups.forEach(function (group) {
            const section = document.createElement('section');
            section.className = 'shift-picker__day';

            const heading = document.createElement('h4');
            heading.className = 'shift-picker__date';
            heading.textContent = formatDateHeading(group.key);
            section.appendChild(heading);

            const options = document.createElement('div');
            options.className = 'shift-picker__options';

            if (group.events.length > 1) {
                const note = document.createElement('p');
                note.className = 'shift-picker__day-note';
                note.textContent = 'Choose one shift for this date.';
                section.appendChild(note);
            }

            group.events.forEach(function (event) {
                const id = String(event.id);
                const blocked = state.blocked.has(id);
                const selected = state.selected.has(id);

                const row = document.createElement('button');
                row.type = 'button';
                row.className = 'shift-picker__option';
                if (blocked) {
                    row.classList.add('shift-picker__option--blocked');
                    row.disabled = true;
                } else if (selected) {
                    row.classList.add('shift-picker__option--selected');
                }

                const mark = document.createElement('span');
                mark.className = 'shift-picker__mark';
                mark.setAttribute('aria-hidden', 'true');
                row.appendChild(mark);

                const body = document.createElement('span');
                body.className = 'shift-picker__body';

                const name = document.createElement('span');
                name.className = 'shift-picker__name';
                name.textContent = event.name;
                body.appendChild(name);

                const metaParts = [];
                if (event.workTypeLabel) {
                    metaParts.push(event.workTypeLabel);
                }
                if (event.time) {
                    metaParts.push(event.time);
                }
                if (event.location) {
                    metaParts.push(event.location);
                }
                if (metaParts.length > 0) {
                    const meta = document.createElement('span');
                    meta.className = 'shift-picker__meta';
                    meta.textContent = metaParts.join(' · ');
                    body.appendChild(meta);
                }

                row.appendChild(body);

                if (blocked) {
                    const status = document.createElement('span');
                    status.className = 'shift-picker__status';
                    status.textContent = 'Registered';
                    row.appendChild(status);
                } else if (selected) {
                    const status = document.createElement('span');
                    status.className = 'shift-picker__status shift-picker__status--selected';
                    status.textContent = 'Selected';
                    row.appendChild(status);
                }

                row.addEventListener('click', function () {
                    selectEvent(containerEl, event);
                });

                options.appendChild(row);
            });

            section.appendChild(options);
            listEl.appendChild(section);
        });

        syncHiddenInputs(containerEl);
        updateFooter(containerEl);
    }

    function buildShell(containerEl) {
        containerEl.className = 'event-list shift-calendar-host shift-picker';
        containerEl.innerHTML = [
            '<p class="shift-picker__intro form-hint">Select each shift you want to work. You may only choose <strong>one shift per date</strong>; you can apply for several dates.</p>',
            '<div class="shift-picker__days"></div>',
            '<p class="shift-picker__footer"></p>',
            '<div class="shift-picker__inputs" aria-hidden="true"></div>',
        ].join('');
    }

    function renderShiftCalendar(containerEl, registeredIds, events) {
        if (!containerEl) {
            return;
        }

        if (!containerEl.querySelector('.shift-picker__days')) {
            buildShell(containerEl);
        }

        const list = Array.isArray(events) ? events : [];
        let selectedIds = [];

        try {
            selectedIds = JSON.parse(containerEl.dataset.selected || '[]').map(String);
        } catch (err) {
            selectedIds = [];
        }

        state.events = list;
        state.blocked = new Set((registeredIds || global.REGISTERED_EVENT_IDS || []).map(String));
        state.selected = new Map();

        selectedIds.forEach(function (id) {
            if (!state.blocked.has(id) && list.some(function (e) { return String(e.id) === id; })) {
                state.selected.set(id, id);
            }
        });

        if (list.length === 0) {
            containerEl.innerHTML = '<p class="form-hint">No shifts at this venue for your role yet. Try another venue or check back later.</p>';
            return;
        }

        renderList(containerEl);
    }

    global.renderShiftCalendar = renderShiftCalendar;
})(typeof window !== 'undefined' ? window : globalThis);

/**
 * Registration wizard funnel analytics — beacon to api/registration-analytics-event.php
 * Active when data-wizard-analytics="1" on body (feature_registration_wizard_v2 ON).
 */
(function () {
    'use strict';

    var body = document.body;
    if (!body || body.dataset.wizardAnalytics !== '1') {
        return;
    }

    var sessionId = body.dataset.analyticsSession || '';
    var csrfToken = body.dataset.analyticsCsrf || '';
    var formSlug = body.dataset.analyticsFormSlug || '';
    var endpoint = 'api/registration-analytics-event.php';
    var maxStep = 1;
    var started = false;
    var submitted = false;
    var abandonedSent = false;

    function sendEvent(eventName, extra) {
        if (!sessionId || !csrfToken) {
            return;
        }

        var payload = {
            event: eventName,
            session_id: sessionId,
            csrf_token: csrfToken,
            form_slug: formSlug,
        };

        if (extra) {
            Object.keys(extra).forEach(function (key) {
                payload[key] = extra[key];
            });
        }

        var json = JSON.stringify(payload);

        if (navigator.sendBeacon) {
            try {
                var blob = new Blob([json], { type: 'application/json' });
                navigator.sendBeacon(endpoint, blob);
                return;
            } catch (e) {
                // fall through to fetch
            }
        }

        fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: json,
            keepalive: true,
            credentials: 'same-origin',
        }).catch(function () {});
    }

    function trackStarted() {
        if (started) {
            return;
        }
        started = true;
        sendEvent('registration_started');
        sendEvent('step_reached', { step: 1 });
    }

    function trackStep(step) {
        step = Math.max(1, Math.min(8, parseInt(step, 10) || 1));
        if (step > maxStep) {
            maxStep = step;
        }
        sendEvent('step_reached', { step: step });
    }

    function trackSubmitted() {
        if (submitted) {
            return;
        }
        submitted = true;
        sendEvent('registration_submitted');
    }

    function trackAbandoned() {
        if (submitted || abandonedSent || !started) {
            return;
        }
        abandonedSent = true;
        sendEvent('registration_abandoned', { last_step: maxStep });
    }

    function trackEventSelected(eventId, eventName) {
        if (!eventId) {
            return;
        }
        sendEvent('event_selected', {
            event_id: parseInt(eventId, 10),
            event_name: eventName || '',
        });
    }

    trackStarted();

    var registeredCount = parseInt(body.dataset.registeredCount || '0', 10);
    if (registeredCount > 0) {
        trackSubmitted();
    }

    function trackEvent(eventName, extra) {
        sendEvent(eventName, extra || {});
    }

    window.RegistrationWizardAnalytics = {
        trackStep: trackStep,
        trackSubmitted: trackSubmitted,
        trackEventSelected: trackEventSelected,
        trackEvent: trackEvent,
        getMaxStep: function () { return maxStep; },
        getSessionId: function () { return sessionId; },
    };

    window.addEventListener('beforeunload', trackAbandoned);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') {
            trackAbandoned();
        }
    });

    var form = document.getElementById('registration-form');
    if (form) {
        form.addEventListener('submit', function () {
            trackStep(8);
            trackSubmitted();
        });
    }

    document.addEventListener('change', function (e) {
        var target = e.target;
        if (!target || target.name !== 'event_ids[]') {
            return;
        }
        if (!target.checked) {
            return;
        }
        var row = target.closest('[data-event-id]');
        var eventId = row ? row.getAttribute('data-event-id') : target.value;
        var eventName = row ? (row.getAttribute('data-event-name') || '') : '';
        trackEventSelected(eventId, eventName);
    }, true);
})();

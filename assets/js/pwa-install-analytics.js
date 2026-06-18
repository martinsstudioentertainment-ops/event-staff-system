(function () {
    'use strict';

    var body = document.body;
    if (!body || body.dataset.pwaAnalytics !== '1') {
        return;
    }

    var csrf = body.dataset.pwaAnalyticsCsrf || '';
    var appContext = body.dataset.pwaAppContext || 'staff';
    var staffEmail = body.dataset.staffEmail || '';
    var endpoint = (body.dataset.pwaAnalyticsEndpoint || 'api/pwa-install-event.php');

    function storageKey() {
        return 'pwa_device_id_' + appContext;
    }

    function getVisitorKey() {
        try {
            var existing = localStorage.getItem(storageKey());
            if (existing && existing.length >= 16) {
                return existing;
            }
            var id = '';
            if (window.crypto && window.crypto.getRandomValues) {
                var bytes = new Uint8Array(16);
                window.crypto.getRandomValues(bytes);
                id = Array.from(bytes, function (b) {
                    return ('0' + b.toString(16)).slice(-2);
                }).join('');
            } else {
                id = String(Date.now()) + Math.random().toString(16).slice(2);
            }
            localStorage.setItem(storageKey(), id);
            return id;
        } catch (e) {
            return '';
        }
    }

    function displayMode() {
        if (window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true) {
            return 'standalone';
        }
        return 'browser';
    }

    function sessionFlag(key) {
        try {
            return sessionStorage.getItem(key) === '1';
        } catch (e) {
            return false;
        }
    }

    function setSessionFlag(key) {
        try {
            sessionStorage.setItem(key, '1');
        } catch (e) {
            /* ignore */
        }
    }

    function postEvent(eventName) {
        var visitorKey = getVisitorKey();
        if (!visitorKey || !csrf) {
            return;
        }

        var payload = {
            event: eventName,
            visitor_key: visitorKey,
            app_context: appContext,
            staff_email: staffEmail,
            display_mode: displayMode(),
            user_agent: navigator.userAgent || '',
            csrf_token: csrf
        };

        fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(payload),
            keepalive: true
        }).catch(function () {
            /* ignore */
        });
    }

    function trackPageLoad() {
        var mode = displayMode();
        if (mode === 'standalone') {
            if (!sessionFlag('pwa_standalone_tracked')) {
                setSessionFlag('pwa_standalone_tracked');
                postEvent('standalone_open');
            }
            return;
        }

        if (!sessionFlag('pwa_usage_ping')) {
            setSessionFlag('pwa_usage_ping');
            postEvent('usage_ping');
        }
    }

    window.trackPwaInstallHelpOpen = function () {
        postEvent('install_help_open');
    };

    window.addEventListener('appinstalled', function () {
        postEvent('app_installed');
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', trackPageLoad);
    } else {
        trackPageLoad();
    }
})();

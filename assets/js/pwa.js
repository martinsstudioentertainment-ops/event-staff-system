(function () {
    'use strict';

    if (!('serviceWorker' in navigator)) {
        return;
    }

    var SW_REFRESH_PENDING_KEY = 'olasentra_sw_refresh_pending';
    var reloaded = false;

    function isRegistrationWizardMode() {
        return !!(document.body && document.body.dataset.wizardMode === '1');
    }

    function isRegistrationComplete() {
        if (!document.body) {
            return false;
        }
        if (parseInt(document.body.dataset.registeredCount || '0', 10) > 0) {
            return true;
        }
        if (document.body.dataset.flash === 'success') {
            return true;
        }
        try {
            var params = new URLSearchParams(window.location.search);
            if (params.get('registered')) {
                return true;
            }
        } catch (e) {
            // ignore
        }
        return false;
    }

    function isRegistrationInProgress() {
        if (!isRegistrationWizardMode() || isRegistrationComplete()) {
            return false;
        }
        var form = document.getElementById('registration-form');
        if (form && form.dataset.submitting === '1') {
            return true;
        }
        if (window.RegistrationWizard && typeof window.RegistrationWizard.getCurrentStep === 'function') {
            return window.RegistrationWizard.getCurrentStep() > 1;
        }
        return false;
    }

    function showSwRefreshPrompt() {
        if (document.getElementById('olasentra-sw-update-prompt')) {
            return;
        }
        var el = document.createElement('div');
        el.id = 'olasentra-sw-update-prompt';
        el.className = 'olasentra-sw-update-prompt';
        el.setAttribute('role', 'status');
        el.innerHTML =
            '<div class="olasentra-sw-update-prompt__inner">' +
                '<p class="olasentra-sw-update-prompt__text">' +
                    '<strong>Update available.</strong> A new version of this page is ready. ' +
                    'Refresh when you have finished registration so you do not lose your progress.' +
                '</p>' +
                '<div class="olasentra-sw-update-prompt__actions">' +
                    '<button type="button" class="btn btn--primary btn--small" id="olasentra-sw-update-now">Refresh now</button>' +
                    '<button type="button" class="btn btn--secondary btn--small" id="olasentra-sw-update-later">Later</button>' +
                '</div>' +
            '</div>';
        document.body.appendChild(el);

        document.getElementById('olasentra-sw-update-now').addEventListener('click', function () {
            try {
                sessionStorage.removeItem(SW_REFRESH_PENDING_KEY);
            } catch (err) {
                // ignore
            }
            reloaded = true;
            window.location.reload();
        });
        document.getElementById('olasentra-sw-update-later').addEventListener('click', function () {
            el.hidden = true;
        });
    }

    function maybeShowPendingRefreshPrompt() {
        if (isRegistrationInProgress()) {
            return;
        }
        try {
            if (sessionStorage.getItem(SW_REFRESH_PENDING_KEY) === '1') {
                showSwRefreshPrompt();
            }
        } catch (e) {
            // ignore
        }
    }

    function onControllerChange() {
        if (reloaded) {
            return;
        }
        if (isRegistrationInProgress()) {
            try {
                sessionStorage.setItem(SW_REFRESH_PENDING_KEY, '1');
            } catch (e) {
                // ignore
            }
            showSwRefreshPrompt();
            return;
        }
        reloaded = true;
        window.location.reload();
    }

    window.addEventListener('load', function () {
        maybeShowPendingRefreshPrompt();

        navigator.serviceWorker.register('sw.js').then(function (registration) {
            registration.update();

            if (registration.waiting) {
                registration.waiting.postMessage({ type: 'SKIP_WAITING' });
            }

            registration.addEventListener('updatefound', function () {
                var worker = registration.installing;
                if (!worker) {
                    return;
                }

                worker.addEventListener('statechange', function () {
                    if (worker.state === 'installed' && navigator.serviceWorker.controller) {
                        worker.postMessage({ type: 'SKIP_WAITING' });
                    }
                });
            });
        }).catch(function (err) {
            console.warn('[EventStaff] Service worker registration failed:', err.message);
        });

        navigator.serviceWorker.addEventListener('controllerchange', onControllerChange);
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', maybeShowPendingRefreshPrompt);
    } else {
        maybeShowPendingRefreshPrompt();
    }

    window.OlasentraPwa = {
        isRegistrationInProgress: isRegistrationInProgress,
        maybeShowPendingRefreshPrompt: maybeShowPendingRefreshPrompt,
    };
})();

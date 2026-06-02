(function () {
    'use strict';

    if (window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true) {
        return;
    }

    var deferredPrompt = null;

    function isIosDevice() {
        return /iphone|ipad|ipod/i.test(navigator.userAgent)
            || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    }

    function isAndroidDevice() {
        return /android/i.test(navigator.userAgent);
    }

    function dismissBannerStorage() {
        try {
            localStorage.setItem('pwa_install_dismissed', '1');
        } catch (e) {
            /* ignore */
        }
    }

    function wasBannerDismissed() {
        try {
            return localStorage.getItem('pwa_install_dismissed') === '1';
        } catch (e) {
            return false;
        }
    }

    function removeModal() {
        var existing = document.getElementById('pwa-install-modal');
        if (existing) {
            existing.remove();
        }
        document.body.classList.remove('pwa-install-modal-open');
    }

    function showInstallHelpModal() {
        removeModal();

        var ios = isIosDevice();
        var android = isAndroidDevice();
        var steps;

        if (ios) {
            steps =
                '<ol class="pwa-install-modal__steps">' +
                '<li>Open this page in <strong>Safari</strong> (not Chrome in-app browser).</li>' +
                '<li>Tap the <strong>Share</strong> button <span class="pwa-install-modal__icon">□↑</span> at the bottom of the screen.</li>' +
                '<li>Scroll and tap <strong>Add to Home Screen</strong>.</li>' +
                '<li>Tap <strong>Add</strong> — the app icon appears on your home screen.</li>' +
                '</ol>';
        } else if (android) {
            steps =
                '<ol class="pwa-install-modal__steps">' +
                '<li>Tap the <strong>menu</strong> (⋮) in Chrome.</li>' +
                '<li>Choose <strong>Install app</strong> or <strong>Add to Home screen</strong>.</li>' +
                '<li>Confirm — open the app from your home screen next time.</li>' +
                '</ol>';
        } else {
            steps =
                '<ol class="pwa-install-modal__steps">' +
                '<li>Use your browser menu.</li>' +
                '<li>Look for <strong>Install app</strong> or <strong>Add to Home Screen</strong>.</li>' +
                '</ol>';
        }

        var overlay = document.createElement('div');
        overlay.id = 'pwa-install-modal';
        overlay.className = 'pwa-install-modal';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'pwa-install-modal-title');
        overlay.innerHTML =
            '<div class="pwa-install-modal__backdrop" data-pwa-modal-close></div>' +
            '<div class="pwa-install-modal__panel">' +
            '<button type="button" class="pwa-install-modal__close" data-pwa-modal-close aria-label="Close">×</button>' +
            '<h2 id="pwa-install-modal-title" class="pwa-install-modal__title">Install on your phone</h2>' +
            '<p class="pwa-install-modal__lead">Add this site to your home screen for quick access to registration and check-in.</p>' +
            steps +
            '<div class="pwa-install-modal__actions">' +
            (deferredPrompt
                ? '<button type="button" class="pwa-install-modal__btn pwa-install-modal__btn--primary" data-pwa-install-native>Install now</button>'
                : '') +
            '<button type="button" class="pwa-install-modal__btn pwa-install-modal__btn--ghost" data-pwa-modal-close>Got it</button>' +
            '</div></div>';

        document.body.appendChild(overlay);
        document.body.classList.add('pwa-install-modal-open');

        overlay.querySelectorAll('[data-pwa-modal-close]').forEach(function (el) {
            el.addEventListener('click', removeModal);
        });

        var nativeBtn = overlay.querySelector('[data-pwa-install-native]');
        if (nativeBtn) {
            nativeBtn.addEventListener('click', function () {
                triggerNativeInstall().then(function () {
                    removeModal();
                });
            });
        }

        requestAnimationFrame(function () {
            overlay.classList.add('pwa-install-modal--visible');
        });
    }

    function triggerNativeInstall() {
        if (!deferredPrompt) {
            showInstallHelpModal();
            return Promise.resolve();
        }

        return deferredPrompt.prompt().then(function () {
            return deferredPrompt.userChoice;
        }).then(function () {
            deferredPrompt = null;
            var bar = document.getElementById('pwa-install-banner');
            if (bar) {
                bar.remove();
            }
        }).catch(function () {
            showInstallHelpModal();
        });
    }

    function showBanner() {
        if (wasBannerDismissed()) {
            return;
        }

        var bar = document.getElementById('pwa-install-banner');
        if (!bar) {
            bar = document.createElement('div');
            bar.id = 'pwa-install-banner';
            bar.className = 'pwa-install';
            bar.innerHTML =
                '<div class="pwa-install__inner">' +
                '<div class="pwa-install__text">' +
                '<strong>Install on your phone</strong>' +
                '<span>Quick access to register &amp; check in</span>' +
                '</div>' +
                '<div class="pwa-install__actions">' +
                '<button type="button" class="pwa-install__btn pwa-install__btn--primary" data-pwa-install>How to install</button>' +
                '<button type="button" class="pwa-install__btn pwa-install__btn--ghost" data-pwa-dismiss>Not now</button>' +
                '</div></div>';
            document.body.appendChild(bar);

            bar.querySelector('[data-pwa-install]').addEventListener('click', function (e) {
                e.preventDefault();
                if (deferredPrompt) {
                    triggerNativeInstall();
                } else {
                    showInstallHelpModal();
                }
            });

            bar.querySelector('[data-pwa-dismiss]').addEventListener('click', function () {
                dismissBannerStorage();
                bar.classList.remove('pwa-install--visible');
                setTimeout(function () { bar.remove(); }, 300);
            });
        }

        requestAnimationFrame(function () {
            bar.classList.add('pwa-install--visible');
        });
    }

    window.showPwaInstallHelp = showInstallHelpModal;

    window.addEventListener('beforeinstallprompt', function (event) {
        event.preventDefault();
        deferredPrompt = event;
        showBanner();
    });

    function bindStaffAppInstallButton() {
        var btn = document.getElementById('staff-app-install-btn');
        if (!btn || btn.dataset.pwaBound === '1') {
            return;
        }
        btn.dataset.pwaBound = '1';
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            if (deferredPrompt) {
                triggerNativeInstall();
            } else {
                showInstallHelpModal();
            }
        });
    }

    if (document.body && document.body.dataset.pwaInstall === '1') {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', bindStaffAppInstallButton);
        } else {
            bindStaffAppInstallButton();
        }

        if (!wasBannerDismissed()) {
            setTimeout(function () {
                if (/iphone|ipad|ipod|android/i.test(navigator.userAgent) || deferredPrompt) {
                    showBanner();
                }
            }, 1200);
        }
    }
})();

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

    function isIosInAppBrowser() {
        var ua = navigator.userAgent || '';
        return isIosDevice() && /(FBAN|FBAV|Instagram|Line\/|Twitter|LinkedInApp|Snapchat|WhatsApp)/i.test(ua);
    }

    function isAndroidDevice() {
        return /android/i.test(navigator.userAgent);
    }

    function dismissStorageKey() {
        return isAdminContext() ? 'pwa_install_dismissed_admin' : 'pwa_install_dismissed';
    }

    function dismissBannerStorage() {
        try {
            localStorage.setItem(dismissStorageKey(), '1');
        } catch (e) {
            /* ignore */
        }
    }

    function wasBannerDismissed() {
        try {
            return localStorage.getItem(dismissStorageKey()) === '1';
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

    function isAdminContext() {
        return document.body && document.body.dataset.pwaContext === 'admin';
    }

    function installLeadText() {
        return isAdminContext()
            ? 'Add the admin console to your home screen for quick access to staff, events, and messages.'
            : 'Add this site to your home screen for quick access to registration and check-in.';
    }

    function installBannerTitle() {
        return isAdminContext() ? 'Install admin app' : 'Install on your phone';
    }

    function installBannerSubtitle() {
        return isAdminContext()
            ? 'Manage staff &amp; events from your phone'
            : 'Quick access to register &amp; check in';
    }

    function showInstallHelpModal() {
        removeModal();

        var ios = isIosDevice();
        var android = isAndroidDevice();
        var steps;

        if (ios) {
            var inAppNote = isIosInAppBrowser()
                ? '<p class="pwa-install-modal__note"><strong>Tip:</strong> Open this page in <strong>Safari</strong> first (tap ⋯ → Open in Safari). In-app browsers cannot install apps.</p>'
                : '';
            steps =
                '<ol class="pwa-install-modal__steps">' +
                '<li>Open this page in <strong>Safari</strong> (recommended) or Chrome on iPhone.</li>' +
                '<li>Tap the <strong>Share</strong> button <span class="pwa-install-modal__icon">□↑</span> at the bottom of Safari.</li>' +
                '<li>Scroll and tap <strong>Add to Home Screen</strong>.</li>' +
                '<li>Tap <strong>Add</strong> — the app icon appears on your home screen.</li>' +
                '</ol>' + inAppNote;
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
            '<h2 id="pwa-install-modal-title" class="pwa-install-modal__title">' + installBannerTitle() + '</h2>' +
            '<p class="pwa-install-modal__lead">' + installLeadText() + '</p>' +
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

        if (typeof window.trackPwaInstallHelpOpen === 'function') {
            window.trackPwaInstallHelpOpen();
        }
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
                '<strong>' + installBannerTitle() + '</strong>' +
                '<span>' + installBannerSubtitle() + '</span>' +
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

    function bindInstallButton(buttonId) {
        var btn = document.getElementById(buttonId);
        if (!btn || btn.dataset.pwaBound === '1') {
            return;
        }
        btn.dataset.pwaBound = '1';
        var tapLock = false;

        function handleInstallTap(e) {
            if (tapLock) {
                return;
            }
            tapLock = true;
            setTimeout(function () { tapLock = false; }, 500);
            if (e.type === 'touchend') {
                e.preventDefault();
            }
            e.stopPropagation();
            if (deferredPrompt) {
                triggerNativeInstall();
            } else {
                showInstallHelpModal();
            }
        }

        btn.addEventListener('click', handleInstallTap);
        if (isIosDevice()) {
            btn.addEventListener('touchend', handleInstallTap, { passive: false });
        }
    }

    function bindStaffAppInstallButton() {
        bindInstallButton('staff-app-install-btn');
    }

    function bindAdminInstallButton() {
        bindInstallButton('admin-app-install-btn');
    }

    if (document.body && document.body.dataset.pwaInstall === '1') {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () {
                bindStaffAppInstallButton();
                bindAdminInstallButton();
            });
        } else {
            bindStaffAppInstallButton();
            bindAdminInstallButton();
        }

        if (!wasBannerDismissed() && !document.body.classList.contains('staff-app-shell--guest')) {
            setTimeout(function () {
                if (/iphone|ipad|ipod|android/i.test(navigator.userAgent) || deferredPrompt) {
                    showBanner();
                }
            }, isAdminContext() ? 2000 : 1200);
        }
    }
})();

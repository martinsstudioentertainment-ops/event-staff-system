(function () {
    'use strict';

    if (window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true) {
        return;
    }

    if (localStorage.getItem('pwa_install_dismissed') === '1') {
        return;
    }

    var deferredPrompt = null;

    window.addEventListener('beforeinstallprompt', function (event) {
        event.preventDefault();
        deferredPrompt = event;
        showBanner();
    });

    function showBanner() {
        if (document.getElementById('pwa-install-banner')) {
            document.getElementById('pwa-install-banner').classList.add('pwa-install--visible');
            return;
        }

        var bar = document.createElement('div');
        bar.id = 'pwa-install-banner';
        bar.className = 'pwa-install';
        bar.innerHTML =
            '<div class="pwa-install__inner">' +
            '<div class="pwa-install__text"><strong>Install app</strong>Add to your home screen for quick check-in.</div>' +
            '<div class="pwa-install__actions">' +
            '<button type="button" class="pwa-install__btn pwa-install__btn--primary" data-pwa-install>Install</button>' +
            '<button type="button" class="pwa-install__btn pwa-install__btn--ghost" data-pwa-dismiss>Not now</button>' +
            '</div></div>';

        document.body.appendChild(bar);

        requestAnimationFrame(function () {
            bar.classList.add('pwa-install--visible');
        });

        bar.querySelector('[data-pwa-install]').addEventListener('click', function () {
            if (!deferredPrompt) {
                alert('Use your browser menu: Add to Home Screen / Install app.');
                return;
            }
            deferredPrompt.prompt();
            deferredPrompt.userChoice.then(function () {
                deferredPrompt = null;
                bar.remove();
            });
        });

        bar.querySelector('[data-pwa-dismiss]').addEventListener('click', function () {
            localStorage.setItem('pwa_install_dismissed', '1');
            bar.classList.remove('pwa-install--visible');
            setTimeout(function () { bar.remove(); }, 300);
        });
    }

    if (document.body && document.body.dataset.pwaInstall === '1') {
        setTimeout(function () {
            if (!deferredPrompt && /iphone|ipad|ipod|android/i.test(navigator.userAgent)) {
                showBanner();
            }
        }, 1500);
    }
})();

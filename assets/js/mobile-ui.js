/* =========================================
   STAFF MOBILE UI LAYER
========================================= */

document.addEventListener('DOMContentLoaded', () => {

    initMobileInstall();
    initTouchFeedback();
    fixInstallPopup();

});

/* =========================================
   INSTALL BUTTON
========================================= */

let deferredInstallPrompt = null;

window.addEventListener('beforeinstallprompt', (event) => {

    event.preventDefault();

    deferredInstallPrompt = event;

});

function initMobileInstall(){

    const installBtn =
        document.getElementById('mobile-install-trigger');

    const heroInstallBtn =
        document.getElementById('staff-app-install-btn');

    if (installBtn) {

        installBtn.addEventListener(
            'click',
            triggerInstallPrompt
        );
    }

    if (heroInstallBtn) {

        heroInstallBtn.addEventListener(
            'click',
            triggerInstallPrompt
        );
    }

}

async function triggerInstallPrompt(){

    if (navigator.vibrate) {
        navigator.vibrate(25);
    }

    if (!deferredInstallPrompt) {

        showInstallHelp();

        return;
    }

    deferredInstallPrompt.prompt();

    await deferredInstallPrompt.userChoice;

    deferredInstallPrompt = null;

}

/* =========================================
   INSTALL HELP
========================================= */

function showInstallHelp(){

    const isiPhone =
        /iphone|ipad|ipod/i.test(navigator.userAgent);

    let message = '';

    if (isiPhone) {

        message =
            'To install this app on iPhone:\n\n' +
            '1. Tap Share\n' +
            '2. Tap Add to Home Screen\n' +
            '3. Tap Add';

    } else {

        message =
            'To install this app:\n\n' +
            '1. Open browser menu\n' +
            '2. Tap Install App\n' +
            '3. Confirm install';

    }

    alert(message);

}

/* =========================================
   TOUCH FEEDBACK
========================================= */

function initTouchFeedback(){

    const buttons = document.querySelectorAll(
        '.staff-app__tile, .mobile-bottom-nav__item, button'
    );

    buttons.forEach(button => {

        button.addEventListener('touchstart', () => {

            button.style.transform = 'scale(0.98)';

        });

        button.addEventListener('touchend', () => {

            setTimeout(() => {

                button.style.transform = '';

            }, 120);

        });

    });

}

/* =========================================
   FIX INSTALL POPUP POSITION
========================================= */

function fixInstallPopup(){

    setTimeout(() => {

        const selectors = [

            '.pwa-install-modal',
            '.pwa-install-sheet',
            '.pwa-install-dialog',
            '.pwa-install-card'

        ];

        selectors.forEach(selector => {

            document.querySelectorAll(selector)
                .forEach(element => {

                    element.style.zIndex = '999999';

                    element.style.bottom = '120px';

                    element.style.position = 'fixed';

                });

        });

    }, 800);

}
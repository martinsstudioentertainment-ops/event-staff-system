(function () {
    'use strict';

    var body = document.body;
    if (!body) {
        return;
    }

    var timeoutSec = parseInt(body.getAttribute('data-session-idle-timeout') || '0', 10);
    if (!(timeoutSec > 0)) {
        return;
    }

    var signoutUrl = body.getAttribute('data-session-signout-url') || '';
    if (!signoutUrl) {
        return;
    }

    var idleMs = timeoutSec * 1000;
    var timer = null;

    function resetIdleTimer() {
        if (timer !== null) {
            clearTimeout(timer);
        }
        timer = setTimeout(function () {
            var sep = signoutUrl.indexOf('?') >= 0 ? '&' : '?';
            window.location.href = signoutUrl + (signoutUrl.indexOf('reason=') >= 0 ? '' : sep + 'reason=idle');
        }, idleMs);
    }

    ['mousedown', 'mousemove', 'keydown', 'scroll', 'touchstart', 'click'].forEach(function (eventName) {
        document.addEventListener(eventName, resetIdleTimer, { passive: true });
    });

    resetIdleTimer();
})();

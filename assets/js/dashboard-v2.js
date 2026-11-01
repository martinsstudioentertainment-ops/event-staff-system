(function () {
    'use strict';

    function animateCount(el, target, duration) {
        if (el.textContent.indexOf('€') !== -1 || el.textContent.indexOf('£') !== -1 || el.textContent.indexOf('$') !== -1) {
            return;
        }

        var start = 0;
        var startTime = null;
        el.classList.add('odv3-counting');

        function step(ts) {
            if (!startTime) {
                startTime = ts;
            }
            var progress = Math.min((ts - startTime) / duration, 1);
            var eased = 1 - Math.pow(1 - progress, 3);
            el.textContent = String(Math.round(start + (target - start) * eased));
            if (progress < 1) {
                window.requestAnimationFrame(step);
            } else {
                el.classList.remove('odv3-counting');
            }
        }

        window.requestAnimationFrame(step);
    }

    document.querySelectorAll('[data-odv3-count]').forEach(function (el) {
        var target = parseInt(el.getAttribute('data-odv3-count'), 10);
        if (!Number.isNaN(target)) {
            animateCount(el, target, 650);
        }
    });

    var clock = document.getElementById('dash-live-clock');
    if (clock) {
        setInterval(function () {
            var now = new Date();
            clock.textContent = now.toLocaleString(undefined, {
                weekday: 'short',
                day: 'numeric',
                month: 'short',
                hour: '2-digit',
                minute: '2-digit'
            }).replace(',', ' ·');
        }, 30000);
    }
})();

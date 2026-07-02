(function () {
    'use strict';

    if (!document.body || document.body.dataset.staffAppV2 !== '1') {
        return;
    }

    function scrollToCheckin(hash, smooth) {
        if (!hash || hash.indexOf('checkin') === -1) {
            return;
        }
        var el = document.getElementById('staff-v2-checkin');
        if (!el) {
            return;
        }
        el.scrollIntoView({ behavior: smooth ? 'smooth' : 'auto', block: 'center' });
        el.classList.add('staff-v2__widget--pulse');
        setTimeout(function () {
            el.classList.remove('staff-v2__widget--pulse');
        }, 1200);
    }

    document.querySelectorAll('.staff-v2__nav-item[href^="#"]').forEach(function (link) {
        link.addEventListener('click', function (e) {
            var href = link.getAttribute('href') || '';
            if (href.charAt(0) !== '#') {
                return;
            }
            e.preventDefault();
            scrollToCheckin(href.slice(1), true);
            if (history.replaceState) {
                history.replaceState(null, '', href);
            }
        });
    });

    if (window.location.hash) {
        scrollToCheckin(window.location.hash.replace('#', ''), false);
    }

    window.addEventListener('hashchange', function () {
        scrollToCheckin((window.location.hash || '').replace('#', ''), true);
    });

    var style = document.createElement('style');
    style.textContent = '.staff-v2__widget--pulse{box-shadow:0 0 0 3px rgba(236,72,153,0.45),0 14px 36px rgba(15,23,42,0.14)!important;}';
    document.head.appendChild(style);
})();

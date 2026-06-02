/**
 * Scrolling notice — one copy in HTML; JS clones for seamless loop.
 */
(function () {
    'use strict';

    function initNotice(notice) {
        if (notice.classList.contains('site-notice--static')) {
            return;
        }

        var track = notice.querySelector('.site-notice__track');
        var group = track && track.querySelector('.site-notice__group');
        if (!track || !group) {
            return;
        }

        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            notice.classList.add('site-notice--reduced');
            return;
        }

        var clone = group.cloneNode(true);
        clone.setAttribute('aria-hidden', 'true');
        track.appendChild(clone);

        requestAnimationFrame(function () {
            var loopWidth = track.scrollWidth / 2;
            var seconds = Math.min(90, Math.max(28, loopWidth / 45));
            track.style.setProperty('--notice-duration', seconds + 's');
            track.classList.add('site-notice__track--loop');
        });
    }

    document.querySelectorAll('[data-site-notice]').forEach(initNotice);
})();

(function () {
    'use strict';

    function animateCount(el, target, suffix, duration) {
        if (target <= 0) {
            el.textContent = '0' + (suffix || '');
            return;
        }

        var start = 0;
        var startTime = null;

        function step(timestamp) {
            if (!startTime) {
                startTime = timestamp;
            }
            var progress = Math.min((timestamp - startTime) / duration, 1);
            var eased = 1 - Math.pow(1 - progress, 3);
            var current = Math.floor(start + (target - start) * eased);
            el.textContent = String(current) + (suffix || '');
            if (progress < 1) {
                window.requestAnimationFrame(step);
            }
        }

        window.requestAnimationFrame(step);
    }

    function initCounters() {
        var nodes = document.querySelectorAll('[data-hp-count]');
        if (!nodes.length) {
            return;
        }

        var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        function run(el) {
            var target = parseInt(el.getAttribute('data-hp-count'), 10);
            if (Number.isNaN(target)) {
                return;
            }
            var suffix = el.getAttribute('data-hp-suffix') || '';
            if (reduced) {
                el.textContent = String(target) + suffix;
                return;
            }
            animateCount(el, target, suffix, 1400);
        }

        if (!('IntersectionObserver' in window)) {
            nodes.forEach(run);
            return;
        }

        var observer = new IntersectionObserver(function (entries, obs) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) {
                    return;
                }
                run(entry.target);
                obs.unobserve(entry.target);
            });
        }, { threshold: 0.2, rootMargin: '0px 0px -40px 0px' });

        nodes.forEach(function (el) {
            observer.observe(el);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initCounters);
    } else {
        initCounters();
    }
})();

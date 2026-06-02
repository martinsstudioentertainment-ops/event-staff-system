(function () {
    'use strict';

    var btn = document.getElementById('site-menu-btn');
    var nav = document.getElementById('site-nav');

    function lockScroll(locked) {
        document.body.style.overflow = locked ? 'hidden' : '';
    }

    if (btn && nav) {
        btn.addEventListener('click', function () {
            var open = nav.classList.toggle('site-nav--open');
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            lockScroll(open);
        });

        nav.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', function () {
                nav.classList.remove('site-nav--open');
                btn.setAttribute('aria-expanded', 'false');
                lockScroll(false);
            });
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && nav.classList.contains('site-nav--open')) {
                nav.classList.remove('site-nav--open');
                btn.setAttribute('aria-expanded', 'false');
                lockScroll(false);
            }
        });
    }
})();

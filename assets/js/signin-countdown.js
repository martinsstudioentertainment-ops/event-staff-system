(function () {
    function pad(n) {
        return String(n).padStart(2, '0');
    }

    function formatDuration(ms) {
        if (ms <= 0) {
            return '00:00:00';
        }
        var totalSeconds = Math.floor(ms / 1000);
        var days = Math.floor(totalSeconds / 86400);
        var hours = Math.floor((totalSeconds % 86400) / 3600);
        var minutes = Math.floor((totalSeconds % 3600) / 60);
        var seconds = totalSeconds % 60;
        if (days > 0) {
            return days + 'd ' + pad(hours) + ':' + pad(minutes) + ':' + pad(seconds);
        }
        return pad(hours) + ':' + pad(minutes) + ':' + pad(seconds);
    }

    function updatePhaseBadge(block) {
        var badge = document.querySelector('[data-signin-phase-status]');
        if (!badge) {
            return;
        }

        var status = block.getAttribute('data-status') || 'open';
        var eventEnd = Date.parse(badge.getAttribute('data-event-end-at') || '');
        var now = Date.now();
        var key = status;

        if (status === 'open' && !Number.isNaN(eventEnd) && now > eventEnd) {
            key = 'event_ended';
        }

        var label = badge.getAttribute('data-label-' + key.replace(/_/g, '-')) || badge.textContent;
        badge.textContent = label;
        badge.className = 'signin-page-heading__status signin-page-heading__status--' + key;
    }

    function tick(block) {
        var status = block.getAttribute('data-status') || 'open';
        var opensAt = Date.parse(block.getAttribute('data-opens-at') || '');
        var closesAt = Date.parse(block.getAttribute('data-closes-at') || '');
        var now = Date.now();
        var label = block.querySelector('.signin-countdown__label');
        var timer = block.querySelector('.signin-countdown__timer');

        if (!timer || Number.isNaN(closesAt)) {
            return;
        }

        if (status === 'before' && !Number.isNaN(opensAt)) {
            label.textContent = block.getAttribute('data-label-opens') || 'Sign-in opens in';
            timer.textContent = formatDuration(opensAt - now);
            if (opensAt - now <= 0) {
                block.setAttribute('data-status', 'open');
                status = 'open';
            }
        }

        if (status === 'open') {
            label.textContent = block.getAttribute('data-label-closes') || 'Time remaining before sign-in closes';
            timer.textContent = formatDuration(closesAt - now);
            if (closesAt - now <= 0) {
                block.setAttribute('data-status', 'after');
                label.textContent = block.getAttribute('data-label-closed') || 'Sign-in closed';
                timer.textContent = '00:00:00';
                block.classList.add('signin-countdown--closed');
            }
        }

        if (status === 'after') {
            label.textContent = block.getAttribute('data-label-closed') || 'Sign-in closed';
            timer.textContent = '00:00:00';
            block.classList.add('signin-countdown--closed');
        }

        updatePhaseBadge(block);
    }

    document.querySelectorAll('[data-signin-countdown]').forEach(function (block) {
        tick(block);
        window.setInterval(function () {
            tick(block);
        }, 1000);
    });
})();

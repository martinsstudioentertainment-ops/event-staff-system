(function () {
    'use strict';

    var root = document.getElementById('scan-checkin-root');
    if (!root || typeof Html5Qrcode === 'undefined') {
        return;
    }

    var readerId = 'scan-reader';
    var resultEl = document.getElementById('scan-result');
    var recentWrap = document.getElementById('scan-recent-wrap');
    var recentList = document.getElementById('scan-recent-list');
    var eventId = root.getAttribute('data-event-id') || '0';
    var csrf = root.getAttribute('data-csrf') || '';
    var lastScan = '';
    var busy = false;
    var scanner = new Html5Qrcode(readerId);

    function showResult(type, message, extra) {
        if (!resultEl) {
            return;
        }
        resultEl.hidden = false;
        resultEl.className = 'scan-checkin__result scan-checkin__result--' + type;
        resultEl.innerHTML = '<strong>' + escapeHtml(message) + '</strong>'
            + (extra ? '<div>' + extra + '</div>' : '');
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function addRecent(name, eventLabel, time) {
        if (!recentList || !recentWrap) {
            return;
        }
        recentWrap.hidden = false;
        var li = document.createElement('li');
        li.textContent = name + ' · ' + eventLabel + ' · ' + time;
        recentList.prepend(li);
    }

    function postScan(decodedText) {
        if (busy || decodedText === lastScan) {
            return;
        }
        busy = true;
        lastScan = decodedText;

        var body = new FormData();
        body.append('csrf_token', csrf);
        body.append('scan_data', decodedText);
        body.append('event_id', eventId);

        fetch('scan-checkin-action.php', {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (data.ok) {
                    showResult('success', 'Checked in: ' + data.name, escapeHtml(data.role + ' · ' + data.event));
                    addRecent(data.name, data.event, data.time);
                } else {
                    showResult(data.already ? 'warning' : 'error', data.error || 'Scan failed', data.name ? escapeHtml(data.name) : '');
                }
            })
            .catch(function () {
                showResult('error', 'Network error — try again.');
            })
            .finally(function () {
                setTimeout(function () {
                    busy = false;
                    lastScan = '';
                }, 2500);
            });
    }

    function cameraSetupError() {
        if (window.isSecureContext === false) {
            return 'Camera needs a secure page. Use https://… or open via http://127.0.0.1:8080/admin/scan-checkin.php (not the .test hostname on plain HTTP).';
        }
        return 'Camera access denied or unavailable. Allow camera for this site in browser settings, or use Attendance → manual check-in instead.';
    }

    Html5Qrcode.getCameras().then(function (cameras) {
        if (!cameras || !cameras.length) {
            showResult('error', 'No camera found on this device.');
            return;
        }

        var cameraId = cameras[0].id;
        scanner.start(
            cameraId,
            { fps: 10, qrbox: { width: 260, height: 260 } },
            postScan,
            function () {}
        ).catch(function () {
            showResult('error', 'Unable to start camera. Check browser permissions.');
        });
    }).catch(function () {
        showResult('error', cameraSetupError());
    });
})();

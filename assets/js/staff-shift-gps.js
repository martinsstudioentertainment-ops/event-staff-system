/**
 * Staff app GPS shift monitor — uses phone session cookie, not the venue QR page.
 */
(function () {
    'use strict';

    var body = document.body;
    if (!body || body.getAttribute('data-staff-shift-monitor') !== '1') {
        return;
    }

    var venueLat = parseFloat(body.getAttribute('data-shift-venue-lat') || '');
    var venueLng = parseFloat(body.getAttribute('data-shift-venue-lng') || '');
    var radiusM = parseInt(body.getAttribute('data-shift-radius-m') || '1000', 10);
    var attendanceActive = body.getAttribute('data-shift-active') === '1';
    var preCheckedIn = body.getAttribute('data-shift-pre-check') === '1';
    var eventName = body.getAttribute('data-shift-event-name') || 'your shift';
    var monitorIntervalId = null;
    var watchId = null;
    var signedOut = false;

    function haversineMeters(lat1, lng1, lat2, lng2) {
        var R = 6371000;
        var toRad = function (deg) { return deg * Math.PI / 180; };
        var dLat = toRad(lat2 - lat1);
        var dLng = toRad(lng2 - lng1);
        var a = Math.sin(dLat / 2) * Math.sin(dLat / 2)
            + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2))
            * Math.sin(dLng / 2) * Math.sin(dLng / 2);
        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }

    function updateBanner(message, tone) {
        var banner = document.getElementById('staff-shift-banner');
        if (!banner) {
            return;
        }
        banner.className = 'staff-v2__alert staff-v2__alert--' + (tone || 'info');
        banner.innerHTML = message;
    }

    function stopMonitoring() {
        if (monitorIntervalId !== null) {
            clearInterval(monitorIntervalId);
            monitorIntervalId = null;
        }
        if (watchId !== null && navigator.geolocation && navigator.geolocation.clearWatch) {
            navigator.geolocation.clearWatch(watchId);
            watchId = null;
        }
    }

    function handleSignedOut(data) {
        signedOut = true;
        stopMonitoring();
        body.setAttribute('data-shift-active', '0');
        body.setAttribute('data-staff-shift-monitor', '0');
        updateBanner(
            '<strong>Signed out automatically</strong><br>'
            + ((data && data.message) || 'You left the venue zone. Hours worked have been recorded.'),
            'warning'
        );
    }

    function pingServer(lat, lng, accuracyM) {
        if (signedOut) {
            return;
        }

        var formData = new FormData();
        formData.append('sign_lat', String(lat));
        formData.append('sign_lng', String(lng));
        if (accuracyM != null && isFinite(accuracyM)) {
            formData.append('sign_accuracy_m', String(Math.round(accuracyM)));
        }

        fetch('api/staff-shift-gps.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data) {
                    return;
                }
                if (data.auth_required) {
                    updateBanner(
                        '<strong>Sign in again</strong><br>Open the staff app and sign in with email + last 4 of PPS. Tick “Keep me signed in on this phone”.',
                        'warning'
                    );
                    stopMonitoring();
                    return;
                }
                if (data.signed_out) {
                    handleSignedOut(data);
                    return;
                }
                if (data.activated) {
                    attendanceActive = true;
                    preCheckedIn = false;
                    body.setAttribute('data-shift-active', '1');
                    body.setAttribute('data-shift-pre-check', '0');
                    updateBanner(
                        '<strong>On shift — ' + eventName + '</strong><br>'
                        + 'GPS active inside ' + radiusM + ' m. Leaving the zone signs you out automatically.',
                        'success'
                    );
                    return;
                }
                if (data.outside_warning && data.message) {
                    updateBanner('<strong>Outside venue zone</strong><br>' + data.message, 'warning');
                } else if (attendanceActive && data.in_zone) {
                    updateBanner(
                        '<strong>On shift — ' + eventName + '</strong><br>'
                        + 'Inside venue zone (' + radiusM + ' m). You can use other apps — reopen staff app during shift.',
                        'success'
                    );
                }
            })
            .catch(function () { /* retry on next tick */ });
    }

    function onPosition(pos) {
        var lat = pos.coords.latitude;
        var lng = pos.coords.longitude;
        var acc = pos.coords.accuracy;
        if (!isFinite(lat) || !isFinite(lng)) {
            return;
        }

        var distance = haversineMeters(venueLat, venueLng, lat, lng);
        if (distance > radiusM) {
            pingServer(lat, lng, acc);
            return;
        }

        pingServer(lat, lng, acc);
    }

    function onPositionError() {
        updateBanner(
            '<strong>GPS needed for your shift</strong><br>'
            + 'Allow location for this site in your phone settings, then reopen the staff app.',
            'warning'
        );
    }

    function requestLocation() {
        if (!navigator.geolocation) {
            onPositionError();
            return;
        }

        navigator.geolocation.getCurrentPosition(onPosition, onPositionError, {
            enableHighAccuracy: true,
            timeout: 25000,
            maximumAge: 15000
        });
    }

    function startMonitoring() {
        requestLocation();
        stopMonitoring();
        monitorIntervalId = setInterval(requestLocation, 45000);

        if (navigator.geolocation && navigator.geolocation.watchPosition) {
            watchId = navigator.geolocation.watchPosition(onPosition, function () {}, {
                enableHighAccuracy: true,
                maximumAge: 30000,
                timeout: 30000
            });
        }

        document.addEventListener('visibilitychange', function () {
            if (!document.hidden && !signedOut) {
                requestLocation();
            }
        });
    }

    if (attendanceActive) {
        updateBanner(
            '<strong>On shift — ' + eventName + '</strong><br>'
            + 'GPS tracking active (' + radiusM + ' m zone). You do not need the venue QR page open — stay signed in here.',
            'success'
        );
    } else if (preCheckedIn) {
        updateBanner(
            '<strong>Checked in — ' + eventName + '</strong><br>'
            + 'Keep this staff app on your phone. Attendance activates when the event starts.',
            'info'
        );
    }

    startMonitoring();
})();

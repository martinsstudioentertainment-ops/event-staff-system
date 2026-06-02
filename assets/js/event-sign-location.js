/**
 * Geofenced event sign-in — requires browser location at venue + open time window.
 */
(function () {
    'use strict';

    var body = document.body;
    if (body.dataset.eventSignPage !== 'true') {
        return;
    }

    var venueLat = parseFloat(body.dataset.venueLat || '');
    var venueLng = parseFloat(body.dataset.venueLng || '');
    var radiusM = parseInt(body.dataset.signinRadiusM || '200', 10);
    var timeOpen = body.dataset.timeOpen === '1';
    var venueConfigured = body.dataset.venueConfigured === '1';
    var alreadyDone = body.dataset.alreadyCheckedIn === '1';

    var statusEl = document.getElementById('signin-location-status');
    var emailPanel = document.getElementById('signin-email-panel');
    var staffPanel = document.getElementById('signin-staff-panel');
    var checkinPanel = document.getElementById('signin-checkin-panel');
    var latInput = document.getElementById('sign_lat');
    var lngInput = document.getElementById('sign_lng');

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

    function setStatus(message, type) {
        if (!statusEl) return;
        statusEl.textContent = message;
        statusEl.className = 'alert alert--' + (type || 'warning') + ' alert--visible';
        statusEl.hidden = false;
    }

    function hidePanel(el) {
        if (el) el.hidden = true;
    }

    function showPanel(el) {
        if (el) el.hidden = false;
    }

    function setCoords(lat, lng) {
        if (latInput) latInput.value = String(lat);
        if (lngInput) lngInput.value = String(lng);
        document.querySelectorAll('form[data-requires-location="true"]').forEach(function (form) {
            var fLat = form.querySelector('input[name="sign_lat"]');
            var fLng = form.querySelector('input[name="sign_lng"]');
            if (fLat) fLat.value = String(lat);
            if (fLng) fLng.value = String(lng);
        });
    }

    function evaluateLocation(lat, lng) {
        if (!venueConfigured) {
            setStatus('Sign-in is not active — venue GPS has not been set for this event.', 'error');
            hidePanel(emailPanel);
            hidePanel(staffPanel);
            hidePanel(checkinPanel);
            return false;
        }

        if (!timeOpen) {
            setStatus(body.dataset.timeMessage || 'Check-in is not open at this time.', 'warning');
            hidePanel(emailPanel);
            hidePanel(staffPanel);
            hidePanel(checkinPanel);
            return false;
        }

        var distance = haversineMeters(venueLat, venueLng, lat, lng);
        if (distance > radiusM) {
            setStatus(
                'You must be at the venue to sign in (' + Math.round(distance) + 'm away). '
                + 'Move closer and allow location access.',
                'warning'
            );
            hidePanel(emailPanel);
            hidePanel(staffPanel);
            hidePanel(checkinPanel);
            return false;
        }

        setStatus('Location verified — you are at the venue.', 'success');
        setCoords(lat, lng);
        if (emailPanel) showPanel(emailPanel);
        if (staffPanel && body.dataset.staffReady === '1') showPanel(staffPanel);
        if (checkinPanel && body.dataset.checkinReady === '1') showPanel(checkinPanel);
        return true;
    }

    function requestLocation() {
        if (!navigator.geolocation) {
            setStatus('Your browser does not support location. Use a phone at the venue.', 'error');
            return;
        }

        setStatus('Checking your location…', 'warning');

        navigator.geolocation.getCurrentPosition(
            function (pos) {
                evaluateLocation(pos.coords.latitude, pos.coords.longitude);
            },
            function () {
                setStatus('Location access is required to sign in at the venue. Enable GPS and try again.', 'error');
                hidePanel(emailPanel);
                hidePanel(staffPanel);
                hidePanel(checkinPanel);
            },
            { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 }
        );
    }

    function boot() {
        if (alreadyDone) {
            if (staffPanel) showPanel(staffPanel);
            if (statusEl) statusEl.hidden = true;
            return;
        }

        hidePanel(emailPanel);
        hidePanel(staffPanel);
        hidePanel(checkinPanel);
        requestLocation();
        setInterval(requestLocation, 45000);
    }

    document.querySelectorAll('form[data-requires-location="true"]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            var lat = latInput ? parseFloat(latInput.value) : NaN;
            var lng = lngInput ? parseFloat(lngInput.value) : NaN;
            if (!isFinite(lat) || !isFinite(lng) || !evaluateLocation(lat, lng)) {
                event.preventDefault();
                requestLocation();
            }
        });
    });

    boot();
})();

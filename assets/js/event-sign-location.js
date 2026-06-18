/**
 * Geofenced event sign-in — GPS mandatory when feature_gps_attendance_v2 is ON.
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
    var gpsV2On = body.dataset.gpsV2On === '1';
    var maxAccuracyM = parseInt(body.dataset.maxAccuracyM || '0', 10);
    var preCheckedIn = body.dataset.preCheckedIn === '1';
    var attendanceActive = body.dataset.attendanceActive === '1';
    var autoSignedOut = body.dataset.autoSignedOut === '1';
    var signoutMessage = body.dataset.signoutMessage || '';
    var registrationId = parseInt(body.dataset.registrationId || '0', 10);
    var monitorIntervalId = null;
    var eventId = parseInt(body.dataset.eventId || '0', 10);
    var checkinToken = body.dataset.checkinToken || '';
    var eventToken = body.dataset.eventToken || '';
    var gpsRequiredMsg = body.dataset.gpsRequiredMsg
        || 'Location access is required for attendance. Please enable GPS and allow location permission to continue.';
    var verificationStorageKey = 'olasentra_signin_verify_' + (eventToken || 'event');

    var statusEl = document.getElementById('signin-location-status');
    var emailPanel = document.getElementById('signin-email-panel');
    var staffPanel = document.getElementById('signin-staff-panel');
    var checkinPanel = document.getElementById('signin-checkin-panel');
    var latInput = document.getElementById('sign_lat');
    var lngInput = document.getElementById('sign_lng');
    var accuracyInput = document.getElementById('sign_accuracy_m');
    var locationVerified = false;

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

    function setSubmitButtonsEnabled(enabled) {
        document.querySelectorAll('form[data-requires-location="true"] button[type="submit"]').forEach(function (btn) {
            btn.disabled = !enabled;
            btn.setAttribute('aria-disabled', enabled ? 'false' : 'true');
        });
    }

    function setCoords(lat, lng, accuracyM) {
        if (latInput) latInput.value = String(lat);
        if (lngInput) lngInput.value = String(lng);
        if (accuracyInput && accuracyM != null && isFinite(accuracyM)) {
            accuracyInput.value = String(Math.round(accuracyM));
        }
        document.querySelectorAll('form[data-requires-location="true"]').forEach(function (form) {
            var fLat = form.querySelector('input[name="sign_lat"]');
            var fLng = form.querySelector('input[name="sign_lng"]');
            var fAcc = form.querySelector('input[name="sign_accuracy_m"]');
            if (fLat) fLat.value = String(lat);
            if (fLng) fLng.value = String(lng);
            if (fAcc && accuracyM != null && isFinite(accuracyM)) {
                fAcc.value = String(Math.round(accuracyM));
            }
        });
    }

    function isMonitoringMode() {
        return alreadyDone && !autoSignedOut && gpsV2On && (preCheckedIn || attendanceActive);
    }

    function accuracyAcceptable(accuracyM) {
        if (!gpsV2On || maxAccuracyM <= 0) {
            return true;
        }
        if (accuracyM == null || !isFinite(accuracyM)) {
            return false;
        }
        return accuracyM <= maxAccuracyM;
    }

    function evaluateLocation(lat, lng, accuracyM) {
        locationVerified = false;
        setSubmitButtonsEnabled(false);

        if (!venueConfigured) {
            setStatus('Sign-in is not active — venue GPS has not been set for this event.', 'error');
            hidePanel(emailPanel);
            hidePanel(staffPanel);
            hidePanel(checkinPanel);
            return false;
        }

        if (!timeOpen && !preCheckedIn) {
            setStatus(body.dataset.timeMessage || 'Check-in is not open at this time.', 'warning');
            hidePanel(emailPanel);
            hidePanel(staffPanel);
            hidePanel(checkinPanel);
            return false;
        }

        if (!isFinite(lat) || !isFinite(lng)) {
            setStatus(gpsRequiredMsg, 'error');
            hidePanel(emailPanel);
            hidePanel(staffPanel);
            hidePanel(checkinPanel);
            return false;
        }

        if (gpsV2On && !accuracyAcceptable(accuracyM)) {
            var accText = accuracyM != null && isFinite(accuracyM) ? Math.round(accuracyM) + 'm' : 'unknown';
            setStatus(
                'GPS accuracy is too low (' + accText + '). Open area or stronger signal needed'
                + (maxAccuracyM > 0 ? ' (≤' + maxAccuracyM + 'm).' : '.'),
                'warning'
            );
            hidePanel(emailPanel);
            hidePanel(staffPanel);
            hidePanel(checkinPanel);
            return false;
        }

        var distance = haversineMeters(venueLat, venueLng, lat, lng);
        if (distance > radiusM) {
            if (isMonitoringMode()) {
                setStatus(
                    'Outside venue zone (' + Math.round(distance) + 'm away, limit ' + radiusM + 'm). Return to the venue or you will be signed out automatically.',
                    'warning'
                );
                if (staffPanel) showPanel(staffPanel);
                pingServer(lat, lng, accuracyM);
                return false;
            }

            setStatus(
                'You must be inside the event attendance zone to sign in (' + Math.round(distance) + 'm away, limit ' + radiusM + 'm).',
                'warning'
            );
            hidePanel(emailPanel);
            hidePanel(staffPanel);
            hidePanel(checkinPanel);
            return false;
        }

        locationVerified = true;
        setCoords(lat, lng, accuracyM);
        if (isMonitoringMode()) {
            if (staffPanel) showPanel(staffPanel);
            pingServer(lat, lng, accuracyM);
            return true;
        }

        setStatus('Location verified — you are inside the attendance zone.', 'success');
        setSubmitButtonsEnabled(true);
        logLocationVerification(lat, lng, accuracyM);
        if (emailPanel && !alreadyDone) showPanel(emailPanel);
        if (staffPanel && body.dataset.staffReady === '1') showPanel(staffPanel);
        if (checkinPanel && body.dataset.checkinReady === '1') showPanel(checkinPanel);
        return true;
    }

    function setVerificationId(id) {
        if (!id) return;
        try {
            sessionStorage.setItem(verificationStorageKey, String(id));
        } catch (e) { /* private mode */ }
        document.querySelectorAll('input[name="location_verification_id"]').forEach(function (input) {
            input.value = String(id);
        });
    }

    function logLocationVerification(lat, lng, accuracyM) {
        if (!eventToken) return;

        var formData = new FormData();
        formData.append('e', eventToken);
        formData.append('sign_lat', String(lat));
        formData.append('sign_lng', String(lng));
        if (accuracyM != null && isFinite(accuracyM)) {
            formData.append('sign_accuracy_m', String(Math.round(accuracyM)));
        }

        fetch('api/signin-location-verify.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data && data.ok && data.verification_id) {
                    setVerificationId(data.verification_id);
                }
            })
            .catch(function () { /* non-blocking */ });
    }

    function stopMonitoring() {
        if (monitorIntervalId !== null) {
            clearInterval(monitorIntervalId);
            monitorIntervalId = null;
        }
    }

    function handleSignedOut(data) {
        autoSignedOut = true;
        attendanceActive = false;
        preCheckedIn = false;
        body.dataset.autoSignedOut = '1';
        body.dataset.attendanceActive = '0';
        body.dataset.preCheckedIn = '0';
        stopMonitoring();
        setStatus((data && data.message) || signoutMessage || 'You have been signed out automatically.', 'warning');
    }

    function pingServer(lat, lng, accuracyM) {
        if (!gpsV2On || registrationId <= 0 || eventId <= 0 || !checkinToken) {
            return;
        }
        if (!alreadyDone || autoSignedOut) {
            return;
        }
        if (!preCheckedIn && !attendanceActive) {
            return;
        }

        var formData = new FormData();
        formData.append('registration_id', String(registrationId));
        formData.append('event_id', String(eventId));
        formData.append('checkin_token', checkinToken);
        formData.append('sign_lat', String(lat));
        formData.append('sign_lng', String(lng));
        if (accuracyM != null && isFinite(accuracyM)) {
            formData.append('sign_accuracy_m', String(Math.round(accuracyM)));
        }

        fetch('api/attendance-gps-ping.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data) {
                    return;
                }
                if (data.signed_out) {
                    handleSignedOut(data);
                    return;
                }
                if (data.activated) {
                    setStatus(
                        'Attendance is now active. Stay inside the venue zone (' + radiusM + ' m) — leaving signs you out automatically.',
                        'success'
                    );
                    body.dataset.preCheckedIn = '0';
                    body.dataset.attendanceActive = '1';
                    preCheckedIn = false;
                    attendanceActive = true;
                    return;
                }
                if (data.outside_warning && data.message) {
                    setStatus(data.message, 'warning');
                } else if (attendanceActive && data.in_zone) {
                    setStatus(
                        'On shift — inside venue zone (' + radiusM + ' m). You will be signed out automatically if you leave.',
                        'success'
                    );
                }
            })
            .catch(function () { /* silent — next poll retries */ });
    }

    function requestLocation() {
        if (!navigator.geolocation) {
            setStatus(gpsRequiredMsg, 'error');
            setSubmitButtonsEnabled(false);
            return;
        }

        if (!alreadyDone && !preCheckedIn) {
            setStatus('Checking your location…', 'warning');
        }

        navigator.geolocation.getCurrentPosition(
            function (pos) {
                var ok = evaluateLocation(pos.coords.latitude, pos.coords.longitude, pos.coords.accuracy);
                if (ok) {
                    pingServer(pos.coords.latitude, pos.coords.longitude, pos.coords.accuracy);
                }
            },
            function () {
                setStatus(gpsRequiredMsg, 'error');
                hidePanel(emailPanel);
                hidePanel(staffPanel);
                hidePanel(checkinPanel);
                setSubmitButtonsEnabled(false);
            },
            { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 }
        );
    }

    function startMonitoring(initialMessage, initialType) {
        if (staffPanel) showPanel(staffPanel);
        if (initialMessage) {
            setStatus(initialMessage, initialType || 'success');
        }
        requestLocation();
        stopMonitoring();
        monitorIntervalId = setInterval(requestLocation, 45000);
    }

    function boot() {
        setSubmitButtonsEnabled(false);
        hidePanel(emailPanel);
        hidePanel(staffPanel);
        hidePanel(checkinPanel);
        if (!alreadyDone && !preCheckedIn && venueConfigured && timeOpen) {
            setStatus('Checking your location… Allow GPS access on your phone to continue.', 'warning');
        }
        try {
            var storedId = sessionStorage.getItem(verificationStorageKey);
            if (storedId) setVerificationId(storedId);
        } catch (e) { /* ignore */ }

        if (autoSignedOut) {
            if (staffPanel) showPanel(staffPanel);
            setStatus(signoutMessage || 'You have been signed out automatically.', 'warning');
            return;
        }

        if (alreadyDone && attendanceActive && gpsV2On) {
            startMonitoring(
                'On shift — stay inside the venue zone (' + radiusM + ' m). Leaving signs you out automatically.',
                'success'
            );
            return;
        }

        if (alreadyDone && preCheckedIn && gpsV2On) {
            startMonitoring(
                'You are checked in. Attendance activates when the event starts — keep this page open and stay in the venue zone.',
                'success'
            );
            return;
        }

        if (alreadyDone && !preCheckedIn && !attendanceActive) {
            if (staffPanel) showPanel(staffPanel);
            if (statusEl) statusEl.hidden = true;
            return;
        }

        hidePanel(emailPanel);
        hidePanel(staffPanel);
        hidePanel(checkinPanel);
        requestLocation();
        stopMonitoring();
        monitorIntervalId = setInterval(requestLocation, 45000);
    }

    document.querySelectorAll('form[data-requires-location="true"]').forEach(function (form) {
        var submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.setAttribute('aria-disabled', 'true');
        }
        form.addEventListener('submit', function (event) {
            if (!locationVerified) {
                event.preventDefault();
                setStatus(gpsRequiredMsg, 'error');
                requestLocation();
                return;
            }
            var lat = latInput ? parseFloat(latInput.value) : NaN;
            var lng = lngInput ? parseFloat(lngInput.value) : NaN;
            var acc = accuracyInput ? parseFloat(accuracyInput.value) : null;
            if (!isFinite(lat) || !isFinite(lng) || !evaluateLocation(lat, lng, acc)) {
                event.preventDefault();
                setStatus(gpsRequiredMsg, 'error');
                requestLocation();
            }
        });
    });

    boot();
})();

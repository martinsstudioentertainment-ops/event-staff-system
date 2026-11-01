/**
 * Admin event form — Eircode lookup + venue GPS pin (sign-in radius from events.signin_radius_m).
 */
(function () {
    'use strict';

    var mapEl = document.getElementById('event-venue-map');
    if (!mapEl) {
        return;
    }

    var apiKey = window.GOOGLE_MAPS_API_KEY || '';
    var geocodeUrl = (mapEl.dataset.geocodeUrl || 'geocode-eircode.php').trim();
    var reverseGeocodeUrl = (mapEl.dataset.reverseGeocodeUrl || 'reverse-geocode.php').trim();
    var mapsEnabled = mapEl.dataset.mapsEnabled === '1' && apiKey !== '';
    var defaultLat = 53.3498;
    var defaultLng = -6.2603;
    var map = null;
    var marker = null;
    var geocoder = null;
    var locatingWatchId = null;
    var locateToken = 0;

    function fields() {
        return {
            lat: document.getElementById('venue_lat'),
            lng: document.getElementById('venue_lng'),
            latManual: document.getElementById('venue_lat_manual'),
            lngManual: document.getElementById('venue_lng_manual'),
            location: document.getElementById('location'),
            search: document.getElementById('venue_search'),
            eircode: document.getElementById('venue_eircode'),
            eircodeBtn: document.getElementById('venue_eircode_lookup'),
            myLocationBtn: document.getElementById('venue_use_my_location'),
            gpsStatus: document.getElementById('venue_gps_status')
        };
    }

    function normalizeEircode(value) {
        return String(value || '').trim().replace(/\s+/g, ' ').toUpperCase();
    }

    function isValidEircode(value) {
        return /^[A-Z0-9]{3}\s?[A-Z0-9]{4}$/.test(normalizeEircode(value));
    }

    function setGpsStatus(message, isError) {
        var el = fields().gpsStatus;
        if (!el) return;
        if (!message) {
            el.hidden = true;
            el.textContent = '';
            return;
        }
        el.hidden = false;
        el.textContent = message;
        el.style.color = isError ? '#b91c1c' : '#047857';
    }

    function parseCoords() {
        var f = fields();
        var lat = f.lat && f.lat.value ? parseFloat(f.lat.value) : defaultLat;
        var lng = f.lng && f.lng.value ? parseFloat(f.lng.value) : defaultLng;
        if (!isFinite(lat) || !isFinite(lng)) {
            return { lat: defaultLat, lng: defaultLng };
        }
        return { lat: lat, lng: lng };
    }

    function setCoords(lat, lng) {
        var f = fields();
        if (f.lat) f.lat.value = String(lat);
        if (f.lng) f.lng.value = String(lng);
        if (f.latManual) f.latManual.value = String(lat);
        if (f.lngManual) f.lngManual.value = String(lng);
        setGpsStatus('GPS set: ' + lat.toFixed(6) + ', ' + lng.toFixed(6), false);
    }

    function initMap(forceLat, forceLng) {
        if (!mapEl || typeof google === 'undefined' || !google.maps) {
            return;
        }

        // If an iframe preview replaced the map node, rebuild a clean map host.
        if (mapEl.tagName === 'IFRAME' || mapEl.querySelector('iframe')) {
            mapEl.innerHTML = '';
            map = null;
            marker = null;
        }

        geocoder = new google.maps.Geocoder();
        var coords = (typeof forceLat === 'number' && typeof forceLng === 'number' && isFinite(forceLat) && isFinite(forceLng))
            ? { lat: forceLat, lng: forceLng }
            : parseCoords();
        var hasRealPin = (typeof forceLat === 'number' && isFinite(forceLat))
            || !!(fields().lat && fields().lat.value);

        map = new google.maps.Map(mapEl, {
            center: coords,
            zoom: hasRealPin ? 17 : 7,
            mapTypeControl: false,
            streetViewControl: false
        });

        marker = new google.maps.Marker({
            map: map,
            position: coords,
            draggable: true
        });

        marker.addListener('dragend', function () {
            var pos = marker.getPosition();
            if (!pos) return;
            setCoords(pos.lat(), pos.lng());
            reverseGeocodeCoords(pos.lat(), pos.lng(), true);
        });

        map.addListener('click', function (event) {
            if (!event.latLng) return;
            marker.setPosition(event.latLng);
            setCoords(event.latLng.lat(), event.latLng.lng());
            reverseGeocodeCoords(event.latLng.lat(), event.latLng.lng(), true);
        });
    }

    function extractEircodeFromComponents(components) {
        var found = '';
        (components || []).forEach(function (component) {
            if (!component || !component.types) return;
            if (component.types.indexOf('postal_code') !== -1) {
                var candidate = normalizeEircode(component.long_name || component.short_name || '');
                if (isValidEircode(candidate)) {
                    found = candidate.length === 7
                        ? candidate.slice(0, 3) + ' ' + candidate.slice(3)
                        : candidate;
                }
            }
        });
        return found;
    }

    function applyReverseResult(result, quiet, lockedLat, lockedLng) {
        if (!result || !result.ok) {
            if (!quiet) {
                setGpsStatus((result && result.error) || 'Could not resolve address from GPS.', true);
            }
            return;
        }

        // Always keep the phone GPS pin — never jump back to an old Dublin eircode centroid.
        var lat = (typeof lockedLat === 'number' && isFinite(lockedLat)) ? lockedLat : parseFloat(result.lat);
        var lng = (typeof lockedLng === 'number' && isFinite(lockedLng)) ? lockedLng : parseFloat(result.lng);
        if (!isFinite(lat) || !isFinite(lng)) {
            return;
        }

        applyCoords(lat, lng, result.formatted_address || '');
        ensureInteractiveMap(lat, lng);

        var f = fields();
        var eircode = normalizeEircode(result.eircode || '');
        if (isValidEircode(eircode) && f.eircode) {
            f.eircode.value = eircode.length === 7
                ? eircode.slice(0, 3) + ' ' + eircode.slice(3)
                : eircode;
        } else if (f.eircode && !quiet) {
            // Do not keep a previous venue's Dublin eircode when GPS moved.
            f.eircode.value = '';
        }
        if (f.search && result.formatted_address) {
            f.search.value = result.formatted_address;
        }

        if (result.warning) {
            setGpsStatus(result.warning + ' Pin: ' + lat.toFixed(5) + ', ' + lng.toFixed(5), true);
        } else if (isValidEircode(f.eircode && f.eircode.value)) {
            setGpsStatus('Phone GPS pin set (' + lat.toFixed(5) + ', ' + lng.toFixed(5) + '). Eircode: ' + f.eircode.value, false);
        } else {
            setGpsStatus('Phone GPS pin set (' + lat.toFixed(5) + ', ' + lng.toFixed(5) + '). Eircode optional — leave blank if unknown.', false);
        }
    }

    function reverseGeocodeViaServer(lat, lng, onDone) {
        var url = reverseGeocodeUrl
            + (reverseGeocodeUrl.indexOf('?') >= 0 ? '&' : '?')
            + 'lat=' + encodeURIComponent(String(lat))
            + '&lng=' + encodeURIComponent(String(lng));
        fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' })
            .then(function (response) {
                return response.json().catch(function () {
                    return { ok: false, error: 'Server reverse lookup failed.' };
                });
            })
            .then(function (data) { onDone(data); })
            .catch(function () {
                onDone({ ok: false, error: 'Network error while resolving location.' });
            });
    }

    function reverseGeocodeViaClient(lat, lng, onDone) {
        if (!geocoder) {
            onDone({ ok: false, error: 'Google Maps is required for reverse lookup.' });
            return;
        }
        geocoder.geocode({ location: { lat: lat, lng: lng } }, function (results, status) {
            if (status !== 'OK' || !results || !results[0]) {
                onDone({
                    ok: true,
                    lat: lat,
                    lng: lng,
                    eircode: '',
                    formatted_address: '',
                    warning: 'GPS captured. Could not resolve Eircode automatically.'
                });
                return;
            }
            var eircode = '';
            var formatted = '';
            results.forEach(function (place) {
                if (!formatted && place.formatted_address) {
                    formatted = place.formatted_address;
                }
                if (!eircode) {
                    eircode = extractEircodeFromComponents(place.address_components);
                }
            });
            onDone({
                ok: true,
                lat: lat,
                lng: lng,
                eircode: eircode,
                formatted_address: formatted
            });
        });
    }

    function reverseGeocodeCoords(lat, lng, quiet) {
        reverseGeocodeViaServer(lat, lng, function (serverResult) {
            if (serverResult && serverResult.ok && (serverResult.eircode || serverResult.formatted_address || serverResult.warning)) {
                applyReverseResult(serverResult, quiet, lat, lng);
                return;
            }
            reverseGeocodeViaClient(lat, lng, function (clientResult) {
                applyReverseResult(clientResult, quiet, lat, lng);
            });
        });
    }

    function stopLocatingWatch() {
        if (locatingWatchId !== null && navigator.geolocation) {
            try { navigator.geolocation.clearWatch(locatingWatchId); } catch (e) {}
            locatingWatchId = null;
        }
    }

    function finishFromPhoneGps(lat, lng, accuracyM, token) {
        if (token !== locateToken) return;
        stopLocatingWatch();

        var f = fields();
        // Wipe previous venue eircode so Dublin codes cannot stick / re-geocode.
        if (f.eircode) f.eircode.value = '';
        if (f.search) f.search.value = '';

        setCoords(lat, lng);
        ensureInteractiveMap(lat, lng);

        var accNote = (typeof accuracyM === 'number' && isFinite(accuracyM))
            ? (' (±' + Math.round(accuracyM) + ' m)')
            : '';
        setGpsStatus('Phone GPS locked' + accNote + ': ' + lat.toFixed(6) + ', ' + lng.toFixed(6) + '. Resolving address…', false);

        reverseGeocodeViaServer(lat, lng, function (serverResult) {
            if (token !== locateToken) return;
            if (serverResult && serverResult.ok) {
                if (f.myLocationBtn) f.myLocationBtn.disabled = false;
                applyReverseResult(serverResult, false, lat, lng);
                return;
            }
            reverseGeocodeViaClient(lat, lng, function (clientResult) {
                if (token !== locateToken) return;
                if (f.myLocationBtn) f.myLocationBtn.disabled = false;
                applyReverseResult(clientResult || {
                    ok: true,
                    lat: lat,
                    lng: lng,
                    eircode: '',
                    formatted_address: '',
                    warning: 'Phone GPS pin set. Address lookup unavailable — Eircode left blank.'
                }, false, lat, lng);
            });
        });
    }

    function useMyLocation() {
        var f = fields();
        if (!navigator.geolocation) {
            setGpsStatus('GPS is not available on this device/browser.', true);
            return;
        }
        if (!window.isSecureContext && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
            setGpsStatus('Location needs HTTPS. Open admin over https:// then try again.', true);
            return;
        }

        stopLocatingWatch();
        locateToken += 1;
        var token = locateToken;
        pendingSaveAfterGeocode = false;

        // Clear old Dublin pin/eircode immediately so it cannot win a race.
        if (f.eircode) f.eircode.value = '';
        if (f.lat) f.lat.value = '';
        if (f.lng) f.lng.value = '';
        if (f.latManual) f.latManual.value = '';
        if (f.lngManual) f.lngManual.value = '';

        setGpsStatus('Getting your phone GPS… allow location, stand at the venue entrance.', false);
        if (f.myLocationBtn) f.myLocationBtn.disabled = true;

        var best = null;
        var settled = false;

        function considerFix(pos) {
            if (token !== locateToken || !pos || !pos.coords) return;
            var lat = pos.coords.latitude;
            var lng = pos.coords.longitude;
            var acc = pos.coords.accuracy;
            if (!isFinite(lat) || !isFinite(lng)) return;
            if (!best || (typeof acc === 'number' && acc < (best.accuracy || 99999))) {
                best = { lat: lat, lng: lng, accuracy: acc };
                // Live preview while we wait for a tighter fix.
                setCoords(lat, lng);
                ensureInteractiveMap(lat, lng);
                setGpsStatus('GPS reading… ' + lat.toFixed(5) + ', ' + lng.toFixed(5)
                    + (typeof acc === 'number' ? (' (±' + Math.round(acc) + ' m)') : ''), false);
            }
            // Good enough for venue pin, or we've waited long enough via timeout below.
            if (typeof acc === 'number' && acc <= 80 && !settled) {
                settled = true;
                finishFromPhoneGps(best.lat, best.lng, best.accuracy, token);
            }
        }

        navigator.geolocation.getCurrentPosition(
            function (pos) { considerFix(pos); },
            function () { /* watch below will also try */ },
            { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 }
        );

        try {
            locatingWatchId = navigator.geolocation.watchPosition(
                function (pos) { considerFix(pos); },
                function (err) {
                    if (token !== locateToken) return;
                    if (best && !settled) {
                        settled = true;
                        finishFromPhoneGps(best.lat, best.lng, best.accuracy, token);
                        return;
                    }
                    if (f.myLocationBtn) f.myLocationBtn.disabled = false;
                    stopLocatingWatch();
                    var msg = 'Could not get GPS. Allow location permission and try again.';
                    if (err && err.code === 1) msg = 'Location permission denied. Allow location for admin.olasentra.com, then tap Use my location again.';
                    if (err && err.code === 3) msg = 'Location timed out. Stand outdoors / near a window and try again.';
                    setGpsStatus(msg, true);
                },
                { enableHighAccuracy: true, timeout: 25000, maximumAge: 0 }
            );
        } catch (e) {
            locatingWatchId = null;
        }

        // Hard stop: use best reading we have after 12s.
        setTimeout(function () {
            if (token !== locateToken || settled) return;
            if (best) {
                settled = true;
                finishFromPhoneGps(best.lat, best.lng, best.accuracy, token);
                return;
            }
            if (f.myLocationBtn) f.myLocationBtn.disabled = false;
            stopLocatingWatch();
            setGpsStatus('No GPS yet. Allow location for this site, use your phone (not desktop), and try again.', true);
        }, 12000);
    }

    function showStaticMapPreview(lat, lng) {
        if (!mapEl || !apiKey) {
            return;
        }

        var embedUrl = 'https://www.google.com/maps/embed/v1/view?key=' + encodeURIComponent(apiKey)
            + '&center=' + encodeURIComponent(lat + ',' + lng)
            + '&zoom=17&maptype=roadmap';
        mapEl.innerHTML = '<iframe title="Venue map preview" style="width:100%;height:100%;border:0;" loading="lazy" referrerpolicy="no-referrer-when-downgrade" src="'
            + embedUrl + '"></iframe>';
    }

    function ensureInteractiveMap(lat, lng) {
        if (typeof google !== 'undefined' && google.maps) {
            if (!map) {
                initMap(lat, lng);
            }
            if (map) {
                applyCoords(lat, lng, '');
                return;
            }
        }
        showStaticMapPreview(lat, lng);
    }

    function applyCoords(lat, lng, formattedAddress) {
        setCoords(lat, lng);
        var f = fields();
        if (f.search && formattedAddress) {
            f.search.value = formattedAddress;
        }
        if (marker) {
            marker.setPosition({ lat: lat, lng: lng });
        }
        if (map) {
            map.panTo({ lat: lat, lng: lng });
            map.setZoom(17);
        }
    }

    function applyPlace(place) {
        if (!place || !place.geometry || !place.geometry.location) {
            return;
        }

        var lat = place.geometry.location.lat();
        var lng = place.geometry.location.lng();
        var f = fields();

        applyCoords(lat, lng, place.formatted_address || '');
        if (f.location && place.formatted_address) {
            // Keep venue name short — full address belongs in Eircode/GPS, not location label.
            var shortName = place.name || '';
            f.location.value = shortName !== '' ? shortName : place.formatted_address;
        }
        (place.address_components || []).forEach(function (component) {
            if (component.types.indexOf('postal_code') !== -1 && f.eircode) {
                f.eircode.value = normalizeEircode(component.long_name || component.short_name || '');
            }
        });
        if (f.search && place.formatted_address) {
            f.search.value = place.formatted_address;
        }
    }

    function geocodeEircodeViaServer(eircode, onDone) {
        var url = geocodeUrl + (geocodeUrl.indexOf('?') >= 0 ? '&' : '?') + 'eircode=' + encodeURIComponent(eircode);
        fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' })
            .then(function (response) {
                return response.text().then(function (text) {
                    var data = null;
                    try {
                        data = text ? JSON.parse(text) : null;
                    } catch (error) {
                        data = null;
                    }

                    if (!data || typeof data !== 'object') {
                        if (response.status === 401 || response.status === 403) {
                            return { ok: false, error: 'Session expired — refresh the page and sign in again.' };
                        }
                        return {
                            ok: false,
                            error: 'Server lookup failed (HTTP ' + response.status + '). Refresh and try again.'
                        };
                    }

                    return data;
                });
            })
            .then(function (data) {
                onDone(data);
            })
            .catch(function () {
                onDone({ ok: false, error: 'Network error while looking up Eircode.' });
            });
    }

    function geocodeEircodeViaClient(eircode, onDone) {
        if (!geocoder) {
            onDone({ ok: false, error: 'Google Maps is required to look up GPS from Eircode.' });
            return;
        }

        geocoder.geocode({
            address: eircode + ', Ireland',
            componentRestrictions: { country: 'IE' }
        }, function (results, status) {
            if (status !== 'OK' || !results || !results[0]) {
                onDone({
                    ok: false,
                    error: 'Could not find GPS for that Eircode (Google Maps: ' + status + ').'
                });
                return;
            }

            var place = results[0];
            onDone({
                ok: true,
                lat: place.geometry.location.lat(),
                lng: place.geometry.location.lng(),
                formatted_address: place.formatted_address || '',
                place: place
            });
        });
    }

    function finishGeocodeLookup(eircode, result) {
        if (!result || !result.ok) {
            pendingSaveAfterGeocode = false;
            setGpsStatus((result && result.error) || 'Could not find GPS for that Eircode.', true);
            return;
        }

        if (result.place) {
            applyPlace(result.place);
        } else {
            applyCoords(result.lat, result.lng, result.formatted_address || '');
            ensureInteractiveMap(result.lat, result.lng);
        }

        setGpsStatus('GPS found for ' + eircode + '. Adjust the pin if needed.', false);

        if (pendingSaveAfterGeocode) {
            pendingSaveAfterGeocode = false;
            var form = mapEl.closest('form');
            if (form && fields().lat && fields().lat.value) {
                form.requestSubmit();
            }
        }
    }

    function geocodeEircode() {
        var f = fields();
        if (!f.eircode) return;

        var eircode = normalizeEircode(f.eircode.value);
        f.eircode.value = eircode;

        if (!isValidEircode(eircode)) {
            setGpsStatus('Enter a valid Eircode (e.g. D02 X285).', true);
            return;
        }

        setGpsStatus('Looking up GPS for ' + eircode + '…', false);

        if (f.eircodeBtn) {
            f.eircodeBtn.disabled = true;
        }

        function finishLookup() {
            if (f.eircodeBtn) {
                f.eircodeBtn.disabled = false;
            }
        }

        geocodeEircodeViaServer(eircode, function (serverResult) {
            if (serverResult && serverResult.ok) {
                finishLookup();
                finishGeocodeLookup(eircode, serverResult);
                return;
            }

            geocodeEircodeViaClient(eircode, function (clientResult) {
                finishLookup();
                if (clientResult && clientResult.ok) {
                    finishGeocodeLookup(eircode, clientResult);
                    return;
                }

                pendingSaveAfterGeocode = false;
                var message = (serverResult && serverResult.error)
                    || (clientResult && clientResult.error)
                    || 'Could not find GPS for that Eircode.';
                setGpsStatus(message, true);
            });
        });
    }

    function initAutocomplete() {
        var f = fields();
        if (!f.search || typeof google === 'undefined' || !google.maps.places) {
            return;
        }

        var autocomplete = new google.maps.places.Autocomplete(f.search, {
            componentRestrictions: { country: ['ie', 'gb'] },
            fields: ['formatted_address', 'geometry', 'address_components']
        });

        autocomplete.addListener('place_changed', function () {
            var place = autocomplete.getPlace();
            applyPlace(place);

            (place.address_components || []).forEach(function (component) {
                if (component.types.indexOf('postal_code') !== -1 && fields().eircode) {
                    fields().eircode.value = normalizeEircode(component.long_name || component.short_name || '');
                }
            });
        });
    }

    function bindManualFields() {
        var f = fields();
        if (!f.latManual || !f.lngManual) return;

        function syncManual() {
            var lat = parseFloat(f.latManual.value);
            var lng = parseFloat(f.lngManual.value);
            if (isFinite(lat) && isFinite(lng)) {
                setCoords(lat, lng);
                if (marker) marker.setPosition({ lat: lat, lng: lng });
                if (map) {
                    map.panTo({ lat: lat, lng: lng });
                    map.setZoom(17);
                }
            }
        }

        f.latManual.addEventListener('change', syncManual);
        f.latManual.addEventListener('input', syncManual);
        f.lngManual.addEventListener('change', syncManual);
        f.lngManual.addEventListener('input', syncManual);
    }

    function bindEircodeLookup() {
        var f = fields();
        if (f.eircodeBtn) {
            f.eircodeBtn.addEventListener('click', geocodeEircode);
        }
        if (f.myLocationBtn) {
            f.myLocationBtn.addEventListener('click', useMyLocation);
        }
        if (f.eircode) {
            f.eircode.addEventListener('blur', function () {
                f.eircode.value = normalizeEircode(f.eircode.value);
            });
        }
    }

    var pendingSaveAfterGeocode = false;

    function bindFormSubmit() {
        var form = mapEl.closest('form');
        if (!form) return;

        form.addEventListener('submit', function (event) {
            var submitter = event.submitter;
            if (submitter && submitter.getAttribute('formaction')) {
                return;
            }

            var f = fields();
            if (f.latManual && f.lngManual) {
                var lat = parseFloat(f.latManual.value);
                var lng = parseFloat(f.lngManual.value);
                if (isFinite(lat) && isFinite(lng)) {
                    setCoords(lat, lng);
                }
            }

            if (!f.lat || !f.lng || !f.lat.value || !f.lng.value) {
                if (mapsEnabled && f.eircode && isValidEircode(f.eircode.value)) {
                    event.preventDefault();
                    pendingSaveAfterGeocode = true;
                    geocodeEircode();
                    setGpsStatus('Looking up GPS — will save when ready…', false);
                    return;
                }
                event.preventDefault();
                setGpsStatus('Tap Use my location at the venue (or look up from Eircode) before saving.', true);
            }
        });
    }

    function loadMaps(callback) {
        if (typeof google !== 'undefined' && google.maps) {
            callback(true);
            return;
        }
        if (!apiKey) {
            callback(false);
            return;
        }

        var script = document.createElement('script');
        script.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(apiKey)
            + '&loading=async&libraries=places&callback=initAdminEventVenueMap';
        script.async = true;
        script.defer = true;
        script.onerror = function () {
            callback(false);
        };
        window.initAdminEventVenueMap = function () {
            callback(typeof google !== 'undefined' && !!google.maps);
        };
        document.head.appendChild(script);
    }

    function boot() {
        bindManualFields();
        bindEircodeLookup();
        bindFormSubmit();

        if (!mapsEnabled) {
            setGpsStatus('Add a Google Maps API key in Settings → Security to enable map preview. Eircode lookup still works.', true);
            return;
        }

        document.querySelectorAll('.event-venue-map__placeholder').forEach(function (el) {
            el.remove();
        });

        loadMaps(function (mapsLoaded) {
            if (!mapsLoaded) {
                setGpsStatus(
                    'Interactive map could not load — Eircode lookup will still set GPS and show a map preview.',
                    true
                );
            } else {
                initMap();
                initAutocomplete();
            }

            var f = fields();
            // Never auto-geocode an old eircode on load — that snapped pins back to Dublin.
            // Trust saved lat/lng from the database.
            if (f.lat && f.lat.value && f.lng && f.lng.value) {
                var savedLat = parseFloat(f.lat.value);
                var savedLng = parseFloat(f.lng.value);
                if (isFinite(savedLat) && isFinite(savedLng)) {
                    setGpsStatus('Saved GPS: ' + savedLat.toFixed(6) + ', ' + savedLng.toFixed(6), false);
                    ensureInteractiveMap(savedLat, savedLng);
                }
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();

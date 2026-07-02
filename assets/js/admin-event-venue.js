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
    var mapsEnabled = mapEl.dataset.mapsEnabled === '1' && apiKey !== '';
    var defaultLat = 53.3498;
    var defaultLng = -6.2603;
    var map = null;
    var marker = null;
    var geocoder = null;

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

    function initMap() {
        if (!mapEl || typeof google === 'undefined' || !google.maps) {
            return;
        }

        geocoder = new google.maps.Geocoder();
        var coords = parseCoords();
        map = new google.maps.Map(mapEl, {
            center: coords,
            zoom: fields().lat && fields().lat.value ? 17 : 7,
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
        });

        map.addListener('click', function (event) {
            if (!event.latLng) return;
            marker.setPosition(event.latLng);
            setCoords(event.latLng.lat(), event.latLng.lng());
        });
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
                initMap();
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
                }
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
            if (f.eircode && isValidEircode(f.eircode.value) && f.lat && !f.lat.value) {
                geocodeEircode();
            } else if (f.lat && f.lat.value) {
                setGpsStatus('GPS: ' + f.lat.value + ', ' + f.lng.value, false);
                ensureInteractiveMap(parseFloat(f.lat.value), parseFloat(f.lng.value));
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();

/**
 * Google Maps — location search and before/after map panels on registration form.
 */
(function () {
    'use strict';

    if (document.body.dataset.registrationPage !== 'true') {
        return;
    }

    var apiKey = window.GOOGLE_MAPS_API_KEY || '';
    var mapsEnabled = document.body.dataset.mapsEnabled === 'true' && apiKey !== '';
    var defaultLat = parseFloat(document.body.dataset.defaultLat || '53.3498');
    var defaultLng = parseFloat(document.body.dataset.defaultLng || '-6.2603');
    var mapBefore = null;
    var mapAfter = null;
    var markerBefore = null;
    var markerAfter = null;

    function getLatLngInputs() {
        return {
            lat: document.getElementById('location_lat'),
            lng: document.getElementById('location_lng'),
            address: document.getElementById('full_address'),
            search: document.getElementById('location_search'),
            eircode: document.getElementById('eircode')
        };
    }

    function parseCoords() {
        var fields = getLatLngInputs();
        var lat = fields.lat && fields.lat.value ? parseFloat(fields.lat.value) : defaultLat;
        var lng = fields.lng && fields.lng.value ? parseFloat(fields.lng.value) : defaultLng;
        if (!isFinite(lat) || !isFinite(lng)) {
            return { lat: defaultLat, lng: defaultLng };
        }
        return { lat: lat, lng: lng };
    }

    function setCoords(lat, lng) {
        var fields = getLatLngInputs();
        if (fields.lat) fields.lat.value = String(lat);
        if (fields.lng) fields.lng.value = String(lng);
    }

    function updateMarkers(position) {
        if (mapBefore && markerBefore) {
            markerBefore.setPosition(position);
            mapBefore.panTo(position);
        }
        if (mapAfter && markerAfter) {
            markerAfter.setPosition(position);
            mapAfter.panTo(position);
        }
    }

    function initMapElement(elementId, isAfter) {
        var el = document.getElementById(elementId);
        if (!el || typeof google === 'undefined' || !google.maps) {
            return null;
        }

        var coords = parseCoords();
        var map = new google.maps.Map(el, {
            center: coords,
            zoom: el.dataset.lat ? 15 : 7,
            mapTypeControl: false,
            streetViewControl: false,
            fullscreenControl: true
        });

        var marker = new google.maps.Marker({
            map: map,
            position: coords,
            draggable: true
        });

        marker.addListener('dragend', function () {
            var pos = marker.getPosition();
            if (!pos) return;
            setCoords(pos.lat(), pos.lng());
            if (isAfter && markerBefore) {
                markerBefore.setPosition(pos);
                mapBefore.panTo(pos);
            } else if (!isAfter && markerAfter) {
                markerAfter.setPosition(pos);
                mapAfter.panTo(pos);
            }
        });

        if (elementId === 'registration-map-before') {
            mapBefore = map;
            markerBefore = marker;
        } else if (elementId === 'registration-map-after') {
            mapAfter = map;
            markerAfter = marker;
        }

        return map;
    }

    function extractAddressParts(place) {
        var parts = {
            address: place.formatted_address || '',
            eircode: ''
        };

        (place.address_components || []).forEach(function (component) {
            if (component.types.indexOf('postal_code') !== -1) {
                parts.eircode = component.long_name || component.short_name || '';
            }
        });

        return parts;
    }

    function applyPlace(place) {
        if (!place || !place.geometry || !place.geometry.location) {
            return;
        }

        var lat = place.geometry.location.lat();
        var lng = place.geometry.location.lng();
        var parts = extractAddressParts(place);
        var fields = getLatLngInputs();

        setCoords(lat, lng);
        if (fields.address) fields.address.value = parts.address;
        if (fields.search) fields.search.value = parts.address;
        if (fields.eircode && parts.eircode) fields.eircode.value = parts.eircode;

        var position = { lat: lat, lng: lng };
        updateMarkers(position);
        if (mapBefore) mapBefore.setZoom(15);
        if (mapAfter) mapAfter.setZoom(15);
    }

    function initAutocomplete() {
        var fields = getLatLngInputs();
        if (!fields.search || typeof google === 'undefined' || !google.maps.places) {
            return;
        }

        var autocomplete = new google.maps.places.Autocomplete(fields.search, {
            componentRestrictions: { country: ['ie', 'gb'] },
            fields: ['formatted_address', 'geometry', 'address_components']
        });

        autocomplete.addListener('place_changed', function () {
            applyPlace(autocomplete.getPlace());
        });
    }

    function renderSuccessMap() {
        var el = document.getElementById('registration-map-success');
        if (!el || typeof google === 'undefined' || !google.maps) {
            return;
        }

        var lat = parseFloat(el.dataset.lat || '');
        var lng = parseFloat(el.dataset.lng || '');
        if (!isFinite(lat) || !isFinite(lng)) {
            return;
        }

        var map = new google.maps.Map(el, {
            center: { lat: lat, lng: lng },
            zoom: 15,
            mapTypeControl: false,
            streetViewControl: false
        });

        new google.maps.Marker({ map: map, position: { lat: lat, lng: lng } });
    }

    function bindManualAddressSync() {
        var fields = getLatLngInputs();
        if (!fields.address) return;

        fields.address.addEventListener('change', function () {
            if (fields.search) fields.search.value = fields.address.value;
        });
    }

    function bindFormTypeDropdown() {
        var select = document.getElementById('form_slug');
        var roleInput = document.getElementById('staff_role');
        if (!select || select.tagName !== 'SELECT' || !roleInput) {
            return;
        }

        function syncRole() {
            var option = select.options[select.selectedIndex];
            if (option && option.dataset.role) {
                roleInput.value = option.dataset.role;
            }
        }

        select.addEventListener('change', syncRole);
        syncRole();
    }

    function loadGoogleMaps(callback) {
        if (typeof google !== 'undefined' && google.maps) {
            callback();
            return;
        }

        if (!apiKey) {
            return;
        }

        var script = document.createElement('script');
        script.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(apiKey) + '&libraries=places&callback=initRegistrationMaps';
        script.async = true;
        script.defer = true;
        window.initRegistrationMaps = callback;
        document.head.appendChild(script);
    }

    function boot() {
        bindFormTypeDropdown();
        bindManualAddressSync();

        if (!mapsEnabled) {
            return;
        }

        document.querySelectorAll('.registration-map__placeholder').forEach(function (el) {
            el.remove();
        });
        document.querySelectorAll('.registration-map--placeholder').forEach(function (el) {
            el.classList.remove('registration-map--placeholder');
        });

        loadGoogleMaps(function () {
            initMapElement('registration-map-before', false);
            initMapElement('registration-map-after', true);
            initAutocomplete();
            renderSuccessMap();

            var coords = parseCoords();
            if (getLatLngInputs().lat && getLatLngInputs().lat.value) {
                updateMarkers(coords);
                if (mapBefore) mapBefore.setZoom(15);
                if (mapAfter) mapAfter.setZoom(15);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    document.addEventListener('registrantProfileLoaded', function (event) {
        var detail = event.detail || {};
        if (!isFinite(detail.lat) || !isFinite(detail.lng)) {
            return;
        }

        var position = { lat: detail.lat, lng: detail.lng };
        setCoords(detail.lat, detail.lng);
        updateMarkers(position);
        if (mapBefore) mapBefore.setZoom(15);
        if (mapAfter) mapAfter.setZoom(15);
    });
})();

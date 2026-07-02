(function () {
  'use strict';

  var root = document.body;
  if (!root || root.getAttribute('data-staff-app-v3') !== '1') {
    return;
  }

  /* Offline indicator */
  var offlineEl = document.getElementById('es-v3-offline');
  function setOfflineState() {
    if (!offlineEl) return;
    offlineEl.hidden = navigator.onLine;
  }
  window.addEventListener('online', setOfflineState);
  window.addEventListener('offline', setOfflineState);
  setOfflineState();

  /* Staff app venue check-in (own phone — no QR) */
  var checkinForm = document.getElementById('es-v3-checkin-form');
  var checkinBtn = document.getElementById('es-v3-scanner-btn');
  var latInput = document.getElementById('es-v3-sign-lat');
  var lngInput = document.getElementById('es-v3-sign-lng');
  var accInput = document.getElementById('es-v3-sign-accuracy');
  var gpsEl = document.getElementById('es-v3-gps-status');

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

  function venueCheckinAllowed(lat, lng) {
    if (!checkinForm || checkinForm.getAttribute('data-venue-configured') !== '1') {
      return { ok: true, message: '' };
    }
    var venueLat = parseFloat(checkinForm.getAttribute('data-venue-lat') || '');
    var venueLng = parseFloat(checkinForm.getAttribute('data-venue-lng') || '');
    var radiusM = parseInt(checkinForm.getAttribute('data-signin-radius-m') || '200', 10);
    if (!isFinite(venueLat) || !isFinite(venueLng) || !isFinite(lat) || !isFinite(lng)) {
      return { ok: false, message: 'Location is required to check in at the venue.' };
    }
    var distance = haversineMeters(venueLat, venueLng, lat, lng);
    if (distance > radiusM) {
      return {
        ok: false,
        message: 'You must be at the venue to check in (' + Math.round(distance) + 'm away, limit ' + radiusM + 'm).'
      };
    }
    return { ok: true, message: '' };
  }

  function setStaffCheckinHint(message) {
    if (!checkinBtn) return;
    var hint = checkinBtn.querySelector('.es-v3__scanner-hint');
    if (hint) hint.textContent = message;
  }

  function enableStaffCheckinBtn(lat, lng, accuracy) {
    var venueCheck = venueCheckinAllowed(lat, lng);
    if (!venueCheck.ok) {
      if (latInput) latInput.value = '';
      if (lngInput) lngInput.value = '';
      if (accInput) accInput.value = '';
      if (checkinBtn) checkinBtn.disabled = true;
      setStaffCheckinHint(venueCheck.message);
      if (gpsEl) {
        gpsEl.setAttribute('data-gps-status', 'denied');
        var label = gpsEl.querySelector('.es-v3__gps-label');
        if (label) label.textContent = venueCheck.message;
      }
      return false;
    }
    if (latInput) latInput.value = String(lat);
    if (lngInput) lngInput.value = String(lng);
    if (accInput && accuracy != null) accInput.value = String(Math.round(accuracy));
    if (checkinBtn) {
      checkinBtn.disabled = false;
      setStaffCheckinHint('GPS ready — tap to check in at the venue');
    }
    if (gpsEl) {
      gpsEl.setAttribute('data-gps-status', 'granted');
      var gpsLabel = gpsEl.querySelector('.es-v3__gps-label');
      if (gpsLabel) gpsLabel.textContent = 'GPS ready — inside venue zone';
    }
    return true;
  }

  if (checkinForm && navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(
      function (pos) {
        enableStaffCheckinBtn(pos.coords.latitude, pos.coords.longitude, pos.coords.accuracy);
      },
      function () {
        if (checkinBtn) checkinBtn.disabled = true;
        setStaffCheckinHint('Enable location in phone settings to check in');
        if (gpsEl) {
          gpsEl.setAttribute('data-gps-status', 'denied');
          var label = gpsEl.querySelector('.es-v3__gps-label');
          if (label) label.textContent = 'Enable location for check-in';
        }
      },
      { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 }
    );
    checkinForm.addEventListener('submit', function (e) {
      if (!latInput || !lngInput || latInput.value === '' || lngInput.value === '') {
        e.preventDefault();
        if (checkinBtn) checkinBtn.disabled = true;
        navigator.geolocation.getCurrentPosition(
          function (pos) {
            if (enableStaffCheckinBtn(pos.coords.latitude, pos.coords.longitude, pos.coords.accuracy)) {
              checkinForm.submit();
            }
          },
          function () {
            alert('Location is required to check in at the venue.');
          },
          { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 }
        );
        return;
      }
      var lat = parseFloat(latInput.value);
      var lng = parseFloat(lngInput.value);
      var venueCheck = venueCheckinAllowed(lat, lng);
      if (!venueCheck.ok) {
        e.preventDefault();
        if (checkinBtn) checkinBtn.disabled = true;
        setStaffCheckinHint(venueCheck.message);
        alert(venueCheck.message);
      }
    });
  } else if (checkinForm && checkinBtn) {
    checkinBtn.disabled = true;
    setStaffCheckinHint('Location is not supported on this device');
  }

  /* GPS status on pages without the check-in form */
  if (gpsEl && navigator.geolocation && !checkinForm) {
    var label = gpsEl.querySelector('.es-v3__gps-label');
    navigator.geolocation.getCurrentPosition(
      function () {
        gpsEl.setAttribute('data-gps-status', 'granted');
        if (label) label.textContent = 'GPS ready';
      },
      function () {
        gpsEl.setAttribute('data-gps-status', 'denied');
        if (label) label.textContent = 'Enable location for check-in';
      },
      { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 }
    );
  } else if (gpsEl) {
    gpsEl.setAttribute('data-gps-status', 'denied');
    var lbl = gpsEl.querySelector('.es-v3__gps-label');
    if (lbl) lbl.textContent = 'Location not supported';
  }

  /* PWA install — single v3 banner flow (Phase 10) */
  var pwaBanner = document.getElementById('es-v3-pwa-banner');
  var pwaInstallBtn = document.getElementById('es-v3-pwa-install');
  var pwaDismiss = document.getElementById('es-v3-pwa-dismiss');
  var deferredPrompt = null;
  var PWA_DISMISS_KEY = 'es_v3_pwa_dismiss';

  function isStandalonePwa() {
    return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  }

  function isIosDevice() {
    return /iphone|ipad|ipod/i.test(navigator.userAgent)
      || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  }

  function isMobileDevice() {
    return isIosDevice() || /android/i.test(navigator.userAgent);
  }

  function hidePwaBanner() {
    if (pwaBanner) {
      pwaBanner.hidden = true;
    }
    document.body.classList.remove('es-v3--pwa-banner-open');
  }

  function showPwaBanner() {
    if (!pwaBanner) return;
    if (localStorage.getItem(PWA_DISMISS_KEY)) return;
    if (isStandalonePwa()) return;
    pwaBanner.hidden = false;
    if (document.body.classList.contains('es-v3--login-compact')) {
      document.body.classList.add('es-v3--pwa-banner-open');
    }
  }

  function showV3InstallHelp() {
    var ios = isIosDevice();
    var steps = ios
      ? '1. Tap Share (□↑) in Safari\n2. Tap Add to Home Screen\n3. Tap Add'
      : '1. Open the browser menu (⋮)\n2. Choose Install app or Add to Home screen\n3. Confirm';
    window.alert('Add Olasentra to your home screen:\n\n' + steps);
  }

  function triggerV3Install() {
    if (deferredPrompt) {
      deferredPrompt.prompt();
      deferredPrompt.userChoice.finally(function () {
        deferredPrompt = null;
        hidePwaBanner();
      });
      return;
    }
    showV3InstallHelp();
  }

  if (isStandalonePwa()) {
    hidePwaBanner();
  } else if (isMobileDevice()) {
    setTimeout(showPwaBanner, isIosDevice() ? 1500 : 800);
  }

  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferredPrompt = e;
    showPwaBanner();
  });

  window.addEventListener('appinstalled', function () {
    deferredPrompt = null;
    hidePwaBanner();
  });

  if (pwaInstallBtn) {
    pwaInstallBtn.addEventListener('click', triggerV3Install);
  }

  if (pwaDismiss) {
    pwaDismiss.addEventListener('click', function () {
      localStorage.setItem(PWA_DISMISS_KEY, '1');
      hidePwaBanner();
    });
  }

  /* Check-in success — server renders confirmation card; scroll into view after redirect */
  if (window.location.search.indexOf('checked_in=1') !== -1 || window.location.search.indexOf('done=1') !== -1) {
    var successPanel = document.getElementById('es-v3-checkin-success');
    if (successPanel) {
      successPanel.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
    if (window.history && window.history.replaceState) {
      window.history.replaceState({}, document.title, window.location.pathname);
    }
  }

  /* Stagger animate-in elements */
  var animated = document.querySelectorAll('.es-v3__animate-in');
  animated.forEach(function (el, i) {
    el.style.animationDelay = Math.min(i * 0.06, 0.3) + 's';
  });

  /* Touch feedback on action cards */
  document.querySelectorAll('.es-v3__action-card, .es-v3__shift-card').forEach(function (card) {
    card.addEventListener('touchstart', function () {
      card.style.transition = 'transform 0.1s ease';
    }, { passive: true });
  });

  var signOutBtn = document.getElementById('staff-profile-signout-btn');
  if (signOutBtn) {
    signOutBtn.addEventListener('click', function (e) {
      if (!window.confirm('Sign out of your staff account?')) {
        e.preventDefault();
      }
    });
  }
})();

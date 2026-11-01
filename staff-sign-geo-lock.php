<?php

declare(strict_types=1);

/**
 * Admin Geo Lock — phone only (PIN gate, no web-admin login).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/app-environment.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/staff-sign-geo-lock.php';

if (function_exists('initSecureSession')) {
    initSecureSession();
}

$pdo = getDB();
// Ensure default PIN exists in settings.
staffSignGeoLockPin($pdo);

$apiUrl = 'api/staff-sign-geo-lock.php';
$signUrl = 'sign.php';
$hubUrl = 'staff-sign-hub-app.php?app=1&from_gate=1';
$csrf = csrfToken();
$unlocked = staffSignGeoLockSessionOk();
$radiusMin = defined('EVENT_SIGNIN_RADIUS_MIN_M') ? (int) EVENT_SIGNIN_RADIUS_MIN_M : 50;
$radiusMax = defined('EVENT_SIGNIN_RADIUS_MAX_M') ? (int) EVENT_SIGNIN_RADIUS_MAX_M : 5000;
$radiusDefault = defined('EVENT_SIGNIN_RADIUS_DEFAULT_M') ? (int) EVENT_SIGNIN_RADIUS_DEFAULT_M : 500;

header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Robots-Tag: noindex, nofollow');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex,nofollow">
    <meta name="theme-color" content="#0b1f17">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Geo Lock">
    <meta name="application-name" content="Olasentra Geo Lock">
    <link rel="apple-touch-icon" href="api/pwa-icon.php?size=180">
    <link rel="manifest" href="staff-sign-geo-lock-manifest.php">
    <title>Admin Geo Lock · Olasentra</title>
    <style>
        :root {
            --bg: #0b1f17;
            --card: #122a20;
            --ink: #e8f5ee;
            --muted: #9bb5a8;
            --line: #2a4a3c;
            --accent: #3dd68c;
            --warn: #f0b429;
            --danger: #ff6b6b;
            --ok: #3dd68c;
            --font: "Segoe UI", system-ui, -apple-system, sans-serif;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100dvh;
            font-family: var(--font);
            background: radial-gradient(120% 80% at 50% -10%, #1a3d2e 0%, var(--bg) 55%);
            color: var(--ink);
            padding: 16px;
            padding-bottom: max(24px, env(safe-area-inset-bottom));
        }
        .wrap { max-width: 440px; margin: 0 auto; }
        .kicker { color: var(--accent); font-size: .75rem; letter-spacing: .12em; text-transform: uppercase; margin: 0 0 6px; }
        h1 { font-size: 1.45rem; margin: 0 0 8px; line-height: 1.2; }
        .sub { color: var(--muted); font-size: .92rem; margin: 0 0 18px; line-height: 1.4; }
        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 16px;
            margin-bottom: 14px;
        }
        label { display: block; font-size: .82rem; color: var(--muted); margin-bottom: 6px; }
        input, select {
            width: 100%;
            padding: 12px 14px;
            border-radius: 10px;
            border: 1px solid var(--line);
            background: #0d1c15;
            color: var(--ink);
            font-size: 1rem;
            margin-bottom: 12px;
        }
        input:focus, select:focus { outline: 2px solid var(--accent); border-color: transparent; }
        .btn {
            display: block;
            width: 100%;
            text-align: center;
            padding: 14px 16px;
            border-radius: 12px;
            border: none;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            margin-bottom: 10px;
            text-decoration: none;
        }
        .btn-primary { background: var(--accent); color: #062014; }
        .btn-primary:disabled { opacity: .45; cursor: not-allowed; }
        .btn-ghost { background: transparent; color: var(--ink); border: 1px solid var(--line); }
        .btn-warn { background: var(--warn); color: #2a1a00; }
        .status {
            padding: 10px 12px;
            border-radius: 10px;
            font-size: .9rem;
            margin-bottom: 12px;
            border: 1px solid var(--line);
            display: none;
        }
        .status.show { display: block; }
        .status.ok { border-color: var(--ok); color: #b8f5d4; background: #143528; }
        .status.err { border-color: var(--danger); color: #ffc9c9; background: #3a1a1a; }
        .status.info { border-color: var(--line); color: var(--muted); }
        .meta { font-size: .85rem; color: var(--muted); line-height: 1.45; }
        .meta strong { color: var(--ink); }
        .row { display: flex; gap: 10px; }
        .row > * { flex: 1; }
        .hidden { display: none !important; }
        .nav { display: flex; gap: 10px; margin-top: 8px; }
        .nav a { flex: 1; text-align: center; color: var(--muted); font-size: .85rem; }
        .pin-hint { font-size: .8rem; color: var(--muted); margin-top: -6px; margin-bottom: 12px; }
        .install-card { border-color: var(--accent); }
        .install-card p { margin: 0 0 12px; color: var(--muted); font-size: .9rem; line-height: 1.4; }
        .install-steps { margin: 0 0 12px; padding-left: 1.15rem; color: var(--ink); font-size: .88rem; line-height: 1.45; font-weight: 600; }
    </style>
</head>
<body>
<div class="wrap">
    <p class="kicker">Olasentra · Admin phone</p>
    <h1>Geo Lock</h1>
    <p class="sub">Set the event pin and range from this phone. Staff sign in inside the zone. No web-admin login needed.</p>

    <section id="install-panel" class="card install-card">
        <p><strong>Add Geo Lock to your Home Screen</strong></p>
        <p style="margin-top:-4px">Admin dashboard icon — not the staff app.</p>
        <p style="margin:0 0 8px;font-weight:800;color:var(--accent)">Android</p>
        <ol class="install-steps">
            <li>Tap the green button below.</li>
            <li>If nothing pops up: Chrome menu <strong>⋮</strong> → <strong>Install app</strong> or <strong>Add to Home screen</strong>.</li>
        </ol>
        <p style="margin:0 0 8px;font-weight:800;color:var(--accent)">iPhone</p>
        <ol class="install-steps">
            <li>Open this link in <strong>Safari</strong> (not Chrome / WhatsApp).</li>
            <li>Tap <strong>Share □↑</strong> → <strong>Add to Home Screen</strong> → <strong>Add</strong>.</li>
        </ol>
        <button type="button" class="btn btn-primary" id="btn-install">Add to Home Screen</button>
        <p class="pin-hint" id="install-hint" style="margin-top:8px;margin-bottom:0">Use the steps for your phone above if the button does not show a prompt.</p>
    </section>

    <div id="status" class="status" role="status"></div>

    <section id="pin-panel" class="card <?= $unlocked ? 'hidden' : '' ?>">
        <label for="admin-pin">Admin lock PIN</label>
        <input type="password" id="admin-pin" inputmode="numeric" autocomplete="one-time-code" maxlength="12" placeholder="Enter PIN">
        <p class="pin-hint">Same short PIN as in admin settings (not your web password).</p>
        <button type="button" class="btn btn-primary" id="btn-unlock">Unlock</button>
    </section>

    <section id="lock-panel" class="<?= $unlocked ? '' : 'hidden' ?>">
        <div class="card">
            <label for="event-id">Event</label>
            <select id="event-id">
                <option value="">Loading events…</option>
            </select>
            <p class="meta" id="event-meta"></p>
        </div>

        <div class="card">
            <button type="button" class="btn btn-warn" id="btn-locate">Use my location</button>
            <div class="row">
                <div>
                    <label for="lat">Lat</label>
                    <input type="text" id="lat" readonly placeholder="—">
                </div>
                <div>
                    <label for="lng">Lng</label>
                    <input type="text" id="lng" readonly placeholder="—">
                </div>
            </div>
            <label for="radius">Sign-in range (metres)</label>
            <input type="number" id="radius" min="<?= (int) $radiusMin ?>" max="<?= (int) $radiusMax ?>" step="10" value="<?= (int) $radiusDefault ?>">
            <p class="meta" id="place-meta">Tap Use my location at the venue entrance.</p>
            <button type="button" class="btn btn-primary" id="btn-confirm" disabled>Confirm / Lock geo</button>
            <button type="button" class="btn btn-ghost" id="btn-logout">Lock PIN again</button>
        </div>
    </section>

    <div class="nav">
        <a href="<?= htmlspecialchars($hubUrl, ENT_QUOTES, 'UTF-8') ?>">Staff Sign</a>
        <a href="<?= htmlspecialchars($signUrl, ENT_QUOTES, 'UTF-8') ?>">Install</a>
    </div>
</div>
<script>
(function () {
    const API = <?= json_encode($apiUrl, JSON_UNESCAPED_SLASHES) ?>;
    let csrf = <?= json_encode($csrf) ?>;
    let unlocked = <?= $unlocked ? 'true' : 'false' ?>;
    let eircode = '';
    let address = '';
    const radiusMin = <?= (int) $radiusMin ?>;
    const radiusMax = <?= (int) $radiusMax ?>;

    const statusEl = document.getElementById('status');
    const pinPanel = document.getElementById('pin-panel');
    const lockPanel = document.getElementById('lock-panel');
    const eventSelect = document.getElementById('event-id');
    const eventMeta = document.getElementById('event-meta');
    const latEl = document.getElementById('lat');
    const lngEl = document.getElementById('lng');
    const radiusEl = document.getElementById('radius');
    const placeMeta = document.getElementById('place-meta');
    const btnConfirm = document.getElementById('btn-confirm');

    function showStatus(msg, kind) {
        statusEl.textContent = msg || '';
        statusEl.className = 'status' + (msg ? ' show ' + (kind || 'info') : '');
    }

    function syncConfirm() {
        const hasEvent = !!eventSelect.value;
        const hasGeo = !!latEl.value && !!lngEl.value;
        btnConfirm.disabled = !(hasEvent && hasGeo);
    }

    async function api(action, body) {
        const payload = Object.assign({ action: action, csrf_token: csrf }, body || {});
        const res = await fetch(API, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json().catch(function () { return { ok: false, message: 'Bad response' }; });
        if (data && data.csrf_token) csrf = data.csrf_token;
        return data;
    }

    function setUnlocked(on) {
        unlocked = !!on;
        pinPanel.classList.toggle('hidden', unlocked);
        lockPanel.classList.toggle('hidden', !unlocked);
        if (unlocked) loadEvents();
    }

    async function loadEvents() {
        eventSelect.innerHTML = '<option value="">Loading…</option>';
        const data = await api('events');
        if (!data.ok) {
            showStatus(data.message || 'Could not load events', 'err');
            eventSelect.innerHTML = '<option value="">—</option>';
            return;
        }
        const events = data.events || [];
        if (!events.length) {
            eventSelect.innerHTML = '<option value="">No upcoming events</option>';
            return;
        }
        eventSelect.innerHTML = '<option value="">Select event…</option>' + events.map(function (ev) {
            const locked = ev.geo_locked ? ' · geo set' : '';
            const label = (ev.event_date || '') + ' · ' + (ev.name || ('#' + ev.id)) + locked;
            return '<option value="' + ev.id + '" data-lat="' + (ev.venue_lat || '') + '" data-lng="' + (ev.venue_lng || '') + '" data-radius="' + (ev.signin_radius_m || '') + '" data-loc="' + encodeURIComponent(ev.location || '') + '" data-eircode="' + encodeURIComponent(ev.venue_eircode || '') + '">' + label.replace(/</g, '&lt;') + '</option>';
        }).join('');
        syncConfirm();
    }

    eventSelect.addEventListener('change', function () {
        const opt = eventSelect.selectedOptions[0];
        if (!opt || !opt.value) {
            eventMeta.textContent = '';
            syncConfirm();
            return;
        }
        const loc = decodeURIComponent(opt.getAttribute('data-loc') || '');
        const ec = decodeURIComponent(opt.getAttribute('data-eircode') || '');
        const r = opt.getAttribute('data-radius') || '';
        eventMeta.innerHTML = '<strong>Current:</strong> ' + (loc || 'no address yet') + (ec ? ' · ' + ec : '') +
            (r ? ' · range ' + r + ' m' : '');
        if (r) radiusEl.value = r;
        // Prefill existing pin if already locked (admin can overwrite).
        const plat = opt.getAttribute('data-lat') || '';
        const plng = opt.getAttribute('data-lng') || '';
        if (plat && plng) {
            latEl.value = plat;
            lngEl.value = plng;
            eircode = ec;
            address = loc;
            placeMeta.textContent = 'Existing pin loaded — Use my location to replace, then Confirm.';
        }
        syncConfirm();
    });

    document.getElementById('btn-unlock').addEventListener('click', async function () {
        const pin = (document.getElementById('admin-pin').value || '').trim();
        if (!pin) {
            showStatus('Enter the admin lock PIN.', 'err');
            return;
        }
        showStatus('Checking PIN…', 'info');
        const data = await api('unlock_pin', { pin: pin });
        if (!data.ok) {
            showStatus(data.message || 'Wrong PIN', 'err');
            return;
        }
        showStatus(data.message || 'Unlocked', 'ok');
        document.getElementById('admin-pin').value = '';
        setUnlocked(true);
    });

    document.getElementById('btn-logout').addEventListener('click', async function () {
        await api('logout');
        setUnlocked(false);
        showStatus('PIN locked again on this phone.', 'info');
    });

    document.getElementById('btn-locate').addEventListener('click', function () {
        if (!navigator.geolocation) {
            showStatus('This phone cannot share location.', 'err');
            return;
        }
        showStatus('Getting GPS…', 'info');
        navigator.geolocation.getCurrentPosition(async function (pos) {
            const lat = pos.coords.latitude;
            const lng = pos.coords.longitude;
            latEl.value = String(lat);
            lngEl.value = String(lng);
            syncConfirm();
            placeMeta.textContent = 'GPS accuracy ~' + Math.round(pos.coords.accuracy || 0) + ' m — looking up address…';
            const data = await api('reverse', { lat: lat, lng: lng });
            if (data.ok) {
                eircode = data.eircode || '';
                address = data.formatted_address || '';
                placeMeta.textContent = (address || 'Pin set') + (eircode ? ' · ' + eircode : '') +
                    (data.warning ? ' — ' + data.warning : '');
                showStatus(data.warning || 'Location ready. Set range and Confirm.', data.warning ? 'info' : 'ok');
            } else {
                showStatus(data.message || 'GPS captured; address lookup failed — you can still lock.', 'info');
            }
        }, function (err) {
            showStatus(err && err.message ? err.message : 'Location denied. Allow GPS for this site.', 'err');
        }, { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
    });

    btnConfirm.addEventListener('click', async function () {
        const eventId = parseInt(eventSelect.value || '0', 10);
        const lat = parseFloat(latEl.value);
        const lng = parseFloat(lngEl.value);
        let radius = parseInt(radiusEl.value || '0', 10);
        if (!eventId || isNaN(lat) || isNaN(lng)) {
            showStatus('Pick an event and capture location first.', 'err');
            return;
        }
        if (isNaN(radius) || radius < radiusMin || radius > radiusMax) {
            showStatus('Range must be ' + radiusMin + '–' + radiusMax + ' metres.', 'err');
            return;
        }
        if (!window.confirm('Lock geo for this event?\n\nStaff will only sign in within ' + radius + ' m of this pin.')) {
            return;
        }
        btnConfirm.disabled = true;
        showStatus('Saving…', 'info');
        const data = await api('lock', {
            event_id: eventId,
            lat: lat,
            lng: lng,
            radius_m: radius,
            eircode: eircode,
            address: address
        });
        if (!data.ok) {
            showStatus(data.message || 'Could not lock geo', 'err');
            syncConfirm();
            return;
        }
        const d = data.data || {};
        showStatus((data.message || 'Locked') + ' · ' + (d.event_name || '') + ' · ' + (d.signin_radius_m || radius) + ' m', 'ok');
        placeMeta.innerHTML = '<strong>Locked.</strong> Web admin will show the same pin. Staff can sign in inside the zone.';
        loadEvents();
        syncConfirm();
    });

    radiusEl.addEventListener('input', syncConfirm);

    // —— Home Screen install (admin dashboard) ——
    // Never hide this panel — admin must always see how to add the icon.
    const ua = navigator.userAgent || '';
    const isIOS = /iPhone|iPad|iPod/i.test(ua);
    const btnInstall = document.getElementById('btn-install');
    const installHint = document.getElementById('install-hint');
    let deferredPrompt = null;

    window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault();
        deferredPrompt = e;
        if (installHint) installHint.textContent = 'Ready — tap Add to Home Screen.';
    });

    if (btnInstall) {
        btnInstall.addEventListener('click', async function () {
            if (deferredPrompt) {
                deferredPrompt.prompt();
                try {
                    const choice = await deferredPrompt.userChoice;
                    if (installHint) {
                        installHint.textContent = choice && choice.outcome === 'accepted'
                            ? 'Added. Open Geo Lock from your home screen.'
                            : 'Use Chrome menu ⋮ → Install app / Add to Home screen.';
                    }
                } catch (e) {
                    if (installHint) installHint.textContent = 'Chrome menu ⋮ → Install app / Add to Home screen.';
                }
                deferredPrompt = null;
                return;
            }
            if (installHint) {
                installHint.textContent = isIOS
                    ? 'iPhone: open in Safari → Share → Add to Home Screen.'
                    : 'Android: Chrome menu ⋮ → Install app or Add to Home screen.';
            }
        });
    }

    try {
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('staff-sign-sw.js?v=20260930b', { scope: '/' }).catch(function () {});
        }
    } catch (e) {}

    if (unlocked) loadEvents();
})();
</script>
</body>
</html>

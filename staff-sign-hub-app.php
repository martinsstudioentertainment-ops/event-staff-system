<?php

declare(strict_types=1);

/**
 * Standalone Staff Sign Hub app (no username).
 * Name → PSA → BIB → sign-in (server time). Alarm from event end until self sign-out.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/app-environment.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/date-format.php';

if (function_exists('initSecureSession')) {
    initSecureSession();
}

$isApp = (string) ($_GET['app'] ?? '') === '1';
$csrf  = csrfToken();
$apiUrl = 'api/staff-sign-hub-action.php';

// Force install gate: browser users must go through sign.php once (download / Home Screen).
// Standalone / APK WebView (?app=1) can enter directly.
$forceBypass = $isApp || (string) ($_GET['from_gate'] ?? '') === '1';
$assetV = '20260928c';

header('Cache-Control: no-store, no-cache, must-revalidate');

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, maximum-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="theme-color" content="#0b3d2e">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Staff Sign">
    <meta name="application-name" content="Olasentra Staff Sign">
    <link rel="apple-touch-icon" href="api/pwa-icon.php?size=180&v=<?= htmlspecialchars($assetV, ENT_QUOTES, 'UTF-8') ?>">
    <link rel="manifest" href="staff-sign-manifest.php?v=<?= htmlspecialchars($assetV, ENT_QUOTES, 'UTF-8') ?>">
    <title>Olasentra Staff Sign</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@500;700;800&family=IBM+Plex+Mono:wght@600;700&display=swap" rel="stylesheet">
    <style>
        :root{
            --bg:#f3f0ea; --ink:#1a1a18; --muted:#5c5a55; --line:#d9d3c7; --panel:#fffdf8;
            --ok:#1f7a4c; --ok-bg:#d9f0e3; --warn:#9a5b00; --warn-bg:#f7e3c2;
            --danger:#9b2c2c; --danger-bg:#f8d7d7; --accent:#0b3d2e;
            --safe-top: max(12px, env(safe-area-inset-top, 0px));
            --safe-bottom: max(12px, env(safe-area-inset-bottom, 0px));
        }
        *{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
        html,body{margin:0;min-height:100%}
        body{
            font-family:"DM Sans", system-ui, sans-serif;
            background:linear-gradient(180deg,#ebe6dc 0%,var(--bg) 28%,#efeae2 100%);
            color:var(--ink);
            padding:calc(var(--safe-top) + 10px) 16px calc(var(--safe-bottom) + 18px);
        }
        body.is-app{padding-top:calc(var(--safe-top) + 16px)}
        .topbar{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;margin-bottom:18px;padding-bottom:12px;border-bottom:2px solid var(--ink)}
        .brand-kicker{margin:0;font-size:.72rem;letter-spacing:.14em;text-transform:uppercase;color:var(--muted);font-weight:700}
        .brand-title{margin:4px 0 0;font-size:1.55rem;line-height:1;font-weight:800;letter-spacing:-.03em}
        .panel{background:var(--panel);border:1.5px solid var(--line);padding:16px;margin-bottom:12px}
        .step{margin:0 0 10px;font-size:.75rem;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:var(--accent)}
        label{display:block;font-size:.86rem;color:var(--muted);margin:0 0 6px;font-weight:700}
        select,input{width:100%;padding:14px 12px;border:1.5px solid var(--ink);background:#fff;color:var(--ink);font:inherit;font-size:1.05rem;border-radius:0}
        .btn{appearance:none;border:0;border-radius:0;padding:14px 16px;font:inherit;font-weight:800;font-size:1rem;cursor:pointer}
        .btn-block{width:100%}
        .btn-primary{background:var(--accent);color:#fff}
        .btn-ok{background:var(--ok);color:#fff}
        .btn-warn{background:var(--warn);color:#fff}
        .btn-danger{background:var(--danger);color:#fff}
        .btn-ghost{background:#ece8e0;color:var(--ink);border:1.5px solid var(--line)}
        .flash{padding:12px;margin-bottom:12px;border:1.5px solid;font-size:.95rem;font-weight:600;display:none}
        .flash.show{display:block}
        .flash-success{background:var(--ok-bg);border-color:var(--ok);color:#14442c}
        .flash-warning{background:var(--warn-bg);border-color:var(--warn);color:#5c3700}
        .flash-error{background:var(--danger-bg);border-color:var(--danger);color:#6d1d1d}
        .meta{display:grid;gap:10px;margin-top:12px;padding-top:12px;border-top:1px dashed var(--line)}
        .meta > .meta-row{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;font-size:.95rem}
        .meta > .meta-row > span{color:var(--muted);flex:0 0 auto}
        .meta > .meta-row > b{font-weight:700;text-align:right;word-break:break-word}
        .hint{margin:0;color:var(--muted);font-size:.9rem;line-height:1.4}
        .match{border:1.5px solid var(--line);padding:12px;margin-top:8px;cursor:pointer;background:#fff}
        .match:active,.match.active{border-color:var(--accent);background:#eef6f2}
        .match b{display:block;font-size:1.05rem}
        .match small{color:var(--muted)}
        .bib-input{text-align:center;font-family:"IBM Plex Mono",monospace;font-size:1.4rem;font-weight:700;letter-spacing:.08em}
        .alarm-banner{display:none;background:var(--danger);color:#fff;padding:14px;margin-bottom:12px;font-weight:800;text-align:center}
        .alarm-banner.on{display:block;animation:pulse 1s infinite}
        @keyframes pulse{0%,100%{opacity:1}50%{opacity:.75}}
        .hidden{display:none!important}
        .spacer{height:10px}
        .role-row{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px}
        .role-btn{border:1.5px solid var(--ink);background:#fff;padding:12px;font-weight:800;cursor:pointer}
        .role-btn.active{background:var(--accent);color:#fff;border-color:var(--accent)}
        .steward-chip{display:inline-block;margin-top:6px;padding:3px 8px;font-size:.72rem;font-weight:800;background:#e8f0ec;border:1.5px solid var(--accent);color:var(--accent);text-transform:uppercase;letter-spacing:.06em}
        .psa-card{display:block;width:100%;border:1.5px solid var(--line);overflow:hidden;background:#fff;margin-top:4px}
        .psa-head{background:#e36b1a;color:#fff;padding:12px 14px;font-weight:800;font-size:1.05rem}
        .psa-body{display:block;padding:8px 14px 14px}
        .psa-row{display:block;padding:10px 0;border-bottom:1px solid #eee}
        .psa-row:last-of-type{border-bottom:0}
        .psa-row span{display:block;color:#1d4f8c;font-weight:700;font-size:.78rem;letter-spacing:.02em;margin-bottom:3px}
        .psa-row b{display:block;font-weight:700;font-size:1rem;line-height:1.35;word-break:normal;overflow-wrap:anywhere;white-space:normal}
        .psa-msg{display:block;margin:12px 0 0;padding:10px 12px;background:#eef6f2;border:1.5px solid var(--accent);font-size:.9rem;font-weight:700;color:#14442c;line-height:1.4}
    </style>
</head>
<body class="<?= $isApp ? 'is-app' : '' ?>">
    <div class="topbar">
        <div>
            <p class="brand-kicker">Olasentra · every event</p>
            <h1 class="brand-title">Staff Sign</h1>
        </div>
        <a href="staff-sign-geo-lock.php" style="font-size:.78rem;font-weight:800;color:var(--accent);text-decoration:none;white-space:nowrap">Admin Geo</a>
    </div>
    <p class="hint" style="margin:-8px 0 14px">Security or steward sign-in. Works on iPhone and Android. Short link: <strong>register.olasentra.com/sign.php</strong></p>
    <div class="panel" id="install-tip" style="padding:12px 16px">
        <p class="hint" style="margin:0" id="install-tip-text">Add to Home Screen for a one-tap app icon.</p>
    </div>

    <div id="alarm-banner" class="alarm-banner">Sign-out time — tap Sign out below. Alarm keeps ringing until you do.</div>
    <div id="flash" class="flash"></div>

    <div class="panel" id="step-role">
        <div class="step">0 · Who are you signing in as?</div>
        <div class="role-row">
            <button type="button" class="role-btn active" id="role-security" data-role="security">Security</button>
            <button type="button" class="role-btn" id="role-steward" data-role="steward">Steward</button>
        </div>
        <p class="hint" id="role-hint">Security: name → PSA → BIB. Steward: name → confirm → sign in (BIB optional).</p>
    </div>

    <div class="panel" id="step-event">
        <div class="step">1 · Event</div>
        <label for="date">Date</label>
        <input type="date" id="date">
        <div class="spacer"></div>
        <label for="event">Today’s event</label>
        <select id="event"><option value="">Loading…</option></select>
        <p class="hint" id="venue-hint" style="margin-top:10px"></p>
    </div>

    <div class="panel" id="step-name">
        <div class="step">2 · Your name</div>
        <label for="name">Type your name</label>
        <input type="search" id="name" placeholder="e.g. Jane Murphy" autocomplete="name" enterkeyhint="search">
        <p class="hint" style="margin-top:8px">Must be on the <strong>approved signup for this event</strong>. <strong>One phone = one person</strong> — only <strong>admin</strong> can unlock or sign someone else in.</p>
        <div class="spacer"></div>
        <button type="button" class="btn btn-primary btn-block" id="btn-search">Find me</button>
        <div id="matches"></div>
    </div>

    <div class="panel hidden" id="step-person">
        <div class="step" id="person-step-label">3 · PSA &amp; BIB</div>
        <div class="meta" id="person-meta"></div>
        <div class="spacer"></div>
        <div id="bib-block">
            <label for="bib" id="bib-label">BIB number (only field you enter)</label>
            <input class="bib-input" id="bib" type="tel" inputmode="numeric" pattern="[0-9A-Za-z#\-]*" placeholder="Bib #" autocomplete="off">
            <p class="hint" style="margin-top:8px" id="bib-hint">Sign-in time is set by the system. You cannot edit it.</p>
        </div>
        <div class="spacer"></div>
        <button type="button" class="btn btn-ghost btn-block hidden" id="btn-retry-psa">Retry PSA check on official site</button>
        <button type="button" class="btn btn-ok btn-block" id="btn-signin" disabled>Sign in at venue</button>
        <div class="spacer"></div>
        <button type="button" class="btn btn-ghost btn-block" id="btn-back">Back (only before you sign in)</button>
    </div>

    <div class="panel hidden" id="step-active">
        <div class="step">On site</div>
        <div class="meta" id="active-meta"></div>
        <p class="hint" style="margin-top:10px">Keep this app open. At event end time the phone will ring until you sign out.</p>
        <div class="spacer"></div>
        <button type="button" class="btn btn-danger btn-block" id="btn-signout">Sign out now</button>
        <div class="spacer"></div>
        <button type="button" class="btn btn-warn btn-block" id="btn-snooze">Snooze alarm 5 min</button>
    </div>

<script>
(function () {
    var CSRF = <?= json_encode($csrf, JSON_UNESCAPED_SLASHES) ?>;
    var API = <?= json_encode($apiUrl, JSON_UNESCAPED_SLASHES) ?>;
    var IS_APP = <?= $isApp ? 'true' : 'false' ?>;
    var state = {
        eventId: 0,
        person: null,
        attendanceId: 0,
        signoutDueAt: null,
        snoozeUntil: 0,
        alarmOn: false,
        audioCtx: null,
        osc: null,
        beepTimer: null,
        visitorKey: '',
        roleMode: 'security' // security | steward
    };

    function getVisitorKey() {
        try {
            var k = localStorage.getItem('ssh_visitor_key');
            if (k && /^[a-f0-9]{16,64}$/i.test(k)) return k.toLowerCase();
            var bytes = new Uint8Array(16);
            if (window.crypto && crypto.getRandomValues) crypto.getRandomValues(bytes);
            else for (var i = 0; i < 16; i++) bytes[i] = Math.floor(Math.random() * 256);
            k = Array.prototype.map.call(bytes, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
            localStorage.setItem('ssh_visitor_key', k);
            return k;
        } catch (e) {
            return 'fallback' + String(Date.now()) + Math.random().toString(16).slice(2, 10);
        }
    }

    function displayMode() {
        try {
            if (window.matchMedia('(display-mode: standalone)').matches) return 'standalone';
            if (window.matchMedia('(display-mode: fullscreen)').matches) return 'fullscreen';
            if (window.matchMedia('(display-mode: minimal-ui)').matches) return 'minimal-ui';
            if (window.navigator.standalone) return 'standalone';
        } catch (e) {}
        return IS_APP ? 'standalone' : 'browser';
    }

    function channel() {
        return IS_APP ? 'apk' : (displayMode() === 'browser' ? 'web' : 'pwa');
    }

    function devicePayload(extra) {
        extra = extra || {};
        return Object.assign({
            visitor_key: state.visitorKey,
            display_mode: displayMode(),
            channel: channel(),
            app: IS_APP ? '1' : '0',
            event_id: state.eventId || 0,
            user_agent: navigator.userAgent || ''
        }, extra);
    }

    function trackDevice(action, extra) {
        return post(action, devicePayload(extra)).catch(function () { return null; });
    }

    var el = {
        flash: document.getElementById('flash'),
        date: document.getElementById('date'),
        event: document.getElementById('event'),
        venueHint: document.getElementById('venue-hint'),
        name: document.getElementById('name'),
        matches: document.getElementById('matches'),
        stepRole: document.getElementById('step-role'),
        stepEvent: document.getElementById('step-event'),
        stepPerson: document.getElementById('step-person'),
        stepActive: document.getElementById('step-active'),
        stepName: document.getElementById('step-name'),
        personMeta: document.getElementById('person-meta'),
        activeMeta: document.getElementById('active-meta'),
        bib: document.getElementById('bib'),
        alarm: document.getElementById('alarm-banner')
    };

    function showSetupSteps(show) {
        if (el.stepRole) el.stepRole.classList.toggle('hidden', !show);
        if (el.stepEvent) el.stepEvent.classList.toggle('hidden', !show);
    }

    function showFlash(type, msg) {
        el.flash.className = 'flash show flash-' + type;
        el.flash.textContent = msg || '';
    }

    function clearFlash() {
        el.flash.className = 'flash';
        el.flash.textContent = '';
    }

    function parseJsonResponse(r) {
        return r.text().then(function (text) {
            var raw = (text || '').trim();
            if (!raw) {
                throw new Error('Empty server response. Pull to refresh and try again.');
            }
            try {
                return JSON.parse(raw);
            } catch (e) {
                throw new Error('Bad server response. Pull to refresh and try again.');
            }
        });
    }

    function post(action, body) {
        body = body || {};
        body.action = action;
        body.csrf_token = CSRF;
        if (body.event_id == null) body.event_id = state.eventId;
        if (!body.visitor_key) body.visitor_key = state.visitorKey;
        if (!body.display_mode) body.display_mode = displayMode();
        if (!body.channel) body.channel = channel();
        if (body.app == null) body.app = IS_APP ? '1' : '0';
        if (!body.user_agent) body.user_agent = navigator.userAgent || '';
        return fetch(API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(body)
        }).then(parseJsonResponse);
    }

    function bootstrap(date) {
        var q = 'action=bootstrap' + (date ? ('&date=' + encodeURIComponent(date)) : '');
        return fetch(API + '?' + q, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(parseJsonResponse)
            .then(function (data) {
                if (data.csrf_token) CSRF = data.csrf_token;
                if (!data.ok) throw new Error(data.message || 'Could not load events');
                el.date.value = data.date || data.today;
                el.event.innerHTML = '';
                if (!data.events || !data.events.length) {
                    el.event.innerHTML = '<option value="">No events this day</option>';
                    var nextHint = data.next_event_date
                        ? (' Next event day on file: ' + data.next_event_date + ' — change the date above.')
                        : ' Ask admin if your event is missing.';
                    el.venueHint.textContent = 'No events on this date.' + nextHint;
                    state.eventId = 0;
                    return;
                }
                data.events.forEach(function (ev, i) {
                    var opt = document.createElement('option');
                    opt.value = String(ev.id);
                    opt.textContent = ev.name + (ev.end_time ? (' · ends ' + ev.end_time) : '');
                    opt.dataset.venue = ev.venue_configured ? '1' : '0';
                    opt.dataset.radius = String(ev.signin_radius_m || '');
                    opt.dataset.end = ev.end_time || '';
                    el.event.appendChild(opt);
                    if (i === 0) {
                        state.eventId = ev.id;
                    }
                });
                el.event.value = String(state.eventId);
                updateVenueHint();
            });
    }

    function updateVenueHint() {
        var opt = el.event.selectedOptions[0];
        if (!opt || !opt.value) {
            el.venueHint.textContent = '';
            return;
        }
        state.eventId = parseInt(opt.value, 10) || 0;
        if (opt.dataset.venue !== '1') {
            el.venueHint.textContent = 'Venue geocode not set — admin must update geocode in Staff Sign Hub before you can sign in.';
        } else {
            el.venueHint.textContent = 'Venue zone ready · radius ' + (opt.dataset.radius || '?') + ' m';
        }
    }

    function psaCardHtml(card) {
        var d = card.psa_details || {};
        var emp = d.employee || {};
        var onFile = d.on_file || {};
        var licenceNo = String(emp.licence_no || card.psa_licence || onFile.licence_no || '').trim();
        var titleVal = String(emp.title || '').trim();
        // Official PSA site has a "Title" column; for many licences it is just the same EM number.
        var showTitle = titleVal !== '' && titleVal.replace(/\s+/g, '').toLowerCase() !== licenceNo.replace(/\s+/g, '').toLowerCase();
        var html = '<div class="psa-card"><div class="psa-head">Official PSA licence details</div><div class="psa-body">';
        if (showTitle) {
            html += '<div class="psa-row"><span>Title</span><b>' + escapeHtml(titleVal) + '</b></div>';
        }
        html += '<div class="psa-row"><span>Licence No.</span><b>' + escapeHtml(licenceNo || '—') + '</b></div>';
        html += '<div class="psa-row"><span>Employee Name</span><b>' + escapeHtml(emp.employee_name || card.psa_register_name || card.name || '—') + '</b></div>';
        html += '<div class="psa-row"><span>County</span><b>' + escapeHtml(emp.county || '—') + '</b></div>';
        html += '<div class="psa-row"><span>Country</span><b>' + escapeHtml(emp.country || '—') + '</b></div>';
        html += '<div class="psa-row"><span>Sectors</span><b>' + escapeHtml(emp.sectors || '—') + '</b></div>';
        html += '<div class="psa-row"><span>Issue Date</span><b>' + escapeHtml(emp.issue_date || '—') + '</b></div>';
        html += '<div class="psa-row"><span>Expiry Date</span><b>' + escapeHtml(emp.expiry_date || card.psa_expiry_date || onFile.expiry_date || '—') + '</b></div>';
        if (d.message) {
            html += '<p class="psa-msg">' + escapeHtml(d.message) + '</p>';
        }
        html += '</div></div>';
        return html;
    }

    function renderPerson(card) {
        state.person = card;
        var steward = !!card.is_steward || state.roleMode === 'steward';
        var canProceed = steward || !!card.psa_can_proceed || !!(card.psa_details && card.psa_details.can_proceed);
        var retryBtn = document.getElementById('btn-retry-psa');
        var signBtn = document.getElementById('btn-signin');
        if (state.roleMode === 'steward' && !card.is_steward) {
            showFlash('warning', 'That person is not listed as steward. Switch to Security or pick a steward name.');
        }
        if (state.roleMode === 'security' && card.is_steward) {
            showFlash('warning', 'That person is a steward. Switch to Steward sign-in, or continue if this is correct.');
        }
        var html = '';
        html += row('Name', card.name || '');
        html += row('Event', card.event_name || '—');
        html += row('Role', card.staff_role || '—');
        if (steward) {
            html += '<div class="meta-row"><span></span><b><span class="steward-chip">Steward sign-in</span></b></div>';
            document.getElementById('person-step-label').textContent = '3 · Steward confirm';
            document.getElementById('bib-label').textContent = 'BIB (optional)';
            document.getElementById('bib-hint').textContent = 'Stewards can sign in without a BIB. Time is set by the system.';
            signBtn.textContent = 'Steward sign in at venue';
            retryBtn.classList.add('hidden');
            signBtn.disabled = false;
        } else {
            html += psaCardHtml(card);
            document.getElementById('person-step-label').textContent = '3 · Full PSA & BIB';
            document.getElementById('bib-label').textContent = 'BIB number (type it yourself)';
            document.getElementById('bib-hint').textContent = canProceed
                ? 'PSA confirmed. Type your BIB yourself — nothing is prefilled. Time is set by the system.'
                : 'PSA must be confirmed on the official PSA website before you can sign in.';
            signBtn.textContent = 'Sign in at venue';
            retryBtn.classList.toggle('hidden', canProceed);
            signBtn.disabled = !canProceed;
            if (!canProceed && card.psa_details && card.psa_details.message) {
                showFlash('warning', card.psa_details.message);
            }
        }
        el.personMeta.innerHTML = html;
        // Always blank — staff type their own BIB every time.
        el.bib.value = '';
        el.bib.placeholder = steward ? 'Optional' : 'Type your BIB';
        el.bib.required = !steward;
        showSetupSteps(false);
        el.stepPerson.classList.remove('hidden');
        el.stepName.classList.add('hidden');

        if (card.checked_in && !card.checked_out) {
            enterActive(card);
        }
    }

    function selectPerson(m) {
        clearFlash();
        showFlash('warning', 'Loading PSA licence details…');
        post('select', { registration_id: m.registration_id }).then(function (data) {
            if (!data.ok || !data.data) {
                showFlash('error', data.message || 'Could not load PSA details');
                // Still show what we have from search so you can continue
                renderPerson(Object.assign({}, m, {
                    psa_can_proceed: !!(m.psa_licence || m.is_steward),
                    psa_details: m.psa_details || {
                        can_proceed: !!(m.psa_licence || m.is_steward),
                        employee: {
                            licence_no: m.psa_licence || '',
                            employee_name: m.name || '',
                            expiry_date: m.psa_expiry_date || ''
                        },
                        message: m.psa_licence ? 'PSA from roster — enter BIB to sign in.' : (m.is_steward ? 'Steward' : 'No PSA on file')
                    }
                }));
                return;
            }
            clearFlash();
            renderPerson(data.data);
            trackDevice('device_identify', {
                registration_id: data.data.registration_id,
                first_name: data.data.first_name || '',
                surname: data.data.surname || '',
                event_id: state.eventId
            });
        }).catch(function (err) {
            showFlash('error', (err && err.message) ? err.message : 'Network error loading PSA details');
            renderPerson(Object.assign({}, m, {
                psa_can_proceed: !!(m.psa_licence || m.is_steward),
                psa_details: {
                    can_proceed: !!(m.psa_licence || m.is_steward),
                    employee: {
                        licence_no: m.psa_licence || '',
                        employee_name: m.name || '',
                        expiry_date: m.psa_expiry_date || ''
                    },
                    message: 'Showing roster PSA — you can still enter BIB and sign in.'
                }
            }));
        });
    }

    function row(label, value) {
        return '<div class="meta-row"><span>' + escapeHtml(label) + '</span><b>' + escapeHtml(value) + '</b></div>';
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }

    function enterActive(card) {
        state.attendanceId = card.attendance_id || state.attendanceId;
        state.signoutDueAt = card.signout_due_at || state.signoutDueAt;
        var html = '';
        html += row('Name', card.name || (state.person && state.person.name) || '');
        html += row('Signed in', card.checked_in_at || '—');
        html += row('Gate start', card.gate_start_time || '—');
        html += row('Sign-out due', card.event_end_time || '—');
        el.activeMeta.innerHTML = html;
        showSetupSteps(false);
        el.stepActive.classList.remove('hidden');
        el.stepPerson.classList.add('hidden');
        el.stepName.classList.add('hidden');
        tickAlarm();
    }

    function getGps() {
        return new Promise(function (resolve, reject) {
            if (!navigator.geolocation) {
                reject(new Error('GPS not available on this device.'));
                return;
            }
            navigator.geolocation.getCurrentPosition(
                function (pos) {
                    resolve({
                        sign_lat: pos.coords.latitude,
                        sign_lng: pos.coords.longitude,
                        sign_accuracy_m: pos.coords.accuracy != null ? Math.round(pos.coords.accuracy) : ''
                    });
                },
                function () { reject(new Error('Allow location to sign in at the venue.')); },
                { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 }
            );
        });
    }

    function ensureAudio() {
        if (!state.audioCtx) {
            var Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return null;
            state.audioCtx = new Ctx();
        }
        if (state.audioCtx.state === 'suspended') {
            state.audioCtx.resume();
        }
        return state.audioCtx;
    }

    function startAlarmSound() {
        if (state.alarmOn) return;
        state.alarmOn = true;
        el.alarm.classList.add('on');
        try { if (navigator.vibrate) navigator.vibrate([400, 200, 400, 200, 400]); } catch (e) {}
        var ctx = ensureAudio();
        if (!ctx) return;
        function beep() {
            if (!state.alarmOn) return;
            try {
                var o = ctx.createOscillator();
                var g = ctx.createGain();
                o.type = 'square';
                o.frequency.value = 880;
                g.gain.value = 0.08;
                o.connect(g);
                g.connect(ctx.destination);
                o.start();
                setTimeout(function () { try { o.stop(); } catch (e) {} }, 350);
            } catch (e) {}
        }
        beep();
        state.beepTimer = setInterval(beep, 1200);
    }

    function stopAlarmSound() {
        state.alarmOn = false;
        el.alarm.classList.remove('on');
        if (state.beepTimer) {
            clearInterval(state.beepTimer);
            state.beepTimer = null;
        }
        try { if (navigator.vibrate) navigator.vibrate(0); } catch (e) {}
    }

    function tickAlarm() {
        if (!state.signoutDueAt || !state.attendanceId) return;
        var due = Date.parse(state.signoutDueAt);
        if (!due) return;
        var now = Date.now();
        if (state.snoozeUntil && now < state.snoozeUntil) {
            stopAlarmSound();
            return;
        }
        if (now >= due) {
            startAlarmSound();
        }
    }

    document.getElementById('btn-search').addEventListener('click', function () {
        clearFlash();
        if (!state.eventId) {
            showFlash('error', 'Select an event first.');
            return;
        }
        post('search', { name: el.name.value, role_mode: state.roleMode }).then(function (data) {
            el.matches.innerHTML = '';
            if (!data.ok) {
                showFlash('error', data.message || 'Search failed');
                return;
            }
            var all = data.matches || [];
            var matches = all.filter(function (m) {
                if (state.roleMode === 'steward') return !!m.is_steward;
                if (state.roleMode === 'security') return !m.is_steward;
                return true;
            });
            if (!matches.length) {
                if (all.length && state.roleMode === 'security') {
                    showFlash('warning', 'Name is on this event as steward. Tap Steward above, then Find me again.');
                    return;
                }
                if (all.length && state.roleMode === 'steward') {
                    showFlash('warning', 'Name is on this event as security. Tap Security above, then Find me again.');
                    return;
                }
                showFlash('warning', data.message || 'Your name must be on the approved signup for this event.');
                return;
            }
            matches.forEach(function (m) {
                var div = document.createElement('div');
                div.className = 'match';
                var sub = escapeHtml(m.staff_role || '');
                if (m.is_steward) {
                    sub += ' · Steward';
                } else if (m.psa_licence) {
                    sub += ' · PSA ' + escapeHtml(m.psa_licence);
                    if (m.psa_expiry_date) sub += ' · exp ' + escapeHtml(m.psa_expiry_date);
                    if (m.psa_register_name) sub += ' · ' + escapeHtml(m.psa_register_name);
                } else {
                    sub += ' · No PSA on file';
                }
                div.innerHTML = '<b>' + escapeHtml(m.name) + '</b><small>' + sub + '</small>';
                div.addEventListener('click', function () { selectPerson(m); });
                el.matches.appendChild(div);
            });
        }).catch(function () { showFlash('error', 'Network error'); });
    });

    document.getElementById('role-security').addEventListener('click', function () {
        state.roleMode = 'security';
        document.getElementById('role-security').classList.add('active');
        document.getElementById('role-steward').classList.remove('active');
        document.getElementById('role-hint').textContent = 'Security: name → PSA → BIB. Steward: name → confirm → sign in (BIB optional).';
        el.matches.innerHTML = '';
    });
    document.getElementById('role-steward').addEventListener('click', function () {
        state.roleMode = 'steward';
        document.getElementById('role-steward').classList.add('active');
        document.getElementById('role-security').classList.remove('active');
        document.getElementById('role-hint').textContent = 'Steward sign-in on this app: find your name, confirm, sign in at the venue. No PSA required.';
        el.matches.innerHTML = '';
    });

    document.getElementById('btn-retry-psa').addEventListener('click', function () {
        if (!state.person || !state.person.registration_id) return;
        selectPerson(state.person);
    });

    document.getElementById('btn-back').addEventListener('click', function () {
        stopAlarmSound();
        state.person = null;
        el.stepPerson.classList.add('hidden');
        el.stepActive.classList.add('hidden');
        showSetupSteps(true);
        el.stepName.classList.remove('hidden');
        el.matches.innerHTML = '';
        document.getElementById('btn-signin').disabled = true;
        document.getElementById('btn-retry-psa').classList.add('hidden');
    });

    document.getElementById('btn-signin').addEventListener('click', function () {
        clearFlash();
        if (!state.person) return;
        var btn = document.getElementById('btn-signin');
        btn.disabled = true;
        getGps().then(function (gps) {
            var body = {
                registration_id: state.person.registration_id,
                bib: el.bib.value,
                sign_lat: gps.sign_lat,
                sign_lng: gps.sign_lng,
                sign_accuracy_m: gps.sign_accuracy_m
            };
            return post('present', body);
        }).then(function (data) {
            btn.disabled = false;
            if (!data.ok) {
                showFlash('error', data.message || 'Sign-in failed');
                return;
            }
            showFlash('success', data.message || 'Signed in');
            var card = Object.assign({}, state.person, data.data || {}, {
                checked_in: true,
                checked_out: false,
                checked_in_at: (data.data && data.data.checked_in_at) || new Date().toISOString(),
                gate_start_time: data.data && data.data.gate_start_time,
                attendance_id: data.data && data.data.attendance_id,
                signout_due_at: data.data && data.data.signout_due_at,
                event_end_time: data.data && data.data.event_end_time
            });
            state.signoutDueAt = card.signout_due_at;
            state.attendanceId = card.attendance_id;
            enterActive(card);
        }).catch(function (err) {
            btn.disabled = false;
            showFlash('error', err.message || 'Sign-in failed');
        });
    });

    document.getElementById('btn-signout').addEventListener('click', function () {
        clearFlash();
        if (!state.person) return;
        post('signout', { registration_id: state.person.registration_id }).then(function (data) {
            if (!data.ok) {
                showFlash('error', data.message || 'Sign-out failed');
                return;
            }
            stopAlarmSound();
            state.attendanceId = 0;
            state.signoutDueAt = null;
            showFlash('success', data.message || 'Signed out');
            el.stepActive.classList.add('hidden');
            showSetupSteps(true);
            el.stepName.classList.remove('hidden');
            el.matches.innerHTML = '';
            el.name.value = '';
            state.person = null;
            // Completed cycle — do not leave alarm / active UI hanging.
            state.attendanceId = 0;
            state.signoutDueAt = null;
            document.getElementById('btn-signin').disabled = true;
        });
    });

    document.getElementById('btn-snooze').addEventListener('click', function () {
        state.snoozeUntil = Date.now() + 5 * 60 * 1000;
        stopAlarmSound();
        showFlash('warning', 'Alarm snoozed for 5 minutes. It will return until you sign out.');
        ensureAudio();
    });

    el.event.addEventListener('change', updateVenueHint);
    el.date.addEventListener('change', function () {
        bootstrap(el.date.value).catch(function (e) { showFlash('error', e.message || 'Load failed'); });
    });

    // Unlock audio on first tap (mobile)
    document.body.addEventListener('touchstart', function once() {
        ensureAudio();
        document.body.removeEventListener('touchstart', once);
    }, { passive: true });

    setInterval(tickAlarm, 5000);
    setInterval(function () {
        if (state.visitorKey) trackDevice('device_ping', { event_id: state.eventId });
    }, 120000);

    // Force install: if opened in a normal browser without passing the gate, send to sign.php
    (function () {
        var FORCE_BYPASS = <?= $forceBypass ? 'true' : 'false' ?>;
        var standalone = displayMode() !== 'browser' || IS_APP;
        var downloaded = false;
        try { downloaded = localStorage.getItem('ssh_force_downloaded') === '1' || localStorage.getItem('ssh_installed') === '1'; } catch (e) {}
        if (!FORCE_BYPASS && !standalone && !downloaded) {
            window.location.replace('sign.php');
            return;
        }
        var tip = document.getElementById('install-tip-text');
        var box = document.getElementById('install-tip');
        if (!tip || !box) return;
        if (standalone || IS_APP) {
            box.classList.add('hidden');
            return;
        }
        var ua = navigator.userAgent || '';
        if (/iPhone|iPad|iPod/i.test(ua)) {
            tip.innerHTML = 'Tip: keep using the Home Screen icon for Staff Sign.';
        } else if (/Android/i.test(ua)) {
            tip.innerHTML = 'Tip: open the installed Staff Sign app for the best alarm / GPS experience.';
        } else {
            box.classList.add('hidden');
        }
    })();

    state.visitorKey = getVisitorKey();
    try {
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('staff-sign-sw.js', { scope: '/' }).catch(function () {});
        }
    } catch (e) {}
    bootstrap()
        .then(function () {
            var mode = displayMode();
            var openAction = (IS_APP || mode !== 'browser') ? 'device_install' : 'device_open';
            return trackDevice(openAction, { event_id: state.eventId });
        })
        .catch(function (e) { showFlash('error', e.message || 'Could not load'); });
})();
</script>
</body>
</html>

<?php

declare(strict_types=1);

/**
 * Short link + force install gate (iPhone & Android).
 * https://register.olasentra.com/sign.php
 *
 * Android: APK when packaged, otherwise Chrome Install / Add to Home Screen (PWA).
 * iPhone: Add to Home Screen (Apple does not allow automatic install).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/app-environment.php';
require_once __DIR__ . '/includes/auth.php';

if (function_exists('initSecureSession')) {
    initSecureSession();
}

$csrf = csrfToken();
$ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
$isIOS = (bool) preg_match('/iPhone|iPad|iPod/i', $ua);
$isAndroid = (bool) preg_match('/Android/i', $ua);
$apkPath = __DIR__ . '/storage/mobile/android/olasentra-staff-sign-hub-latest.apk';
$apkReady = is_file($apkPath) && filesize($apkPath) > 1000;
$apkUrl = 'staff-sign-apk.php?download=1';
$appUrl = 'staff-sign-hub-app.php?app=1&from_gate=1&v=20260928b';
$query = $_GET;
unset($query['web'], $query['app'], $query['from_gate']);
if ($query !== []) {
    $appUrl .= '&' . http_build_query($query);
}

header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Robots-Tag: noindex, nofollow');

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, maximum-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="theme-color" content="#0b3d2e">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Staff Sign">
    <meta name="mobile-web-app-capable" content="yes">
    <link rel="apple-touch-icon" href="api/pwa-icon.php?size=180">
    <link rel="manifest" href="staff-sign-manifest.php">
    <title>Install Staff Sign — Olasentra</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@600;700;800&display=swap" rel="stylesheet">
    <style>
        :root{ --bg:#f3f0ea; --ink:#1a1a18; --muted:#5c5a55; --accent:#0b3d2e; --ok:#1f7a4c; --line:#d9d3c7; --panel:#fffdf8;
            --safe-top:max(14px,env(safe-area-inset-top)); --safe-bottom:max(14px,env(safe-area-inset-bottom)); }
        *{box-sizing:border-box}
        body{margin:0;min-height:100vh;font-family:"DM Sans",system-ui,sans-serif;background:linear-gradient(180deg,#ebe6dc,#f3f0ea);
            color:var(--ink);padding:calc(var(--safe-top) + 16px) 18px calc(var(--safe-bottom) + 20px)}
        .card{background:var(--panel);border:1.5px solid var(--ink);padding:20px;max-width:420px;margin:0 auto}
        h1{margin:0 0 8px;font-size:1.6rem;font-weight:800;letter-spacing:-.03em}
        .kicker{margin:0 0 4px;font-size:.72rem;letter-spacing:.14em;text-transform:uppercase;font-weight:800;color:var(--muted)}
        p{margin:0 0 12px;color:var(--muted);line-height:1.45;font-size:.95rem}
        ol{margin:0 0 16px;padding-left:1.2rem;color:var(--ink);font-weight:600;line-height:1.5}
        .btn{display:block;width:100%;appearance:none;border:0;padding:16px;font:inherit;font-weight:800;font-size:1.05rem;cursor:pointer;text-align:center;text-decoration:none;margin-top:10px}
        .btn-primary{background:var(--accent);color:#fff}
        .btn-ok{background:var(--ok);color:#fff}
        .btn-ghost{background:#ece8e0;color:var(--ink);border:1.5px solid var(--line)}
        .btn[disabled]{opacity:.45;pointer-events:none}
        .status{margin-top:12px;padding:10px;border:1.5px solid var(--line);font-size:.9rem;font-weight:700;display:none}
        .status.show{display:block}
        .status.ok{border-color:var(--ok);background:#d9f0e3;color:#14442c}
        .status.warn{border-color:#9a5b00;background:#f7e3c2;color:#5c3700}
        .hidden{display:none!important}
        .share-icon{display:inline-block;padding:2px 8px;border:1.5px solid var(--ink);border-radius:6px;font-size:.85rem}
    </style>
</head>
<body>
    <div class="card" id="gate">
        <p class="kicker">Olasentra</p>
        <h1>Install Staff Sign</h1>
        <p id="lead">Install once on this phone, then sign in at the venue.</p>

        <div id="android-block" class="<?= $isAndroid || (!$isIOS) ? '' : 'hidden' ?>">
            <?php if ($apkReady): ?>
            <ol>
                <li>Tap <strong>Download app</strong>.</li>
                <li>Open the file → <strong>Install</strong>.</li>
                <li>Allow install from this browser if asked.</li>
                <li>Then tap <strong>Open Staff Sign</strong>.</li>
            </ol>
            <a class="btn btn-primary" id="btn-download" href="<?= htmlspecialchars($apkUrl, ENT_QUOTES, 'UTF-8') ?>">Download app (Android)</a>
            <button type="button" class="btn btn-ghost" id="btn-a2hs-android">Or Install as app (Chrome)</button>
            <?php else: ?>
            <ol>
                <li>Tap <strong>Install as app</strong> (Chrome install / Home Screen).</li>
                <li>If no prompt: Chrome menu <strong>⋮ → Install app</strong> or <strong>Add to Home screen</strong>.</li>
                <li>Then tap <strong>Open Staff Sign</strong>.</li>
            </ol>
            <button type="button" class="btn btn-primary" id="btn-a2hs-android">Install as app</button>
            <p style="font-size:.85rem;margin-top:8px">Dedicated APK package is not on the server yet — Home Screen / Chrome Install is the install for Android right now.</p>
            <?php endif; ?>
        </div>

        <div id="ios-block" class="<?= $isIOS ? '' : 'hidden' ?>">
            <ol>
                <li>Open this page in <strong>Safari</strong> (not Chrome / Instagram / WhatsApp).</li>
                <li>Tap the <strong class="share-icon">Share □↑</strong> button at the bottom.</li>
                <li>Scroll and tap <strong>Add to Home Screen</strong> → <strong>Add</strong>.</li>
                <li>Open the <strong>Staff Sign</strong> icon from your Home Screen.</li>
            </ol>
            <p>iPhone cannot install an APK and Apple does not allow automatic Add to Home Screen — you must tap Share once.</p>
            <button type="button" class="btn btn-primary" id="btn-ios-done">I’ve added it — continue</button>
        </div>

        <div id="status" class="status"></div>

        <button type="button" class="btn btn-ok" id="btn-open" disabled>Open Staff Sign</button>
        <p style="margin-top:14px;font-size:.8rem">Short link: register.olasentra.com/sign.php</p>
        <p style="margin-top:8px;font-size:.8rem">
            Admin (set geo from phone):
            <a href="staff-sign-geo-lock.php" style="color:inherit;font-weight:600">Geo Lock</a>
        </p>
    </div>

<script>
(function () {
    var APP_URL = <?= json_encode($appUrl, JSON_UNESCAPED_SLASHES) ?>;
    var APK_URL = <?= json_encode($apkUrl, JSON_UNESCAPED_SLASHES) ?>;
    var APK_READY = <?= $apkReady ? 'true' : 'false' ?>;
    var IS_IOS = <?= $isIOS ? 'true' : 'false' ?>;
    var IS_ANDROID = <?= $isAndroid ? 'true' : 'false' ?>;
    var CSRF = <?= json_encode($csrf, JSON_UNESCAPED_SLASHES) ?>;
    var deferredPrompt = null;

    try {
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('staff-sign-sw.js', { scope: '/' }).catch(function () {});
        }
    } catch (e) {}

    function visitorKey() {
        try {
            var k = localStorage.getItem('ssh_visitor_key');
            if (k && /^[a-f0-9]{16,64}$/i.test(k)) return k.toLowerCase();
            var bytes = new Uint8Array(16);
            if (window.crypto && crypto.getRandomValues) crypto.getRandomValues(bytes);
            else for (var i = 0; i < 16; i++) bytes[i] = Math.floor(Math.random() * 256);
            k = Array.prototype.map.call(bytes, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
            localStorage.setItem('ssh_visitor_key', k);
            return k;
        } catch (e) { return ''; }
    }

    function isStandalone() {
        try {
            if (window.matchMedia('(display-mode: standalone)').matches) return true;
            if (window.matchMedia('(display-mode: fullscreen)').matches) return true;
            if (window.navigator.standalone) return true;
        } catch (e) {}
        return false;
    }

    function downloadedOnce() {
        try { return localStorage.getItem('ssh_force_downloaded') === '1'; } catch (e) { return false; }
    }

    function markDownloaded() {
        try { localStorage.setItem('ssh_force_downloaded', '1'); } catch (e) {}
        try { localStorage.setItem('ssh_installed', '1'); } catch (e) {}
        track('device_download');
        enableOpen('Installed on this phone. Tap Open Staff Sign.');
    }

    function markInstalled() {
        try { localStorage.setItem('ssh_installed', '1'); localStorage.setItem('ssh_force_downloaded', '1'); } catch (e) {}
        track('device_install');
    }

    function track(action) {
        var body = {
            action: action,
            csrf_token: CSRF,
            visitor_key: visitorKey(),
            channel: IS_ANDROID ? (APK_READY ? 'apk' : 'pwa') : 'pwa',
            display_mode: isStandalone() ? 'standalone' : 'browser',
            app: '1',
            user_agent: navigator.userAgent || ''
        };
        try {
            fetch('api/staff-sign-hub-action.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify(body)
            }).catch(function () {});
        } catch (e) {}
    }

    function setStatus(msg, ok) {
        var el = document.getElementById('status');
        el.className = 'status show ' + (ok ? 'ok' : 'warn');
        el.textContent = msg;
    }

    function enableOpen(msg) {
        var btn = document.getElementById('btn-open');
        btn.disabled = false;
        if (msg) setStatus(msg, true);
    }

    function promptInstall() {
        if (deferredPrompt) {
            deferredPrompt.prompt();
            deferredPrompt.userChoice.then(function (choice) {
                if (choice && choice.outcome === 'accepted') {
                    markDownloaded();
                    markInstalled();
                    enableOpen('Installed. Tap Open Staff Sign.');
                } else {
                    setStatus('Install cancelled. Use Chrome ⋮ → Install app / Add to Home screen.', false);
                    markDownloaded();
                }
            });
            deferredPrompt = null;
            return;
        }
        if (IS_ANDROID) {
            setStatus('No auto prompt yet — open Chrome menu ⋮ → Install app or Add to Home screen, then return.', false);
            markDownloaded();
            return;
        }
        setStatus('Use your browser menu → Add to Home Screen.', false);
        markDownloaded();
    }

    if (isStandalone()) {
        markInstalled();
        window.location.replace(APP_URL);
        return;
    }

    if (downloadedOnce()) {
        enableOpen('Already set up on this phone. Tap Open Staff Sign.');
    }

    var btnDownload = document.getElementById('btn-download');
    var btnOpen = document.getElementById('btn-open');
    var btnA2hs = document.getElementById('btn-a2hs-android');
    var btnIosDone = document.getElementById('btn-ios-done');

    if (btnDownload) {
        btnDownload.addEventListener('click', function () {
            if (!APK_READY) {
                setStatus('APK not on server — use Install as app.', false);
                return;
            }
            btnDownload.href = APK_URL + (APK_URL.indexOf('?') >= 0 ? '&' : '?') + 'v=' + encodeURIComponent(visitorKey());
            markDownloaded();
        });
    }

    if (btnA2hs) {
        btnA2hs.addEventListener('click', promptInstall);
    }

    if (btnIosDone) {
        btnIosDone.addEventListener('click', function () {
            markDownloaded();
            markInstalled();
            enableOpen('Great. Prefer opening from the Home Screen icon next time.');
            window.location.href = APP_URL;
        });
    }

    window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault();
        deferredPrompt = e;
        if (IS_ANDROID && !downloadedOnce()) {
            setStatus('Ready to install — tap Install as app.', true);
        }
    });

    btnOpen.addEventListener('click', function () {
        if (IS_IOS && !isStandalone() && !downloadedOnce()) {
            setStatus('iPhone: Add to Home Screen first (Share → Add to Home Screen), then tap I’ve added it.', false);
            return;
        }
        if (!downloadedOnce() && !isStandalone()) {
            setStatus('Install first, then open.', false);
            return;
        }
        markInstalled();
        window.location.href = APP_URL;
    });

    if (IS_ANDROID || (!IS_IOS && /Mobile/i.test(navigator.userAgent || ''))) {
        if (APK_READY) {
            setTimeout(function () {
                var url = APK_URL + (APK_URL.indexOf('?') >= 0 ? '&' : '?') + 'v=' + encodeURIComponent(visitorKey());
                var iframe = document.createElement('iframe');
                iframe.style.display = 'none';
                iframe.src = url;
                document.body.appendChild(iframe);
                markDownloaded();
                setTimeout(function () { try { document.body.removeChild(iframe); } catch (e) {} }, 60000);
            }, 400);
        } else {
            setStatus('Tap Install as app (Chrome). Dedicated APK file is not uploaded yet.', false);
            enableOpen('You can also continue after Install as app / Home Screen.');
        }
    } else if (IS_IOS) {
        setStatus('iPhone: Share → Add to Home Screen. Apple blocks automatic install.', false);
        if (downloadedOnce()) {
            enableOpen('Ready — open Staff Sign (best from Home Screen icon).');
        }
    } else {
        enableOpen('On a phone, install first. On desktop you can open the web app.');
        markDownloaded();
    }
})();
</script>
</body>
</html>

<?php

declare(strict_types=1);

/**
 * Gate-app PSA lookup only — official psa-gov.ie register.
 * Does not write attendance, staff, or admin sign-in.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/psa-licence-verify.php';

requireAdminCapability('attendance');

$isApp   = ((string) ($_GET['app'] ?? $_POST['app'] ?? '')) === '1';
$date    = trim((string) ($_GET['date'] ?? $_POST['date'] ?? ''));
$eventId = (int) ($_GET['event_id'] ?? $_POST['event_id'] ?? 0);

if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = '';
}

$gateQs = [];
if ($isApp) {
    $gateQs[] = 'app=1';
}
if ($date !== '') {
    $gateQs[] = 'date=' . rawurlencode($date);
}
if ($eventId > 0) {
    $gateQs[] = 'event_id=' . $eventId;
}
$gateHref = 'attendant.php' . ($gateQs !== [] ? '?' . implode('&', $gateQs) : '');
$selfQs   = $gateQs !== [] ? '?' . implode('&', $gateQs) : '';

$licence = '';
$result  = null;
$error   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'Session expired. Refresh and try again.';
    } else {
        $licence = normalizePsaLicence((string) ($_POST['psa_licence'] ?? ''));
        $result  = lookupPsaLicenceOnRegister($licence, null);
    }
}

$employee = is_array($result['employee'] ?? null) ? $result['employee'] : null;
$status   = is_array($result) ? trim((string) ($result['status'] ?? '')) : '';
$found    = $employee !== null && in_array($status, ['verified', 'expired', 'name_mismatch'], true);

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, maximum-scale=1">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="#f3f0ea">
    <title>Check PSA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@500;700;800&family=IBM+Plex+Mono:wght@600;700&display=swap" rel="stylesheet">
    <style>
        :root{
            --bg:#f3f0ea;
            --ink:#1a1a1a;
            --muted:#5c5a55;
            --line:#d9d3c7;
            --panel:#fffdf8;
            --ok:#1f7a4c;
            --ok-bg:#d9f0e3;
            --warn:#9a5b00;
            --warn-bg:#f7e3c2;
            --danger:#9b2c2c;
            --danger-bg:#f8d7d7;
            --accent:#0b3d2e;
            --psa:#e36b1a;
            --psa-label:#1d4f8c;
            --safe-top: max(12px, env(safe-area-inset-top, 0px));
            --safe-bottom: max(12px, env(safe-area-inset-bottom, 0px));
        }
        *{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
        html,body{margin:0;min-height:100%}
        body{
            font-family:"DM Sans", system-ui, sans-serif;
            background:linear-gradient(180deg, #ebe6dc 0%, var(--bg) 28%, #efeae2 100%);
            color:var(--ink);
            padding: calc(var(--safe-top) + 10px) 16px calc(var(--safe-bottom) + 18px);
        }
        body.is-app{padding-top: calc(var(--safe-top) + 16px);}
        .topbar{
            display:flex;align-items:flex-end;justify-content:space-between;gap:12px;
            margin-bottom:18px;padding-bottom:12px;border-bottom:2px solid var(--ink);
        }
        .brand-kicker{margin:0;font-size:.72rem;letter-spacing:.14em;text-transform:uppercase;color:var(--muted);font-weight:700}
        .brand-title{margin:4px 0 0;font-size:1.7rem;line-height:1;font-weight:800;letter-spacing:-.03em}
        .panel{background:var(--panel);border:1.5px solid var(--line);padding:16px;margin-bottom:12px}
        .step{margin:0 0 10px;font-size:.75rem;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:var(--accent)}
        label{display:block;font-size:.86rem;color:var(--muted);margin:0 0 6px;font-weight:700}
        input{
            width:100%;padding:14px 12px;border:1.5px solid var(--ink);
            background:#fff;color:var(--ink);font:inherit;font-size:1.12rem;
            font-family:"IBM Plex Mono", monospace;letter-spacing:.04em;text-transform:uppercase;
        }
        input:focus{outline:3px solid rgba(11,61,46,.2);outline-offset:1px}
        .btn{appearance:none;border:0;border-radius:0;padding:14px 16px;font:inherit;font-weight:800;font-size:1rem;cursor:pointer}
        .btn-block{width:100%}
        .btn-primary{background:var(--accent);color:#fff}
        .spacer{height:10px}
        .hint{margin:8px 0 0;color:var(--muted);font-size:.88rem;line-height:1.4}
        .linkish{
            appearance:none;border:0;background:transparent;color:var(--accent);
            font:inherit;font-weight:800;font-size:.9rem;text-decoration:underline;cursor:pointer;padding:0;
        }
        .flash{padding:12px;margin-bottom:12px;border:1.5px solid;font-size:.95rem;font-weight:600}
        .flash-error{background:var(--danger-bg);border-color:var(--danger);color:#6d1d1d}
        .flash-warning{background:var(--warn-bg);border-color:var(--warn);color:#5c3700}
        .flash-success{background:var(--ok-bg);border-color:var(--ok);color:#14442c}
        .psa-card{border:0;padding:0;overflow:hidden;background:#fff;border:1px solid #d9d3c7}
        .psa-head{
            background:var(--psa);color:#fff;padding:14px 16px;
            display:flex;align-items:center;justify-content:space-between;gap:10px;
        }
        .psa-head h2{margin:0;font-size:1.15rem;font-weight:800}
        .psa-body{padding:8px 16px 16px}
        .psa-row{
            display:grid;grid-template-columns:42% 1fr;gap:10px;
            padding:10px 0;border-bottom:1px solid #eee;
            font-size:.95rem;align-items:start;
        }
        .psa-row:last-child{border-bottom:0}
        .psa-row span{color:var(--psa-label);font-weight:700}
        .psa-row b{font-weight:600;color:var(--ink);word-break:break-word}
        .source{margin:10px 0 0;font-size:.78rem;color:var(--muted)}
        .source a{color:var(--accent);font-weight:700}
    </style>
</head>
<body<?= $isApp ? ' class="is-app"' : '' ?>>
    <header class="topbar">
        <div>
            <p class="brand-kicker">Olasentra</p>
            <h1 class="brand-title">Check PSA</h1>
        </div>
        <a class="linkish" href="<?= h($gateHref) ?>">Back to gate</a>
    </header>

    <?php if ($error): ?>
        <div class="flash flash-error"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post" action="attendant-psa.php<?= h($selfQs) ?>" class="panel">
        <div class="step">Official PSA register</div>
        <label for="psa_licence">Licence number</label>
        <input id="psa_licence" name="psa_licence" value="<?= h($licence) ?>" placeholder="EM216481/26" required autocomplete="off" autocapitalize="characters" spellcheck="false" enterkeyhint="search" autofocus>
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <?php if ($isApp): ?><input type="hidden" name="app" value="1"><?php endif; ?>
        <?php if ($date !== ''): ?><input type="hidden" name="date" value="<?= h($date) ?>"><?php endif; ?>
        <?php if ($eventId > 0): ?><input type="hidden" name="event_id" value="<?= (int) $eventId ?>"><?php endif; ?>
        <div class="spacer"></div>
        <button class="btn btn-primary btn-block" type="submit">Look up on PSA website</button>
        <p class="hint">This reads <b>psa-gov.ie</b> only. It does not sign anyone in.</p>
    </form>

    <?php if (is_array($result) && $error === null): ?>
        <?php if ($found && $employee !== null): ?>
            <?php if ($status === 'expired'): ?>
                <div class="flash flash-warning">On the official PSA register, but the expiry date has passed.</div>
            <?php else: ?>
                <div class="flash flash-success">Found on the official PSA register.</div>
            <?php endif; ?>
            <div class="panel psa-card">
                <div class="psa-head">
                    <h2>Employee Details</h2>
                </div>
                <div class="psa-body">
                    <div class="psa-row"><span>Title:</span><b><?= h((string) $employee['title']) ?></b></div>
                    <div class="psa-row"><span>Licence No. :</span><b><?= h((string) $employee['licence_no']) ?></b></div>
                    <div class="psa-row"><span>Employee Name:</span><b><?= h((string) $employee['employee_name']) ?></b></div>
                    <div class="psa-row"><span>County:</span><b><?= h((string) $employee['county']) ?></b></div>
                    <div class="psa-row"><span>Country:</span><b><?= h((string) $employee['country']) ?></b></div>
                    <div class="psa-row"><span>Sectors:</span><b><?= h((string) $employee['sectors']) ?></b></div>
                    <div class="psa-row"><span>Issue Date:</span><b><?= h((string) $employee['issue_date']) ?></b></div>
                    <div class="psa-row"><span>Expiry Date:</span><b><?= h((string) $employee['expiry_date']) ?></b></div>
                    <p class="source">Source: <a href="<?= h((string) ($result['register_url'] ?? psaRegisterEmployeeSearchUrl())) ?>" target="_blank" rel="noopener">psa-gov.ie registered employees</a></p>
                </div>
            </div>
        <?php else: ?>
            <?php
                $flashClass = $status === 'unreachable' ? 'flash-warning' : 'flash-error';
                $msg = trim((string) ($result['message'] ?? ''));
                if ($msg === '') {
                    $msg = $status === 'not_found'
                        ? 'This licence number is not on the official PSA registered employees list.'
                        : 'Could not look up this number on the PSA website.';
                }
            ?>
            <div class="flash <?= h($flashClass) ?>"><?= h($msg) ?></div>
            <p class="hint"><a class="linkish" href="<?= h((string) ($result['register_url'] ?? psaRegisterEmployeeSearchUrl())) ?>" target="_blank" rel="noopener">Open the official PSA register</a></p>
        <?php endif; ?>
    <?php endif; ?>
    <script>
    (function () {
        var el = document.getElementById('psa_licence');
        if (!el) return;
        el.addEventListener('input', function () {
            var start = el.selectionStart, end = el.selectionEnd;
            el.value = el.value.toUpperCase();
            if (start != null && end != null) el.setSelectionRange(start, end);
        });
    })();
    </script>
</body>
</html>

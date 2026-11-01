<?php

declare(strict_types=1);

/**
 * Attendant (mobile): Date → Event → Name → Details → Bib Present / Clock out / Sent off
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/events-repository.php';
require_once __DIR__ . '/../includes/attendance-repository.php';
require_once __DIR__ . '/../includes/attendance-roster-helpers.php';
require_once __DIR__ . '/../includes/settings-repository.php';
require_once __DIR__ . '/../includes/registration-bib.php';
require_once __DIR__ . '/../includes/attendant-actions.php';
require_once __DIR__ . '/../includes/staff-sign-geo-lock.php';
require_once __DIR__ . '/../includes/maps-core.php';
require_once __DIR__ . '/../includes/date-format.php';

requireAdminCapability('attendance');

// AJAX: PSA lookup + Admin Geo Lock (same Gate check phone UI).
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && strcasecmp((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'XMLHttpRequest') === 0) {
    $ajaxAction = (string) ($_POST['action'] ?? '');
    header('Content-Type: application/json; charset=UTF-8');
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'status' => 'forbidden', 'message' => 'Session expired. Refresh and try again.']);
        exit;
    }
    if ($ajaxAction === 'psa_lookup') {
        require_once __DIR__ . '/../includes/psa-licence-verify.php';
        echo json_encode(
            lookupPsaLicenceOnRegister((string) ($_POST['psa_licence'] ?? ''), null),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }
    if ($ajaxAction === 'geo_reverse') {
        $lat = (float) ($_POST['lat'] ?? 0);
        $lng = (float) ($_POST['lng'] ?? 0);
        $result = reverseGeocodeVenueCoords($lat, $lng, getDB());
        if ($result === null) {
            echo json_encode([
                'ok' => true,
                'lat' => $lat,
                'lng' => $lng,
                'eircode' => '',
                'formatted_address' => '',
                'warning' => 'GPS captured. Address lookup unavailable — you can still lock this pin.',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        echo json_encode([
            'ok' => true,
            'lat' => $result['lat'],
            'lng' => $result['lng'],
            'eircode' => $result['eircode'],
            'formatted_address' => $result['formatted_address'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($ajaxAction === 'geo_lock') {
        $result = staffSignGeoLockConfirm(
            getDB(),
            (int) ($_POST['event_id'] ?? 0),
            (float) ($_POST['lat'] ?? 0),
            (float) ($_POST['lng'] ?? 0),
            (int) ($_POST['radius_m'] ?? 500),
            (string) ($_POST['eircode'] ?? ''),
            (string) ($_POST['address'] ?? '')
        );
        http_response_code(!empty($result['ok']) ? 200 : 400);
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($ajaxAction === 'geo_eircode') {
        $pdoAjax = getDB();
        $eircode = trim((string) ($_POST['eircode'] ?? ''));
        if ($eircode === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Enter an Eircode.']);
            exit;
        }
        if (!googleMapsEnabled($pdoAjax)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Maps lookup is not configured.']);
            exit;
        }
        $coords = geocodeVenueEircode($eircode, $pdoAjax);
        if ($coords === null) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Could not find GPS for that Eircode.']);
            exit;
        }
        echo json_encode([
            'ok' => true,
            'lat' => $coords['lat'],
            'lng' => $coords['lng'],
            'eircode' => normalizeEircode($eircode),
            'formatted_address' => '',
            'message' => 'Pin set from Eircode. Confirm to lock.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

$pdo     = getDB();
$flash   = getAdminFlash();
$bibOn   = registrationBibColumnEnabled($pdo);
$date    = trim((string) ($_GET['date'] ?? $_POST['date'] ?? ''));
$nameQ   = trim((string) ($_GET['name'] ?? ''));
$eventId = (int) ($_GET['event_id'] ?? $_POST['event_id'] ?? 0);
$isApp   = ((string) ($_GET['app'] ?? $_POST['app'] ?? '')) === '1';

if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = '';
}

$allEvents = getEventsForAttendanceFilter($pdo);
$eventsByDate = [];
foreach ($allEvents as $ev) {
    $d = substr((string) ($ev['event_date'] ?? ''), 0, 10);
    if ($d === '') {
        continue;
    }
    $eventsByDate[$d][] = $ev;
}
krsort($eventsByDate);
$dateOptions = array_keys($eventsByDate);

// Default to operational today when opening with no date (and today has events).
$todayYmd = getOperationalTodayYmd($pdo);
if ($date === '' && $eventId < 1 && isset($eventsByDate[$todayYmd])) {
    $date = $todayYmd;
}

$eventsForDate = ($date !== '' && isset($eventsByDate[$date])) ? $eventsByDate[$date] : [];

// Resolve event ↔ date safely (changing date must not keep a stale event_id).
$event = null;
if ($eventId > 0) {
    $event = getEventById($pdo, $eventId);
    if ($event === null) {
        $eventId = 0;
    } else {
        $eventDate = substr((string) ($event['event_date'] ?? ''), 0, 10);
        if ($date !== '' && $eventDate !== '' && $date !== $eventDate) {
            // Date was changed while an old event_id was still in the form.
            $eventId = 0;
            $event = null;
        } elseif ($date === '' && $eventDate !== '') {
            $date = $eventDate;
            $eventsForDate = $eventsByDate[$date] ?? [$event];
        }
    }
}

if ($date !== '' && $eventId < 1) {
    $eventsForDate = $eventsByDate[$date] ?? [];
    if (count($eventsForDate) === 1) {
        $eventId = (int) ($eventsForDate[0]['id'] ?? 0);
        $event = $eventId > 0 ? getEventById($pdo, $eventId) : null;
    }
}

if ($event === null && $eventId > 0) {
    $event = getEventById($pdo, $eventId);
}

/**
 * @return list<array<string, mixed>>
 */
function attendantRosterFull(PDO $pdo, int $eventId, bool $bibOn): array
{
    if ($eventId < 1) {
        return [];
    }
    $bibCol = $bibOn ? 'sr.assigned_bib_number' : 'NULL AS assigned_bib_number';
    $order = $bibOn
        ? "CASE WHEN sr.assigned_bib_number IS NULL OR TRIM(sr.assigned_bib_number) = '' THEN 1 ELSE 0 END, CAST(sr.assigned_bib_number AS UNSIGNED), sr.surname, sr.first_name"
        : 'sr.surname, sr.first_name';

    $startCol = ensureAttendanceGateStartTime($pdo) ? 'a.gate_start_time' : 'NULL AS gate_start_time';
    $sql = "SELECT sr.id, sr.first_name, sr.surname, sr.mobile, sr.email, sr.staff_role, {$bibCol},
                   a.id AS attendance_id, a.checked_in_at, a.checked_out_at, a.work_end_at,
                   a.hours_worked, a.hours_paid, a.hours_note, {$startCol},
                   CASE WHEN a.id IS NULL THEN 0 ELSE 1 END AS is_present,
                   CASE WHEN a.checked_out_at IS NOT NULL OR (a.hours_note IS NOT NULL AND a.hours_note LIKE 'Sent off%') THEN 1 ELSE 0 END AS is_finished
            FROM staff_registrations sr
            LEFT JOIN attendance a ON a.registration_id = sr.id
            WHERE sr.event_id = :event_id AND sr.status = 'approved'
            ORDER BY {$order}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['event_id' => $eventId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function attendantInitials(string $first, string $surname): string
{
    $parts = preg_split('/\s+/', trim($first . ' ' . $surname)) ?: [];
    $out = '';
    foreach ($parts as $p) {
        $p = preg_replace('/[^A-Za-z]/', '', $p) ?? '';
        if ($p !== '') {
            $out .= strtoupper($p[0]);
        }
    }
    return $out;
}

/**
 * @param list<array<string, mixed>> $roster
 * @return list<array<string, mixed>>
 */
function attendantFilterName(array $roster, string $q): array
{
    $q = trim($q);
    if ($q === '') {
        return $roster;
    }
    $needle = strtolower(preg_replace('/\s+/', ' ', $q) ?? '');
    $initialNeedle = strtoupper(preg_replace('/[^A-Za-z]/', '', $q) ?? '');
    $out = [];
    foreach ($roster as $row) {
        $first = trim((string) ($row['first_name'] ?? ''));
        $surname = trim((string) ($row['surname'] ?? ''));
        $full = strtolower(trim($first . ' ' . $surname));
        $initials = attendantInitials($first, $surname);
        $hit = ($needle !== '' && str_contains($full, $needle))
            || ($initialNeedle !== '' && ($initials === $initialNeedle || str_starts_with($initials, $initialNeedle)));
        if ($hit) {
            $out[] = $row;
        }
    }
    return $out;
}

$roster = [];
$matches = [];
$error = null;
$stepName = $event !== null;
$stepDetails = $stepName && $nameQ !== '';

if ($event) {
    try {
        $roster = attendantRosterFull($pdo, $eventId, $bibOn);
        if ($stepDetails) {
            $matches = attendantFilterName($roster, $nameQ);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$present = 0;
foreach ($roster as $r) {
    if ((int) ($r['is_present'] ?? 0) === 1) {
        $present++;
    }
}

$psaMode = ((string) ($_GET['psa'] ?? $_POST['psa'] ?? '')) === '1';
$geoMode = ((string) ($_GET['geo'] ?? $_POST['geo'] ?? '')) === '1';
$psaLicence = '';
$psaResult = null;
$psaError = null;

$radiusMin = defined('EVENT_SIGNIN_RADIUS_MIN_M') ? (int) EVENT_SIGNIN_RADIUS_MIN_M : 50;
$radiusMax = defined('EVENT_SIGNIN_RADIUS_MAX_M') ? (int) EVENT_SIGNIN_RADIUS_MAX_M : 5000;
$radiusDefault = defined('EVENT_SIGNIN_RADIUS_DEFAULT_M') ? (int) EVENT_SIGNIN_RADIUS_DEFAULT_M : 500;
$eventGeoLat = null;
$eventGeoLng = null;
$eventGeoRadius = $radiusDefault;
$eventGeoLocked = false;
if ($event) {
    $eventGeoLat = isset($event['venue_lat']) ? normalizeCoordinate((string) $event['venue_lat']) : null;
    $eventGeoLng = isset($event['venue_lng']) ? normalizeCoordinate((string) $event['venue_lng']) : null;
    $eventGeoRadius = getEventSigninRadiusMeters($event, $pdo);
    $eventGeoLocked = $eventGeoLat !== null && $eventGeoLng !== null;
}

if ($psaMode && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['psa_licence'])) {
    require_once __DIR__ . '/../includes/psa-licence-verify.php';
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        $psaError = 'Session expired. Refresh and try again.';
    } else {
        $psaLicence = normalizePsaLicence((string) $_POST['psa_licence']);
        $psaResult = lookupPsaLicenceOnRegister($psaLicence, null);
    }
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, maximum-scale=1">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="#f3f0ea">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <link rel="manifest" href="attendant-manifest.php?v=20260928d">
    <link rel="apple-touch-icon" href="../api/pwa-icon.php?size=180&amp;v=attendant">
    <title>Olasentra Gate</title>
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
            --safe-top: max(12px, env(safe-area-inset-top, 0px));
            --safe-bottom: max(12px, env(safe-area-inset-bottom, 0px));
        }
        *{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
        html,body{margin:0;min-height:100%}
        body{
            font-family:"DM Sans", system-ui, sans-serif;
            background:
                linear-gradient(180deg, #ebe6dc 0%, var(--bg) 28%, #efeae2 100%);
            color:var(--ink);
            padding: calc(var(--safe-top) + 10px) 16px calc(var(--safe-bottom) + 18px);
        }
        /* Extra top air when opened in the APK WebView (status bar handled natively too). */
        body.is-app{padding-top: calc(var(--safe-top) + 16px);}
        .topbar{
            display:flex;align-items:flex-end;justify-content:space-between;gap:12px;
            margin-bottom:18px;padding-bottom:12px;border-bottom:2px solid var(--ink);
        }
        .brand-kicker{margin:0;font-size:.72rem;letter-spacing:.14em;text-transform:uppercase;color:var(--muted);font-weight:700}
        .brand-title{margin:4px 0 0;font-size:1.7rem;line-height:1;font-weight:800;letter-spacing:-.03em}
        .count{
            font-family:"IBM Plex Mono", monospace;font-weight:700;font-size:.95rem;
            background:var(--ink);color:#fff;padding:8px 10px;min-width:72px;text-align:center;
        }
        .top-tools{display:flex;flex-direction:column;align-items:flex-end;gap:8px}
        .panel{
            background:var(--panel);
            border:1.5px solid var(--line);
            padding:16px;
            margin-bottom:12px;
        }
        .step{
            margin:0 0 10px;font-size:.75rem;font-weight:800;letter-spacing:.12em;
            text-transform:uppercase;color:var(--accent);
        }
        label{display:block;font-size:.86rem;color:var(--muted);margin:0 0 6px;font-weight:700}
        select,input{
            width:100%;padding:14px 12px;border:1.5px solid var(--ink);
            background:#fff;color:var(--ink);font:inherit;font-size:1.05rem;border-radius:0;
        }
        select:focus,input:focus{outline:3px solid rgba(11,61,46,.2);outline-offset:1px}
        .btn{
            appearance:none;border:0;border-radius:0;padding:14px 16px;
            font:inherit;font-weight:800;font-size:1rem;cursor:pointer;
        }
        .btn-block{width:100%}
        .btn-primary{background:var(--accent);color:#fff}
        .btn-ok{background:var(--ok);color:#fff;min-width:108px}
        .btn-warn{background:var(--warn);color:#fff}
        .btn-danger{background:var(--danger);color:#fff}
        .btn-ghost{background:#ece8e0;color:var(--ink);border:1.5px solid var(--line)}
        .flash{padding:12px;margin-bottom:12px;border:1.5px solid;font-size:.95rem;font-weight:600}
        .flash-success{background:var(--ok-bg);border-color:var(--ok);color:#14442c}
        .flash-warning{background:var(--warn-bg);border-color:var(--warn);color:#5c3700}
        .flash-error{background:var(--danger-bg);border-color:var(--danger);color:#6d1d1d}
        .meta{display:grid;gap:8px;margin-top:12px;padding-top:12px;border-top:1px dashed var(--line)}
        .meta div{display:flex;justify-content:space-between;gap:10px;font-size:.95rem}
        .meta span{color:var(--muted)}
        .hint{margin:0;color:var(--muted);font-size:.9rem;line-height:1.4}
        .hint b{color:var(--ink)}
        .person{display:flex;gap:12px;align-items:flex-start}
        .bib-badge{
            min-width:58px;height:58px;border:1.5px solid var(--ink);background:#fff;
            display:flex;align-items:center;justify-content:center;
            font-family:"IBM Plex Mono", monospace;font-weight:700;font-size:1.25rem;
        }
        .bib-badge.miss{color:var(--muted);font-size:.85rem}
        .name{font-size:1.15rem;font-weight:800;line-height:1.2}
        .detail{color:var(--muted);font-size:.84rem;margin-top:4px;word-break:break-word}
        .actions{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:12px}
        .actions-3{grid-template-columns:1fr 1fr 1fr}
        .bib-row{display:flex;gap:8px;margin-top:10px;align-items:stretch}
        .bib-row select{flex:0 0 108px;width:108px;font-weight:800;text-align:center;font-size:1rem}
        .bib-row input{flex:1;min-width:0;text-align:center;font-family:"IBM Plex Mono", monospace;font-size:1.35rem;font-weight:700;letter-spacing:.06em}
        .empty{text-align:center;color:var(--muted);padding:18px 8px}
        .status-chip{display:inline-block;margin-top:8px;padding:4px 8px;font-size:.75rem;font-weight:800;border:1.5px solid}
        .status-chip.on{background:var(--ok-bg);border-color:var(--ok);color:#14442c}
        .status-chip.off{background:var(--danger-bg);border-color:var(--danger);color:#6d1d1d}
        .status-chip.wait{background:#efebe3;border-color:var(--line);color:var(--muted)}
        .spacer{height:10px}
        .compact{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
        .compact b{font-size:1rem}
        .linkish{
            appearance:none;border:0;background:transparent;color:var(--accent);
            font:inherit;font-weight:800;font-size:.9rem;text-decoration:underline;cursor:pointer;padding:0;
        }
        .bib-dock{
            position:sticky;bottom:0;z-index:5;
            margin:0 -16px;padding:12px 16px calc(12px + env(safe-area-inset-bottom, 0px));
            background:linear-gradient(180deg, rgba(243,240,234,0), var(--bg) 28%, var(--bg));
        }
        .bib-dock .panel{margin-bottom:0;border-color:var(--ink);box-shadow:0 -8px 24px rgba(0,0,0,.08)}
        .bib-dock .bib-row{margin-top:0}
        .kb-pad{height:8px}
        .btn-psa{
            background:var(--accent);color:#fff;min-height:52px;margin-bottom:12px;
            position:relative;z-index:6;pointer-events:auto;touch-action:manipulation;
        }
        .btn-geo{
            background:#9a5b00;color:#fff;min-height:52px;margin-bottom:12px;
            position:relative;z-index:6;pointer-events:auto;touch-action:manipulation;
        }
        .btn-psa-back{background:#ece8e0;color:var(--ink);border:1.5px solid var(--ink);min-height:48px;margin-bottom:12px;touch-action:manipulation}
        .gate-tools{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:4px}
        .gate-tools .btn{margin-bottom:0}
        .geo-row{display:grid;grid-template-columns:1fr 1fr;gap:8px}
        .geo-meta{font-size:.88rem;color:var(--muted);line-height:1.4;margin:0 0 10px}
        .geo-meta strong{color:var(--ink)}
        input[readonly].geo-coord{background:#f3f0ea;color:var(--muted)}
        .geo-lock{padding:18px 16px 16px}
        .geo-event{margin:0;font-size:1.05rem;font-weight:800;line-height:1.25;letter-spacing:-.02em}
        .geo-pin{margin:6px 0 0;font-size:.9rem;color:var(--muted);line-height:1.35}
        .geo-actions{display:grid;gap:10px;margin-top:18px}
        .geo-actions .btn{margin:0;min-height:52px}
        .geo-help{margin:14px 0 0;text-align:center}
        .geo-help .linkish{font-size:.84rem}
        .geo-more{margin-top:16px;border-top:1px solid var(--line);padding-top:12px}
        .geo-more summary{
            cursor:pointer;font-size:.82rem;font-weight:800;color:var(--muted);
            letter-spacing:.04em;text-transform:uppercase;list-style:none;
        }
        .geo-more summary::-webkit-details-marker{display:none}
        .geo-more[open] summary{color:var(--ink);margin-bottom:12px}
        .geo-more .geo-eircode-row{display:grid;grid-template-columns:1fr auto;gap:8px;align-items:stretch;margin-top:8px}
        .geo-more .geo-eircode-row .btn{min-height:48px;padding:12px 14px;margin:0;white-space:nowrap}
        #geo-panel .btn-psa-back{margin-bottom:14px}
        .psa-card{border:1px solid var(--line);padding:0;overflow:hidden;background:#fff}
        .psa-head{background:#e36b1a;color:#fff;padding:14px 16px}
        .psa-head h2{margin:0;font-size:1.15rem;font-weight:800}
        .psa-body{padding:8px 16px 16px}
        .psa-row{display:grid;grid-template-columns:42% 1fr;gap:10px;padding:10px 0;border-bottom:1px solid #eee;font-size:.95rem}
        .psa-row:last-child{border-bottom:0}
        .psa-row span{color:#1d4f8c;font-weight:700}
        .psa-row b{font-weight:600;word-break:break-word}
        .source{margin:10px 0 0;font-size:.78rem;color:var(--muted)}
        input.psa-input{font-family:"IBM Plex Mono", monospace;letter-spacing:.04em;text-transform:uppercase}
    </style>
</head>
<body<?= $isApp ? ' class="is-app"' : '' ?>>
    <header class="topbar">
        <div>
            <p class="brand-kicker">Olasentra</p>
            <h1 class="brand-title"><?= $geoMode ? 'Lock geo' : ($psaMode ? 'Check PSA' : 'Gate check') ?></h1>
        </div>
        <?php if ($event && !$psaMode && !$geoMode): ?>
            <div class="count"><?= (int) $present ?>/<?= (int) count($roster) ?></div>
        <?php elseif (!$psaMode && !$geoMode): ?>
            <div class="count">IN</div>
        <?php endif; ?>
    </header>

    <div id="gate-main"<?= ($psaMode || $geoMode) ? ' hidden' : '' ?>>
    <div class="gate-tools">
        <form method="get" action="" id="open-psa-form">
            <?php if ($isApp): ?><input type="hidden" name="app" value="1"><?php endif; ?>
            <input type="hidden" name="psa" value="1">
            <?php if ($date !== ''): ?><input type="hidden" name="date" value="<?= h($date) ?>"><?php endif; ?>
            <?php if ($eventId > 0): ?><input type="hidden" name="event_id" value="<?= (int) $eventId ?>"><?php endif; ?>
            <button type="submit" class="btn btn-psa btn-block" id="open-psa">Check PSA</button>
        </form>
        <form method="get" action="" id="open-geo-form">
            <?php if ($isApp): ?><input type="hidden" name="app" value="1"><?php endif; ?>
            <input type="hidden" name="geo" value="1">
            <?php if ($date !== ''): ?><input type="hidden" name="date" value="<?= h($date) ?>"><?php endif; ?>
            <?php if ($eventId > 0): ?><input type="hidden" name="event_id" value="<?= (int) $eventId ?>"><?php endif; ?>
            <button type="submit" class="btn btn-geo btn-block" id="open-geo">Lock geo</button>
        </form>
    </div>

    <?php if ($flash): ?>
        <div class="flash flash-<?= h((string) $flash['type']) ?>"><?= h((string) $flash['message']) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="flash flash-error"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if (!$stepDetails): ?>
        <form method="get" class="panel" id="event-pick-form">
            <?php if ($isApp): ?><input type="hidden" name="app" value="1"><?php endif; ?>
            <div class="step">1 · Date</div>
            <label for="date">Event date</label>
            <select id="date" name="date" required>
                <option value="">Select date…</option>
                <?php foreach ($dateOptions as $d): ?>
                    <option value="<?= h($d) ?>"<?= $date === $d ? ' selected' : '' ?>><?= h(formatEventDateLabel($d)) ?><?= $d === $todayYmd ? ' · today' : '' ?></option>
                <?php endforeach; ?>
            </select>

            <?php if ($date !== '' && count($eventsForDate) > 1): ?>
                <div class="spacer"></div>
                <label for="event_id">Event</label>
                <select id="event_id" name="event_id" required>
                    <option value="">Select event…</option>
                    <?php foreach ($eventsForDate as $ev): ?>
                        <option value="<?= (int) $ev['id'] ?>"<?= $eventId === (int) $ev['id'] ? ' selected' : '' ?>><?= h((string) $ev['name']) ?><?= (int) ($ev['is_active'] ?? 1) !== 1 ? ' (inactive)' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

            <?php if ($event): ?>
                <div class="meta">
                    <div><span>Event</span><b><?= h((string) $event['name']) ?></b></div>
                    <div><span>Venue</span><b><?= h((string) ($event['location'] ?? '—')) ?></b></div>
                    <div><span>Time</span><b><?= h(substr((string) ($event['start_time'] ?? ''), 0, 5)) ?> – <?= h(substr((string) ($event['end_time'] ?? ''), 0, 5)) ?></b></div>
                    <div><span>Geo</span><b><?= $eventGeoLocked ? ('Set · ' . (int) $eventGeoRadius . ' m') : 'Not locked' ?></b></div>
                </div>
            <?php elseif ($date !== '' && count($eventsForDate) > 1 && $eventId < 1): ?>
                <p class="geo-meta" style="margin-top:10px">Select which event on this date.</p>
            <?php elseif ($date !== '' && $eventsForDate === []): ?>
                <p class="geo-meta" style="margin-top:10px">No events on this date.</p>
            <?php endif; ?>
        </form>
        <script>
        (function () {
            var form = document.getElementById('event-pick-form');
            if (!form) return;
            var dateEl = document.getElementById('date');
            var eventEl = document.getElementById('event_id');
            if (dateEl) {
                dateEl.addEventListener('change', function () {
                    // Drop stale event_id when date changes — server loads the right event for the new date.
                    if (eventEl) eventEl.remove();
                    form.submit();
                });
            }
            if (eventEl) {
                eventEl.addEventListener('change', function () { form.submit(); });
            }
        })();
        </script>
    <?php else: ?>
        <div class="panel">
            <div class="step">Event</div>
            <div class="compact">
                <div>
                    <b><?= h((string) ($event['name'] ?? '')) ?></b>
                    <div class="detail"><?= h(formatEventDateLabel($date)) ?> · <?= h(substr((string) ($event['start_time'] ?? ''), 0, 5)) ?>–<?= h(substr((string) ($event['end_time'] ?? ''), 0, 5)) ?></div>
                </div>
                <a class="linkish" href="?<?= $isApp ? 'app=1&amp;' : '' ?>date=<?= h(rawurlencode($date)) ?>&amp;event_id=<?= (int) $eventId ?>">Change</a>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($stepName && !$stepDetails): ?>
        <form method="get" class="panel" id="name-form">
            <?php if ($isApp): ?><input type="hidden" name="app" value="1"><?php endif; ?>
            <input type="hidden" name="date" value="<?= h($date) ?>">
            <input type="hidden" name="event_id" value="<?= (int) $eventId ?>">
            <div class="step">2 · Name</div>
            <label for="name">Staff name or initials</label>
            <input id="name" type="search" name="name" value="<?= h($nameQ) ?>" placeholder="e.g. Olabode or OO" autocomplete="off" enterkeyhint="search" autofocus>
            <div class="spacer"></div>
            <button class="btn btn-primary btn-block" type="submit">Load details</button>
        </form>
    <?php endif; ?>

    <?php if ($stepDetails): ?>
        <div class="panel">
            <div class="step">2 · Name</div>
            <div class="compact">
                <b><?= h($nameQ) ?></b>
                <a class="linkish" href="?<?= $isApp ? 'app=1&amp;' : '' ?>date=<?= h(rawurlencode($date)) ?>&amp;event_id=<?= (int) $eventId ?>">Search again</a>
            </div>
        </div>

        <?php if ($matches === []): ?>
            <div class="panel empty">No lad matching “<?= h($nameQ) ?>” on this event.</div>
        <?php else: ?>
            <?php foreach ($matches as $row):
                $first = (string) ($row['first_name'] ?? '');
                $surname = (string) ($row['surname'] ?? '');
                $bib = trim((string) ($row['assigned_bib_number'] ?? ''));
                $isPresent = (int) ($row['is_present'] ?? 0) === 1;
                $isOut = trim((string) ($row['checked_out_at'] ?? '')) !== '';
                $sentNote = (string) ($row['hours_note'] ?? '');
                $isSent = stripos($sentNote, 'sent off') !== false || stripos($sentNote, 'sent home') !== false;
                $attId = (int) ($row['attendance_id'] ?? 0);
                $rid = (int) $row['id'];
                $needsBib = !$isPresent;
                ?>
                <div class="panel<?= $needsBib ? ' person-card' : '' ?>"<?= $needsBib ? ' id="bib-target"' : '' ?>>
                    <div class="person">
                        <div class="bib-badge<?= (!$isPresent || $bib === '') ? ' miss' : '' ?>"><?= $isPresent && $bib !== '' ? h($bib) : '—' ?></div>
                        <div style="flex:1;min-width:0">
                            <div class="name"><?= h(trim($first . ' ' . $surname)) ?></div>
                            <div class="detail"><?= h(strtoupper((string) ($row['staff_role'] ?? 'dsp'))) ?><?= !empty($row['mobile']) ? ' · ' . h((string) $row['mobile']) : '' ?></div>
                            <?php if ($isPresent && !empty($row['checked_in_at'])): ?>
                                <div class="detail">In: <?= h((string) $row['checked_in_at']) ?></div>
                            <?php endif; ?>
                            <?php if ($isOut): ?>
                                <div class="detail">Out: <?= h((string) $row['checked_out_at']) ?></div>
                            <?php endif; ?>
                            <?php if ($isSent): ?>
                                <div class="detail"><?= h($sentNote) ?></div>
                            <?php endif; ?>
                            <?php if ($isSent): ?>
                                <div class="status-chip off">Sent off</div>
                            <?php elseif ($isOut): ?>
                                <div class="status-chip off">Clocked out</div>
                            <?php elseif ($isPresent): ?>
                                <?php $gateStart = trim((string) ($row['gate_start_time'] ?? '')); ?>
                                <div class="status-chip on">Present<?= $bib !== '' ? ' · bib ' . h($bib) : '' ?><?= $gateStart !== '' ? ' · ' . h($gateStart) : '' ?></div>
                            <?php else: ?>
                                <div class="status-chip wait">Waiting · enter bib below</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!$needsBib): ?>
                    <div class="actions<?= $isPresent && !$isOut && !$isSent ? ' actions-3' : '' ?>">
                        <?php if (!$isOut && !$isSent && $attId > 0): ?>
                            <form method="post" action="attendant-action.php">
                                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                <input type="hidden" name="action" value="clock_out">
                                <input type="hidden" name="attendance_id" value="<?= $attId ?>">
                                <input type="hidden" name="event_id" value="<?= (int) $eventId ?>">
                                <input type="hidden" name="date" value="<?= h($date) ?>">
                                <input type="hidden" name="name" value="<?= h($nameQ) ?>">
                                <?php if ($isApp): ?><input type="hidden" name="app" value="1"><?php endif; ?>
                                <button class="btn btn-warn btn-block" type="submit">Clock out</button>
                            </form>
                            <form method="post" action="attendant-action.php" onsubmit="return confirm('Mark this lad Sent off now? Time will record on the back-office sheet.');">
                                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                <input type="hidden" name="action" value="sent_off">
                                <input type="hidden" name="attendance_id" value="<?= $attId ?>">
                                <input type="hidden" name="event_id" value="<?= (int) $eventId ?>">
                                <input type="hidden" name="date" value="<?= h($date) ?>">
                                <input type="hidden" name="name" value="<?= h($nameQ) ?>">
                                <?php if ($isApp): ?><input type="hidden" name="app" value="1"><?php endif; ?>
                                <button class="btn btn-danger btn-block" type="submit">Sent off</button>
                            </form>
                            <button class="btn btn-ghost btn-block" type="button" disabled>Present ✓</button>
                        <?php else: ?>
                            <div class="empty" style="grid-column:1/-1;padding:4px">Shift closed on sheet</div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($needsBib): ?>
                <div class="bib-dock" id="bib-dock">
                    <div class="panel">
                        <div class="step">3 · Bib number</div>
                        <form method="post" action="attendant-action.php" class="bib-row">
                            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                            <input type="hidden" name="action" value="present">
                            <input type="hidden" name="registration_id" value="<?= $rid ?>">
                            <input type="hidden" name="event_id" value="<?= (int) $eventId ?>">
                            <input type="hidden" name="date" value="<?= h($date) ?>">
                            <input type="hidden" name="name" value="<?= h($nameQ) ?>">
                            <?php if ($isApp): ?><input type="hidden" name="app" value="1"><?php endif; ?>
                            <select name="start_time" required aria-label="Start time">
                                <option value="">Time</option>
                                <?php for ($hour = 0; $hour <= 23; $hour++): ?>
                                    <?php $hourLabel = sprintf('%02d:00', $hour); ?>
                                    <option value="<?= h($hourLabel) ?>"><?= h($hourLabel) ?></option>
                                <?php endfor; ?>
                            </select>
                            <input id="bib-input" type="tel" name="bib" inputmode="numeric" pattern="[0-9]*" placeholder="Bib #" required autocomplete="off" enterkeyhint="done" autofocus>
                            <button class="btn btn-ok" type="submit">Present</button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
            <?php endforeach; ?>
            <div class="kb-pad"></div>
            <script>
            (function () {
                var bib = document.getElementById('bib-input');
                var dock = document.getElementById('bib-dock');
                if (!bib || !dock) return;
                function reveal() {
                    try { bib.focus({ preventScroll: false }); } catch (e) { bib.focus(); }
                    dock.scrollIntoView({ block: 'end', behavior: 'smooth' });
                }
                setTimeout(reveal, 50);
                setTimeout(reveal, 320);
                bib.addEventListener('focus', function () {
                    setTimeout(function () { dock.scrollIntoView({ block: 'end', behavior: 'smooth' }); }, 120);
                });
            })();
            </script>
        <?php endif; ?>
    <?php endif; ?>
    </div>

    <div id="geo-panel"<?= $geoMode ? '' : ' hidden' ?>>
        <form method="get" action="" id="close-geo-form">
            <?php if ($isApp): ?><input type="hidden" name="app" value="1"><?php endif; ?>
            <?php if ($date !== ''): ?><input type="hidden" name="date" value="<?= h($date) ?>"><?php endif; ?>
            <?php if ($eventId > 0): ?><input type="hidden" name="event_id" value="<?= (int) $eventId ?>"><?php endif; ?>
            <button type="submit" class="btn btn-psa-back btn-block" id="close-geo">Back</button>
        </form>
        <div id="geo-status" class="flash" hidden></div>
        <div class="panel geo-lock">
            <?php if (!$event): ?>
                <p class="geo-pin">Pick an event on Gate check first.</p>
            <?php else: ?>
                <p class="geo-event"><?= h((string) ($event['name'] ?? '')) ?></p>
                <p class="geo-pin" id="geo-place"><?= $eventGeoLocked
                    ? 'Pin set · ' . (int) $eventGeoRadius . ' m range'
                    : 'Stand at the entrance, then capture GPS' ?></p>

                <div class="geo-actions">
                    <button type="button" class="btn btn-geo btn-block" id="geo-locate">Use my location</button>
                    <button type="button" class="btn btn-primary btn-block" id="geo-confirm"<?= ($eventGeoLat !== null && $eventGeoLng !== null) ? '' : ' disabled' ?>>Confirm lock</button>
                </div>
                <div class="geo-help">
                    <button type="button" class="linkish" id="geo-open-settings">Allow location in app settings</button>
                </div>

                <details class="geo-more">
                    <summary>More options</summary>
                    <label for="geo-radius">Range (metres)</label>
                    <input id="geo-radius" type="number" min="<?= (int) $radiusMin ?>" max="<?= (int) $radiusMax ?>" step="10" value="<?= (int) $eventGeoRadius ?>">
                    <label for="geo-eircode-input" style="margin-top:12px">Eircode (no GPS)</label>
                    <div class="geo-eircode-row">
                        <input id="geo-eircode-input" type="text" maxlength="10" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="e.g. R14 WD68" value="<?= h((string) ($event['venue_eircode'] ?? '')) ?>">
                        <button type="button" class="btn btn-ghost" id="geo-eircode-btn">Look up</button>
                    </div>
                </details>

                <input type="hidden" id="geo-lat" value="<?= $eventGeoLat !== null ? h((string) $eventGeoLat) : '' ?>">
                <input type="hidden" id="geo-lng" value="<?= $eventGeoLng !== null ? h((string) $eventGeoLng) : '' ?>">
                <input type="hidden" id="geo-event-id" value="<?= (int) $eventId ?>">
                <input type="hidden" id="geo-csrf" value="<?= h(csrfToken()) ?>">
                <input type="hidden" id="geo-eircode" value="<?= h((string) ($event['venue_eircode'] ?? '')) ?>">
                <input type="hidden" id="geo-address" value="<?= h((string) ($event['location'] ?? '')) ?>">
            <?php endif; ?>
        </div>
    </div>

    <?php
        $psaEmployee = is_array($psaResult['employee'] ?? null) ? $psaResult['employee'] : null;
        $psaStatus = is_array($psaResult) ? trim((string) ($psaResult['status'] ?? '')) : '';
        $psaFound = $psaEmployee !== null && in_array($psaStatus, ['verified', 'expired', 'name_mismatch'], true);
    ?>
    <div id="psa-panel"<?= $psaMode ? '' : ' hidden' ?>>
        <form method="get" action="" id="close-psa-form">
            <?php if ($isApp): ?><input type="hidden" name="app" value="1"><?php endif; ?>
            <?php if ($date !== ''): ?><input type="hidden" name="date" value="<?= h($date) ?>"><?php endif; ?>
            <?php if ($eventId > 0): ?><input type="hidden" name="event_id" value="<?= (int) $eventId ?>"><?php endif; ?>
            <button type="submit" class="btn btn-psa-back btn-block" id="close-psa">Back to gate</button>
        </form>
        <?php if ($psaError): ?>
            <div class="flash flash-error" id="psa-flash"><?= h($psaError) ?></div>
        <?php else: ?>
            <div id="psa-flash" hidden></div>
        <?php endif; ?>
        <form method="post" class="panel" id="psa-form">
            <div class="step">Official PSA register</div>
            <label for="psa_licence">Licence number</label>
            <input class="psa-input" id="psa_licence" name="psa_licence" value="<?= h($psaLicence) ?>" placeholder="EM216481/26" required autocomplete="off" autocapitalize="characters" spellcheck="false" enterkeyhint="search">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="psa_lookup">
            <input type="hidden" name="psa" value="1">
            <?php if ($isApp): ?><input type="hidden" name="app" value="1"><?php endif; ?>
            <?php if ($date !== ''): ?><input type="hidden" name="date" value="<?= h($date) ?>"><?php endif; ?>
            <?php if ($eventId > 0): ?><input type="hidden" name="event_id" value="<?= (int) $eventId ?>"><?php endif; ?>
            <div class="spacer"></div>
            <button class="btn btn-primary btn-block" type="submit" id="psa-submit">Look up on PSA website</button>
            <p class="hint">This reads <b>psa-gov.ie</b> only. It does not sign anyone in.</p>
        </form>
        <div id="psa-result">
            <?php if (is_array($psaResult) && $psaError === null): ?>
                <?php if ($psaFound && $psaEmployee !== null): ?>
                    <?php if ($psaStatus === 'expired'): ?>
                        <div class="flash flash-warning">On the official PSA register, but the expiry date has passed.</div>
                    <?php else: ?>
                        <div class="flash flash-success">Found on the official PSA register.</div>
                    <?php endif; ?>
                    <div class="panel psa-card">
                        <div class="psa-head"><h2>Employee Details</h2></div>
                        <div class="psa-body">
                            <div class="psa-row"><span>Title:</span><b><?= h((string) $psaEmployee['title']) ?></b></div>
                            <div class="psa-row"><span>Licence No. :</span><b><?= h((string) $psaEmployee['licence_no']) ?></b></div>
                            <div class="psa-row"><span>Employee Name:</span><b><?= h((string) $psaEmployee['employee_name']) ?></b></div>
                            <div class="psa-row"><span>County:</span><b><?= h((string) $psaEmployee['county']) ?></b></div>
                            <div class="psa-row"><span>Country:</span><b><?= h((string) $psaEmployee['country']) ?></b></div>
                            <div class="psa-row"><span>Sectors:</span><b><?= h((string) $psaEmployee['sectors']) ?></b></div>
                            <div class="psa-row"><span>Issue Date:</span><b><?= h((string) $psaEmployee['issue_date']) ?></b></div>
                            <div class="psa-row"><span>Expiry Date:</span><b><?= h((string) $psaEmployee['expiry_date']) ?></b></div>
                            <p class="source">Source: psa-gov.ie registered employees</p>
                        </div>
                    </div>
                <?php else: ?>
                    <?php
                        $flashClass = $psaStatus === 'unreachable' ? 'flash-warning' : 'flash-error';
                        $msg = trim((string) ($psaResult['message'] ?? ''));
                        if ($msg === '') {
                            $msg = 'Could not look up this number on the PSA website.';
                        }
                    ?>
                    <div class="flash <?= h($flashClass) ?>"><?= h($msg) ?></div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <script>
    (function () {
        var gate = document.getElementById('gate-main');
        var panel = document.getElementById('psa-panel');
        var geoPanel = document.getElementById('geo-panel');
        var openForm = document.getElementById('open-psa-form');
        var openBtn = document.getElementById('open-psa');
        var closeBtn = document.getElementById('close-psa');
        var form = document.getElementById('psa-form');
        var title = document.querySelector('.brand-title');
        var licence = document.getElementById('psa_licence');
        var resultBox = document.getElementById('psa-result');
        var submitBtn = document.getElementById('psa-submit');
        var radiusMin = <?= (int) $radiusMin ?>;
        var radiusMax = <?= (int) $radiusMax ?>;

        function showPsa() {
            if (gate) gate.hidden = true;
            if (geoPanel) geoPanel.hidden = true;
            if (panel) panel.hidden = false;
            if (title) title.textContent = 'Check PSA';
            if (licence) {
                try { licence.focus(); } catch (e) {}
            }
        }
        function showGeo() {
            if (gate) gate.hidden = true;
            if (panel) panel.hidden = true;
            if (geoPanel) geoPanel.hidden = false;
            if (title) title.textContent = 'Lock geo';
        }
        function showGate() {
            if (panel) panel.hidden = true;
            if (geoPanel) geoPanel.hidden = true;
            if (gate) gate.hidden = false;
            if (title) title.textContent = 'Gate check';
        }
        function esc(value) {
            return String(value == null ? '' : value)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }
        function renderPsa(data) {
            if (!resultBox) return;
            var employee = data && data.employee;
            var status = data && data.status ? String(data.status) : '';
            if (employee && (status === 'verified' || status === 'expired' || status === 'name_mismatch')) {
                var warn = status === 'expired'
                    ? '<div class="flash flash-warning">On the official PSA register, but the expiry date has passed.</div>'
                    : '<div class="flash flash-success">Found on the official PSA register.</div>';
                resultBox.innerHTML = warn
                    + '<div class="panel psa-card"><div class="psa-head"><h2>Employee Details</h2></div><div class="psa-body">'
                    + '<div class="psa-row"><span>Title:</span><b>' + esc(employee.title) + '</b></div>'
                    + '<div class="psa-row"><span>Licence No. :</span><b>' + esc(employee.licence_no) + '</b></div>'
                    + '<div class="psa-row"><span>Employee Name:</span><b>' + esc(employee.employee_name) + '</b></div>'
                    + '<div class="psa-row"><span>County:</span><b>' + esc(employee.county) + '</b></div>'
                    + '<div class="psa-row"><span>Country:</span><b>' + esc(employee.country) + '</b></div>'
                    + '<div class="psa-row"><span>Sectors:</span><b>' + esc(employee.sectors) + '</b></div>'
                    + '<div class="psa-row"><span>Issue Date:</span><b>' + esc(employee.issue_date) + '</b></div>'
                    + '<div class="psa-row"><span>Expiry Date:</span><b>' + esc(employee.expiry_date) + '</b></div>'
                    + '<p class="source">Source: psa-gov.ie registered employees</p>'
                    + '</div></div>';
                return;
            }
            var msg = (data && data.message) ? data.message : 'Could not look up this number on the PSA website.';
            var cls = status === 'unreachable' ? 'flash-warning' : 'flash-error';
            resultBox.innerHTML = '<div class="flash ' + cls + '">' + esc(msg) + '</div>';
        }

        if (openForm) {
            openForm.addEventListener('submit', function (e) {
                e.preventDefault();
                e.stopPropagation();
                showPsa();
            });
        }
        if (openBtn) {
            openBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                showPsa();
            });
        }
        if (closeBtn) {
            closeBtn.addEventListener('click', function (e) {
                e.preventDefault();
                showGate();
            });
        }
        var closeForm = document.getElementById('close-psa-form');
        if (closeForm) {
            closeForm.addEventListener('submit', function (e) {
                e.preventDefault();
                showGate();
            });
        }

        var openGeoForm = document.getElementById('open-geo-form');
        var openGeoBtn = document.getElementById('open-geo');
        var closeGeoBtn = document.getElementById('close-geo');
        var closeGeoForm = document.getElementById('close-geo-form');
        if (openGeoForm) {
            openGeoForm.addEventListener('submit', function (e) {
                e.preventDefault();
                e.stopPropagation();
                showGeo();
            });
        }
        if (openGeoBtn) {
            openGeoBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                showGeo();
            });
        }
        if (closeGeoBtn) {
            closeGeoBtn.addEventListener('click', function (e) {
                e.preventDefault();
                showGate();
            });
        }
        if (closeGeoForm) {
            closeGeoForm.addEventListener('submit', function (e) {
                e.preventDefault();
                showGate();
            });
        }

        var geoStatus = document.getElementById('geo-status');
        var geoLat = document.getElementById('geo-lat');
        var geoLng = document.getElementById('geo-lng');
        var geoRadius = document.getElementById('geo-radius');
        var geoPlace = document.getElementById('geo-place');
        var geoConfirm = document.getElementById('geo-confirm');
        var geoLocate = document.getElementById('geo-locate');
        var geoEventId = document.getElementById('geo-event-id');
        var geoCsrf = document.getElementById('geo-csrf');
        var geoEircode = document.getElementById('geo-eircode');
        var geoAddress = document.getElementById('geo-address');

        function setGeoStatus(msg, kind) {
            if (!geoStatus) return;
            if (!msg) {
                geoStatus.hidden = true;
                geoStatus.textContent = '';
                geoStatus.className = 'flash';
                return;
            }
            geoStatus.hidden = false;
            geoStatus.textContent = msg;
            geoStatus.className = 'flash flash-' + (kind || 'success');
        }
        function syncGeoConfirm() {
            if (!geoConfirm) return;
            geoConfirm.disabled = !(geoLat && geoLng && geoLat.value && geoLng.value && geoEventId && geoEventId.value);
        }

        if (geoLocate) {
            geoLocate.addEventListener('click', function () {
                if (!window.isSecureContext) {
                    setGeoStatus('Open Gate Check over HTTPS to use GPS.', 'error');
                    return;
                }
                if (!navigator.geolocation) {
                    setGeoStatus('GPS not available here. Use Eircode under More options.', 'error');
                    return;
                }

                function runGps() {
                    setGeoStatus('Getting GPS…', 'warning');
                    navigator.geolocation.getCurrentPosition(function (pos) {
                        var lat = pos.coords.latitude;
                        var lng = pos.coords.longitude;
                        if (geoLat) geoLat.value = String(lat);
                        if (geoLng) geoLng.value = String(lng);
                        syncGeoConfirm();
                        if (geoPlace) geoPlace.textContent = 'GPS ~' + Math.round(pos.coords.accuracy || 0) + ' m — looking up…';
                        var body = new FormData();
                        body.set('action', 'geo_reverse');
                        body.set('csrf_token', geoCsrf ? geoCsrf.value : '');
                        body.set('lat', String(lat));
                        body.set('lng', String(lng));
                        fetch(window.location.pathname, {
                            method: 'POST',
                            body: body,
                            credentials: 'same-origin',
                            headers: { 'X-Requested-With': 'XMLHttpRequest' }
                        }).then(function (res) { return res.json(); }).then(function (data) {
                            if (data && data.eircode && geoEircode) geoEircode.value = data.eircode;
                            if (data && data.eircode) {
                                var eIn = document.getElementById('geo-eircode-input');
                                if (eIn) eIn.value = data.eircode;
                            }
                            if (data && data.formatted_address && geoAddress) geoAddress.value = data.formatted_address;
                            if (geoPlace) {
                                geoPlace.textContent = (data.formatted_address || 'Pin ready')
                                    + (data.eircode ? ' · ' + data.eircode : '');
                            }
                            setGeoStatus(data.warning || 'Ready — tap Confirm lock.', data.warning ? 'warning' : 'success');
                        }).catch(function () {
                            setGeoStatus('GPS captured — you can Confirm lock.', 'warning');
                        });
                    }, function (err) {
                        var code = err && err.code ? err.code : 0;
                        var msg;
                        if (code === 1) {
                            msg = 'Location blocked. Tap “Allow location in app settings”, then try again.';
                        } else if (code === 2) {
                            msg = 'GPS unavailable. Turn on high-accuracy Location, or use Eircode.';
                        } else if (code === 3) {
                            msg = 'GPS timed out. Step outside, or use Eircode.';
                        } else {
                            msg = (err && err.message) ? err.message : 'Could not get location.';
                        }
                        setGeoStatus(msg, 'error');
                    }, { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
                }

                if (navigator.permissions && navigator.permissions.query) {
                    navigator.permissions.query({ name: 'geolocation' }).then(function (status) {
                        if (status && status.state === 'denied') {
                            setGeoStatus('Location blocked. Tap “Allow location in app settings”.', 'error');
                            return;
                        }
                        runGps();
                    }).catch(function () { runGps(); });
                } else {
                    runGps();
                }
            });
        }

        var geoOpenSettings = document.getElementById('geo-open-settings');
        if (geoOpenSettings) {
            geoOpenSettings.addEventListener('click', function (e) {
                e.preventDefault();
                try {
                    if (window.GateCheck && typeof window.GateCheck.openAppSettings === 'function') {
                        window.GateCheck.openAppSettings();
                        setGeoStatus('Set Location → Allow, then come back and Use my location.', 'warning');
                        return;
                    }
                } catch (err) {}
                setGeoStatus('Phone: Settings → Apps → Gate Check → Permissions → Location → Allow.', 'warning');
            });
        }

        var geoEircodeInput = document.getElementById('geo-eircode-input');
        var geoEircodeBtn = document.getElementById('geo-eircode-btn');
        if (geoEircodeBtn) {
            geoEircodeBtn.addEventListener('click', function () {
                var code = geoEircodeInput ? String(geoEircodeInput.value || '').trim() : '';
                if (!code) {
                    setGeoStatus('Enter an Eircode first.', 'error');
                    return;
                }
                geoEircodeBtn.disabled = true;
                setGeoStatus('Looking up Eircode…', 'warning');
                var body = new FormData();
                body.set('action', 'geo_eircode');
                body.set('csrf_token', geoCsrf ? geoCsrf.value : '');
                body.set('eircode', code);
                fetch(window.location.pathname, {
                    method: 'POST',
                    body: body,
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                }).then(function (res) { return res.json().then(function (data) { return { ok: res.ok, data: data }; }); })
                .then(function (pack) {
                    var data = pack.data || {};
                    if (!data.ok) {
                        setGeoStatus(data.message || 'Eircode lookup failed', 'error');
                        return;
                    }
                    if (geoLat) geoLat.value = String(data.lat);
                    if (geoLng) geoLng.value = String(data.lng);
                    if (geoEircode) geoEircode.value = data.eircode || code;
                    if (geoPlace) geoPlace.textContent = 'Pin from Eircode ' + (data.eircode || code) + ' — Confirm to lock.';
                    setGeoStatus(data.message || 'Pin ready. Confirm to lock.', 'success');
                    syncGeoConfirm();
                }).catch(function () {
                    setGeoStatus('Eircode lookup failed. Try again.', 'error');
                }).then(function () {
                    geoEircodeBtn.disabled = false;
                });
            });
        }
        if (geoLat) geoLat.addEventListener('input', syncGeoConfirm);
        if (geoLng) geoLng.addEventListener('input', syncGeoConfirm);

        if (geoConfirm) {
            geoConfirm.addEventListener('click', function () {
                var eventId = geoEventId ? parseInt(geoEventId.value || '0', 10) : 0;
                var lat = geoLat ? parseFloat(geoLat.value) : NaN;
                var lng = geoLng ? parseFloat(geoLng.value) : NaN;
                var radius = geoRadius ? parseInt(geoRadius.value || '0', 10) : 0;
                if (!eventId || isNaN(lat) || isNaN(lng)) {
                    setGeoStatus('Pick an event and capture location first.', 'error');
                    return;
                }
                if (isNaN(radius) || radius < radiusMin || radius > radiusMax) {
                    setGeoStatus('Range must be ' + radiusMin + '–' + radiusMax + ' metres.', 'error');
                    return;
                }
                if (!window.confirm('Lock geo for this event?\n\nStaff will only sign in within ' + radius + ' m of this pin.')) {
                    return;
                }
                geoConfirm.disabled = true;
                setGeoStatus('Saving…', 'warning');
                var body = new FormData();
                body.set('action', 'geo_lock');
                body.set('csrf_token', geoCsrf ? geoCsrf.value : '');
                body.set('event_id', String(eventId));
                body.set('lat', String(lat));
                body.set('lng', String(lng));
                body.set('radius_m', String(radius));
                body.set('eircode', geoEircode ? geoEircode.value : '');
                body.set('address', geoAddress ? geoAddress.value : '');
                fetch(window.location.pathname, {
                    method: 'POST',
                    body: body,
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                }).then(function (res) { return res.json().then(function (data) { return { ok: res.ok, data: data }; }); })
                .then(function (pack) {
                    var data = pack.data || {};
                    if (!data.ok) {
                        setGeoStatus(data.message || 'Could not lock geo', 'error');
                        syncGeoConfirm();
                        return;
                    }
                    var d = data.data || {};
                    setGeoStatus((data.message || 'Locked') + (d.signin_radius_m ? ' · ' + d.signin_radius_m + ' m' : ''), 'success');
                    if (geoPlace) geoPlace.innerHTML = '<strong>Locked.</strong> Staff Sign and Staff Sign Hub show the same pin.';
                    syncGeoConfirm();
                }).catch(function () {
                    setGeoStatus('Could not save geocode. Try again.', 'error');
                    syncGeoConfirm();
                });
            });
        }
        if (geoRadius) geoRadius.addEventListener('input', syncGeoConfirm);
        syncGeoConfirm();

        if (licence) {
            licence.addEventListener('input', function () {
                var start = licence.selectionStart, end = licence.selectionEnd;
                licence.value = licence.value.toUpperCase();
                if (start != null && end != null) {
                    try { licence.setSelectionRange(start, end); } catch (e) {}
                }
            });
        }
        if (form) {
            form.addEventListener('submit', function (e) {
                if (!window.fetch) return;
                e.preventDefault();
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.textContent = 'Looking up…';
                }
                var body = new FormData(form);
                body.set('action', 'psa_lookup');
                fetch(window.location.pathname, {
                    method: 'POST',
                    body: body,
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                }).then(function (res) { return res.json(); }).then(function (data) {
                    renderPsa(data || {});
                }).catch(function () {
                    resultBox.innerHTML = '<div class="flash flash-error">Could not reach the PSA lookup. Try again.</div>';
                }).then(function () {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Look up on PSA website';
                    }
                });
            });
        }
    })();
    </script>
</body>
</html>

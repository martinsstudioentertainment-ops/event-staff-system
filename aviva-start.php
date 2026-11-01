<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/event-start-time-form.php';

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');

$pdo = getDB();
$flash = null;
$nameValue = trim((string) ($_SESSION['aviva_start_name'] ?? ''));
$slots = [];
$eventOpen = false;
$step = 'name';
$person = null;

try {
    ensureEventStartTimeSchema($pdo);
    $event = avivaOct1StartTimeEvent($pdo);
    $eventOpen = $event !== null;
    if ($eventOpen) {
        $slots = eventStartTimeSlotSummaries($pdo, (int) $event['id']);
    }
} catch (Throwable $e) {
    error_log('[EventStaff] aviva-start: ' . $e->getMessage());
    $eventOpen = false;
}

$hold = $_SESSION['aviva_start_hold'] ?? null;
if (!is_array($hold) || (int) ($hold['expires'] ?? 0) < time()) {
    $hold = null;
    unset($_SESSION['aviva_start_hold']);
}

if (isset($_GET['reset'])) {
    unset($_SESSION['aviva_start_hold'], $_SESSION['aviva_start_name'], $_SESSION['aviva_start_flash']);
    header('Location: aviva-start.php');
    exit;
}

if (isset($_SESSION['aviva_start_flash']) && is_array($_SESSION['aviva_start_flash'])) {
    $flash = $_SESSION['aviva_start_flash'];
    unset($_SESSION['aviva_start_flash']);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $eventOpen) {
    $action = trim((string) ($_POST['action'] ?? ''));
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        $_SESSION['aviva_start_flash'] = ['type' => 'error', 'message' => 'Refresh the page and try again.'];
        header('Location: aviva-start.php');
        exit;
    }

    if ($action === 'continue') {
        $nameValue = trim((string) ($_POST['name'] ?? ''));
        $_SESSION['aviva_start_name'] = $nameValue;
        $fails = (int) ($_SESSION['aviva_start_fails'] ?? 0);
        if ($nameValue === '') {
            $_SESSION['aviva_start_flash'] = ['type' => 'error', 'message' => 'Enter your name.'];
        } elseif ($fails >= 25) {
            $_SESSION['aviva_start_flash'] = ['type' => 'error', 'message' => 'Too many tries. Wait and try again.'];
        } else {
            $found = lookupAvivaOct1Roster($pdo, $nameValue);
            if (empty($found['ok'])) {
                if (($found['code'] ?? '') === 'not_found') {
                    $_SESSION['aviva_start_fails'] = $fails + 1;
                }
                unset($_SESSION['aviva_start_hold']);
                $_SESSION['aviva_start_flash'] = ['type' => 'error', 'message' => (string) $found['message']];
            } else {
                $_SESSION['aviva_start_fails'] = 0;
                $_SESSION['aviva_start_hold'] = [
                    'id'      => (int) $found['registration_id'],
                    'expires' => time() + 900,
                ];
                $_SESSION['aviva_start_name'] = (string) $found['name'];
                header('Location: aviva-start.php?step=' . (($found['code'] ?? '') === 'locked' ? 'locked' : 'time'));
                exit;
            }
        }
        header('Location: aviva-start.php');
        exit;
    }

    if ($action === 'confirm') {
        $registrationId = is_array($hold) ? (int) ($hold['id'] ?? 0) : 0;
        $person = $registrationId > 0 ? avivaOct1RegistrationById($pdo, $registrationId) : null;
        if ($person === null) {
            unset($_SESSION['aviva_start_hold']);
            $_SESSION['aviva_start_flash'] = ['type' => 'error', 'message' => 'Enter your name again.'];
            header('Location: aviva-start.php');
            exit;
        }
        if ($person['start_time'] !== '') {
            header('Location: aviva-start.php?step=locked');
            exit;
        }
        $result = saveAvivaOct1StartTime($pdo, $person['name'], trim((string) ($_POST['start_time'] ?? '')));
        if (!empty($result['ok']) || ($result['code'] ?? '') === 'locked') {
            header('Location: aviva-start.php?step=locked');
            exit;
        }
        $_SESSION['aviva_start_flash'] = ['type' => 'error', 'message' => (string) $result['message']];
        header('Location: aviva-start.php?step=time');
        exit;
    }
}

$requested = trim((string) ($_GET['step'] ?? ''));
if ($eventOpen && is_array($hold)) {
    $person = avivaOct1RegistrationById($pdo, (int) ($hold['id'] ?? 0));
    if ($person === null) {
        unset($_SESSION['aviva_start_hold']);
        $step = 'name';
    } elseif ($person['start_time'] !== '' && ($requested === 'locked' || $requested === 'time')) {
        $step = 'locked';
    } elseif ($requested === 'time' && $person['start_time'] === '') {
        $step = 'time';
    }
}

$openSlots = array_values(array_filter($slots, static fn (array $slot): bool => empty($slot['is_full'])));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<title>Start time — Olasentra</title>
<style>
    :root { color-scheme: dark; }
    * { box-sizing: border-box; }
    html, body { height: 100%; }
    body {
        margin: 0;
        min-height: 100%;
        font-family: "Segoe UI", "Helvetica Neue", Arial, sans-serif;
        background:
            radial-gradient(ellipse 70% 42% at 50% 0%, rgba(139, 92, 246, 0.38), transparent 58%),
            #050507;
        color: #fff;
    }
    main {
        min-height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 2.5rem 1.25rem 3rem;
    }
    .panel {
        width: min(26.5rem, 100%);
        text-align: center;
    }
    .logo {
        width: 5.5rem;
        height: 5.5rem;
        border-radius: 1.45rem;
        display: block;
        margin: 0 auto 1.75rem;
        box-shadow: 0 18px 50px rgba(109, 40, 217, 0.45);
    }
    h1 {
        margin: 0;
        font-size: 2rem;
        line-height: 1.1;
        letter-spacing: -0.045em;
        font-weight: 700;
    }
    .time-hero {
        margin: 0.35rem 0 0;
        font-size: 3.4rem;
        letter-spacing: -0.06em;
        font-weight: 750;
    }
    .sub {
        margin: 0.7rem 0 0;
        color: #a1a1aa;
        font-size: 1rem;
    }
    .who { margin: 0.35rem 0 0; color: #fff; font-size: 1.05rem; font-weight: 650; }
    form { margin-top: 1.75rem; text-align: left; }
    label {
        display: block;
        margin: 0 0 0.45rem;
        color: #a1a1aa;
        font-size: 0.82rem;
        font-weight: 650;
        letter-spacing: 0.01em;
    }
    input, select {
        width: 100%;
        height: 3.5rem;
        border-radius: 1rem;
        border: 1px solid #2c2c31;
        background: #141417;
        color: #fff;
        font: inherit;
        font-size: 1.05rem;
        padding: 0 1rem;
    }
    input:focus, select:focus {
        outline: none;
        border-color: #a78bfa;
        box-shadow: 0 0 0 4px rgba(167, 139, 250, 0.18);
    }
    .select-wrap { position: relative; }
    select {
        appearance: none;
        padding-right: 2.6rem;
    }
    .select-wrap::after {
        content: "";
        position: absolute;
        right: 1.1rem;
        top: 50%;
        width: 0.55rem;
        height: 0.55rem;
        border-right: 2px solid #fff;
        border-bottom: 2px solid #fff;
        transform: translateY(-70%) rotate(45deg);
        pointer-events: none;
    }
    button {
        width: 100%;
        height: 3.5rem;
        margin-top: 1.15rem;
        border: 0;
        border-radius: 999px;
        background: #fff;
        color: #09090b;
        font: inherit;
        font-size: 1.05rem;
        font-weight: 750;
        cursor: pointer;
    }
    button:active { transform: scale(0.99); }
    .note {
        margin: 0.85rem 0 0;
        color: #71717a;
        font-size: 0.86rem;
        text-align: center;
    }
    .msg {
        margin: 1.15rem 0 0;
        padding: 0.85rem 1rem;
        border-radius: 1rem;
        text-align: left;
        font-size: 0.95rem;
    }
    .msg.error { background: #3f1518; color: #fecaca; }
    .msg.ok { background: #052e1b; color: #bbf7d0; }
    .lock {
        width: 2.4rem;
        height: 2.4rem;
        margin: 1.2rem auto 0;
        border-radius: 999px;
        display: grid;
        place-items: center;
        background: #18181b;
        color: #e4e4e7;
        font-size: 1rem;
    }
    .again {
        display: inline-block;
        margin-top: 1.4rem;
        color: #d4d4d8;
        font-size: 0.92rem;
        text-decoration: none;
    }
    .again:hover { color: #fff; }
    .closed { color: #a1a1aa; }
</style>
</head>
<body>
<main>
    <section class="panel">
        <img class="logo" src="new-logo.png" alt="Olasentra">
        <?php if (!$eventOpen): ?>
            <h1>Start time</h1>
            <p class="closed">This event is not open for start times.</p>
        <?php elseif ($step === 'locked' && is_array($person)): ?>
            <p class="lock" aria-hidden="true">&#128274;</p>
            <h1>Locked</h1>
            <p class="time-hero"><?= h((string) $person['label']) ?></p>
            <p class="who"><?= h((string) $person['name']) ?></p>
            <a class="again" href="aviva-start.php?reset=1">Check another name</a>
        <?php elseif ($step === 'time' && is_array($person)): ?>
            <h1>Choose a time</h1>
            <p class="who"><?= h((string) $person['name']) ?></p>
            <p class="sub">AVIVA Stadium · 1 October</p>
            <?php if (is_array($flash)): ?>
                <p class="msg <?= ($flash['type'] ?? '') === 'ok' ? 'ok' : 'error' ?>"><?= h((string) ($flash['message'] ?? '')) ?></p>
            <?php endif; ?>
            <form method="post" action="aviva-start.php">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="confirm">
                <label for="start_time">Starting time</label>
                <div class="select-wrap">
                    <select id="start_time" name="start_time" required>
                        <option value="" disabled selected>Choose a time</option>
                        <?php foreach ($slots as $slot): ?>
                            <?php if (!empty($slot['is_full'])) { continue; } ?>
                            <option value="<?= h((string) $slot['start_time']) ?>"><?= h((string) $slot['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" <?= $openSlots === [] ? 'disabled' : '' ?>>Lock this time</button>
                <p class="note">Once you lock a time, only an admin can change it.</p>
            </form>
            <a class="again" href="aviva-start.php?reset=1">Not you?</a>
        <?php else: ?>
            <h1>Start time</h1>
            <p class="sub">AVIVA Stadium · 1 October</p>
            <?php if (is_array($flash)): ?>
                <p class="msg <?= ($flash['type'] ?? '') === 'ok' ? 'ok' : 'error' ?>"><?= h((string) ($flash['message'] ?? '')) ?></p>
            <?php endif; ?>
            <form method="post" action="aviva-start.php">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="continue">
                <label for="name">Name</label>
                <input id="name" name="name" type="text" autocomplete="name" required maxlength="120" value="<?= h($nameValue) ?>" placeholder="First name and surname">
                <button type="submit">Continue</button>
            </form>
        <?php endif; ?>
    </section>
</main>
</body>
</html>

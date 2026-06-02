<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/events-repository.php';
require_once __DIR__ . '/../includes/attendance-repository.php';

require_once __DIR__ . '/../includes/share-meta.php';

requireAdminCapability('events');

$id    = (int) ($_GET['id'] ?? 0);
$pdo   = getDB();
$event = $id > 0 ? getEventById($pdo, $id) : null;

if (!$event) {
    setAdminFlash('error', 'Event not found.');
    header('Location: events.php');
    exit;
}

$token     = ensureEventSigninToken($pdo, $id);
$venueUrl  = $token ? getEventVenueSigninUrl($token, $pdo) : '';
$emailUrl  = $token ? getEventEmailSigninUrl($token, $pdo) : '';
$qrUrl     = $venueUrl !== '' ? getQrCodeImageUrl($venueUrl, 320) : '';

$siteName   = getSiteName($pdo);
$assetBase  = '../';
$shareImage = getShareImagePreviewUrl($pdo, $assetBase);
$emailShareTitle = $event['name'] . ' — Email Sign-in';
$emailShareDesc = 'Sign in for ' . $event['name'] . ' on ' . formatEventDateLabel($event['event_date']) . ' at ' . formatEventLocationLabel($event);
$venueShareTitle = $event['name'] . ' — Venue Sign-in';
$venueShareDesc = 'Scan at the venue to sign in for ' . $event['name'] . '. GPS required within 100m.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Event Sign-in — <?= h($event['name']) ?></title>
    <?php if ($emailUrl !== ''): ?>
    <?php renderShareMeta([
        'title'       => $emailShareTitle,
        'description' => $emailShareDesc,
        'url'         => $emailUrl,
        'site_name'   => $siteName,
    ], $pdo); ?>
    <?php endif; ?>
    <?php include __DIR__ . '/../includes/pwa-head.php'; ?>
    <link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/admin.css">
    <style>
        body { background: #fff; color: #111; padding: 1.5rem; max-width: 820px; margin: 0 auto; }
        .print-toolbar { display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
        .signin-links { display: grid; gap: 1.25rem; margin-bottom: 1.5rem; }
        .signin-link-card {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 1.25rem;
            background: #f8fafc;
        }
        .signin-link-card h2 { font-size: 1.1rem; margin: 0 0 0.5rem; }
        .signin-link-card p { margin: 0 0 0.75rem; color: #475569; font-size: 14px; line-height: 1.5; }
        .signin-link-card__copy {
            position: relative;
            z-index: 1;
            margin-bottom: 0.75rem;
        }
        .share-preview-card__image img {
            display: block;
            width: 100%;
            height: auto;
            aspect-ratio: 1.91 / 1;
            object-fit: cover;
        }
        .event-sign-sheet {
            border: 2px solid #111;
            border-radius: 16px;
            padding: 2rem;
            text-align: center;
            page-break-inside: avoid;
        }
        .event-sign-sheet__brand {
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #64748b;
            margin-bottom: 0.75rem;
        }
        .event-sign-sheet__title { font-size: 28px; font-weight: 700; margin: 0 0 0.5rem; }
        .event-sign-sheet__meta { font-size: 16px; color: #334155; margin-bottom: 1.5rem; line-height: 1.6; }
        .event-sign-sheet__qr img { margin: 0 auto; display: block; }
        .event-sign-sheet__instructions {
            margin-top: 1.5rem;
            font-size: 15px;
            line-height: 1.6;
            text-align: left;
            background: #f8fafc;
            border-radius: 12px;
            padding: 1rem 1.25rem;
        }
        .event-sign-sheet__instructions ol { margin: 0.5rem 0 0; padding-left: 1.25rem; }
        @media print {
            .print-toolbar, .no-print, .signin-links { display: none !important; }
            body { padding: 0; }
        }
    </style>
</head>
<body class="page-content">
    <div class="print-toolbar no-print">
        <button type="button" class="btn btn--primary" onclick="window.print()">Print Venue QR Sign</button>
        <a href="events.php" class="btn btn--secondary">← Back to Events</a>
        <a href="attendance.php?event_id=<?= (int) $id ?>" class="btn btn--secondary">Attendance</a>
    </div>

    <header class="card__header no-print" style="margin-bottom: 1.5rem;">
        <h1 class="card__title"><?= h($event['name']) ?> — Sign-in options</h1>
        <p class="card__subtitle">Send the email link remotely, or print the QR for staff at the venue.</p>
    </header>

    <div class="signin-links no-print">
        <section class="signin-link-card">
            <h2>1. Email sign-in link (send to staff)</h2>
            <p>Share this link by text, email, or WhatsApp. Staff enter their registration email and <strong>last 4 characters of PPS</strong> — no QR needed.</p>
            <?php if ($emailUrl !== ''): ?>
                <div class="signin-link-card__copy copy-field">
                    <input class="form-input copy-field__input" type="text" id="email-signin-url" readonly value="<?= h($emailUrl) ?>" onclick="this.select()">
                    <button type="button" class="btn btn--primary btn--small copy-field__btn" data-copy-target="email-signin-url">Copy link</button>
                </div>
                <div class="share-preview-grid">
                    <article class="share-preview-card" aria-label="WhatsApp / email link preview">
                        <div class="share-preview-card__image">
                            <img src="<?= h($shareImage) ?>" alt="" width="1200" height="630" loading="lazy" decoding="async">
                        </div>
                        <div class="share-preview-card__body">
                            <p class="share-preview-card__title"><?= h($emailShareTitle) ?></p>
                            <p class="share-preview-card__desc"><?= h($emailShareDesc) ?></p>
                        </div>
                    </article>
                </div>
            <?php endif; ?>
        </section>

        <section class="signin-link-card">
            <h2>2. Venue QR / barcode (at location only)</h2>
            <p>Print and display at the entrance. Staff scan with their phone — GPS must confirm they are at the venue. Email entry on that page also requires being on site.</p>
            <?php if ($venueUrl !== ''): ?>
                <div class="signin-link-card__copy copy-field">
                    <input class="form-input copy-field__input" type="text" id="venue-signin-url" readonly value="<?= h($venueUrl) ?>" onclick="this.select()">
                    <button type="button" class="btn btn--secondary btn--small copy-field__btn" data-copy-target="venue-signin-url">Copy venue link</button>
                </div>
                <div class="share-preview-grid">
                    <article class="share-preview-card" aria-label="Venue link preview">
                        <div class="share-preview-card__image">
                            <img src="<?= h($shareImage) ?>" alt="" width="1200" height="630" loading="lazy" decoding="async">
                        </div>
                        <div class="share-preview-card__body">
                            <p class="share-preview-card__title"><?= h($venueShareTitle) ?></p>
                            <p class="share-preview-card__desc"><?= h($venueShareDesc) ?></p>
                        </div>
                    </article>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <article class="event-sign-sheet">
        <p class="event-sign-sheet__brand"><?= h($siteName) ?></p>
        <h1 class="event-sign-sheet__title">Venue Sign-in</h1>
        <p class="event-sign-sheet__meta">
            <strong><?= h($event['name']) ?></strong><br>
            <?= h(formatEventDateLabel($event['event_date'])) ?> · <?= h(formatEventTimeRangeLabel($event)) ?><br>
            <?= h(formatEventLocationLabel($event)) ?>
        </p>

        <?php if ($qrUrl !== ''): ?>
            <div class="event-sign-sheet__qr">
                <img src="<?= h($qrUrl) ?>" width="320" height="320" alt="Venue sign-in QR code">
            </div>
        <?php endif; ?>

        <div class="event-sign-sheet__instructions">
            <strong>At the venue — scan to sign in</strong>
            <ol>
                <li>Scan this QR code with your phone camera.</li>
                <li>Allow location access (required at the venue).</li>
                <li>Enter your registration email and <strong>last 4 of PPS</strong>, then tap <strong>Check In Now</strong>.</li>
            </ol>
            <p style="margin: 0.75rem 0 0;">Venue sign-in requires GPS within <strong>100 metres</strong> of the event Eircode location.</p>
        </div>
    </article>

    <script>
    (function () {
        function copyText(text) {
            if (navigator.clipboard && window.isSecureContext) {
                return navigator.clipboard.writeText(text);
            }
            return Promise.reject(new Error('clipboard unavailable'));
        }

        function copyFallback(input) {
            var text = input.value || '';
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.cssText = 'position:fixed;left:-9999px;top:0;opacity:0';
            document.body.appendChild(ta);
            ta.focus();
            ta.select();
            var ok = false;
            try {
                ok = document.execCommand('copy');
            } catch (e) {
                ok = false;
            }
            document.body.removeChild(ta);
            if (!ok) {
                input.focus();
                input.select();
            }
            return ok;
        }

        document.querySelectorAll('[data-copy-target]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var input = document.getElementById(btn.getAttribute('data-copy-target') || '');
                if (!input) return;

                var prev = btn.textContent;
                var text = input.value || '';

                function showCopied() {
                    btn.textContent = 'Copied!';
                    btn.classList.add('btn--success');
                    setTimeout(function () {
                        btn.textContent = prev;
                        btn.classList.remove('btn--success');
                    }, 2000);
                }

                function showFailed() {
                    btn.textContent = 'Select link above';
                    setTimeout(function () { btn.textContent = prev; }, 2500);
                }

                copyText(text).then(showCopied).catch(function () {
                    if (copyFallback(input)) {
                        showCopied();
                    } else {
                        showFailed();
                    }
                });
            });
        });
    })();
    </script>
</body>
</html>

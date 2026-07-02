<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/settings-repository.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/includes/site-urls.php';
require_once __DIR__ . '/includes/company.php';
require_once __DIR__ . '/includes/brand-logo.php';
require_once __DIR__ . '/includes/website-content.php';
require_once __DIR__ . '/includes/rich-text.php';
require_once __DIR__ . '/includes/public/homepage-live.php';

try {
    $pdo = getDB();
} catch (Throwable $e) {
    $pdo = null;
}

$web         = initPublicWebsite($pdo, 'home');
$c           = $web['content']['home'];
$live        = getHomepageLiveData($pdo);
$testimonials = getHomepageTestimonials();
$trustItems  = getHomepageTrustIndicators();
$categories  = getHomepageEventCategories();
$howSteps    = $web['content']['how']['steps'] ?? [];
$roleItems   = array_slice($web['content']['roles']['items'] ?? [], 0, 6);

$heroTitle = (string) ($c['hero_title'] ?? '');
$highlight = trim((string) ($c['hero_highlight'] ?? ''));
if ($highlight !== '' && stripos($heroTitle, $highlight) !== false) {
    $heroTitleHtml = preg_replace(
        '/' . preg_quote($highlight, '/') . '/iu',
        '<span class="hp-hero__accent">' . h($highlight) . '</span>',
        $heroTitle,
        1
    ) ?? h($heroTitle);
} else {
    $heroTitleHtml = h($heroTitle);
}

$pageTitle       = 'Home';
$pageDescription = ($c['hero_lead'] ?? '') . ' — ' . $web['companyName'];

include __DIR__ . '/includes/public/layout-top.php';
?>

<div class="hp-page">
    <div class="hp-bg" aria-hidden="true">
        <div class="hp-bg__gradient"></div>
        <div class="hp-bg__orb hp-bg__orb--1"></div>
        <div class="hp-bg__orb hp-bg__orb--2"></div>
        <div class="hp-bg__grid"></div>
    </div>

    <!-- Hero -->
    <section class="hp-hero">
        <div class="hp-container hp-hero__grid">
            <div class="hp-hero__copy">
                <div class="hp-live-badge">
                    <span class="hp-live-badge__dot" aria-hidden="true"></span>
                    <span><?= (int) $live['activity']['open_events'] ?> events open for registration</span>
                </div>
                <p class="hp-eyebrow"><?= h($c['hero_eyebrow'] ?? '') ?></p>
                <h1 class="hp-hero__title"><?= $heroTitleHtml ?></h1>
                <div class="hp-hero__lead rich-content"><?= renderRichText($c['hero_lead'] ?? '') ?></div>
                <div class="hp-hero__actions">
                    <a class="hp-btn hp-btn--primary hp-btn--lg" href="<?= h($web['registrationUrl']) ?>">
                        <?= h($c['cta_primary'] ?? 'Register for events') ?>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                    </a>
                    <a class="hp-btn hp-btn--glass hp-btn--lg" href="<?= h(getWebsitePageUrl('how', $pdo)) ?>">
                        <?= h($c['cta_secondary'] ?? 'See how it works') ?>
                    </a>
                </div>
                <div class="hp-hero__trust-row">
                    <?php foreach (array_slice($trustItems, 0, 3) as $t): ?>
                        <span class="hp-hero__trust-chip">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m5 12 5 5L20 7"/></svg>
                            <?= h($t['title']) ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <aside class="hp-hero__panel" aria-label="Platform snapshot">
                <div class="hp-glass hp-hero__card">
                    <?php renderSiteBrandLogo($pdo, 'hero', '', $web['companyName']); ?>
                    <p class="hp-hero__brand-name"><?= h($web['companyName']) ?></p>
                    <p class="hp-hero__brand-tag"><?= h($pdo ? getCompanyTagline($pdo) : '') ?></p>
                    <div class="hp-hero__mini-stats">
                        <div>
                            <strong data-hp-count="<?= (int) $live['activity']['recent_registrations'] ?>">0</strong>
                            <span>Registrations this week</span>
                        </div>
                        <div>
                            <strong data-hp-count="<?= (int) $live['activity']['approved'] ?>">0</strong>
                            <span>Approved staff</span>
                        </div>
                    </div>
                    <a class="hp-btn hp-btn--primary hp-btn--block" href="<?= h($web['registrationUrl']) ?>">Browse open events</a>
                </div>
            </aside>
        </div>

        <div class="hp-container">
            <div class="hp-stats-bar hp-glass">
                <?php foreach ($live['stats'] as $stat): ?>
                    <article class="hp-stat">
                        <p class="hp-stat__value"
                           <?php if ($stat['animate'] && $stat['numeric'] !== null): ?>
                               data-hp-count="<?= (int) $stat['numeric'] ?>"
                               data-hp-suffix="<?= h($stat['suffix']) ?>"
                           <?php endif; ?>>
                            <?php if ($stat['animate'] && $stat['numeric'] !== null): ?>0<?= h($stat['suffix']) ?><?php else: ?><?= h($stat['value']) ?><?php endif; ?>
                        </p>
                        <p class="hp-stat__label"><?= h($stat['label']) ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- Live events -->
    <section class="hp-section" id="live-events">
        <div class="hp-container">
            <div class="hp-section__head">
                <div>
                    <p class="hp-eyebrow hp-eyebrow--light">Live opportunities</p>
                    <h2 class="hp-heading">Events recruiting staff now</h2>
                    <p class="hp-lead">Real openings on the registration form — pick your shifts and apply in one submission.</p>
                </div>
                <a class="hp-btn hp-btn--glass" href="<?= h($web['registrationUrl']) ?>">Register for all →</a>
            </div>

            <?php if ($live['events'] !== []): ?>
                <div class="hp-events-grid">
                    <?php foreach ($live['events'] as $event): ?>
                        <article class="hp-event-card hp-glass">
                            <div class="hp-event-card__top">
                                <span class="hp-event-card__live">Open</span>
                                <time class="hp-event-card__date"><?= h($event['date']) ?></time>
                            </div>
                            <h3 class="hp-event-card__name"><?= h($event['name']) ?></h3>
                            <?php if ($event['location'] !== ''): ?>
                                <p class="hp-event-card__loc">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 21s7-4.5 7-11a7 7 0 1 0-14 0c0 6.5 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>
                                    <?= h($event['location']) ?>
                                </p>
                            <?php endif; ?>
                            <?php if ($event['staff_needed'] !== null && $event['staff_needed'] > 0): ?>
                                <p class="hp-event-card__meta"><?= (int) $event['staff_needed'] ?> staff needed</p>
                            <?php endif; ?>
                            <a class="hp-event-card__cta" href="<?= h($web['registrationUrl']) ?>">Apply →</a>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="hp-empty hp-glass">
                    <p>New events are added to the roster regularly. <a href="<?= h($web['registrationUrl']) ?>">Open the registration form</a> to see the latest listings.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Staff activity -->
    <section class="hp-section hp-section--activity">
        <div class="hp-container hp-activity">
            <div class="hp-activity__copy">
                <p class="hp-eyebrow hp-eyebrow--light">Platform pulse</p>
                <h2 class="hp-heading">A workforce platform that feels alive</h2>
                <p class="hp-lead">Registrations, approvals, and event staffing — tracked in one operations system built for Irish event work.</p>
                <ul class="hp-activity__list">
                    <li><strong data-hp-count="<?= (int) $live['activity']['recent_registrations'] ?>">0</strong> new registrations in the last 7 days</li>
                    <li><strong data-hp-count="<?= (int) $live['activity']['pending'] ?>">0</strong> applications awaiting review</li>
                    <li><strong data-hp-count="<?= (int) $live['activity']['open_events'] ?>">0</strong> events open right now</li>
                </ul>
            </div>
            <div class="hp-activity__visual hp-glass">
                <div class="hp-activity__ring" aria-hidden="true"></div>
                <div class="hp-activity__center">
                    <span class="hp-activity__big" data-hp-count="<?= (int) $live['activity']['approved'] ?>">0</span>
                    <span class="hp-activity__sub">Approved registrations</span>
                </div>
            </div>
        </div>
    </section>

    <!-- Roles -->
    <section class="hp-section">
        <div class="hp-container">
            <div class="hp-section__head hp-section__head--center">
                <p class="hp-eyebrow hp-eyebrow--light">Roles</p>
                <h2 class="hp-heading"><?= h($c['preview_title'] ?? 'Roles you can register for') ?></h2>
                <div class="hp-lead rich-content"><?= renderRichText($c['preview_desc'] ?? '') ?></div>
            </div>
            <div class="hp-roles-grid">
                <?php foreach ($roleItems as $item): ?>
                    <a class="hp-role-card hp-glass" href="<?= h(getWebsitePageUrl('roles', $pdo)) ?>">
                        <span class="hp-role-card__icon"><?= renderHomeServiceIcon($item['icon'] ?? 'shield') ?></span>
                        <h3><?= h($item['title'] ?? '') ?></h3>
                        <p><?= h($item['desc'] ?? '') ?></p>
                        <span class="hp-role-card__link">View roles →</span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- How it works timeline -->
    <section class="hp-section hp-section--timeline" id="how-it-works">
        <div class="hp-container">
            <div class="hp-section__head">
                <p class="hp-eyebrow hp-eyebrow--purple">How it works</p>
                <h2 class="hp-heading">From registration to event day</h2>
                <p class="hp-lead">Four steps. One form. No agency runaround.</p>
            </div>
            <ol class="hp-timeline">
                <?php foreach ($howSteps as $i => $step): ?>
                    <li class="hp-timeline__item hp-glass">
                        <span class="hp-timeline__num"><?= h($step['num'] ?? sprintf('%02d', $i + 1)) ?></span>
                        <div>
                            <h3><?= h($step['title'] ?? '') ?></h3>
                            <div class="rich-content"><?= renderRichText($step['desc'] ?? '') ?></div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>
            <div class="hp-timeline__cta">
                <a class="hp-btn hp-btn--primary" href="<?= h(getWebsitePageUrl('how', $pdo)) ?>">Full guide</a>
            </div>
        </div>
    </section>

    <!-- Event categories -->
    <section class="hp-section">
        <div class="hp-container">
            <div class="hp-section__head hp-section__head--center">
                <p class="hp-eyebrow hp-eyebrow--light">Coverage</p>
                <h2 class="hp-heading">Event categories across Ireland</h2>
            </div>
            <div class="hp-categories">
                <?php foreach ($categories as $cat): ?>
                    <a class="hp-category hp-glass" href="<?= h(getWebsitePageUrl('events', $pdo)) ?>">
                        <span class="hp-category__icon hp-category__icon--<?= h($cat['slug']) ?>" aria-hidden="true"></span>
                        <span><?= h($cat['label']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
            <div class="hp-categories__pills">
                <?php foreach (($web['content']['events']['types'] ?? []) as $type): ?>
                    <span class="hp-pill"><?= h($type) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- Trust -->
    <section class="hp-section hp-section--trust">
        <div class="hp-container">
            <div class="hp-trust-grid">
                <?php foreach ($trustItems as $t): ?>
                    <article class="hp-trust-card hp-glass">
                        <h3><?= h($t['title']) ?></h3>
                        <p><?= h($t['desc']) ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
            <ul class="hp-trust-list">
                <?php foreach (($web['content']['how']['trust'] ?? getCompanyTrustPoints()) as $point): ?>
                    <li><?= h($point) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </section>

    <!-- Testimonials -->
    <section class="hp-section">
        <div class="hp-container">
            <div class="hp-section__head hp-section__head--center">
                <p class="hp-eyebrow hp-eyebrow--light">Staff voices</p>
                <h2 class="hp-heading">Trusted by event security professionals</h2>
            </div>
            <div class="hp-testimonials">
                <?php foreach ($testimonials as $t): ?>
                    <blockquote class="hp-testimonial hp-glass">
                        <p>“<?= h($t['quote']) ?>”</p>
                        <footer>
                            <strong><?= h($t['author']) ?></strong>
                            <span><?= h($t['role']) ?></span>
                        </footer>
                    </blockquote>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- Staff app -->
    <section class="hp-section hp-section--portal" id="staff-portal">
        <div class="hp-container hp-portal hp-glass">
            <div class="hp-portal__copy">
                <p class="hp-eyebrow hp-eyebrow--purple">Returning staff</p>
                <h2 class="hp-heading">Staff app — sign in with Google</h2>
                <p class="hp-lead">Already registered? Open the staff app and <strong>Continue with Google</strong> using the same Gmail as your registration. View shifts, get GPS shift tracking, and update your profile.</p>
                <div class="hp-portal__actions">
                    <a class="hp-btn hp-btn--primary" href="<?= h($live['staff_portal_url']) ?>">Open staff app</a>
                    <a class="hp-btn hp-btn--glass" href="<?= h(getWebsitePageUrl('faq', $pdo)) ?>">FAQ for returning staff</a>
                </div>
            </div>
            <div class="hp-portal__features">
                <div class="hp-portal__feat"><span>01</span> Sign in with Gmail — stays signed in on your phone</div>
                <div class="hp-portal__feat"><span>02</span> Shift GPS tracking from the app (add to home screen)</div>
                <div class="hp-portal__feat"><span>03</span> Venue QR still uses email + PPS last 4 at the event</div>
            </div>
        </div>
        <div class="hp-container" style="margin-top:1.25rem;">
            <div class="hp-glass" style="padding:1.25rem 1.5rem;border-radius:16px;">
                <p class="hp-eyebrow hp-eyebrow--purple" style="margin-bottom:0.5rem;">Before your shift</p>
                <p class="hp-lead" style="margin:0;font-size:0.95rem;line-height:1.6;">
                    Allow <strong>location</strong> for <?= h($pdo ? parse_url(getRegistrationSiteUrl($pdo), PHP_URL_HOST) ?: 'register.olasentra.com' : 'register.olasentra.com') ?> in phone settings.
                    Add the staff app to your <strong>home screen</strong> and reopen it during breaks — phones cannot track GPS when the browser is fully closed.
                    At the venue, use the <strong>QR sign-in</strong> (email + last 4 of PPS) once; then use the staff app for the rest of the shift.
                </p>
            </div>
        </div>
    </section>

    <!-- CTA -->
    <section class="hp-cta">
        <div class="hp-container hp-cta__inner hp-glass">
            <h2><?= h($c['cta_band_title'] ?? 'Ready to work Ireland\'s best events?') ?></h2>
            <div class="hp-lead rich-content"><?= renderRichText($c['cta_band_desc'] ?? '') ?></div>
            <div class="hp-cta__actions">
                <a class="hp-btn hp-btn--white hp-btn--lg" href="<?= h($web['registrationUrl']) ?>">Start registration</a>
                <a class="hp-btn hp-btn--ghost-light hp-btn--lg" href="<?= h(getWebsitePageUrl('contact', $pdo)) ?>">Contact us</a>
            </div>
        </div>
    </section>
</div>

<?php include __DIR__ . '/includes/public/layout-bottom.php'; ?>

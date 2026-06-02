<?php
/**
 * Public website page content — editable from Admin → Website.
 */

require_once __DIR__ . '/settings-repository.php';
require_once __DIR__ . '/company.php';
require_once __DIR__ . '/brand-logo.php';

/** @return array<string, mixed> */
function getDefaultWebsiteContent(): array
{
    return [
        'global' => [
            'brand_tag'      => 'Event & security job registrations',
            'footer_tagline' => 'Helping people find event and security work across Ireland.',
            'notice_enabled' => true,
            'notice_items'   => [
                'This platform is provided free of charge for PSA-licensed security staff who never get the chance to find event work.',
                'We only list registration opportunities — we are not your employer, event organiser, or security company, and we do not handle wages or payroll.',
                'We accept no liability for payment or any work you do. Pay, hours, and conditions are agreed directly with the event organiser or the security company running that event.',
                'Always confirm who is paying you — the event organiser or the security firm — before you start work.',
            ],
        ],
        'home' => [
            'hero_eyebrow'  => 'Free for PSA-licensed staff · Open registrations',
            'hero_title'    => 'Find security & event work near you',
            'hero_highlight'=> 'security & event work',
            'hero_lead'     => 'Helping people find security, steward, and event jobs — even if you have never done it before. Register online, pick the events you want, and we will guide you through the rest.',
            'cta_primary'   => 'Register for Events',
            'cta_secondary' => 'See how it works',
            'stats' => [
                ['value' => 'Free', 'label' => 'Online registration — no fee to apply'],
                ['value' => '1 form', 'label' => 'Apply for multiple events at once'],
                ['value' => 'Clear', 'label' => 'See which events are open to join'],
                ['value' => 'IE', 'label' => 'Festivals, gigs & events nationwide'],
            ],
            'preview_title' => 'Roles you can register for',
            'preview_desc'  => 'Security, stewarding, gig crew, and general event staff — all in one portal.',
            'cta_band_title' => 'Looking for security or event work?',
            'cta_band_desc'  => 'Register once, select the events you want, and wait for confirmation. It only takes a few minutes.',
        ],
        'roles' => [
            'title'       => 'Roles you can register for',
            'subtitle'    => 'New to event or security work? Pick a role that interests you when you register.',
            'intro'       => 'Each role below links to our registration form. You can apply for multiple events in a single submission.',
            'items'         => getCompanyServices(),
        ],
        'events' => [
            'title'    => 'Events & venues we cover',
            'subtitle' => 'Open events are listed on the registration form — choose the dates that suit you.',
            'intro'    => 'From summer festivals to arena concerts and corporate functions, we list opportunities as they become available.',
            'types'    => getCompanyEventTypes(),
            'steps'    => [
                ['title' => 'Browse open events', 'desc' => 'See active events on the registration form with dates and locations.'],
                ['title' => 'Select your shifts', 'desc' => 'Tick the events you want — one form covers them all.'],
                ['title' => 'Wait for review', 'desc' => 'The event organiser or security company reviews applications and emails you when approved.'],
                ['title' => 'Check in on the day', 'desc' => 'Approved staff receive a check-in link for event day.'],
            ],
        ],
        'how' => [
            'title'    => 'How it works',
            'subtitle' => 'Built to help you get started — even with no prior experience.',
            'intro'    => '',
            'steps'    => [
                ['num' => '01', 'title' => 'Create your profile', 'desc' => 'Fill in one registration form with your details and preferred role.'],
                ['num' => '02', 'title' => 'Choose events', 'desc' => 'Select from the list of open festivals, gigs, and events.'],
                ['num' => '03', 'title' => 'Get confirmed', 'desc' => 'Receive email updates when your application is approved or needs attention.'],
                ['num' => '04', 'title' => 'Work the event', 'desc' => 'Use your check-in link on the day and follow the event briefing.'],
            ],
            'trust' => getCompanyTrustPoints(),
        ],
        'contact' => [
            'title'    => 'Contact us',
            'subtitle' => 'Questions about registering or an event you applied for?',
            'intro'    => 'Send us an email or call — we are happy to help you understand the registration process.',
            'hours'    => 'Mon – Fri, 9:00 – 17:00',
        ],
        'faq' => [
            'title'    => 'Frequently asked questions',
            'subtitle' => 'Common questions about registering for event and security work.',
            'items'    => [
                ['q' => 'Do I need experience?', 'a' => 'Not always. Some events welcome first-timers — read each listing and pick roles that match your comfort level.'],
                ['q' => 'Is registration free?', 'a' => 'Yes. Creating a profile and applying for events on our portal is completely free.'],
                ['q' => 'Can I apply for more than one event?', 'a' => 'Yes. Select all the events you want on a single registration form.'],
                ['q' => 'How will I know if I am approved?', 'a' => 'You will receive an email when your application is reviewed. Approved staff also get a status link and check-in details.'],
                ['q' => 'Who pays me — the event or the security company?', 'a' => 'That depends on the event. Some organisers hire staff directly; others use a security company. We do not pay you — confirm pay, hours, and terms with whoever is running that event before you work.'],
                ['q' => 'What if I registered twice by mistake?', 'a' => 'You cannot register twice for the same event with the same email — the system will block duplicates.'],
            ],
        ],
        'footer' => [
            'columns' => [
                [
                    'title' => 'Explore',
                    'links' => [
                        ['label' => 'Home', 'page' => 'home'],
                        ['label' => 'Roles', 'page' => 'roles'],
                        ['label' => 'Events', 'page' => 'events'],
                        ['label' => 'How it works', 'page' => 'how'],
                        ['label' => 'FAQ', 'page' => 'faq'],
                    ],
                ],
                [
                    'title' => 'Apply',
                    'links' => [
                        ['label' => 'Register now', 'page' => 'register'],
                        ['label' => 'Contact', 'page' => 'contact'],
                    ],
                ],
            ],
        ],
    ];
}

/** @return array<string, string> */
function getWebsitePageRoutes(): array
{
    return [
        'home'     => 'home.php',
        'roles'    => 'roles.php',
        'events'   => 'events-page.php',
        'how'      => 'how-it-works.php',
        'contact'  => 'contact.php',
        'faq'      => 'faq.php',
        'register' => null,
    ];
}

function getWebsitePageUrl(string $page, ?PDO $pdo = null, ?string $basePath = ''): string
{
    if ($page === 'register') {
        return $pdo ? getRegistrationFormUrl($pdo) : 'index.php';
    }

    $routes = getWebsitePageRoutes();
    $file   = $routes[$page] ?? 'home.php';

    return $basePath . $file;
}

/** @return array<string, mixed> */
function getWebsiteContent(?PDO $pdo = null): array
{
    $defaults = getDefaultWebsiteContent();

    if ($pdo === null) {
        return mergeWebsiteWithCompany($defaults, null);
    }

    $raw = trim(getSetting($pdo, 'website_content', ''));
    if ($raw === '') {
        return mergeWebsiteWithCompany($defaults, $pdo);
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return mergeWebsiteWithCompany($defaults, $pdo);
    }

    return mergeWebsiteWithCompany(array_replace_recursive($defaults, $decoded), $pdo);
}

/** @param array<string, mixed> $content */
function mergeWebsiteWithCompany(array $content, ?PDO $pdo): array
{
    if ($pdo !== null) {
        $about = getCompanyAbout($pdo);
        if ($about !== '' && ($content['how']['intro'] ?? '') === '') {
            $content['how']['intro'] = $about;
        }
        if (($content['home']['hero_lead'] ?? '') === '' || str_contains($content['home']['hero_lead'], 'Helping people find')) {
            $tagline = getCompanyTagline($pdo);
            if ($tagline !== '') {
                $content['home']['hero_lead'] = $tagline . '. Register online, pick the events you want, and we will guide you through the rest.';
            }
        }
    }

    return $content;
}

function getWebsiteSection(?PDO $pdo, string $section): array
{
    $all = getWebsiteContent($pdo);

    return is_array($all[$section] ?? null) ? $all[$section] : [];
}

/** @param array<string, mixed> $content */
function saveWebsiteContent(PDO $pdo, array $content): void
{
    $merged = array_replace_recursive(getDefaultWebsiteContent(), $content);
    setSetting($pdo, 'website_content', json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/** @param array<string, mixed> $sectionData */
function saveWebsiteSection(PDO $pdo, string $section, array $sectionData): void
{
    $all = getWebsiteContent($pdo);
    $all[$section] = array_replace_recursive($all[$section] ?? [], $sectionData);
    saveWebsiteContent($pdo, $all);
}

/** @return array<int, array{label: string, url: string, slug: string}> */
function getWebsiteNavItems(?PDO $pdo, string $activeSlug = '', string $basePath = ''): array
{
    return [
        ['label' => 'Home', 'url' => getWebsitePageUrl('home', $pdo, $basePath), 'slug' => 'home'],
        ['label' => 'Roles', 'url' => getWebsitePageUrl('roles', $pdo, $basePath), 'slug' => 'roles'],
        ['label' => 'Events', 'url' => getWebsitePageUrl('events', $pdo, $basePath), 'slug' => 'events'],
        ['label' => 'How it works', 'url' => getWebsitePageUrl('how', $pdo, $basePath), 'slug' => 'how'],
        ['label' => 'FAQ', 'url' => getWebsitePageUrl('faq', $pdo, $basePath), 'slug' => 'faq'],
        ['label' => 'Contact', 'url' => getWebsitePageUrl('contact', $pdo, $basePath), 'slug' => 'contact'],
    ];
}

/** @return string[] */
function getWebsiteNoticeItems(?PDO $pdo): array
{
    $global  = getWebsiteSection($pdo, 'global');
    $enabled = !empty($global['notice_enabled']);
    $items   = $global['notice_items'] ?? [];

    if (!$enabled || !is_array($items)) {
        return [];
    }

    return array_values(array_filter(array_map(static fn ($item): string => trim((string) $item), $items)));
}

function isWebsiteNoticeEnabled(?PDO $pdo): bool
{
    return getWebsiteNoticeItems($pdo) !== [];
}

require_once __DIR__ . '/global-public-site.php';
require_once __DIR__ . '/system-settings.php';

function initPublicWebsite(?PDO $pdo, string $pageSlug): array
{
    if ($pdo !== null) {
        enforceMaintenanceMode($pdo);
    }

    $brand   = getGlobalPublicSiteConfig($pdo);
    $content = getWebsiteContent($pdo);

    return [
        'pdo'             => $pdo,
        'pageSlug'        => $pageSlug,
        'companyName'     => $brand['companyName'],
        'email'           => $brand['email'],
        'phone'           => $brand['phone'],
        'whatsapp'        => $pdo ? getCompanyWhatsapp($pdo) : '',
        'whatsappGroup'   => $pdo ? getCompanyWhatsappGroup($pdo) : '',
        'whatsappUrl'     => $pdo ? formatWhatsappHref(getCompanyWhatsapp($pdo)) : '',
        'registrationUrl' => $brand['registrationUrl'],
        'content'         => $content,
        'themeColor'      => $brand['themeColor'],
        'assetBase'       => '',
        'enablePwa'       => false,
        'logoUrl'         => $brand['logoUrl'],
        'hasLogo'         => $brand['hasLogo'],
    ];
}

<?php

/**
 * Live metrics and content for the premium public homepage.
 */

require_once __DIR__ . '/../events-repository.php';
require_once __DIR__ . '/../staff-repository.php';
require_once __DIR__ . '/../site-urls.php';
require_once __DIR__ . '/../company.php';

/**
 * @return array{
 *     stats: list<array{value: string, numeric: int|null, suffix: string, label: string, animate: bool}>,
 *     events: list<array{id: int, name: string, date: string, location: string, staff_needed: int|null}>,
 *     activity: array{recent_registrations: int, approved: int, pending: int, open_events: int},
 *     staff_portal_url: string
 * }
 */
function getHomepageLiveData(?PDO $pdo): array
{
    $staffPortalUrl = $pdo
        ? rtrim(getRegistrationSiteUrl($pdo), '/') . '/staff-app.php'
        : 'staff-app.php';

    $defaults = [
        'stats' => [
            ['value' => '60+', 'numeric' => 60, 'suffix' => '+', 'label' => 'Staff on the platform', 'animate' => true],
            ['value' => '30+', 'numeric' => 30, 'suffix' => '+', 'label' => 'Events on the roster', 'animate' => true],
            ['value' => 'IE', 'numeric' => null, 'suffix' => '', 'label' => 'Nationwide coverage', 'animate' => false],
            ['value' => 'Free', 'numeric' => null, 'suffix' => '', 'label' => 'Registration — no agency fees', 'animate' => false],
        ],
        'events'      => [],
        'activity'    => [
            'recent_registrations' => 0,
            'approved'             => 0,
            'pending'              => 0,
            'open_events'          => 0,
        ],
        'staff_portal_url' => $staffPortalUrl,
    ];

    if ($pdo === null) {
        return $defaults;
    }

    try {
        $dash   = getDashboardStats($pdo);
        $open   = getEventsOpenForRegistration($pdo);
        $recent = (int) $pdo->query(
            "SELECT COUNT(*) FROM staff_registrations WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        )->fetchColumn();

        $uniqueStaff = (int) ($dash['unique_staff'] ?? 0);
        $totalEvents = countAllEvents($pdo);
        $openCount   = count($open);

        $defaults['stats'] = [
            [
                'value'   => (string) max(1, $uniqueStaff),
                'numeric' => max(1, $uniqueStaff),
                'suffix'  => '+',
                'label'   => 'Active staff profiles',
                'animate' => true,
            ],
            [
                'value'   => (string) max(1, (int) ($dash['approved_registrations'] ?? 0)),
                'numeric' => max(1, (int) ($dash['approved_registrations'] ?? 0)),
                'suffix'  => '+',
                'label'   => 'Approved registrations',
                'animate' => true,
            ],
            [
                'value'   => (string) max(1, $openCount),
                'numeric' => max(1, $openCount),
                'suffix'  => '',
                'label'   => 'Live opportunities open now',
                'animate' => true,
            ],
            [
                'value'   => (string) max(1, $totalEvents),
                'numeric' => max(1, $totalEvents),
                'suffix'  => '+',
                'label'   => 'Events on the summer roster',
                'animate' => true,
            ],
        ];

        $defaults['activity'] = [
            'recent_registrations' => $recent,
            'approved'             => (int) ($dash['approved_registrations'] ?? 0),
            'pending'              => (int) ($dash['pending_registrations'] ?? 0),
            'open_events'          => $openCount,
        ];

        foreach (array_slice($open, 0, 8) as $row) {
            $defaults['events'][] = [
                'id'           => (int) ($row['id'] ?? 0),
                'name'         => (string) ($row['name'] ?? ''),
                'date'         => formatEventDateLabel((string) ($row['event_date'] ?? '')),
                'location'     => trim((string) ($row['location'] ?? '')),
                'staff_needed' => isset($row['staff_needed']) ? (int) $row['staff_needed'] : null,
            ];
        }
    } catch (Throwable $e) {
        error_log('[Homepage] live data: ' . $e->getMessage());
    }

    return $defaults;
}

/** @return list<array{quote: string, author: string, role: string}> */
function getHomepageTestimonials(): array
{
    return [
        [
            'quote'  => 'I registered for multiple summer concerts in one form. Clear events, fast confirmation, and a proper check-in link on the day.',
            'author' => 'S.K.',
            'role'   => 'Door Supervisor · Dublin',
        ],
        [
            'quote'  => 'Returning staff can update PSA details in the portal without re-registering. That alone saves hours every season.',
            'author' => 'M.N.',
            'role'   => 'Static Security · Cork',
        ],
        [
            'quote'  => 'The platform feels professional — you see which events are open, apply once, and get email updates when approved.',
            'author' => 'A.O.',
            'role'   => 'DSP · Galway',
        ],
    ];
}

/** @return list<array{icon: string, title: string, desc: string}> */
function getHomepageTrustIndicators(): array
{
    return [
        ['icon' => 'shield', 'title' => 'PSA-licensed focus', 'desc' => 'Built for Door Supervisor and Static security registrations across Ireland.'],
        ['icon' => 'lock', 'title' => 'Secure staff portal', 'desc' => 'Encrypted check-in links, profile updates, and status tracking for approved staff.'],
        ['icon' => 'check', 'title' => 'Transparent process', 'desc' => 'Free registration portal — we connect you to events, not employ you directly.'],
        ['icon' => 'map', 'title' => 'Nationwide events', 'desc' => 'Festivals, arena shows, clubs, and corporate venues listed as they go live.'],
    ];
}

/** @return list<array{icon: string, label: string, slug: string}> */
function getHomepageEventCategories(): array
{
    return [
        ['icon' => 'music', 'label' => 'Concerts & arena shows', 'slug' => 'concerts'],
        ['icon' => 'festival', 'label' => 'Festivals & outdoor', 'slug' => 'festivals'],
        ['icon' => 'club', 'label' => 'Nightlife & clubs', 'slug' => 'nightlife'],
        ['icon' => 'corp', 'label' => 'Corporate & VIP', 'slug' => 'corporate'],
        ['icon' => 'sport', 'label' => 'Sporting venues', 'slug' => 'sport'],
        ['icon' => 'static', 'label' => 'Static & site security', 'slug' => 'static'],
    ];
}

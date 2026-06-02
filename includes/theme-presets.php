<?php
/**
 * Event Staff System — Interface theme presets (Security, Steward, Gig, Events)
 */

require_once __DIR__ . '/settings-repository.php';

/**
 * @return array<string, array<string, string>>
 */
function getThemePresets(): array
{
    return [
        'security-classic-blue' => [
            'label' => 'Classic Security Blue', 'category' => 'security',
            'description' => 'Trusted navy blue — standard corporate security',
            'primary' => '#1e40af', 'sidebar' => '#0f172a', 'background' => '#eff6ff',
            'gradient' => 'linear-gradient(135deg, #dbeafe 0%, #f8fafc 50%, #e0e7ff 100%)',
            'font' => 'inter', 'subtitle' => 'Security Staff Registration',
        ],
        'security-midnight-guard' => [
            'label' => 'Midnight Guard', 'category' => 'security',
            'description' => 'Dark, authoritative night-shift security look',
            'primary' => '#2563eb', 'sidebar' => '#020617', 'background' => '#f1f5f9',
            'gradient' => 'linear-gradient(135deg, #0f172a 0%, #1e293b 40%, #1e3a8a 100%)',
            'font' => 'roboto', 'subtitle' => 'Night Security Registration',
        ],
        'security-tactical-black' => [
            'label' => 'Tactical Black', 'category' => 'security',
            'description' => 'Black & amber — ops / tactical security teams',
            'primary' => '#d97706', 'sidebar' => '#111827', 'background' => '#f9fafb',
            'gradient' => 'linear-gradient(135deg, #111827 0%, #1f2937 50%, #451a03 100%)',
            'font' => 'roboto', 'subtitle' => 'Tactical Security Team',
        ],
        'security-navy-gold' => [
            'label' => 'Navy & Gold', 'category' => 'security',
            'description' => 'Premium venue security — navy with gold accents',
            'primary' => '#b45309', 'sidebar' => '#1e3a5f', 'background' => '#fffbeb',
            'gradient' => 'linear-gradient(135deg, #1e3a5f 0%, #fef3c7 60%, #fffbeb 100%)',
            'font' => 'poppins', 'subtitle' => 'Venue Security Registration',
        ],
        'security-steel-grey' => [
            'label' => 'Steel Grey', 'category' => 'security',
            'description' => 'Industrial steel — warehouses & logistics security',
            'primary' => '#475569', 'sidebar' => '#1e293b', 'background' => '#f8fafc',
            'gradient' => 'linear-gradient(135deg, #cbd5e1 0%, #f1f5f9 50%, #e2e8f0 100%)',
            'font' => 'inter', 'subtitle' => 'Industrial Security Staff',
        ],
        'security-forest-patrol' => [
            'label' => 'Forest Patrol', 'category' => 'security',
            'description' => 'Outdoor festivals & green-field event security',
            'primary' => '#15803d', 'sidebar' => '#14532d', 'background' => '#f0fdf4',
            'gradient' => 'linear-gradient(135deg, #dcfce7 0%, #f0fdf4 50%, #bbf7d0 100%)',
            'font' => 'poppins', 'subtitle' => 'Outdoor Event Security',
        ],
        'security-crimson-shield' => [
            'label' => 'Crimson Shield', 'category' => 'security',
            'description' => 'High-alert red — stadium & major event security',
            'primary' => '#b91c1c', 'sidebar' => '#450a0a', 'background' => '#fef2f2',
            'gradient' => 'linear-gradient(135deg, #fecaca 0%, #fef2f2 50%, #fee2e2 100%)',
            'font' => 'roboto', 'subtitle' => 'Stadium Security Registration',
        ],
        'security-royal-protection' => [
            'label' => 'Royal Protection', 'category' => 'security',
            'description' => 'VIP & executive protection — deep indigo',
            'primary' => '#4f46e5', 'sidebar' => '#312e81', 'background' => '#eef2ff',
            'gradient' => 'linear-gradient(135deg, #c7d2fe 0%, #eef2ff 50%, #e0e7ff 100%)',
            'font' => 'poppins', 'subtitle' => 'VIP Security Registration',
        ],
        'security-charcoal-ops' => [
            'label' => 'Charcoal Ops', 'category' => 'security',
            'description' => 'Modern charcoal with cyan — tech & CCTV teams',
            'primary' => '#0891b2', 'sidebar' => '#18181b', 'background' => '#ecfeff',
            'gradient' => 'linear-gradient(135deg, #18181b 0%, #27272a 40%, #164e63 100%)',
            'font' => 'inter', 'subtitle' => 'Operations Security Team',
        ],
        'security-army-green' => [
            'label' => 'Army Green', 'category' => 'security',
            'description' => 'Military-style green for crowd perimeter teams',
            'primary' => '#4d7c0f', 'sidebar' => '#365314', 'background' => '#f7fee7',
            'gradient' => 'linear-gradient(135deg, #ecfccb 0%, #f7fee7 50%, #d9f99d 100%)',
            'font' => 'roboto', 'subtitle' => 'Perimeter Security Staff',
        ],
        'security-corporate-guard' => [
            'label' => 'Corporate Guard', 'category' => 'security',
            'description' => 'Clean corporate blue-grey for office & conference security',
            'primary' => '#0369a1', 'sidebar' => '#0c4a6e', 'background' => '#f0f9ff',
            'gradient' => 'linear-gradient(135deg, #e0f2fe 0%, #f0f9ff 50%, #bae6fd 100%)',
            'font' => 'inter', 'subtitle' => 'Corporate Security Registration',
        ],
        'security-euro-night' => [
            'label' => 'Euro Night', 'category' => 'security',
            'description' => 'European nightlife & club door security',
            'primary' => '#7c3aed', 'sidebar' => '#0c0a09', 'background' => '#faf5ff',
            'gradient' => 'linear-gradient(135deg, #1e1b4b 0%, #312e81 40%, #581c87 100%)',
            'font' => 'poppins', 'subtitle' => 'Nightlife Security Staff',
        ],
        'security-access-control' => [
            'label' => 'Access Control', 'category' => 'security',
            'description' => 'Badge & gate access — teal professional',
            'primary' => '#0d9488', 'sidebar' => '#134e4a', 'background' => '#f0fdfa',
            'gradient' => 'linear-gradient(135deg, #ccfbf1 0%, #f0fdfa 50%, #99f6e4 100%)',
            'font' => 'inter', 'subtitle' => 'Access Control Registration',
        ],
        'security-border-blue' => [
            'label' => 'Border Blue', 'category' => 'security',
            'description' => 'Strong border-control inspired deep blue',
            'primary' => '#1d4ed8', 'sidebar' => '#172554', 'background' => '#eff6ff',
            'gradient' => 'linear-gradient(135deg, #172554 0%, #1e3a8a 30%, #dbeafe 100%)',
            'font' => 'roboto', 'subtitle' => 'Checkpoint Security Registration',
        ],
        'steward-emerald' => [
            'label' => 'Steward Emerald', 'category' => 'steward',
            'description' => 'Friendly green for festival stewards',
            'primary' => '#059669', 'sidebar' => '#064e3b', 'background' => '#ecfdf5',
            'gradient' => 'linear-gradient(135deg, #a7f3d0 0%, #ecfdf5 50%, #d1fae5 100%)',
            'font' => 'poppins', 'subtitle' => 'Event Steward Registration',
        ],
        'steward-welcoming-teal' => [
            'label' => 'Welcoming Teal', 'category' => 'steward',
            'description' => 'Warm, approachable steward team look',
            'primary' => '#0f766e', 'sidebar' => '#115e59', 'background' => '#f0fdfa',
            'gradient' => 'linear-gradient(135deg, #99f6e4 0%, #f0fdfa 50%, #ccfbf1 100%)',
            'font' => 'poppins', 'subtitle' => 'Guest Services & Stewarding',
        ],
        'steward-crowd-orange' => [
            'label' => 'Crowd Control Orange', 'category' => 'steward',
            'description' => 'High-visibility orange for crowd stewards',
            'primary' => '#ea580c', 'sidebar' => '#7c2d12', 'background' => '#fff7ed',
            'gradient' => 'linear-gradient(135deg, #fed7aa 0%, #fff7ed 50%, #ffedd5 100%)',
            'font' => 'roboto', 'subtitle' => 'Crowd Steward Registration',
        ],
        'steward-festival-purple' => [
            'label' => 'Festival Purple', 'category' => 'steward',
            'description' => 'Colourful festival & gig stewarding',
            'primary' => '#9333ea', 'sidebar' => '#581c87', 'background' => '#faf5ff',
            'gradient' => 'linear-gradient(135deg, #e9d5ff 0%, #faf5ff 50%, #f3e8ff 100%)',
            'font' => 'poppins', 'subtitle' => 'Festival Steward Registration',
        ],
        'gig-concert-red' => [
            'label' => 'Concert Red', 'category' => 'gig',
            'description' => 'Bold red for live music & concert staff',
            'primary' => '#dc2626', 'sidebar' => '#7f1d1d', 'background' => '#fef2f2',
            'gradient' => 'linear-gradient(135deg, #fca5a5 0%, #fef2f2 50%, #fee2e2 100%)',
            'font' => 'poppins', 'subtitle' => 'Concert Staff Registration',
        ],
        'gig-live-stage' => [
            'label' => 'Live Stage', 'category' => 'gig',
            'description' => 'Pink & magenta — arena & stage crew',
            'primary' => '#db2777', 'sidebar' => '#831843', 'background' => '#fdf2f8',
            'gradient' => 'linear-gradient(135deg, #fbcfe8 0%, #fdf2f8 50%, #fce7f3 100%)',
            'font' => 'poppins', 'subtitle' => 'Live Event Staff Registration',
        ],
        'gig-outdoor-sky' => [
            'label' => 'Outdoor Sky', 'category' => 'gig',
            'description' => 'Open-air gigs & summer festival staff',
            'primary' => '#0284c7', 'sidebar' => '#0c4a6e', 'background' => '#f0f9ff',
            'gradient' => 'linear-gradient(135deg, #7dd3fc 0%, #f0f9ff 50%, #bae6fd 100%)',
            'font' => 'poppins', 'subtitle' => 'Outdoor Gig Staff Registration',
        ],
        'gig-club-neon' => [
            'label' => 'Club Neon', 'category' => 'gig',
            'description' => 'Neon purple — club & DJ event staff',
            'primary' => '#c026d3', 'sidebar' => '#701a75', 'background' => '#fdf4ff',
            'gradient' => 'linear-gradient(135deg, #1e1b4b 0%, #701a75 40%, #fdf4ff 100%)',
            'font' => 'inter', 'subtitle' => 'Club Event Staff Registration',
        ],
        'events-professional' => [
            'label' => 'Professional Blue', 'category' => 'events',
            'description' => 'Clean default for mixed event staff',
            'primary' => '#2563eb', 'sidebar' => '#111827', 'background' => '#f1f5f9',
            'gradient' => 'linear-gradient(135deg, #e2e8f0 0%, #f8fafc 50%, #e0e7ff 100%)',
            'font' => 'poppins', 'subtitle' => 'Event Staff Registration',
        ],
        'events-minimal-slate' => [
            'label' => 'Minimal Slate', 'category' => 'events',
            'description' => 'Neutral slate — multi-role events',
            'primary' => '#475569', 'sidebar' => '#1e293b', 'background' => '#f8fafc',
            'gradient' => 'linear-gradient(135deg, #f1f5f9 0%, #f8fafc 50%, #e2e8f0 100%)',
            'font' => 'inter', 'subtitle' => 'Staff Registration Portal',
        ],
    ];
}

/** @return array<string, array{label: string, role: string}> */
function getThemeCategoryMeta(): array
{
    return [
        'security' => ['label' => 'Security', 'role' => 'Security'],
        'steward'  => ['label' => 'Stewards', 'role' => 'Stewards'],
        'gig'      => ['label' => 'Gig / Concert', 'role' => 'Gig Staff'],
        'events'   => ['label' => 'General Events', 'role' => 'Event Staff'],
    ];
}

function getThemeRoleLabel(string $category): string
{
    $meta = getThemeCategoryMeta();

    return $meta[$category]['role'] ?? 'Event Staff';
}

/** @return array<string, string> */
function getThemePresetCategories(): array
{
    return [
        'all'      => 'All Themes',
        'security' => 'Security (14)',
        'steward'  => 'Steward (4)',
        'gig'      => 'Gig / Concert (4)',
        'events'   => 'General Events (2)',
    ];
}

function getThemePresetKey(PDO $pdo): string
{
    $key     = trim(getSetting($pdo, 'theme_preset', 'security-classic-blue'));
    $presets = getThemePresets();

    return array_key_exists($key, $presets) ? $key : 'security-classic-blue';
}

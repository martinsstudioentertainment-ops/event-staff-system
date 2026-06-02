<?php

/**

 * Event Staff System — Dynamic theme overrides from settings

 */



require_once __DIR__ . '/settings-repository.php';

require_once __DIR__ . '/theme-presets.php';
require_once __DIR__ . '/theme-icons.php';



function getThemeFontOptions(): array

{

    return [

        'poppins' => ['label' => 'Poppins', 'family' => "'Poppins', sans-serif", 'url' => 'https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap'],

        'inter'   => ['label' => 'Inter', 'family' => "'Inter', sans-serif", 'url' => 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap'],

        'roboto'  => ['label' => 'Roboto', 'family' => "'Roboto', sans-serif", 'url' => 'https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap'],

        'system'  => ['label' => 'System UI', 'family' => "system-ui, -apple-system, 'Segoe UI', sans-serif", 'url' => ''],

    ];

}



/** @return array<string, string> */

function getActiveThemePreset(PDO $pdo): array

{

    $presets = getThemePresets();

    $key     = getThemePresetKey($pdo);

    $preset  = $presets[$key];



    $customPrimary = trim(getSetting($pdo, 'theme_primary_color', ''));

    if ($customPrimary !== '' && isValidThemeColor($customPrimary)) {

        $preset['primary'] = $customPrimary;

    }



    $customFont = strtolower(trim(getSetting($pdo, 'theme_font_family', '')));

    if ($customFont !== '' && array_key_exists($customFont, getThemeFontOptions())) {

        $preset['font'] = $customFont;

    }



    $preset['id'] = $key;



    return $preset;

}



function getThemePublicSubtitle(PDO $pdo): string
{
    $preset = getActiveThemePreset($pdo);

    return getThemeRoleLabel($preset['category'] ?? 'events');
}

function getThemeCategory(PDO $pdo): string
{
    $preset = getActiveThemePreset($pdo);

    return $preset['category'] ?? 'events';
}

function renderThemeBrandIcon(PDO $pdo): string
{
    return renderThemeCategoryIcon(getThemeCategory($pdo));
}



function getThemeFontKey(PDO $pdo): string

{

    $preset = getActiveThemePreset($pdo);

    $key    = strtolower($preset['font'] ?? 'poppins');



    return array_key_exists($key, getThemeFontOptions()) ? $key : 'poppins';

}



function getThemeFontUrl(PDO $pdo): string

{

    $options = getThemeFontOptions();

    $key     = getThemeFontKey($pdo);



    return $options[$key]['url'];

}



function getThemeFontFamily(PDO $pdo): string

{

    $options = getThemeFontOptions();



    return $options[getThemeFontKey($pdo)]['family'];

}



function isValidThemeColor(string $color): bool

{

    return (bool) preg_match('/^#[0-9A-Fa-f]{6}$/', $color);

}



/**

 * @return array{0: int, 1: int, 2: int}|null

 */

function hexToRgb(string $hex): ?array

{

    if (!isValidThemeColor($hex)) {

        return null;

    }



    return [

        hexdec(substr($hex, 1, 2)),

        hexdec(substr($hex, 3, 2)),

        hexdec(substr($hex, 5, 2)),

    ];

}



function darkenHex(string $hex, float $amount = 0.12): string

{

    $rgb = hexToRgb($hex);

    if ($rgb === null) {

        return $hex;

    }



    $parts = array_map(static function (int $channel) use ($amount): int {

        return max(0, min(255, (int) round($channel * (1 - $amount))));

    }, $rgb);



    return sprintf('#%02x%02x%02x', $parts[0], $parts[1], $parts[2]);

}



function renderThemeCss(PDO $pdo): string

{

    $preset      = getActiveThemePreset($pdo);

    $primary     = $preset['primary'];

    $rgb         = hexToRgb($primary);

    $hover       = darkenHex($primary);

    $light       = $rgb ? sprintf('rgba(%d, %d, %d, 0.15)', $rgb[0], $rgb[1], $rgb[2]) : 'rgba(37, 99, 235, 0.15)';

    $lightDark   = $rgb ? sprintf('rgba(%d, %d, %d, 0.25)', $rgb[0], $rgb[1], $rgb[2]) : 'rgba(37, 99, 235, 0.25)';

    $sidebarActive = $lightDark;

    $fontFamily  = getThemeFontFamily($pdo);



    $lines = [

        ':root {',

        '    --primary-color: ' . $primary . ';',

        '    --primary-hover: ' . $hover . ';',

        '    --primary-light: ' . $light . ';',

        '    --sidebar-color: ' . ($preset['sidebar'] ?? '#111827') . ';',

        '    --sidebar-active: ' . $sidebarActive . ';',

        '    --background-color: ' . ($preset['background'] ?? '#f1f5f9') . ';',

        '    --background-gradient: ' . ($preset['gradient'] ?? 'linear-gradient(135deg, #e2e8f0 0%, #f8fafc 50%, #e0e7ff 100%)') . ';',

        '    --focus-ring: 0 0 0 3px ' . $light . ';',

        '    --font-family: ' . $fontFamily . ';',

        '}',

        '[data-theme="dark"] {',

        '    --primary-light: ' . $lightDark . ';',

        '    --sidebar-active: ' . $lightDark . ';',

        '}',

    ];



    return implode("\n", $lines) . "\n";

}



function getThemeColor(PDO $pdo): string

{

    $preset = getActiveThemePreset($pdo);



    return $preset['primary'] ?? '#2563eb';

}



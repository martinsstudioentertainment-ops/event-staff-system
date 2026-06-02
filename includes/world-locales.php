<?php

/**
 * BCP 47 / ISO language locales for admin + public language pickers.
 * Uses PHP intl when available; falls back to a broad static list.
 */

/** @return array<string, string> locale => "Display name (code)" */
function getWorldLocaleData(): array
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    if (extension_loaded('intl') && class_exists('ResourceBundle', false)) {
        $out = [];

        foreach (ResourceBundle::getLocales('') as $locale) {
            if ($locale === 'root' || str_contains($locale, '@')) {
                continue;
            }

            $normalized = normalizeWorldLocaleCode($locale);
            if ($normalized === '') {
                continue;
            }

            $display = Locale::getDisplayName($locale, 'en');
            if ($display === '' || $display === false) {
                $display = $normalized;
            }

            $out[$normalized] = $display . ' (' . $normalized . ')';
        }

        if ($out !== []) {
            asort($out, SORT_NATURAL | SORT_FLAG_CASE);
            $cache = $out;

            return $cache;
        }
    }

    $cache = getWorldLocaleFallbackData();

    return $cache;
}

/** @return array<string, string> */
function getWorldLocaleFallbackData(): array
{
    $languages = [
        'aa' => 'Afar', 'ab' => 'Abkhazian', 'ae' => 'Avestan', 'af' => 'Afrikaans', 'ak' => 'Akan',
        'am' => 'Amharic', 'an' => 'Aragonese', 'ar' => 'Arabic', 'as' => 'Assamese', 'av' => 'Avaric',
        'ay' => 'Aymara', 'az' => 'Azerbaijani', 'ba' => 'Bashkir', 'be' => 'Belarusian', 'bg' => 'Bulgarian',
        'bh' => 'Bihari', 'bi' => 'Bislama', 'bm' => 'Bambara', 'bn' => 'Bengali', 'bo' => 'Tibetan',
        'br' => 'Breton', 'bs' => 'Bosnian', 'ca' => 'Catalan', 'ce' => 'Chechen', 'ch' => 'Chamorro',
        'co' => 'Corsican', 'cr' => 'Cree', 'cs' => 'Czech', 'cu' => 'Church Slavic', 'cv' => 'Chuvash',
        'cy' => 'Welsh', 'da' => 'Danish', 'de' => 'German', 'dv' => 'Divehi', 'dz' => 'Dzongkha',
        'ee' => 'Ewe', 'el' => 'Greek', 'en' => 'English', 'eo' => 'Esperanto', 'es' => 'Spanish',
        'et' => 'Estonian', 'eu' => 'Basque', 'fa' => 'Persian', 'ff' => 'Fulah', 'fi' => 'Finnish',
        'fj' => 'Fijian', 'fo' => 'Faroese', 'fr' => 'French', 'fy' => 'Western Frisian', 'ga' => 'Irish',
        'gd' => 'Scottish Gaelic', 'gl' => 'Galician', 'gn' => 'Guarani', 'gu' => 'Gujarati', 'gv' => 'Manx',
        'ha' => 'Hausa', 'he' => 'Hebrew', 'hi' => 'Hindi', 'ho' => 'Hiri Motu', 'hr' => 'Croatian',
        'ht' => 'Haitian Creole', 'hu' => 'Hungarian', 'hy' => 'Armenian', 'hz' => 'Herero', 'ia' => 'Interlingua',
        'id' => 'Indonesian', 'ie' => 'Interlingue', 'ig' => 'Igbo', 'ii' => 'Sichuan Yi', 'ik' => 'Inupiaq',
        'io' => 'Ido', 'is' => 'Icelandic', 'it' => 'Italian', 'iu' => 'Inuktitut', 'ja' => 'Japanese',
        'jv' => 'Javanese', 'ka' => 'Georgian', 'kg' => 'Kongo', 'ki' => 'Kikuyu', 'kj' => 'Kuanyama',
        'kk' => 'Kazakh', 'kl' => 'Kalaallisut', 'km' => 'Khmer', 'kn' => 'Kannada', 'ko' => 'Korean',
        'kr' => 'Kanuri', 'ks' => 'Kashmiri', 'ku' => 'Kurdish', 'kv' => 'Komi', 'kw' => 'Cornish',
        'ky' => 'Kyrgyz', 'la' => 'Latin', 'lb' => 'Luxembourgish', 'lg' => 'Ganda', 'li' => 'Limburgish',
        'ln' => 'Lingala', 'lo' => 'Lao', 'lt' => 'Lithuanian', 'lu' => 'Luba-Katanga', 'lv' => 'Latvian',
        'mg' => 'Malagasy', 'mh' => 'Marshallese', 'mi' => 'Maori', 'mk' => 'Macedonian', 'ml' => 'Malayalam',
        'mn' => 'Mongolian', 'mr' => 'Marathi', 'ms' => 'Malay', 'mt' => 'Maltese', 'my' => 'Burmese',
        'na' => 'Nauru', 'nb' => 'Norwegian Bokmål', 'nd' => 'North Ndebele', 'ne' => 'Nepali', 'ng' => 'Ndonga',
        'nl' => 'Dutch', 'nn' => 'Norwegian Nynorsk', 'no' => 'Norwegian', 'nr' => 'South Ndebele', 'nv' => 'Navajo',
        'ny' => 'Chichewa', 'oc' => 'Occitan', 'oj' => 'Ojibwa', 'om' => 'Oromo', 'or' => 'Odia',
        'os' => 'Ossetian', 'pa' => 'Punjabi', 'pi' => 'Pali', 'pl' => 'Polish', 'ps' => 'Pashto',
        'pt' => 'Portuguese', 'qu' => 'Quechua', 'rm' => 'Romansh', 'rn' => 'Rundi', 'ro' => 'Romanian',
        'ru' => 'Russian', 'rw' => 'Kinyarwanda', 'sa' => 'Sanskrit', 'sc' => 'Sardinian', 'sd' => 'Sindhi',
        'se' => 'Northern Sami', 'sg' => 'Sango', 'si' => 'Sinhala', 'sk' => 'Slovak', 'sl' => 'Slovenian',
        'sm' => 'Samoan', 'sn' => 'Shona', 'so' => 'Somali', 'sq' => 'Albanian', 'sr' => 'Serbian',
        'ss' => 'Swati', 'st' => 'Southern Sotho', 'su' => 'Sundanese', 'sv' => 'Swedish', 'sw' => 'Swahili',
        'ta' => 'Tamil', 'te' => 'Telugu', 'tg' => 'Tajik', 'th' => 'Thai', 'ti' => 'Tigrinya',
        'tk' => 'Turkmen', 'tl' => 'Tagalog', 'tn' => 'Tswana', 'to' => 'Tongan', 'tr' => 'Turkish',
        'ts' => 'Tsonga', 'tt' => 'Tatar', 'tw' => 'Twi', 'ty' => 'Tahitian', 'ug' => 'Uyghur',
        'uk' => 'Ukrainian', 'ur' => 'Urdu', 'uz' => 'Uzbek', 've' => 'Venda', 'vi' => 'Vietnamese',
        'vo' => 'Volapük', 'wa' => 'Walloon', 'wo' => 'Wolof', 'xh' => 'Xhosa', 'yi' => 'Yiddish',
        'yo' => 'Yoruba', 'za' => 'Zhuang', 'zh' => 'Chinese', 'zu' => 'Zulu',
    ];

    $regions = [
        'en' => ['US', 'GB', 'AU', 'CA', 'IE', 'NG', 'IN', 'ZA'],
        'es' => ['ES', 'MX', 'AR', 'CO'],
        'fr' => ['FR', 'CA', 'BE'],
        'pt' => ['BR', 'PT'],
        'zh' => ['CN', 'TW', 'HK'],
        'ar' => ['SA', 'EG', 'AE'],
    ];

    $out = [];

    foreach ($languages as $code => $name) {
        $out[$code] = $name . ' (' . $code . ')';
    }

    foreach ($regions as $lang => $countries) {
        foreach ($countries as $country) {
            $locale = $lang . '-' . $country;
            $base   = $languages[$lang] ?? $lang;
            $out[$locale] = $base . ' — ' . $country . ' (' . $locale . ')';
        }
    }

    asort($out, SORT_NATURAL | SORT_FLAG_CASE);

    return $out;
}

/** @return array<string, string> */
function getWorldLocaleOptions(): array
{
    return getWorldLocaleData();
}

function normalizeWorldLocaleCode(string $locale): string
{
    $locale = str_replace('_', '-', trim($locale));
    $locale = preg_replace('/\s+/', '', $locale) ?? '';

    if ($locale === '') {
        return '';
    }

    $parts = explode('-', $locale);
    $built = [];

    foreach ($parts as $index => $part) {
        if ($part === '') {
            continue;
        }

        if ($index === 0) {
            $built[] = strtolower($part);
            continue;
        }

        if (strlen($part) === 2) {
            $built[] = strtoupper($part);
            continue;
        }

        if (strlen($part) === 4) {
            $built[] = ucfirst(strtolower($part));
            continue;
        }

        $built[] = $part;
    }

    return implode('-', $built);
}

function isValidWorldLocale(string $locale): bool
{
    $locale = normalizeWorldLocaleCode($locale);

    if ($locale === '') {
        return false;
    }

    if (array_key_exists($locale, getWorldLocaleOptions())) {
        return true;
    }

    return (bool) preg_match('/^[a-z]{2,3}(-[A-Za-z]{4})?(-[A-Za-z]{2})?$/', $locale);
}

function normalizeWorldLocale(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return 'en';
    }

    $code = normalizeWorldLocaleCode($value);
    $options = getWorldLocaleOptions();

    if (array_key_exists($code, $options)) {
        return $code;
    }

    foreach ($options as $locale => $label) {
        if ($value === $locale || strcasecmp($value, $label) === 0) {
            return $locale;
        }
    }

    if (preg_match('/\(([a-z]{2,3}(?:-[A-Za-z]{2,4})*(?:-[A-Za-z]{2})?)\)\s*$/i', $value, $m)) {
        $parsed = normalizeWorldLocaleCode($m[1]);

        return isValidWorldLocale($parsed) ? $parsed : 'en';
    }

    if (isValidWorldLocale($code)) {
        return $code;
    }

    return 'en';
}

function getWorldLocaleLabel(string $locale): string
{
    $locale = normalizeWorldLocaleCode($locale);

    return getWorldLocaleOptions()[$locale] ?? ($locale !== '' ? $locale . ' (' . $locale . ')' : 'English (en)');
}

/** Backward-compatible alias for public/admin pickers. */
function getSupportedLocales(): array
{
    return getWorldLocaleOptions();
}

function isSupportedLocale(string $locale): bool
{
    return isValidWorldLocale($locale);
}

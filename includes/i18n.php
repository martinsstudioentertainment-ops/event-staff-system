<?php

require_once __DIR__ . '/world-locales.php';

/** @var array<string, string>|null */
$appTranslations = null;

/** @var string|null */
$appLocale = null;

function normalizeAppLocale(string $value): string
{
    return normalizeWorldLocale($value);
}

function resolveAppLocale(?PDO $pdo = null): string
{
    $requested = normalizeWorldLocaleCode((string) ($_GET['lang'] ?? ''));

    if ($requested !== '' && isValidWorldLocale($requested)) {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['app_locale'] = $requested;
        }

        return $requested;
    }

    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['app_locale'])) {
        $saved = normalizeWorldLocaleCode((string) $_SESSION['app_locale']);
        if ($saved !== '' && isValidWorldLocale($saved)) {
            return $saved;
        }
    }

    if ($pdo !== null) {
        require_once __DIR__ . '/system-settings.php';

        return normalizeAppLocale(getSystemSettings($pdo)['language']);
    }

    return 'en';
}

/** @return string[] */
function getTranslationFileCandidates(string $locale): array
{
    $locale = normalizeWorldLocaleCode($locale);
    $candidates = [];

    if ($locale !== '') {
        $candidates[] = $locale;
    }

    if (str_contains($locale, '-')) {
        $parts = explode('-', $locale);
        $candidates[] = $parts[0];
        if (count($parts) >= 2) {
            $candidates[] = $parts[0] . '-' . $parts[1];
        }
    }

    $candidates[] = 'en';

    return array_values(array_unique(array_filter($candidates)));
}

/** @return array<string, string> */
function loadTranslations(string $locale): array
{
    $english = [];
    $enPath  = dirname(__DIR__) . '/lang/en.php';
    if (is_file($enPath)) {
        $loaded = require $enPath;
        if (is_array($loaded)) {
            $english = $loaded;
        }
    }

    foreach (getTranslationFileCandidates($locale) as $candidate) {
        $path = dirname(__DIR__) . '/lang/' . $candidate . '.php';
        if (!is_file($path)) {
            continue;
        }

        $local = require $path;
        if (!is_array($local)) {
            continue;
        }

        return array_merge($english, $local);
    }

    return $english;
}

function bootstrapAppLocale(?PDO $pdo = null): void
{
    global $appTranslations, $appLocale;

    $appLocale       = resolveAppLocale($pdo);
    $appTranslations = loadTranslations($appLocale);
}

function getAppLocale(): string
{
    global $appLocale;

    return $appLocale ?? 'en';
}

function t(string $key, array $replace = []): string
{
    global $appTranslations;

    $text = $appTranslations[$key] ?? $key;

    foreach ($replace as $name => $value) {
        $text = str_replace(':' . $name, (string) $value, $text);
    }

    return $text;
}

function e(string $key, array $replace = []): void
{
    echo htmlspecialchars(t($key, $replace), ENT_QUOTES, 'UTF-8');
}

function renderLanguageSwitcher(string $extraQuery = ''): void
{
    $current = getAppLocale();
    $locales = getWorldLocaleOptions();
    $path    = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';
    $query   = $_GET;
    unset($query['lang']);
    if ($extraQuery !== '') {
        parse_str(ltrim($extraQuery, '?&'), $extra);
        $query = array_merge($query, $extra);
    }
    ?>
    <form class="lang-switcher" method="get" action="<?= h($path) ?>" id="lang-switcher-form">
        <?php foreach ($query as $name => $value): ?>
            <?php if (is_array($value)) {
                continue;
            } ?>
            <input type="hidden" name="<?= h((string) $name) ?>" value="<?= h((string) $value) ?>">
        <?php endforeach; ?>
        <label class="lang-switcher__label" for="lang-input"><?= t('language') ?></label>
        <input
            class="form-input lang-switcher__input"
            type="text"
            id="lang-input"
            value="<?= h(getWorldLocaleLabel($current)) ?>"
            list="world-locale-options"
            autocomplete="off"
            aria-label="<?= h(t('language')) ?>"
        >
        <datalist id="world-locale-options">
            <?php foreach ($locales as $code => $label): ?>
                <option value="<?= h($label) ?>"></option>
            <?php endforeach; ?>
        </datalist>
        <input type="hidden" name="lang" id="lang-code" value="<?= h($current) ?>">
    </form>
    <script>
    (function () {
        var form = document.getElementById('lang-switcher-form');
        var input = document.getElementById('lang-input');
        var hidden = document.getElementById('lang-code');
        if (!form || !input || !hidden) return;

        var localeMap = <?= json_encode($locales, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        function syncLocaleCode() {
            var val = input.value.trim();
            Object.keys(localeMap).forEach(function (code) {
                if (val === code || val === localeMap[code]) {
                    hidden.value = code;
                }
            });
        }

        input.addEventListener('change', function () {
            syncLocaleCode();
            form.submit();
        });

        input.addEventListener('blur', syncLocaleCode);
        form.addEventListener('submit', syncLocaleCode);
    })();
    </script>
    <?php
}

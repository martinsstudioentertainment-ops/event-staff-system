<?php

/**
 * IANA timezones — all identifiers PHP supports, with UTC offset labels.
 */

/** @return array<string, string> timezone id => "Region/City (UTC±HH:MM)" */
function getWorldTimezoneData(): array
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $preferred = [
        'UTC',
        'Europe/Dublin',
        'Europe/London',
        'Africa/Lagos',
        'Africa/Johannesburg',
        'Africa/Cairo',
        'Africa/Nairobi',
        'Asia/Dubai',
        'Asia/Kolkata',
        'Asia/Singapore',
        'Asia/Tokyo',
        'Australia/Sydney',
        'America/New_York',
        'America/Chicago',
        'America/Denver',
        'America/Los_Angeles',
        'America/Toronto',
        'America/Sao_Paulo',
        'Pacific/Auckland',
    ];

    $all = DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC);
    $out = [];

    foreach ($preferred as $tz) {
        if (in_array($tz, $all, true)) {
            $out[$tz] = formatWorldTimezoneLabel($tz);
        }
    }

    foreach ($all as $tz) {
        if (!isset($out[$tz])) {
            $out[$tz] = formatWorldTimezoneLabel($tz);
        }
    }

    $cache = $out;

    return $cache;
}

/** @return array<string, string> */
function getWorldTimezoneOptions(): array
{
    return getWorldTimezoneData();
}

/** @return list<string> */
function getWorldTimezoneIds(): array
{
    return array_keys(getWorldTimezoneData());
}

function formatWorldTimezoneLabel(string $timezoneId): string
{
    $timezoneId = trim($timezoneId);

    if ($timezoneId === '') {
        return '';
    }

    try {
        $dt     = new DateTime('now', new DateTimeZone($timezoneId));
        $offset = $dt->format('P');

        return $timezoneId . ' (UTC' . $offset . ')';
    } catch (Throwable $e) {
        return $timezoneId;
    }
}

function isValidWorldTimezone(string $timezoneId): bool
{
    $timezoneId = trim($timezoneId);

    if ($timezoneId === '') {
        return false;
    }

    return in_array($timezoneId, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true);
}

function normalizeWorldTimezone(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return 'UTC';
    }

    if (isValidWorldTimezone($value)) {
        return $value;
    }

    $options = getWorldTimezoneOptions();

    foreach ($options as $id => $label) {
        if ($value === $id || strcasecmp($value, $label) === 0) {
            return $id;
        }
    }

    if (preg_match('/^([A-Za-z_]+\/[A-Za-z0-9_+\-]+)/', $value, $m)) {
        $candidate = $m[1];

        return isValidWorldTimezone($candidate) ? $candidate : 'UTC';
    }

    if (strcasecmp($value, 'UTC') === 0 || str_starts_with(strtoupper($value), 'UTC')) {
        return 'UTC';
    }

    return 'UTC';
}

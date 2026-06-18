<?php

declare(strict_types=1);

$files = [
    '2026-06-10' => __DIR__ . '/jun10-hours.json',
    '2026-06-13' => __DIR__ . '/jun13-hours.json',
];

foreach ($files as $date => $path) {
    if (!is_readable($path)) {
        echo "=== {$date} — file missing ===\n\n";
        continue;
    }

    $raw   = (string) file_get_contents($path);
    $raw   = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
    $data  = json_decode($raw, true);
    $staff = $data['all_staff'] ?? [];

    usort($staff, static fn(array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name']));

    $event = (string) ($staff[0]['event'] ?? 'Unknown');
    echo "=== {$date} — {$event} ===\n";

    $sum = 0.0;
    foreach ($staff as $row) {
        $hours = (float) ($row['hours_paid'] ?? 0);
        $sum += $hours;
        printf("%5.2f  %s\n", $hours, (string) $row['name']);
    }

    echo 'Total: ' . count($staff) . ' staff, ' . number_format($sum, 2) . " payable hours\n\n";
}

<?php
/**
 * Shift times from your paper / master list.
 * Only rows listed here are published on the staff registration form.
 *
 * Format per row:
 *   'date'  => 'YYYY-MM-DD',
 *   'name'  => 'Event name (must match Admin → Events)',
 *   'start' => 'HH:MM',
 *   'end'   => 'HH:MM',
 *
 * Apply: php database/apply-event-shift-times.php
 */
return [
    // Example (remove # and duplicate for each gig on your paper):
    // ['date' => '2026-06-10', 'name' => 'Nick Cave', 'start' => '18:00', 'end' => '23:00'],
];

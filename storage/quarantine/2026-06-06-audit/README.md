# Quarantine — 2026-06-06 production audit

Dev/diagnostic scripts moved here so they are not web-accessible under `/database/` or `/scripts/`.

| Original path | Reason |
|---------------|--------|
| `database/tmp-venue-check.php` | Unauthenticated DB dump |
| `scripts/test-pending.php` | Dev diagnostic |
| `scripts/diag-event-staff-count.php` | Dev diagnostic |

Run from CLI only: `php storage/quarantine/2026-06-06-audit/<script>.php`

Restore by copying back if needed for local debugging.

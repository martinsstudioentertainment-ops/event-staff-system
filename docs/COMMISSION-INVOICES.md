# Commission invoices — build spec

Handover for **client commission invoices**: one invoice per event (commission), one line per lad, editable hours and amounts, with **event-level hour totals** on every invoice.

**Status:** Built (Phase 28 — admin Invoices module).

---

## Business goal

When an event is done, produce a **commission invoice** for the client showing:

1. **Every lad** who worked (signed in)
2. **Hours per lad** (editable)
3. **Rate and line amount** per lad (amount editable)
4. **Event totals** — all hours done summed for that event (worked + billed + money)

---

## What exists today

| Feature | Location | Notes |
|---------|----------|--------|
| Per-lad hours worked | `attendance.hours_worked` | Sign-in → shift end |
| Per-lad payable hours | `attendance.hours_paid` | Editable in admin |
| Event filter + day totals | Admin → **Work hours** | `getWorkHoursTotals()` |
| CSV export (hours only) | `admin/export-work-hours.php` | No money |

**Work hours page already shows per-event totals when filtered:**

- Staff signed in (headcount)
- Total hours worked
- Total hours payable
- Scheduled shift total

These totals must appear on the **commission invoice header** and stay in sync with line items.

---

## Core rules

### One commission invoice = one event

Each event (e.g. *Nick Cave — 10/06/2026*) gets **one commission invoice** unless you later split by client (Phase C).

### Event hour totals (required)

On every invoice, show **aggregates for the whole event**:

| Total | Source |
|-------|--------|
| **Staff count** | Number of invoice lines / sign-ins |
| **Total hours worked** | Sum of all lads’ `hours_worked` |
| **Total hours billed** | Sum of all lads’ editable billed hours |
| **Total commission** | Sum of all line amounts (editable per lad) |

Stored on invoice header (snapshot at save) and **recomputed from lines** in draft mode.

```
Event: Nick Cave — 10/06/2026
─────────────────────────────────────────
Staff: 12 lads
Hours worked (event total):   94.50 h
Hours billed (event total):   91.25 h
Commission total:         €1,642.50
─────────────────────────────────────────
[line per lad…]
```

### Per-lad lines (editable)

| Field | Editable | Default |
|-------|----------|---------|
| Lad name / role | No (snapshot) | From registration |
| Hours worked | Yes | From `attendance.hours_worked` |
| Hours billed | Yes | From `attendance.hours_paid` |
| Hourly rate | Yes | Event/role default or settings |
| Line amount | Yes | `hours_billed × rate` until manually overridden |

If admin edits **line amount** directly, set `amount_override = 1` and do not auto-change it when hours/rate change unless user clicks “Recalculate”.

---

## Proposed database — Phase 28

```sql
-- migrate-phase28-commission-invoices.sql

CREATE TABLE commission_invoices (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    event_id            INT NOT NULL,
    client_name         VARCHAR(255) NULL,
    invoice_number      VARCHAR(50) NULL,
    invoice_date        DATE NOT NULL,
    status              ENUM('draft','sent','paid','void') NOT NULL DEFAULT 'draft',
    currency            CHAR(3) NOT NULL DEFAULT 'EUR',

    -- Event totals (snapshot; recomputed in draft from lines)
    staff_count         INT NOT NULL DEFAULT 0,
    total_hours_worked  DECIMAL(8,2) NOT NULL DEFAULT 0,
    total_hours_billed  DECIMAL(8,2) NOT NULL DEFAULT 0,
    subtotal_amount     DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_amount        DECIMAL(12,2) NOT NULL DEFAULT 0,

    notes               TEXT NULL,
    created_by          INT NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_commission_invoice_event (event_id),
    CONSTRAINT fk_commission_invoice_event
        FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE RESTRICT,
    CONSTRAINT fk_commission_invoice_admin
        FOREIGN KEY (created_by) REFERENCES admin_users(id) ON DELETE SET NULL
);

CREATE TABLE commission_invoice_lines (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id          INT NOT NULL,
    attendance_id       INT NULL,
    registration_id     INT NOT NULL,
    staff_name          VARCHAR(255) NOT NULL,
    staff_role          VARCHAR(50) NULL,

    hours_worked        DECIMAL(6,2) NOT NULL DEFAULT 0,
    hours_billed        DECIMAL(6,2) NOT NULL DEFAULT 0,
    hourly_rate         DECIMAL(8,2) NOT NULL DEFAULT 0,
    line_amount         DECIMAL(10,2) NOT NULL DEFAULT 0,
    amount_override     TINYINT(1) NOT NULL DEFAULT 0,
    note                VARCHAR(255) NULL,
    sort_order          INT NOT NULL DEFAULT 0,

    CONSTRAINT fk_commission_line_invoice
        FOREIGN KEY (invoice_id) REFERENCES commission_invoices(id) ON DELETE CASCADE,
    CONSTRAINT fk_commission_line_attendance
        FOREIGN KEY (attendance_id) REFERENCES attendance(id) ON DELETE SET NULL,
    CONSTRAINT fk_commission_line_registration
        FOREIGN KEY (registration_id) REFERENCES staff_registrations(id) ON DELETE RESTRICT
);
```

**Optional on `events`:**

- `commission_client_name`
- `default_rate_dsp`, `default_rate_steward`, `default_rate_static` (DECIMAL)

---

## Admin UI

### Navigation

**Operations → Invoices** (capability: `invoices`)

### Screens

| Screen | Purpose |
|--------|---------|
| `admin/invoices.php` | List invoices; columns: event, date, staff count, **total hours billed**, **total amount**, status |
| `admin/invoice-form.php` | Create/edit; pull lines from event attendance |
| `admin/invoice-action.php` | Save lines + recompute event totals |
| `admin/print-invoice.php` | Print/PDF — header totals + line table |
| `admin/export-invoices.php` | CSV |

### Create flow

1. Admin → **Invoices** → **New from event** (or Work hours → **Create commission invoice**)
2. Pick event → system loads all sign-ins with work hours
3. Pre-fill lines from `getWorkHoursList($pdo, $eventId)`
4. Show **event totals** at top (live-updating as lines edit)
5. Save as `draft` → totals persisted on `commission_invoices`

### Work hours integration

On `admin/work-hours.php` when filtered to one event:

- Show existing stat cards (already there)
- Add button: **Create / open commission invoice** for this event

---

## Repository functions (planned)

`includes/commission-invoice-repository.php`:

```php
getCommissionInvoiceByEventId(PDO $pdo, int $eventId): ?array
getCommissionInvoiceLines(PDO $pdo, int $invoiceId): array
buildInvoiceLinesFromEvent(PDO $pdo, int $eventId): array
recomputeInvoiceTotals(array $lines): array  // staff_count, total_hours_worked, total_hours_billed, subtotal_amount
createOrUpdateCommissionInvoice(PDO $pdo, int $eventId, array $lines, array $header, int $adminId): int
```

**`recomputeInvoiceTotals`** — single source of truth for event totals:

```php
// Returns:
// staff_count, total_hours_worked, total_hours_billed, subtotal_amount
foreach ($lines as $line) {
    $totals['hours_worked'] += $line['hours_worked'];
    $totals['hours_billed'] += $line['hours_billed'];
    $totals['amount']       += $line['line_amount'];
}
```

---

## Build phases

### Phase A — MVP (start here when back)

1. `database/migrate-phase28-commission-invoices.sql`
2. Add to `database/setup.php` migration list
3. `includes/commission-invoice-schema.php` (auto-ensure like venues)
4. `includes/commission-invoice-repository.php`
5. `admin/invoices.php`, `invoice-form.php`, `invoice-action.php`
6. Event totals on form + print
7. Sidebar link + `invoices` capability
8. “Create invoice” from Work hours (single event filter)

### Phase B

9. Default rates per role (settings or event form)
10. `print-invoice.php` + CSV export
11. Invoice number auto-sequence (`INV-2026-0001`)
12. Audit log entries on save/send

### Phase C (optional)

13. VAT line
14. Multiple clients per event
15. Email invoice to client
16. Staff payslip (separate from client commission)

---

## Decisions to confirm on return

1. **Rate defaults** — global, per role, or per event?
2. **Draft vs work hours** — if `hours_paid` changes after invoice created, update draft only?
3. **One invoice per event** — OK to enforce `UNIQUE(event_id)`?
4. **Currency** — use ERP System → Currency setting?
5. **Who can edit** — same as work hours (`admin`, `manager`)?

---

## Files to add / touch

| New | Purpose |
|-----|---------|
| `docs/COMMISSION-INVOICES.md` | This spec |
| `database/migrate-phase28-commission-invoices.sql` | Schema |
| `includes/commission-invoice-repository.php` | Business logic |
| `includes/commission-invoice-schema.php` | Dev auto-migrate |
| `admin/invoices.php` | List |
| `admin/invoice-form.php` | Edit lines + totals |
| `admin/invoice-action.php` | POST handler |
| `admin/print-invoice.php` | Print view |

| Existing | Change |
|----------|--------|
| `database/setup.php` | Register phase 28 |
| `includes/admin-capabilities.php` | `invoices` cap |
| `includes/admin/sidebar.php` or nav | Invoices link |
| `admin/work-hours.php` | Link to invoice + event totals CTA |

---

## When you return — quick start

```
1. Laragon → Start All
2. Admin → Work hours → filter one event → confirm totals
3. Tell agent: "Build Phase 28 commission invoices"
4. Confirm rate defaults (question 1 above)
```

---

## Related code today

| File | Role |
|------|------|
| `includes/work-hours-repository.php` | `getWorkHoursList`, `getWorkHoursTotals`, `updateWorkHours` |
| `admin/work-hours.php` | Per-lad ledger + event stat cards |
| `admin/work-hours-action.php` | Save payable hours |
| `admin/export-work-hours.php` | Hours CSV (no amounts) |
| `includes/system-settings.php` | Currency for invoice |

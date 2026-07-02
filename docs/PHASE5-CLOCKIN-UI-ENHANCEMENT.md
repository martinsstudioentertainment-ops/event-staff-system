# Phase 5 — Clock-In Visibility & GTBank Orange UI Enhancement

**Date:** 2026-06-21  
**Status:** Implemented locally — **not deployed** (awaiting review and approval)  
**Scope:** Display / CSS / layout only — no attendance, clock-in logic, GPS, BIB, API, or database changes

---

## 1. UI Audit Report (Before)

### Dashboard layout (Staff App v3 home)

| Area | Current behaviour (pre-Phase 5) | Issue |
|------|----------------------------------|-------|
| **Clock In placement** | One of four equal tiles in “Quick actions” 2×2 grid | Same visual weight as Roster / Messages / Documents |
| **Primary CTA** | Subtle orange tint on Check In tile only | Easy to miss on shift days |
| **Bottom nav FAB** | 56px orange circle, icon only, no label | Functional but not reinforced on home screen |
| **Home hierarchy** | Top bar → stats → today’s shift → quick actions | Clock In appears **below** shift card |
| **Colour palette** | Slate `#0F172A` / `#1E293B`, accent `#F48221` | Close to GTBank orange but not aligned to spec |
| **Stat cards** | Glass surface, orange values | No card accent; blend with background |
| **Notification badges** | Red circles | Standard but not on-brand orange |
| **Active nav state** | Orange text only | Low contrast active indicator |
| **Empty states** | Dashed border, plain text | Minimal visual guidance |
| **Shift cards** | Uniform glass cards | No accent for today / live shift |

### Clock-in page (unchanged logic)

- Large scanner button already orange-accented — adequate prominence on the check-in route itself.
- Home dashboard was the main visibility gap.

### Files driving the UI

| File | Role |
|------|------|
| `assets/css/staff-app-v3.css` | All v3 tokens, layout, components |
| `includes/staff-app-v3-pages.php` | Home dashboard markup |
| `includes/staff-app-v3-shell.php` | Top bar, bottom nav FAB, page shell |
| `includes/staff-app-v3-data.php` | **Not modified** — no logic changes |

---

## 2. Before / After Screenshots

Design mockups (illustrative — device capture recommended after deploy):

| | |
|---|---|
| **Before** | `docs/phase5-before-dashboard.png` |
| **After** | `docs/phase5-after-dashboard.png` |

### Before → After summary

| Element | Before | After |
|---------|--------|-------|
| Clock In on home | Small grid tile | **Full-width hero CTA** (72px min-height, orange gradient, pulse when shift ready) |
| Home section order | Stats first | **Clock In hero first** (after top bar / BIB banner) |
| Quick actions | 4 tiles incl. Check In | **4 secondary tiles** (Shifts, Roster, Messages, Documents) — Clock In removed from grid to avoid duplicate |
| Bottom nav FAB | 56px, no label | **64px + “Clock In” label**, active glow on check-in page |
| Background | `#0F172A` | **`#0B1020`** |
| Cards | `#1E293B` glass | **`#162238`** with orange accents |
| Primary orange | `#F48221` | **`#F58220`** |
| Secondary orange | — | **`#FFA64D`** |
| Notification badges | Red | **Orange gradient** (GTBank-style) |
| Active nav | Text colour | **Orange text + underline bar + glow** |
| Stat cards | Plain glass | **Orange top border accent** |
| Today’s shift card | Plain border | **Orange left accent bar** |
| Empty state | Text only | **Calendar icon + dashed orange border** |
| Progress bar (live) | Green → orange mix | **Orange gradient only** |

---

## 3. Files To Change

### Modified (Phase 5 implementation)

| File | Changes |
|------|---------|
| `assets/css/staff-app-v3.css` | GTBank colour tokens; Clock In hero styles; stat/shift/empty/nav/badge enhancements |
| `includes/staff-app-v3-pages.php` | Clock In hero placement; “More actions” grid (preserves all links) |
| `includes/staff-app-v3-shell.php` | `renderStaffV3ClockInHero()`; enhanced bottom nav FAB + label; `theme-color` `#F58220` |

### Not modified (protected)

- `includes/staff-app-v3-data.php`
- `includes/staff-venue-checkin.php`
- `includes/attendance-repository.php`
- `staff-checkin.php` (logic)
- Mobile API / database / GPS / BIB

### New deliverable assets

| File | Purpose |
|------|---------|
| `docs/phase5-before-dashboard.png` | Before mockup |
| `docs/phase5-after-dashboard.png` | After mockup |
| `docs/PHASE5-CLOCKIN-UI-ENHANCEMENT.md` | This report |

---

## 4. Implementation Details

### Clock In hero (`renderStaffV3ClockInHero`)

Display-only states using **existing** attendance fields:

| State | Visual | Copy |
|-------|--------|------|
| Shift today, not checked in | Orange gradient + pulse | “Clock In” / “Ready for {event}” |
| Active shift | Green gradient | “Shift in progress” |
| Completed checkout | Green gradient | “Shift complete” |
| No shift context | Orange gradient | “Clock In” / generic subtitle |

Links to existing `staff-checkin.php` / `checkin_url` — **no workflow change**.

### GTBank colour tokens

```css
--es-primary: #0B1020;
--es-card: #162238;
--es-accent: #F58220;
--es-accent-secondary: #FFA64D;
```

### Preserved features

All previous quick-action destinations remain:

- My Shifts → `staff-shifts.php`
- View Roster → status URL
- Messages → `staff-messages.php`
- Documents → profile hub `#documents`
- Clock In → hero + bottom nav FAB + check-in page (unchanged logic)

---

## 5. Risk Assessment

| Risk | Level | Notes |
|------|-------|-------|
| Clock-in workflow altered | **None** | Hero is a link only; same URL and server handlers |
| GPS / BIB validation changed | **None** | Check-in page logic untouched |
| Feature removed | **Low** | Documents moved to secondary grid; Clock In promoted not removed |
| Mobile API contract | **None** | No API files changed |
| CSS cache on devices | **Low** | `staffV3CssVersion()` uses `filemtime` — auto-busts on deploy |
| PWA theme colour | **Low** | Updated to `#F58220` — aligns with orange branding |
| Nav layout shift (taller FAB) | **Low** | `--es-nav-h` increased 72→80px; main padding adjusted |
| Accessibility | **Low** | Hero retains text labels; FAB has `aria-label` |
| Android app | **None** | Native app uses Mobile API, not staff PWA CSS |

**Overall risk:** **Low** — CSS and presentational markup only.

---

## 6. Deployment Plan

**Do not deploy until this report is reviewed and approved.**

### Approved deploy scope

| File | Remote path |
|------|-------------|
| `assets/css/staff-app-v3.css` | `assets/css/staff-app-v3.css` |
| `includes/staff-app-v3-pages.php` | `includes/staff-app-v3-pages.php` |
| `includes/staff-app-v3-shell.php` | `includes/staff-app-v3-shell.php` |

### Pre-deploy

1. Run deploy safety gate: `powershell -ExecutionPolicy Bypass -File .\scripts\deploy-safety-gate.ps1`
2. Confirm `deploy_allowed: true` in `docs/phase2-deploy-safety-gate.json`
3. FTP backup of the three production files (same approach as Phase 4A)
4. Record SHA256 hashes

### Deploy

Create/run targeted script (recommended):

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\deploy-phase5-clockin-ui.ps1
```

Or manual FTP upload of the three files above only.

### Post-deploy device verification

- [ ] Home loads without PHP/CSS errors
- [ ] Clock In hero visible at top, full width
- [ ] Pulse animation when shift ready (not when already checked in)
- [ ] Bottom nav FAB shows “Clock In” label
- [ ] All four “More actions” links work
- [ ] Clock-in page still completes GPS/BIB flow unchanged
- [ ] Notification badges visible (orange)
- [ ] Hard refresh / PWA cache shows new colours

### Rollback

Restore pre-deploy copies of the three files from backup. No database rollback needed.

---

## 7. Success Criteria

- [x] Clock In is the primary home dashboard action
- [x] GTBank orange palette applied (`#F58220`, `#FFA64D`, `#0B1020`, `#162238`)
- [x] Dashboard cards, badges, nav, shift cards, empty states improved
- [x] No attendance / clock-in / GPS / BIB / API / schema changes
- [x] All existing routes and links preserved
- [ ] Production device sign-off (pending deploy approval)

---

## Related documents

- Phase 4 live hours: `docs/PHASE4-LIVE-HOURS-COUNTER-IMPLEMENTATION.md`
- Phase 4A deploy: `docs/PHASE4A-DEPLOYMENT-VALIDATION-REPORT.md`

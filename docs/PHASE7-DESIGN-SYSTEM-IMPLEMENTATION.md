# Phase 7 — Design System & UI Modernization

**Status:** Complete locally — **not deployed** (awaiting review and approval)

**Date:** 2026-06-21

## Summary

Implemented the Olasentra Design System (`es-ds__*`) across Staff PWA screens per Phase 6 audit priorities. All changes are **CSS and markup only** — no auth, attendance, GPS, BIB, schema, or API changes.

## Design system documentation

See [`docs/OLASENTRA-DESIGN-SYSTEM.md`](OLASENTRA-DESIGN-SYSTEM.md)

## Screens updated

### Priority 1 — Profile, Documents, Settings

| Screen | Changes |
|--------|---------|
| Profile hub | Gradient hero card, email subtitle, `es-ds__card` menu sections |
| Documents | Icon rows, compact empty state with upload hint |
| Settings | Icon rows with descriptions; install row uses install-target pattern |
| Sign out | `es-ds__btn--danger` |

### Priority 2 — Notifications, Messages

| Screen | Changes |
|--------|---------|
| Notifications | `es-ds__empty` for empty list; orange unread badge; Mark all read uses `es-ds__btn` |
| WhatsApp card | Orange/dark theme (was green) |
| Messages | Orange staff bubbles; `es-ds__input` + primary send button; copy updated |

### Priority 3 — Dashboard, shifts, empty states

| Screen | Changes |
|--------|---------|
| Home — no shift today | `es-ds__empty` with icon + CTA |
| Shifts — no results | `es-ds__empty` with clear filters |
| Shift banner | New `es-v3__shift-banner` (was unstyled `staff-v2__alert`) |
| Shift cards | Added `.es-v3__shift-card--compact` CSS |
| Offline page | Full v3 dark redesign |

### PWA

| Rule | Implementation |
|------|----------------|
| Install visible when installable | `.es-v3__install-target` + JS `beforeinstallprompt` / iOS fallback |
| Hidden when installed | `appinstalled` event hides targets |
| Hidden in standalone | CSS + JS `isStandalonePwa()` |
| No duplicate legacy banner | `#pwa-install-banner` hidden on `body.es-v3` |

## Before / after screenshots

| Screen | Before | After |
|--------|--------|-------|
| Profile / settings | [phase6-profile-settings.png](phase6/phase6-profile-settings.png) | [phase7-after-profile.png](phase7/phase7-after-profile.png) |
| Notifications | [phase6-notifications.png](phase6/phase6-notifications.png) | [phase7-after-notifications.png](phase7/phase7-after-notifications.png) |
| Home dashboard | [phase6-home-dashboard.png](phase6/phase6-home-dashboard.png) | [phase7-after-dashboard.png](phase7/phase7-after-dashboard.png) |
| Offline | [phase6-pwa-offline.png](phase6/phase6-pwa-offline.png) | [phase7-after-offline.png](phase7/phase7-after-offline.png) |

## Files modified

| File | Change |
|------|--------|
| `assets/css/staff-app-v3.css` | Design system components, shift banner, chat, PWA CSS |
| `assets/css/notifications.css` | Orange badge, WhatsApp dark theme |
| `assets/js/staff-app-v3.js` | Install visibility logic |
| `includes/staff-app-v3-pages.php` | Profile, documents, settings, messages, empty states, install targets |
| `includes/staff-portal-shift.php` | Shift banner markup |
| `includes/components/notification-list.php` | Empty state component |
| `includes/staff-app-easy.php` | Profile banner copy (not payroll) |
| `offline.php` | v3 dark page |

## Files added

| File | Purpose |
|------|---------|
| `docs/OLASENTRA-DESIGN-SYSTEM.md` | Design system reference |
| `docs/PHASE7-DESIGN-SYSTEM-IMPLEMENTATION.md` | This report |
| `scripts/phase7-design-system-test.php` | Regression checks |
| `scripts/deploy-phase7-design-system.ps1` | Deploy plan (not run) |

## Risk assessment

| Area | Risk | Mitigation |
|------|------|------------|
| Profile/settings layout | Low | Markup/CSS only; same links and handlers |
| PWA install visibility | Medium | iOS shows install when not standalone; Android on prompt only |
| Shift banner class change | Low | Display only; same text and GPS logic |
| Offline page | Low | Same reload/home actions |
| Messages/notifications | Low | No API or thread logic changed |
| WhatsApp card restyle | Low | Same component, new colors |

## Regression testing

```
php scripts/phase7-design-system-test.php
```

**Result:** 22/22 PASS

### Manual test plan (post-deploy)

1. Profile hub — hero, documents empty/filled, settings rows, sign out
2. Notifications — empty state, unread badge orange, WhatsApp card dark/orange
3. Messages — send message, bubble colors, compose button
4. Home — empty today shift state; shift banner when on active shift
5. Shifts — empty filter state
6. Offline — open `offline.php` — dark v3 styling
7. PWA — browser: install row hidden until prompt; standalone: no install UI
8. Auth unchanged — Google + OTP + Register on login

## Deployment plan

**Do not deploy until approved.**

When approved, run:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\deploy-phase7-design-system.ps1
```

Upload set (9 files):

- `assets/css/staff-app-v3.css`
- `assets/css/notifications.css`
- `assets/js/staff-app-v3.js`
- `includes/staff-app-v3-pages.php`
- `includes/staff-portal-shift.php`
- `includes/components/notification-list.php`
- `includes/staff-app-easy.php`
- `offline.php`

**Not uploaded:** `config.php`, credentials, DB configs.

Post-deploy: hard-refresh staff app; test profile + notifications on device.

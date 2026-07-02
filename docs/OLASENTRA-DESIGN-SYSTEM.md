# Olasentra Design System — Staff PWA (v3)

**Version:** 1.0 (Phase 7)  
**Scope:** Staff PWA (`body.es-v3`) — CSS/UI only

## Brand colors

| Token | CSS variable | Hex | Usage |
|-------|--------------|-----|--------|
| Background | `--es-primary` | `#0B1020` | Page background |
| Card | `--es-secondary`, `--es-card` | `#162238` | Cards, surfaces |
| Primary accent | `--es-accent` | `#F58220` | CTAs, active nav, badges |
| Secondary accent | `--es-accent-secondary` | `#FFA64D` | Gradients, highlights |
| Text | `--es-text` | `#F8FAFC` | Primary copy |
| Muted | `--es-text-muted` | `#94A3B8` | Labels, hints |
| Success | `--es-success` | `#10B981` | Checked-in, valid |
| Danger | `--es-danger` | `#EF4444` | Errors, sign out |

Supporting tokens: `--es-surface`, `--es-glass`, `--es-glass-border`, `--es-accent-soft`, `--es-accent-glow`, `--es-radius`, `--es-radius-sm`, `--es-shadow`, `--es-font`.

## Typography

- **Family:** Inter (`--es-font`)
- **Base:** 15px / 1.45 line-height on `body.es-v3`
- **Page title:** `.es-v3__page-title` — 1.5rem, weight 800
- **Section label:** `.es-v3__section-title` — 0.8125rem, uppercase, weight 700
- **Body muted:** 0.8125rem–0.875rem, `--es-text-muted`

## Components (`es-ds__*`)

### Buttons

| Class | Use |
|-------|-----|
| `.es-ds__btn` | Base button / link button |
| `.es-ds__btn--primary` | Orange gradient CTA |
| `.es-ds__btn--secondary` | Surface outline |
| `.es-ds__btn--ghost` | Transparent orange border |
| `.es-ds__btn--danger` | Sign out, destructive |
| `.es-ds__btn--sm` | Compact (40px) |
| `.es-ds__btn--block` | Full width |

Legacy aliases still work: `.es-v3__compose-send`, `.es-v3__clockin-btn`, `.es-v3-login__otp-btn--primary`.

### Inputs

| Class | Use |
|-------|-----|
| `.es-ds__input` | Text, email, search |
| `.es-ds__input--textarea` | Message compose |

Legacy: `.es-v3-login__otp-input`, `.es-v3__compose-input`, `.es-v3__search-bar input`.

### Cards

| Class | Use |
|-------|-----|
| `.es-ds__card` | Base card |
| `.es-ds__card--menu` | Profile/settings menu stack |
| `.es-v3__glass` | Glass utility |

### Empty states

| Class | Use |
|-------|-----|
| `.es-ds__empty` | Standard empty block |
| `.es-ds__empty--compact` | Inside menu cards |
| `.es-ds__empty--page` | Full-page (offline) |
| `.es-ds__empty-icon` | Orange icon container |
| `.es-ds__empty-title` | Heading |
| `.es-ds__empty-text` | Supporting copy |

### Profile / settings

| Class | Use |
|-------|-----|
| `.es-ds__profile-hero` | Profile header card |
| `.es-ds__doc-row` | Document list row |
| `.es-ds__settings-row` | Settings navigation row |
| `.es-ds__menu-action` | Card footer action link |

### Shift banner

| Class | Use |
|-------|-----|
| `.es-v3__shift-banner` | GPS shift monitoring banner |
| `.es-v3__shift-banner--active` | Active shift variant |
| `.es-v3__shift-banner--precheck` | Pre-event check-in |

## Navigation

- Bottom nav: `.es-v3__nav`, `.es-v3__nav-fab`
- Tabs: `.es-v3__tabs`, `.es-v3__tab`
- Chips: `.es-v3__chip-row`, `.es-v3__chip`

## PWA install

- Targets: `.es-v3__install-target`
- Visible class: `.es-v3--install-visible` (set by JS when installable)
- Hidden in standalone via CSS + JS
- Legacy `#pwa-install-banner` suppressed on `body.es-v3`

## Accessibility

- Global `:focus-visible` outline (orange, 2px) on interactive elements
- Preserve existing `aria-*`, `role="alert"`, labels

## File locations

| Asset | Path |
|-------|------|
| Design tokens + components | `assets/css/staff-app-v3.css` |
| Notifications overrides | `assets/css/notifications.css` |
| PWA install JS | `assets/js/staff-app-v3.js` |

## Do not use on Staff PWA login

- Email + PPS login UI (product decision Phase 5C)

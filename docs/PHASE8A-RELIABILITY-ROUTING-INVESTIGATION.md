# Phase 8A — Reliability & Routing Corrections (Investigation)

**Status:** Investigation complete — **no code changes, no deployment**

**Scope:** P8-01 (Service Worker), P8-04 (Roster routing), P8-05 (Guest messages)  
**Out of scope:** Login compactness, auth/OTP/OAuth logic, attendance/GPS/BIB

---

## Executive summary

| Issue | Verdict | Action |
|-------|---------|--------|
| **P8-01** Service worker precache gap | **FIX RECOMMENDED** | Update `sw.js` precache + bump cache version |
| **P8-04** “View Roster” → `status.php` | **FIX RECOMMENDED** | Label/copy correction (rename); routing is intentional |
| **P8-05** Guest messages legacy UI | **FIX RECOMMENDED** (partial) | Redirect guests without session/token to login; keep token deep links |

---

## 1. Service worker findings (P8-01)

### Current state

**File:** `sw.js`  
**Cache name:** `event-staff-v9-pwa-ios-fix`  
**Registration:** `assets/js/pwa.js` → `navigator.serviceWorker.register('sw.js')` (loaded via `includes/pwa-scripts.php` on all PWA pages including v3 shell)

### Precache list (`CORE_ASSETS`)

| Asset | In precache | Used by staff PWA v3 |
|-------|-------------|----------------------|
| `offline.php` | Yes | Yes (navigate fallback) |
| `staff-app.php` | Yes | Yes |
| `assets/css/staff-app.css` | Yes | **No** (legacy) |
| `assets/css/staff-app-v2.css` | Yes | **No** (legacy) |
| `assets/css/staff-app-v3.css` | **No** | **Yes** (all v3 pages + offline.php) |
| `assets/css/notifications.css` | **No** | **Yes** (v3 shell head) |
| `assets/js/staff-app-v3.js` | **No** | **Yes** (signed-in + guest shell) |
| `assets/js/staff-portal-email-otp.js` | **No** | **Yes** (guest login) |
| `assets/css/pwa-install.css` | Yes | Yes |
| `assets/css/variables.css`, `style.css`, `mobile.css` | Yes | Legacy public shell only |
| `assets/theme.css.php` | Yes | Legacy pages |
| `assets/js/pwa.js`, `pwa-install.js` | Yes | Yes |

### Cache versioning behaviour

| Mechanism | Behaviour | Gap |
|-----------|-----------|-----|
| `CACHE_NAME` bump | On `activate`, deletes all caches ≠ current name | Works — requires manual bump in `sw.js` |
| `skipWaiting` + `clients.claim` | New SW activates immediately; `controllerchange` reloads page | Works |
| Static fetch handler | Cache-first for `.css`/`.js`; network updates cache on 200 | **Only helps after online visit** |
| Query-string cache bust (`?v=filemtime`) | Runtime cache keyed by full URL | Precache URLs have **no** version query |

### Failure modes (production)

1. **Fresh PWA install, then offline** — Precached `staff-app.php` loads but `staff-app-v3.css` / OTP JS are missing → unstyled or broken guest login.
2. **Offline page styling** — `offline.php` (precached) references `staff-app-v3.css` (not precached) → offline fallback may render without v3 styles until user has been online once.
3. **Stale legacy CSS in cache** — Precache still pulls `staff-app.css` / v2, wasting cache budget; does not serve v3 but may confuse debugging.
4. **Installed PWA after Phase 7B** — Users who installed before v3 deploy may retain old runtime cache entries until `CACHE_NAME` bump forces clean precache.

### Root cause

Service worker precache was last aligned with **pre-v3** staff app assets. Phase 7B deployed v3 CSS/JS to production but **`sw.js` was not updated** in that deploy set.

---

## 2. Cache strategy review

### Navigate requests

```
fetch(network) → on failure → caches.match(offline.php) → fallback style.css
```

- Offline shell uses v3-branded `offline.php` (Phase 7) — **good**.
- Fallback to `style.css` is legacy — minor; only if `offline.php` missing from cache.

### Static assets

- **Strategy:** Stale-while-revalidate (serve cache, update in background).
- **Gap:** No precache of v3 bundle → first offline session fails for v3 styling.
- **Recommendation:** Add v3 assets to `CORE_ASSETS`; bump `CACHE_NAME` to `event-staff-v10-v3-precache` (or similar).

### Proposed precache additions (implementation phase)

```javascript
'./assets/css/staff-app-v3.css',
'./assets/css/notifications.css',
'./assets/js/staff-app-v3.js',
'./assets/js/staff-portal-email-otp.js',
```

### Optional precache removals (low priority)

Legacy `staff-app.css` / `staff-app-v2.css` can remain for `status.php` and other light-shell pages until those pages migrate — **do not remove in same pass without verifying legacy pages offline**.

### Service worker registration scope

- Same `sw.js` serves registration site (`register.olasentra.com`) and admin (via `data-pwa-sw`).
- Change is **site-wide** but limited to cache list + version string — no fetch logic change required for P8-01.

---

## 3. Roster routing findings (P8-04)

### What happens today

Home dashboard “More actions” grid in `includes/staff-app-v3-pages.php`:

```118:120:includes/staff-app-v3-pages.php
            <a href="<?= h((string) $ctx['status_url']) ?>" class="es-v3__action-card">
                <svg viewBox="0 0 24 24" aria-hidden="true">...</svg>
                <span>View Roster</span>
```

`status_url` is built in `includes/staff-app-v3-shell.php`:

```53:55:includes/staff-app-v3-shell.php
    $statusPageUrl = $staffStatusToken !== ''
        ? 'status.php?token=' . urlencode($staffStatusToken)
        : 'status.php';
```

### Why it opens `status.php`

The link **intentionally** uses `$ctx['status_url']`. That variable has always pointed at the **personal registration status dashboard**, not a team roster. The label “View Roster” was added in v3 UI but the **href was never changed** — this is a **copy/label mismatch**, not a broken route.

### What `status.php` is

| Property | Detail |
|----------|--------|
| Page title | “My status” |
| Purpose | Personal applications, approval status, shifts, check-in history, PSA, WhatsApp card |
| Shell | Legacy light `staff-public-shell` (not v3) |
| Auth | Token in URL or email lookup form; respects portal session if present |
| Team roster | **Does not show** other staff or event roster assignments |

### Is `status.php` the intended destination?

**Yes**, for *personal application status* — it is the long-standing staff-facing status page (post-registration emails, `submit.php` `status_url`, notification links).  
**No**, if the user expectation from “View Roster” is a **team/event roster** — no staff-facing team roster route exists (admin-only: `admin/event-rostering.php`).

### Is the page legacy?

**Yes** — light theme, `staff-status-dashboard.css`, separate from v3 staff app. Functionally correct; visually inconsistent with Phase 7 dashboard.

### Relationship to “My Shifts”

| Action | Destination | Content |
|--------|-------------|---------|
| My Shifts | `staff-shifts.php` | v3 — approved/upcoming shift list |
| View Roster | `status.php` | Legacy — application metrics + all registrations (pending/approved/rejected) |

These overlap partially (both show shift/application info) but `status.php` includes pending/rejected applications and PSA flows that `staff-shifts.php` does not.

### Verdict: P8-04

| Question | Answer |
|----------|--------|
| Why View Roster → status.php? | `status_url` wired in shell; label not updated when v3 home was built |
| Is status.php intended? | **Yes** for personal status |
| Is destination incorrect? | **Only if label implies team roster** — functionally correct for personal status |
| Is rename sufficient? | **Yes** — recommended: **“Application status”** or **“My status”** |
| Route change needed? | **No** — unless product wants v3-native status page (separate phase) |

**Return:** **FIX RECOMMENDED** (label/copy only, 1 line in `staff-app-v3-pages.php`)

---

## 4. Guest messages findings (P8-05)

### Routing logic

**File:** `staff-messages.php`

```108:117:staff-messages.php
$useV3Shell = $portalStaff !== null && !$showLookup;

if ($useV3Shell) {
    require_once __DIR__ . '/includes/staff-app-v3-pages.php';
    $ctx = buildStaffV3Context($pdo, $portalStaff);
    renderStaffV3PageStart($ctx, 'messages', 'Messages');
    renderStaffV3MessagesPage($ctx, $messages, $flash, $msgUrl);
    renderStaffV3PageEnd($ctx);
    exit;
}
```

Legacy light shell renders when `$useV3Shell === false`.

### When legacy shell appears

| Condition | Shell | UX |
|-----------|-------|-----|
| Signed-in portal session | **v3** | Dark app, bottom nav, `renderStaffV3MessagesPage` |
| Valid `?token=` (no portal session) | **Legacy** | Full thread + compose; token auth |
| No session, no token | **Legacy lookup** | Email lookup form (`showLookup = true`) |
| Invalid token | **Legacy lookup** | Error + email form |

### Why guest users see legacy styling

1. **By design:** Token-based public access predates v3; uses `staff-public-shell` + `messages.css` (same pattern as `status.php`).
2. **Guest lookup path:** When `$portalStaff === null` and no token, code sets `$showLookup = true` and renders legacy HTML (lines 119–193) instead of redirecting to `staff-app.php`.
3. **Inconsistency with notifications:** `staff-notifications.php` requires portal sign-in (`staffV3RequireSignIn`) and redirects token/deep-link guests to login. Messages does **not** mirror this.

### Signed-in PWA users

Bottom nav “Messages” → `staff-messages.php` with active portal session → **always v3**. The Phase 8 production issue applies to **guest/direct URL access**, not normal signed-in navigation.

### Should guest lookup remain accessible?

| Option | Pros | Cons |
|--------|------|------|
| **A. Redirect to `staff-app.php`** (no token) | Consistent v3; matches notifications | Removes email-only lookup on messages page |
| **B. Keep lookup, modernize shell** | Preserves token-less email discovery | Large scope; duplicates login OTP |
| **C. Keep as-is** | No change | Legacy UI confusion; HTTP 200 on guest URL |

### Token deep links (`staff-messages.php?token=…`)

Still used for staff who message coordinators **before** full portal login (email workflows). **Must remain accessible.** Legacy shell acceptable for this edge case until a dedicated v3 token view exists.

### Verdict: P8-05

| Question | Answer |
|----------|--------|
| Why legacy styling? | Explicit `$useV3Shell` gate; legacy path for token + guest lookup |
| Login redirect preferable? | **Yes** for no-session/no-token (align with notifications) |
| Modernize full page? | **Defer** — out of P8A scope |
| Remain accessible? | **Yes** for `?token=` links |

**Return:** **FIX RECOMMENDED** — redirect guest (no session, no token) to `staff-app.php?return=staff-messages.php`; **NO ACTION** on token-based legacy thread (functional, low traffic)

---

## 5. Risk assessment

| Fix | Files | Risk | Regression area |
|-----|-------|------|-----------------|
| P8-01 SW precache | `sw.js` only | **Low** | PWA offline/install; test offline login + dashboard |
| P8-04 Label rename | `staff-app-v3-pages.php` | **None** | Copy only |
| P8-05 Guest redirect | `staff-messages.php` | **Low** | Guest bookmark to messages; token links unchanged |
| Remove legacy precache | `sw.js` | **Medium** | `status.php` offline — defer |

**Protected areas untouched:** Auth, OAuth, OTP APIs, attendance, GPS, BIB, schema, API contracts.

---

## 6. Recommended fixes (implementation phase — not executed)

### P8-01 — Service worker (priority: **P2**)

1. Add to `CORE_ASSETS`:
   - `assets/css/staff-app-v3.css`
   - `assets/css/notifications.css`
   - `assets/js/staff-app-v3.js`
   - `assets/js/staff-portal-email-otp.js`
2. Bump `CACHE_NAME` to new version (e.g. `event-staff-v10-v3-staff-pwa`).
3. Post-deploy test: fresh install → airplane mode → open PWA → verify v3 login styling + offline page.

### P8-04 — Roster label (priority: **P3**)

Change label only:

- **From:** `View Roster`
- **To:** `Application status` or `My status` (match `status.php` h1)

No href change unless product later requests v3 status page.

### P8-05 — Guest messages (priority: **P3**)

After session/token resolution, before legacy render:

```php
if ($portalStaff === null && $token === '' && $showLookup) {
    header('Location: staff-app.php?return=' . urlencode('staff-messages.php'));
    exit;
}
```

Preserve:

- `?token=` messaging
- POST `msg_lookup` flow (or redirect failed lookup back to login with flash)

---

## 7. Deployment recommendation

| Item | Deploy? | When | Bundle |
|------|---------|------|--------|
| P8-01 `sw.js` | **Yes** | First — highest reliability impact | Single file |
| P8-04 label | **Yes** | With or after P8-01 | `staff-app-v3-pages.php` |
| P8-05 redirect | **Yes** | After P8-01 | `staff-messages.php` |

**Suggested deploy order:** P8-01 alone first → device verify offline PWA → P8-04 + P8-05 together.

**Do not bundle with:** Login compactness (Phase 8B), manifest colour (P8-02), or status.php v3 migration.

**Rollback:** Restore previous `sw.js` from `storage/backups/phase7b-pre-deploy-20260621-065446/` if offline regressions; label/redirect are trivially reversible.

---

## 8. Final returns

| ID | Return |
|----|--------|
| **P8-01** | **FIX RECOMMENDED** |
| **P8-04** | **FIX RECOMMENDED** (rename sufficient; routing correct for personal status) |
| **P8-05** | **FIX RECOMMENDED** (guest redirect only); token legacy path = **NO ACTION REQUIRED** |

**Overall Phase 8A:** **FIX RECOMMENDED** — proceed to implementation + deploy when approved. No login redesign. No schema/API/auth changes.

---

**Related:** [`PHASE8-POST-DEPLOY-VALIDATION-REPORT.md`](PHASE8-POST-DEPLOY-VALIDATION-REPORT.md) · [`PHASE8-LOGIN-COMPACTNESS-AUDIT.md`](PHASE8-LOGIN-COMPACTNESS-AUDIT.md)

/**
 * Authenticated production smoke — Staff App + Admin.
 *
 * Requires saved Playwright storage state (sign in once in browser, export cookies):
 *
 *   STAFF_AUTH_STATE=path/to/staff-auth.json
 *   ADMIN_AUTH_STATE=path/to/admin-auth.json
 *
 * Capture staff state:
 *   npx playwright open https://register.olasentra.com/staff-app.php
 *   (sign in with Google/OTP), then in DevTools run storage export or use codegen.
 *
 * Run:
 *   npm run test:authenticated
 */
import { chromium } from 'playwright';
import { existsSync, mkdirSync, writeFileSync } from 'fs';
import { dirname, join } from 'path';
import { fileURLToPath } from 'url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const STAFF_BASE = (process.env.STAFF_BASE_URL || 'https://register.olasentra.com').replace(/\/$/, '');
const ADMIN_BASE = (process.env.ADMIN_BASE_URL || 'https://admin.olasentra.com/admin').replace(/\/$/, '');
const STAFF_AUTH = process.env.STAFF_AUTH_STATE || '';
const ADMIN_AUTH = process.env.ADMIN_AUTH_STATE || '';
const OUT_DIR = join(__dirname, '..', '..', 'docs', 'authenticated-smoke-' + new Date().toISOString().slice(0, 10));
mkdirSync(OUT_DIR, { recursive: true });

const results = [];

function log(area, test, status, detail = '') {
  const row = { area, test, status, detail, at: new Date().toISOString() };
  results.push(row);
  const mark = status === 'PASS' ? '✓' : status === 'FAIL' ? '✗' : status === 'SKIP' ? '○' : '·';
  console.log(`${mark} [${area}] ${test}: ${status}${detail ? ' — ' + detail : ''}`);
}

async function checkPage(page, area, name, url, expectText) {
  const consoleErrors = [];
  const failedRequests = [];
  page.on('console', (msg) => {
    if (msg.type() === 'error') consoleErrors.push(msg.text());
  });
  page.on('pageerror', (err) => consoleErrors.push(err.message));
  page.on('response', (res) => {
    if (res.status() >= 400 && !res.url().includes('favicon')) {
      failedRequests.push(`${res.status()} ${res.url()}`);
    }
  });

  let status = 'PASS';
  let detail = '';
  try {
    const res = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });
    const code = res?.status() ?? 0;
    if (code >= 500) {
      status = 'FAIL';
      detail = `HTTP ${code}`;
    } else {
      const body = await page.locator('body').innerText();
      if (/Fatal error|Parse error|Uncaught Error/i.test(body)) {
        status = 'FAIL';
        detail = 'PHP error in page body';
      } else if (expectText && !body.includes(expectText) && !new RegExp(expectText, 'i').test(body)) {
        if (/sign in|login/i.test(body) && (STAFF_AUTH || ADMIN_AUTH)) {
          status = 'FAIL';
          detail = 'Auth session expired — still on sign-in';
        } else if (expectText) {
          status = 'WARN';
          detail = `Expected text not found: ${expectText}`;
        }
      }
    }
    if (consoleErrors.length) {
      log(area, `${name} console`, consoleErrors.length ? 'WARN' : 'PASS', consoleErrors.slice(0, 3).join('; '));
    }
    if (failedRequests.filter((r) => r.startsWith('5')).length) {
      status = 'FAIL';
      detail = (detail ? detail + '; ' : '') + failedRequests.filter((r) => r.startsWith('5')).join(', ');
    }
  } catch (e) {
    status = 'FAIL';
    detail = e.message;
  }
  log(area, name, status, detail);
  await page.screenshot({ path: join(OUT_DIR, `${area}-${name.replace(/\s+/g, '-')}.png`), fullPage: true }).catch(() => {});
}

async function runStaffSmoke(browser) {
  if (!STAFF_AUTH || !existsSync(STAFF_AUTH)) {
    log('Staff App', 'Authenticated session', 'SKIP', 'Set STAFF_AUTH_STATE to Playwright storage JSON');
    return;
  }
  const context = await browser.newContext({ storageState: STAFF_AUTH });
  const page = await context.newPage();

  await checkPage(page, 'Staff', 'Dashboard', `${STAFF_BASE}/staff-app.php`, 'Upcoming');
  await checkPage(page, 'Staff', 'My Profile', `${STAFF_BASE}/staff-profile-hub.php`, 'Profile');
  await checkPage(page, 'Staff', 'My Documents', `${STAFF_BASE}/staff-profile-hub.php#documents`, 'Documents');
  await checkPage(page, 'Staff', 'My Availability', `${STAFF_BASE}/portal/staff-dashboard.php?tab=availability`, 'Availability');
  await checkPage(page, 'Staff', 'My Shifts', `${STAFF_BASE}/staff-shifts.php`, 'Shifts');
  await checkPage(page, 'Staff', 'Notifications', `${STAFF_BASE}/staff-notifications.php`, 'Notification');

  const homeBody = await page.goto(`${STAFF_BASE}/staff-app.php`, { waitUntil: 'domcontentloaded' })
    .then(() => page.locator('body').innerText());
  if (/No shifts are currently available/i.test(homeBody)) {
    log('Staff', 'No-shifts message', 'PASS', 'visible on dashboard');
  } else if (/Today's shift|Upcoming|Register for events/i.test(homeBody)) {
    log('Staff', 'Shifts content', 'PASS', 'dashboard has shift content');
  } else {
    log('Staff', 'Dashboard content', 'WARN', 'Could not confirm shifts or empty state');
  }

  await page.goto(`${STAFF_BASE}/staff-shifts.php`, { waitUntil: 'domcontentloaded' });
  const shiftCard = page.locator('.es-v3__shift-card, .es-v3__empty-card').first();
  if (await shiftCard.count()) {
    log('Staff', 'Shift list renders', 'PASS', 'shift card or empty state present');
  }

  await context.close();
}

async function runAdminSmoke(browser) {
  if (!ADMIN_AUTH || !existsSync(ADMIN_AUTH)) {
    log('Admin', 'Authenticated session', 'SKIP', 'Set ADMIN_AUTH_STATE to Playwright storage JSON');
    return;
  }
  const context = await browser.newContext({ storageState: ADMIN_AUTH });
  const page = await context.newPage();

  const pages = [
    ['Dashboard', 'dashboard.php', 'Dashboard'],
    ['Staff List', 'view-staff.php', 'Staff'],
    ['Staff Profile', 'staff-directory.php', 'Staff'],
    ['Events', 'events.php', 'Event'],
    ['Rosters', 'event-rostering.php', 'Roster'],
    ['Messaging', 'communication-centre.php', 'Communication'],
    ['Inbox', 'staff-inbox.php', 'Inbox'],
    ['Reports', 'operations-reports.php', 'Report'],
    ['Settings', 'settings-site.php', 'Settings'],
  ];

  for (const [name, path, expect] of pages) {
    await checkPage(page, 'Admin', name, `${ADMIN_BASE}/${path}`, expect);
  }

  await context.close();
}

async function main() {
  console.log(`\nAuthenticated smoke — Staff: ${STAFF_BASE} Admin: ${ADMIN_BASE}\n`);

  const browser = await chromium.launch({ headless: true });

  await runStaffSmoke(browser);
  await runAdminSmoke(browser);

  await browser.close();

  writeFileSync(join(OUT_DIR, 'summary.json'), JSON.stringify({
    executed_at: new Date().toISOString(),
    staff_auth: STAFF_AUTH || null,
    admin_auth: ADMIN_AUTH || null,
    results,
  }, null, 2));

  const fails = results.filter((r) => r.status === 'FAIL');
  console.log(`\nDone: ${results.length} checks, ${fails.length} FAIL\n`);
  process.exit(fails.length > 0 ? 1 : 0);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});

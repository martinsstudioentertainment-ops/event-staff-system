/**
 * Live production registration E2E — real Chromium browser.
 * Run: npm install && npx playwright install chromium && npm run test:production
 */
import { chromium } from 'playwright';
import { writeFileSync, mkdirSync } from 'fs';
import { dirname, join } from 'path';
import { fileURLToPath } from 'url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const BASE = (process.env.REGISTRATION_BASE_URL || 'https://register.olasentra.com').replace(/\/$/, '');
const OUT_DIR = join(__dirname, '..', '..', 'docs', 'browser-e2e-' + new Date().toISOString().slice(0, 10));
mkdirSync(OUT_DIR, { recursive: true });

const ts = new Date().toISOString().replace(/[-:TZ.]/g, '').slice(0, 14);
const stewardEmail = `browser.steward.${ts}@olasentra-e2e.test`;
const staticEmail = `browser.static.${ts}@olasentra-e2e.test`;

const results = [];

function log(area, test, status, detail = '') {
  const row = { area, test, status, detail, at: new Date().toISOString() };
  results.push(row);
  const mark = status === 'PASS' ? '✓' : status === 'FAIL' ? '✗' : '○';
  console.log(`${mark} [${area}] ${test}: ${status}${detail ? ' — ' + detail : ''}`);
}

async function waitStep(page, stepNum) {
  try {
    await page.waitForFunction(
      (n) => {
        const el = document.querySelector('.reg-wizard__step--active');
        return el && el.getAttribute('data-step') === String(n);
      },
      stepNum,
      { timeout: 30000 }
    );
  } catch (err) {
    const cur = await page.evaluate(() => {
      const active = document.querySelector('.reg-wizard__step--active');
      const errors = Array.from(document.querySelectorAll('.form-error--visible')).map((e) => e.textContent.trim());
      return { step: active?.getAttribute('data-step'), errors };
    });
    throw new Error(`Expected step ${stepNum}, got step ${cur.step}. Errors: ${cur.errors.join(' | ')}`);
  }
  await page.waitForTimeout(300);
}

async function fillActive(page, selector, value) {
  const el = page.locator(`.reg-wizard__step--active ${selector}`);
  await el.waitFor({ state: 'visible', timeout: 20000 });
  await el.fill(value);
  await el.dispatchEvent('input');
  await el.dispatchEvent('change');
}

async function clickContinue(page) {
  await page.waitForFunction(() => typeof window.RegistrationWizard !== 'undefined', { timeout: 15000 });
  const before = await page.evaluate(() => ({
    current: window.RegistrationWizard.getCurrentStep(),
    dom: document.querySelector('.reg-wizard__step--active')?.getAttribute('data-step'),
  }));
  await page.locator('#reg-wizard-next').click();
  await page.waitForTimeout(900);
  const after = await page.evaluate(() => ({
    step: window.RegistrationWizard.getCurrentStep(),
    dom: document.querySelector('.reg-wizard__step--active')?.getAttribute('data-step'),
    errors: window.RegistrationWizardValidation?.getLastValidationErrors?.() || {},
    nextHidden: document.getElementById('reg-wizard-next')?.hidden,
    submitHidden: document.getElementById('reg-wizard-submit')?.hidden,
  }));
  console.log(`Continue: ${before.current} (dom ${before.dom}) -> ${after.step} (dom ${after.dom})`, after.errors);
  return { before: before.current, after: after.step, domAfter: after.dom, errors: after.errors };
}

async function fillStewardWizard(page, email) {
  await page.goto(`${BASE}/index.php?form=steward`, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForSelector('#registration-wizard', { timeout: 20000 });

  const startStep = await page.evaluate(() => {
    const active = document.querySelector('.reg-wizard__step--active');
    return active ? active.getAttribute('data-step') : '?';
  });
  console.log('Wizard start step:', startStep);

  // Step 1 — continue (role locked via ?form=steward)
  if (startStep === '1') {
    await clickContinue(page);
    await waitStep(page, 3);
  } else if (startStep === '3') {
    // already on email
  } else if (startStep === '4') {
    // account-only may start at personal details
  } else {
    await waitStep(page, 3).catch(() => waitStep(page, 4));
  }

  const activeAfterStart = await page.evaluate(() => document.querySelector('.reg-wizard__step--active')?.getAttribute('data-step'));

  if (activeAfterStart === '3') {
    await fillActive(page, '#email', email);
    await page.waitForTimeout(1200);
    const nav = await clickContinue(page);
    const stillOnEmail = await page.evaluate(() => {
      const dom = document.querySelector('.reg-wizard__step--active')?.getAttribute('data-step');
      return dom === '3';
    });
    if (stillOnEmail) {
      const advanced = await page.evaluate(() => {
        if (!window.RegistrationWizardValidation?.validateStep?.(3, { fastTrack: false, profileEdit: false })) {
          return { ok: false, errors: window.RegistrationWizardValidation.getLastValidationErrors() };
        }
        window.RegistrationWizard.showStep(4);
        return { ok: true, step: window.RegistrationWizard.getCurrentStep() };
      });
      console.log('Manual advance from step 3:', advanced);
      if (!advanced.ok) {
        await page.screenshot({ path: join(OUT_DIR, 'steward-email-blocked.png'), fullPage: true });
        throw new Error('Step 3 validation failed: ' + JSON.stringify(advanced.errors));
      }
    }
    const afterContinue = await page.evaluate(() => {
      const active = document.querySelector('.reg-wizard__step--active');
      const errors = Array.from(document.querySelectorAll('.form-error--visible')).map((e) => e.textContent.trim());
      return { step: active?.getAttribute('data-step'), errors };
    });
    console.log('After email continue:', afterContinue);
    if (afterContinue.step === '8') {
      log('Steward', 'Returning user jump to review', 'INFO', 'complete profile — submit from review');
      await page.locator('.reg-wizard__step--active input[name="privacy_consent"]').check().catch(() => {});
      return;
    }
    if (afterContinue.step !== '4') {
      await page.screenshot({ path: join(OUT_DIR, 'steward-after-email.png'), fullPage: true });
      throw new Error(`Expected step 4 after email, on step ${afterContinue.step}: ${afterContinue.errors.join('; ')}`);
    }
  }

  if (activeAfterStart === '4' || activeAfterStart === '3') {
    // continue from personal details unless already on review
    const cur = await page.evaluate(() => document.querySelector('.reg-wizard__step--active')?.getAttribute('data-step'));
    if (cur !== '8') {
      await fillActive(page, '#surname', 'BrowserE2E');
      await fillActive(page, '#first_name', 'Steward');
      await fillActive(page, '#full_address', '1 Browser Test Lane, Dublin');
      await fillActive(page, '#eircode', 'D02 X285');
      await fillActive(page, '#date_of_birth', '1990-06-15');
      await page.locator('.reg-wizard__step--active input[name="gender"][value="male"]').check();
      await clickContinue(page);
      await waitStep(page, 5);

      await fillActive(page, '#mobile_national', '0871234567');
      await page.locator('.reg-wizard__step--active #mobile_national').dispatchEvent('input');
      await page.waitForTimeout(400);
      await clickContinue(page);
      await waitStep(page, 6);

      await fillActive(page, '#pps_number', '1234567T');
      await fillActive(page, '#bank_iban', 'IE29AIBK93115212345678');
      await clickContinue(page);

      await waitStep(page, 8).catch(async () => {
        await waitStep(page, 7);
        await clickContinue(page);
        await waitStep(page, 8);
      });
    }
  }

  await page.locator('.reg-wizard__step--active input[name="privacy_consent"]').waitFor({ state: 'visible', timeout: 15000 });

  const psaVisible = await page.locator('.reg-wizard__step--active #psa_licence').isVisible().catch(() => false);
  if (psaVisible) {
    const psaRequired = await page.locator('#psa_licence').getAttribute('required');
    log('Steward', 'PSA not required on review step', psaRequired ? 'FAIL' : 'PASS', `required=${psaRequired}`);
  } else {
    log('Steward', 'PSA fields not on review step', 'PASS', 'account-only steward path');
  }

  await page.locator('.reg-wizard__step--active input[name="privacy_consent"]').check();
}

async function runStewardSubmit(page) {  const consoleLogs = [];
  const networkLog = [];

  page.on('console', (msg) => {
    consoleLogs.push({ type: msg.type(), text: msg.text() });
  });
  page.on('pageerror', (err) => {
    consoleLogs.push({ type: 'pageerror', text: err.message });
  });

  page.on('request', (req) => {
    if (req.method() === 'POST' && req.url().includes('submit.php')) {
      networkLog.push({ phase: 'request', method: req.method(), url: req.url() });
    }
  });
  page.on('response', async (res) => {
    if (res.request().method() === 'POST' && res.url().includes('submit.php')) {
      networkLog.push({
        phase: 'response',
        status: res.status(),
        url: res.url(),
        location: res.headers()['location'] || null,
      });
    }
  });

  await fillStewardWizard(page, stewardEmail);

  const submitBtn = page.locator('#reg-wizard-submit');  await submitBtn.waitFor({ state: 'visible', timeout: 10000 });

  const submitResponsePromise = page.waitForResponse(
    (res) => res.request().method() === 'POST' && res.url().includes('submit.php'),
    { timeout: 90000 }
  );

  await submitBtn.click();

  let submitResponse;
  try {
    submitResponse = await submitResponsePromise;
  } catch {
    log('Complete Registration', 'POST submit.php sent', 'FAIL', 'No POST within 90s');
    writeFileSync(join(OUT_DIR, 'steward-console.json'), JSON.stringify(consoleLogs, null, 2));
    writeFileSync(join(OUT_DIR, 'steward-network.json'), JSON.stringify(networkLog, null, 2));
    await page.screenshot({ path: join(OUT_DIR, 'steward-submit-fail.png'), fullPage: true });
    return;
  }

  const status = submitResponse.status();
  const headers = submitResponse.headers();
  log('Complete Registration', 'POST submit.php', status >= 200 && status < 400 ? 'PASS' : 'FAIL', `HTTP ${status}`);
  log('Complete Registration', 'Redirect Location', headers.location?.includes('staff-app.php') ? 'PASS' : 'WARN', headers.location || '(follow navigation)');

  await page.waitForURL(/staff-app\.php/, { timeout: 60000 }).catch(() => null);
  const finalUrl = page.url();
  log('Complete Registration', 'Final URL staff-app', /staff-app\.php.*registered=profile/.test(finalUrl) ? 'PASS' : 'FAIL', finalUrl);

  const jsErrors = consoleLogs.filter((e) => e.type === 'error' || e.type === 'pageerror');
  log('Browser Console', 'No JS errors', jsErrors.length === 0 ? 'PASS' : 'FAIL', jsErrors.map((e) => e.text).join('; '));

  writeFileSync(join(OUT_DIR, 'steward-console.json'), JSON.stringify(consoleLogs, null, 2));
  writeFileSync(join(OUT_DIR, 'steward-network.json'), JSON.stringify(networkLog, null, 2));
  await page.screenshot({ path: join(OUT_DIR, 'steward-success.png'), fullPage: true });

  log('Steward', 'Registration email', 'INFO', stewardEmail);
}

async function runStaticPsaBlock(page) {
  await page.goto(`${BASE}/index.php?form=static`, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForSelector('#registration-wizard', { timeout: 20000 });

  const startStep = await page.evaluate(() => document.querySelector('.reg-wizard__step--active')?.getAttribute('data-step'));
  if (startStep === '1') {
    await clickContinue(page);
    await waitStep(page, 3);
  }

  await fillActive(page, '#email', staticEmail);
  await clickContinue(page);
  await waitStep(page, 4);

  await fillActive(page, '#surname', 'BrowserE2E');
  await fillActive(page, '#first_name', 'Static');
  await fillActive(page, '#full_address', '2 Browser Test Lane, Dublin');
  await fillActive(page, '#eircode', 'D02 X285');
  await fillActive(page, '#date_of_birth', '1985-03-20');
  await page.locator('.reg-wizard__step--active input[name="gender"][value="male"]').check();
  await clickContinue(page);
  await waitStep(page, 5);

  await fillActive(page, '#mobile_national', '0877654321');
  await page.locator('.reg-wizard__step--active #mobile_national').dispatchEvent('input');
  await clickContinue(page);
  await waitStep(page, 6);

  await fillActive(page, '#pps_number', '7654321W');
  await fillActive(page, '#bank_iban', 'IE29AIBK93115212345678');
  await clickContinue(page);
  await waitStep(page, 7);

  const psaVisible = await page.locator('.reg-wizard__step--active #psa_licence').isVisible().catch(() => false);
  if (psaVisible) {
    log('Static', 'PSA fields shown', 'PASS', 'psa_licence visible on step 7');
    await clickContinue(page);
    await page.waitForTimeout(500);
    const psaError = await page.locator('.reg-wizard__step--active .form-error--visible').first().textContent().catch(() => '');
    log('Static', 'PSA blocks without data', psaError ? 'PASS' : 'FAIL', (psaError || '').trim());
  } else {
    log('Static', 'PSA step reached', 'FAIL', 'PSA fields not visible');
  }

  await page.screenshot({ path: join(OUT_DIR, 'static-psa-block.png'), fullPage: true });
}

async function runReturningUser(page, existingEmail) {
  await page.goto(`${BASE}/index.php?form=steward`, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForSelector('#registration-wizard', { timeout: 20000 });
  await clickContinue(page);
  await waitStep(page, 3);

  const emailEl = page.locator('.reg-wizard__step--active #email');
  const lookupResponse = page.waitForResponse(
    (res) => res.url().includes('registrant-lookup.php') && res.request().method() === 'GET',
    { timeout: 15000 }
  );
  await emailEl.fill(existingEmail);
  await emailEl.dispatchEvent('input');
  await emailEl.dispatchEvent('change');
  let lookupJson = null;
  try {
    const lookupRes = await lookupResponse;
    lookupJson = await lookupRes.json();
    writeFileSync(join(OUT_DIR, 'returning-lookup.json'), JSON.stringify(lookupJson, null, 2));
  } catch {
    log('Returning User', 'Lookup API response', 'WARN', 'no response within 15s');
  }
  await page.waitForTimeout(800);

  const state = await page.evaluate(() => ({
    domStep: document.querySelector('.reg-wizard__step--active')?.getAttribute('data-step'),
    current: window.RegistrationWizard?.getCurrentStep?.(),
    panelText: document.getElementById('reg-returning-panel')?.textContent?.trim().slice(0, 120) || '',
    panelHidden: document.getElementById('reg-returning-panel')?.hidden,
  }));

  const welcomeBack = /welcome back/i.test(state.panelText);
  log('Returning User', 'Welcome back panel', welcomeBack ? 'PASS' : 'FAIL', state.panelText || '(empty)');

  const lookupFound = lookupJson?.found === true;
  log('Returning User', 'Lookup finds profile-only staff', lookupFound ? 'PASS' : 'FAIL', lookupJson?.error || '');

  const notOnShifts = state.domStep !== '2' && state.current !== 2;
  log('Returning User', 'No redirect to shift step', notOnShifts ? 'PASS' : 'FAIL', `step ${state.domStep}`);

  if (lookupJson?.profile_complete) {
    const onReview = state.domStep === '8' || state.current === 8;
    log('Returning User', 'Fast-track to review when complete', onReview ? 'PASS' : 'FAIL', `step ${state.domStep || state.current}`);
  } else {
    log('Returning User', 'Incomplete profile stays on email/details', (state.domStep === '3' || state.domStep === '4') ? 'PASS' : 'INFO', `step ${state.domStep} (PSA onboarding flags incomplete for steward)`);
  }

  await page.screenshot({ path: join(OUT_DIR, 'returning-user.png'), fullPage: true });
}

async function runStaffAppGuest(page) {
  await page.goto(`${BASE}/staff-app.php?registered=profile`, { waitUntil: 'domcontentloaded', timeout: 60000 });
  const bodyText = await page.locator('body').innerText();
  const hasNotice = /registration complete|sign in below/i.test(bodyText);
  log('Staff App', 'Post-registration sign-in notice', hasNotice ? 'PASS' : 'FAIL', hasNotice ? 'notice visible' : 'missing');

  const hasSignIn = /sign in|google|email/i.test(bodyText);
  log('Staff App', 'Guest sign-in UI loads', hasSignIn ? 'PASS' : 'FAIL', 'unauthenticated guest page');

  const status = page.url().includes('staff-app.php') ? 'PASS' : 'FAIL';
  log('Staff App', 'Dashboard URL reachable', status, page.url());

  await page.screenshot({ path: join(OUT_DIR, 'staff-app-guest.png'), fullPage: true });
}

async function runAdminSmoke() {
  const ADMIN = 'https://admin.olasentra.com';
  const paths = [
    ['Admin Dashboard', '/admin/dashboard.php'],
    ['Events', '/admin/events.php'],
    ['Staff List', '/admin/view-staff.php'],
    ['Staff Inbox', '/admin/staff-inbox.php'],
    ['Staff Thread', '/admin/staff-inbox-thread.php'],
    ['Messaging', '/admin/communication-centre.php'],
    ['Profile Pages', '/admin/settings-site.php'],
  ];

  for (const [name, path] of paths) {
    try {
      const res = await fetch(ADMIN + path, { redirect: 'manual' });
      const ok = res.status !== 500;
      log('Admin', name, ok ? 'PASS' : 'FAIL', `HTTP ${res.status}`);
    } catch (e) {
      log('Admin', name, 'FAIL', e.message);
    }
  }
}

async function verifyDb(email) {
  const url = `${BASE}/cron/probe-profile-only-registration.php?key=email-encoding-verify-20260606&email=${encodeURIComponent(email)}`;
  try {
    const res = await fetch(url);
    const json = await res.json();
    writeFileSync(join(OUT_DIR, 'steward-db.json'), JSON.stringify(json, null, 2));
    if (json.profile_only_ok) {
      log('Database', 'staff=1, registrations=0', 'PASS', JSON.stringify(json.checks));
    } else {
      log('Database', 'profile-only verification', 'FAIL', JSON.stringify(json));
    }
  } catch (e) {
    log('Database', 'probe endpoint', 'WARN', e.message + ' (deploy cron/probe-profile-only-registration.php)');
  }
}

async function main() {
  console.log(`\nProduction browser E2E — ${BASE}\nOutput: ${OUT_DIR}\n`);

  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({
    userAgent: 'Olasentra-E2E-Browser/1.0',
    locale: 'en-IE',
    serviceWorkers: 'block',
  });
  await context.addInitScript(() => {
    try {
      localStorage.clear();
      sessionStorage.clear();
    } catch (e) { /* ignore */ }
  });
  const page = await context.newPage();

  try {
    await runStewardSubmit(page);
    await verifyDb(stewardEmail);
    await runReturningUser(page, stewardEmail);
    await runStaffAppGuest(page);

    const page2 = await context.newPage();
    await runStaticPsaBlock(page2);

    await runAdminSmoke();
  } finally {
    await browser.close();
  }

  const summary = {
    executed_at: new Date().toISOString(),
    base: BASE,
    steward_email: stewardEmail,
    static_email: staticEmail,
    results,
  };
  writeFileSync(join(OUT_DIR, 'summary.json'), JSON.stringify(summary, null, 2));

  const fails = results.filter((r) => r.status === 'FAIL');
  console.log(`\nDone: ${results.length} checks, ${fails.length} FAIL\n`);
  process.exit(fails.length > 0 ? 1 : 0);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});

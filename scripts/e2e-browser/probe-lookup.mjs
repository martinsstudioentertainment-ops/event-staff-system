import { chromium } from 'playwright';

const email = process.argv[2] || 'browser.steward.20260630005244@olasentra-e2e.test';
const browser = await chromium.launch({ headless: true });
const page = await browser.newPage();
await page.goto('https://register.olasentra.com/index.php?form=steward', { waitUntil: 'domcontentloaded' });
const result = await page.evaluate(async (em) => {
  const csrf = document.body.dataset.analyticsCsrf || '';
  const url = 'api/registrant-lookup.php?email=' + encodeURIComponent(em) + '&csrf_token=' + encodeURIComponent(csrf);
  const res = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
  return { status: res.status, body: await res.text() };
}, email);
console.log(JSON.stringify(result, null, 2));
await browser.close();

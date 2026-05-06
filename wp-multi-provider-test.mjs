// Browser smoke test for multi-provider connector cards.
// Targets host WordPress at http://wordpress.local:8080 (wp-cli admin user).
import { chromium } from 'playwright';

const BASE = process.env.WP_BASE || 'http://wordpress.local:8080';
const ADMIN_USER = process.env.WP_USER || 'admin';
const ADMIN_PASS = process.env.WP_PASS || 'testpass123';
const SHOTS = '/home/dave/.aidevops/.agent-workspace/tmp';

const PROVIDERS = [
  { id: 'anthropic', label: 'Anthropic Max' },
  { id: 'openai', label: 'OpenAI ChatGPT' },
  { id: 'cursor', label: 'Cursor Pro' },
  { id: 'google', label: 'Google AI Pro' },
];

const log = (...a) => console.log('[test]', ...a);
const fail = (msg) => { console.error('[FAIL]', msg); process.exitCode = 1; };

(async () => {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ viewport: { width: 1400, height: 1000 } });
  const page = await context.newPage();

  const errors = [];
  page.on('console', m => { if (m.type() === 'error') errors.push(`console: ${m.text()}`); });
  page.on('pageerror', e => errors.push(`pageerror: ${e.message}`));

  try {
    log('1. Login at', `${BASE}/wp-login.php`);
    await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'domcontentloaded', timeout: 15000 });
    // Site uses Multisite Ultimate's custom login form (input name="log" / "pwd").
    await page.fill('input[name="log"]', ADMIN_USER);
    await page.fill('input[name="pwd"]', ADMIN_PASS);
    await Promise.all([
      page.waitForURL(/wp-admin/, { timeout: 20000 }),
      page.click('button[type="submit"], input[type="submit"]'),
    ]);
    log('   logged in:', page.url());

    log('2. Open Connectors page');
    // Gutenberg registers the page with slug "options-connectors-wp-admin".
    await page.goto(`${BASE}/wp-admin/options-general.php?page=options-connectors-wp-admin`, {
      waitUntil: 'domcontentloaded', timeout: 30000,
    });
    await page.waitForTimeout(4000); // let React + registerConnector ticks settle
    await page.screenshot({ path: `${SHOTS}/multi-connectors-list.png` });
    log('   screenshot:', `${SHOTS}/multi-connectors-list.png`);

    log('3. Verify all four provider cards render');
    const bodyText = await page.locator('body').innerText();
    const present = {};
    for (const p of PROVIDERS) {
      present[p.id] = bodyText.includes(p.label);
      log(`   ${p.id} (${p.label}): ${present[p.id] ? 'OK' : 'MISSING'}`);
      if (!present[p.id]) fail(`${p.label} card missing`);
    }

    if (Object.values(present).every(Boolean)) {
      log('4. Open each card to confirm forms render');
      for (const p of PROVIDERS) {
        // Find the row containing the provider label, then the "Set up" button at row-level.
        const row = page.locator('tr, li, div').filter({ hasText: p.label }).filter({ has: page.locator('button, a').filter({ hasText: /Set up|Manage/i }) }).first();
        const setupBtn = row.locator('button, a').filter({ hasText: /Set up|Manage/i }).first();
        try {
          const c = await setupBtn.count();
          if (!c) {
            log(`   ${p.id}: no setup button found`);
            continue;
          }
          await setupBtn.click({ timeout: 5000 });
          await page.waitForTimeout(1200);
          await page.screenshot({ path: `${SHOTS}/multi-card-${p.id}.png` });

          // Probe for expected fields
          const txt = await page.locator('body').innerText();
          const hasManual = txt.includes('access_token') || txt.includes('Access token') || txt.includes('Paste');
          const hasOAuth = txt.includes('Authorize') || txt.includes('authorize') || txt.includes('OAuth') || txt.includes('paste');
          log(`   ${p.id}: form opened, oauth=${hasOAuth} manual=${hasManual}`);

          // Try to close via cancel/back button or escape
          const cancelBtn = page.locator('button').filter({ hasText: /Cancel|Back|Close/i }).first();
          if (await cancelBtn.count()) {
            await cancelBtn.click({ timeout: 1500 }).catch(() => {});
          } else {
            await page.keyboard.press('Escape').catch(() => {});
          }
          await page.waitForTimeout(500);
        } catch (e) {
          log(`   ${p.id}: click/probe failed (${String(e).split('\n')[0]})`);
        }
      }
    }

    log('5. Console / page errors collected:', errors.length);
    for (const e of errors) console.log('   !', e);
    // Filter out known WP/plugin noise unrelated to our plugin
    const ours = errors.filter(e =>
      /anthropic|connector|@wordpress\/connectors|registerConnector/i.test(e)
    );
    if (ours.length) {
      fail(`Plugin-related JS errors: ${ours.length}`);
      for (const e of ours) console.log('     >', e);
    } else {
      log('   no plugin-related JS errors');
    }
  } catch (err) {
    fail(`exception: ${err.message}`);
    await page.screenshot({ path: `${SHOTS}/multi-error.png` }).catch(() => {});
  } finally {
    await browser.close();
    log('done.');
  }
})();

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
    await page.fill('#user_login', ADMIN_USER);
    await page.fill('#user_pass', ADMIN_PASS);
    await page.click('#wp-submit');
    await page.waitForURL('**/wp-admin/**', { timeout: 15000 });
    log('   logged in:', page.url());

    log('2. Open Connectors page');
    await page.goto(`${BASE}/wp-admin/options-general.php?page=connectors`, {
      waitUntil: 'networkidle', timeout: 20000,
    });
    await page.waitForTimeout(1500); // let registerConnector ticks settle
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
        const card = page.locator(`text=${p.label}`).first();
        const setupBtn = page.locator(`text=${p.label}`).locator('xpath=ancestor::*[self::article or self::section or self::div][1]').locator('button, a').filter({ hasText: /Set up|Manage|Add/i }).first();
        try {
          if (await setupBtn.count()) {
            await setupBtn.click({ timeout: 3000 });
            await page.waitForTimeout(800);
            await page.screenshot({ path: `${SHOTS}/multi-card-${p.id}.png` });
            log(`   ${p.id}: opened, screenshot saved`);
            // close: press Escape or navigate back to list view
            await page.keyboard.press('Escape').catch(() => {});
            await page.waitForTimeout(300);
          } else {
            log(`   ${p.id}: no setup button found (may be already-active variant)`);
          }
        } catch (e) {
          log(`   ${p.id}: click failed (${e.message.split('\n')[0]})`);
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

import { chromium } from 'playwright';

const BASE = 'http://localhost:8895';
const SCREENSHOTS_DIR = '/home/dave/.aidevops/.agent-workspace/tmp';

(async () => {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();

  // Collect console errors
  const consoleErrors = [];
  page.on('console', msg => {
    if (msg.type() === 'error') consoleErrors.push(msg.text());
  });
  page.on('pageerror', err => consoleErrors.push(err.message));

  try {
    // Step 1: Navigate to login page
    console.log('Step 1: Navigating to wp-login.php...');
    await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'networkidle', timeout: 15000 });
    console.log('Login page loaded. Title:', await page.title());

    // Step 2: Log in
    console.log('Step 2: Logging in...');
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', 'password');
    await page.click('#wp-submit');
    await page.waitForURL('**/wp-admin/**', { timeout: 15000 });
    console.log('Logged in. Current URL:', page.url());

    // Step 3: Navigate to Connectors page
    console.log('Step 3: Navigating to Connectors page...');
    await page.goto(`${BASE}/wp-admin/options-general.php?page=connectors`, {
      waitUntil: 'networkidle',
      timeout: 15000,
    });
    console.log('Connectors page loaded. URL:', page.url());

    // Step 4: Screenshot the Connectors page
    console.log('Step 4: Taking screenshot of Connectors page...');
    await page.screenshot({
      path: `${SCREENSHOTS_DIR}/connectors-page.png`,
      fullPage: true,
    });
    console.log('Screenshot saved: connectors-page.png');

    // Check for Anthropic Max card
    const pageContent = await page.content();
    const anthropicMaxVisible = pageContent.includes('Anthropic Max') ||
      pageContent.includes('anthropic-max') ||
      pageContent.includes('anthropic_max');

    console.log(`\nAnthropic Max card visible: ${anthropicMaxVisible}`);

    // Try to find the card more specifically
    const cardLocators = [
      page.locator('text=Anthropic Max'),
      page.locator('[data-connector="anthropic-max"]'),
      page.locator('[data-connector="anthropic_max"]'),
      page.locator('.connector-card:has-text("Anthropic Max")'),
      page.locator('[class*="anthropic"]'),
    ];

    let cardFound = false;
    let cardElement = null;
    for (const loc of cardLocators) {
      const count = await loc.count();
      if (count > 0) {
        cardFound = true;
        cardElement = loc.first();
        console.log(`Found Anthropic Max card via locator: ${loc}`);
        break;
      }
    }

    if (!cardFound) {
      // Let's dump what cards ARE on the page
      console.log('\nNo Anthropic Max card found. Checking what cards exist...');
      const allText = await page.locator('body').innerText();
      // Print first 3000 chars to see what's there
      console.log('Page text (first 3000 chars):\n', allText.substring(0, 3000));
    }

    // Step 5: Click Set up / Manage button if card found
    if (cardFound) {
      console.log('\nStep 5: Looking for Set up / Manage button...');
      const buttonLocators = [
        cardElement.locator('text=Set up'),
        cardElement.locator('text=Manage'),
        cardElement.locator('button'),
        cardElement.locator('a'),
        page.locator('text=Set up').first(),
        page.locator('text=Manage').first(),
      ];

      let buttonClicked = false;
      for (const btn of buttonLocators) {
        const count = await btn.count();
        if (count > 0) {
          console.log(`Clicking button: ${await btn.first().innerText()}`);
          await btn.first().click();
          buttonClicked = true;
          break;
        }
      }

      if (!buttonClicked) {
        // Try clicking the card itself
        console.log('No button found, clicking the card itself...');
        await cardElement.click();
      }

      // Wait for any expansion/navigation
      await page.waitForTimeout(2000);

      // Step 6: Screenshot the expanded card / OAuth form
      console.log('Step 6: Taking screenshot of expanded card...');
      await page.screenshot({
        path: `${SCREENSHOTS_DIR}/connectors-expanded.png`,
        fullPage: true,
      });
      console.log('Screenshot saved: connectors-expanded.png');

      // Check for email input
      const emailInput = page.locator('input[type="email"], input[name*="email"], input[placeholder*="email"]');
      const emailCount = await emailInput.count();
      console.log(`Email input fields found: ${emailCount}`);

      if (emailCount > 0) {
        console.log('OAuth flow form with email input is visible!');
      } else {
        // Check page content for any form
        const expandedText = await page.locator('body').innerText();
        console.log('Expanded page text (first 2000 chars):\n', expandedText.substring(0, 2000));
      }
    } else {
      console.log('\nSkipping Steps 5-6: Anthropic Max card not found.');
      // Still take a second screenshot for debugging
      await page.screenshot({
        path: `${SCREENSHOTS_DIR}/connectors-expanded.png`,
        fullPage: true,
      });
    }

    // Report console errors
    if (consoleErrors.length > 0) {
      console.log('\n--- Console Errors ---');
      consoleErrors.forEach(e => console.log('  ERROR:', e));
    } else {
      console.log('\nNo console errors detected.');
    }

  } catch (err) {
    console.error('Test failed with error:', err.message);
    await page.screenshot({
      path: `${SCREENSHOTS_DIR}/connectors-error.png`,
      fullPage: true,
    });
    console.log('Error screenshot saved: connectors-error.png');
  } finally {
    await browser.close();
    console.log('\nBrowser closed. Test complete.');
  }
})();

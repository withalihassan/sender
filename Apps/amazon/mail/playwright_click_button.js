#!/usr/bin/env node

let chromium;

try {
  ({ chromium } = require('playwright'));
} catch (error) {
  console.log(JSON.stringify({
    success: false,
    message: 'Playwright is not installed. Run: npm install playwright && npx playwright install --with-deps chromium',
  }));
  process.exit(1);
}

const url = process.argv[2] || '';
const selector = process.argv[3] || 'button[data-testid="send-sms-message-button"]';

if (!url) {
  console.error('Usage: node playwright_click_button.js <url> [selector]');
  process.exit(1);
}

(async () => {
  const browser = await chromium.launch({
    headless: true,
    args: [
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-dev-shm-usage',
    ],
  });

  try {
    const page = await browser.newPage();
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.waitForLoadState('networkidle', { timeout: 60000 }).catch(() => {});

    let target = page.locator(selector).first();

    try {
      await target.waitFor({ state: 'visible', timeout: 60000 });
    } catch (_) {
      target = page.getByRole('button', { name: /^SMS text$/ }).first();
      await target.waitFor({ state: 'visible', timeout: 30000 });
    }

    await target.click();
    await page.waitForTimeout(2500);

    console.log(JSON.stringify({
      success: true,
      message: 'Button clicked.',
      selector,
      finalUrl: page.url(),
    }));
  } catch (error) {
    console.log(JSON.stringify({
      success: false,
      message: error.message,
      selector,
    }));
    process.exitCode = 1;
  } finally {
    await browser.close();
  }
})();

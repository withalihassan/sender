#!/usr/bin/env node

const { chromium } = require('playwright');

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
    await page.goto(url, { waitUntil: 'networkidle', timeout: 60000 });
    await page.waitForSelector(selector, { state: 'visible', timeout: 60000 });
    await page.click(selector);
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

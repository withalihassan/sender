#!/usr/bin/env node

const { spawn } = require('node:child_process');
const fs = require('node:fs');
const http = require('node:http');
const os = require('node:os');
const path = require('node:path');

const url = process.argv[2] || '';
const eventId = process.argv[3] || 'debug';
const chromePath = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const debugDir = path.resolve(__dirname, '../data/mail-loaded-pages');
const userDataDir = fs.mkdtempSync(path.join(os.tmpdir(), 'amazon-sms-chrome-'));
const port = 9222 + Math.floor(Math.random() * 1000);

if (!url) {
  fail('Missing URL argument.');
}

if (!fs.existsSync(chromePath)) {
  fail('Google Chrome was not found.');
}

fs.mkdirSync(debugDir, { recursive: true });

const chrome = spawn(chromePath, [
  '--headless=new',
  '--disable-gpu',
  '--no-first-run',
  '--no-default-browser-check',
  `--remote-debugging-port=${port}`,
  `--user-data-dir=${userDataDir}`,
  'about:blank',
], {
  stdio: ['ignore', 'ignore', 'pipe'],
});

let stderr = '';
chrome.stderr.on('data', chunk => {
  stderr += chunk.toString();
});

main()
  .then(result => {
    cleanup();
    console.log(JSON.stringify(result));
    process.exit(0);
  })
  .catch(error => {
    cleanup();
    fail(error.message || String(error));
  });

async function main() {
  await waitForChrome();
  const tab = await requestJson('PUT', `/json/new?${encodeURIComponent(url)}`);
  const cdp = new CDP(tab.webSocketDebuggerUrl);
  await cdp.connect();
  await cdp.send('Page.enable');
  await cdp.send('Runtime.enable');
  await waitForPage(cdp);

  const buttonCheck = await waitForSmsButton(cdp, 30000);
  const htmlResult = await cdp.send('Runtime.evaluate', {
    expression: 'document.documentElement.outerHTML',
    returnByValue: true,
  });
  const html = htmlResult.result.value || '';
  const htmlPath = path.join(debugDir, `event-${safeName(eventId)}-browser.html`);
  fs.writeFileSync(htmlPath, html);

  if (!buttonCheck.found) {
    const summary = await pageSummary(cdp);
    throw new Error(`SMS button not found after browser load. ${summary}`);
  }

  await cdp.send('Runtime.evaluate', {
    expression: `
      (() => {
        const button = document.querySelector('button[data-testid="send-sms-message-button"]');
        if (!button) return false;
        button.scrollIntoView({ block: 'center', inline: 'center' });
        button.click();
        return true;
      })()
    `,
    awaitPromise: true,
    returnByValue: true,
  });

  await sleep(2500);
  const afterClick = await pageSummary(cdp);
  await cdp.close();

  return {
    success: true,
    message: `Browser clicked SMS button. ${afterClick}`,
    loaded_html_url: `data/mail-loaded-pages/event-${safeName(eventId)}-browser.html`,
  };
}

async function waitForSmsButton(cdp, timeoutMs) {
  const started = Date.now();

  while (Date.now() - started < timeoutMs) {
    const result = await cdp.send('Runtime.evaluate', {
      expression: `
        (() => {
          const sms = document.querySelector('button[data-testid="send-sms-message-button"]');
          const voice = document.querySelector('button[data-testid="send-voice-message-button"]');
          return { found: !!sms, disabled: sms ? sms.disabled : null, voiceFound: !!voice };
        })()
      `,
      returnByValue: true,
    });
    const value = result.result.value || {};

    if (value.found && value.disabled === false) {
      return { found: true };
    }

    await sleep(500);
  }

  return { found: false };
}

async function pageSummary(cdp) {
  const result = await cdp.send('Runtime.evaluate', {
    expression: `
      (() => {
        const title = document.title || '';
        const buttons = Array.from(document.querySelectorAll('button')).slice(0, 6).map(button => {
          const text = (button.innerText || button.textContent || '').replace(/\\s+/g, ' ').trim();
          return (button.dataset.testid || 'no-testid') + (text ? ' text=' + text : '');
        });
        return 'Final URL: ' + location.href + ' Title: ' + title + ' Buttons: ' + (buttons.length ? buttons.join(' | ') : 'none');
      })()
    `,
    returnByValue: true,
  });

  return result.result.value || '';
}

async function waitForPage(cdp) {
  await cdp.send('Page.navigate', { url });
  await sleep(1000);

  for (let i = 0; i < 60; i++) {
    const result = await cdp.send('Runtime.evaluate', {
      expression: 'document.readyState',
      returnByValue: true,
    });

    if (result.result.value === 'complete') {
      await sleep(2500);
      return;
    }

    await sleep(500);
  }
}

async function waitForChrome() {
  for (let i = 0; i < 80; i++) {
    try {
      await requestJson('GET', '/json/version');
      return;
    } catch (_) {
      await sleep(250);
    }
  }

  throw new Error(`Chrome did not start. ${stderr.trim()}`);
}

function requestJson(method, route) {
  return new Promise((resolve, reject) => {
    const req = http.request({ host: '127.0.0.1', port, method, path: route }, res => {
      let body = '';
      res.on('data', chunk => body += chunk);
      res.on('end', () => {
        if (res.statusCode < 200 || res.statusCode >= 300) {
          reject(new Error(`Chrome DevTools HTTP ${res.statusCode}: ${body}`));
          return;
        }

        try {
          resolve(JSON.parse(body));
        } catch (error) {
          reject(error);
        }
      });
    });
    req.on('error', reject);
    req.end();
  });
}

class CDP {
  constructor(wsUrl) {
    this.wsUrl = wsUrl;
    this.id = 0;
    this.pending = new Map();
  }

  connect() {
    return new Promise((resolve, reject) => {
      this.ws = new WebSocket(this.wsUrl);
      this.ws.onopen = resolve;
      this.ws.onerror = () => reject(new Error('Unable to connect to Chrome DevTools websocket.'));
      this.ws.onmessage = event => {
        const data = JSON.parse(event.data);
        if (!data.id || !this.pending.has(data.id)) {
          return;
        }

        const { resolve, reject } = this.pending.get(data.id);
        this.pending.delete(data.id);

        if (data.error) {
          reject(new Error(data.error.message || JSON.stringify(data.error)));
        } else {
          resolve(data.result || {});
        }
      };
    });
  }

  send(method, params = {}) {
    return new Promise((resolve, reject) => {
      const id = ++this.id;
      this.pending.set(id, { resolve, reject });
      this.ws.send(JSON.stringify({ id, method, params }));
    });
  }

  close() {
    if (this.ws) {
      this.ws.close();
    }
  }
}

function cleanup() {
  chrome.kill('SIGTERM');
  fs.rmSync(userDataDir, { recursive: true, force: true });
}

function sleep(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}

function safeName(value) {
  return String(value).replace(/[^a-zA-Z0-9_-]/g, '');
}

function fail(message) {
  cleanupIfNeeded();
  console.log(JSON.stringify({ success: false, message }));
  process.exit(1);
}

function cleanupIfNeeded() {
  try {
    if (chrome && !chrome.killed) {
      chrome.kill('SIGTERM');
    }
  } catch (_) {}

  try {
    fs.rmSync(userDataDir, { recursive: true, force: true });
  } catch (_) {}
}

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Code Vault — TOTP Generator</title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600;700&display=swap');

  :root {
    --bg: #F5F0E6;
    --bg-panel: #FBF8F1;
    --bg-panel-raised: #F1EAD9;
    --border: #E0D5BE;
    --border-soft: #E9E0CC;
    --text: #2B2621;
    --text-dim: #6B6255;
    --text-faint: #9A8F7C;
    --accent: #B5852E;
    --accent-dim: #E3C384;
    --accent-glow: rgba(181, 133, 46, 0.14);
    --danger: #B5493D;
    --ok: #4C8C5F;
  }

  * { box-sizing: border-box; }

  body {
    margin: 0;
    min-height: 100vh;
    background:
      radial-gradient(circle at 15% 0%, rgba(181,133,46,0.10), transparent 45%),
      radial-gradient(circle at 85% 100%, rgba(181,133,46,0.07), transparent 40%),
      var(--bg);
    color: var(--text);
    font-family: 'Space Grotesk', -apple-system, sans-serif;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 32px 20px;
  }

  .card {
    width: 100%;
    max-width: 460px;
    background: var(--bg-panel);
    border: 1px solid var(--border);
    border-radius: 18px;
    padding: 36px 32px 32px;
    box-shadow: 0 30px 60px -25px rgba(43,38,33,0.22), 0 0 0 1px rgba(255,255,255,0.5) inset;
  }

  .brand {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 26px;
  }

  .brand-mark {
    width: 30px; height: 30px;
    border-radius: 8px;
    background: linear-gradient(155deg, var(--accent), var(--accent-dim));
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
  }
  .brand-mark svg { width: 16px; height: 16px; }

  .brand-text { line-height: 1.15; }
  .brand-text .name { font-weight: 700; font-size: 15px; letter-spacing: 0.01em; }
  .brand-text .tag { font-size: 11.5px; color: var(--text-faint); letter-spacing: 0.03em; }

  label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--text-dim);
    margin-bottom: 8px;
  }

  .input-row {
    display: flex;
    gap: 10px;
    margin-bottom: 4px;
  }

  input[type="text"] {
    flex: 1;
    min-width: 0;
    background: var(--bg-panel-raised);
    border: 1px solid var(--border);
    color: var(--text);
    font-family: 'JetBrains Mono', monospace;
    font-size: 13px;
    padding: 13px 14px;
    border-radius: 10px;
    outline: none;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
    letter-spacing: 0.02em;
  }

  input[type="text"]::placeholder { color: var(--text-faint); }

  input[type="text"]:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px var(--accent-glow);
  }

  input.invalid {
    border-color: var(--danger);
  }

  button.submit {
    background: var(--accent);
    color: #1a1508;
    border: none;
    font-family: 'Space Grotesk', sans-serif;
    font-weight: 700;
    font-size: 13px;
    padding: 0 20px;
    border-radius: 10px;
    cursor: pointer;
    transition: transform 0.08s ease, filter 0.15s ease;
    white-space: nowrap;
  }
  button.submit:hover { filter: brightness(1.08); }
  button.submit:active { transform: scale(0.97); }

  .hint {
    font-size: 11.5px;
    color: var(--text-faint);
    margin-top: 8px;
    min-height: 16px;
  }
  .hint.error { color: var(--danger); }

  .codes {
    margin-top: 28px;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
  }

  .code-tile {
    background: var(--bg-panel-raised);
    border: 1px solid var(--border-soft);
    border-radius: 14px;
    padding: 18px 16px 16px;
    position: relative;
    opacity: 0;
    transform: translateY(6px);
    transition: opacity 0.35s ease, transform 0.35s ease, border-color 0.3s ease;
  }
  .code-tile.show { opacity: 1; transform: translateY(0); }
  .code-tile.current { border-color: rgba(227,179,65,0.35); }

  .tile-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
  }

  .tile-label {
    font-size: 10.5px;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--text-faint);
  }
  .code-tile.current .tile-label { color: var(--accent); }

  .ring-wrap { width: 22px; height: 22px; position: relative; }
  .ring-wrap svg { width: 22px; height: 22px; transform: rotate(-90deg); }
  .ring-bg { fill: none; stroke: var(--border); stroke-width: 2.5; }
  .ring-fg { fill: none; stroke: var(--accent); stroke-width: 2.5; stroke-linecap: round; transition: stroke-dashoffset 1s linear; }

  .code-value {
    font-family: 'JetBrains Mono', monospace;
    font-size: 26px;
    font-weight: 700;
    letter-spacing: 0.06em;
    color: var(--text);
    margin-bottom: 14px;
  }

  .copy-btn {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    background: transparent;
    border: 1px solid var(--border);
    color: var(--text-dim);
    font-family: 'Space Grotesk', sans-serif;
    font-size: 11.5px;
    font-weight: 600;
    padding: 8px 0;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.15s ease;
  }
  .copy-btn:hover { border-color: var(--accent); color: var(--accent); }
  .copy-btn svg { width: 13px; height: 13px; flex-shrink: 0; }
  .copy-btn.copied { border-color: var(--ok); color: var(--ok); }

  .footnote {
    margin-top: 22px;
    font-size: 11px;
    line-height: 1.6;
    color: var(--text-faint);
    display: flex;
    gap: 8px;
    align-items: flex-start;
  }
  .footnote svg { width: 13px; height: 13px; flex-shrink: 0; margin-top: 1.5px; color: var(--text-faint); }

  @media (max-width: 400px) {
    .card { padding: 28px 22px 26px; }
    .code-value { font-size: 22px; }
  }
</style>
</head>
<body>

<div class="card">
  <div class="brand">
    <div class="brand-mark">
      <svg viewBox="0 0 24 24" fill="none">
        <path d="M12 2L4 6v6c0 5 3.4 8.7 8 10 4.6-1.3 8-5 8-10V6l-8-4z" stroke="#1a1508" stroke-width="2" stroke-linejoin="round"/>
      </svg>
    </div>
    <div class="brand-text">
      <div class="name">Code Vault</div>
      <div class="tag">Local TOTP generator</div>
    </div>
  </div>

  <label for="secret">Secret key</label>
  <div class="input-row">
    <input type="text" id="secret" placeholder="Paste your Base32 secret" autocomplete="off" spellcheck="false" />
    <button class="submit" id="generateBtn">Generate</button>
  </div>
  <div class="hint" id="hint">Never leaves your browser — nothing is sent anywhere.</div>

  <div class="codes" id="codes">
    <div class="code-tile current" id="tile1">
      <div class="tile-top">
        <span class="tile-label">Current</span>
        <div class="ring-wrap">
          <svg viewBox="0 0 22 22">
            <circle class="ring-bg" cx="11" cy="11" r="9"></circle>
            <circle class="ring-fg" id="ring1" cx="11" cy="11" r="9" stroke-dasharray="56.5" stroke-dashoffset="0"></circle>
          </svg>
        </div>
      </div>
      <div class="code-value" id="code1">— — — — — —</div>
      <button class="copy-btn" data-target="code1">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
        Copy
      </button>
    </div>

    <div class="code-tile" id="tile2">
      <div class="tile-top">
        <span class="tile-label">Next</span>
        <div class="ring-wrap">
          <svg viewBox="0 0 22 22">
            <circle class="ring-bg" cx="11" cy="11" r="9"></circle>
            <circle class="ring-fg" id="ring2" cx="11" cy="11" r="9" stroke-dasharray="56.5" stroke-dashoffset="0"></circle>
          </svg>
        </div>
      </div>
      <div class="code-value" id="code2">— — — — — —</div>
      <button class="copy-btn" data-target="code2">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
        Copy
      </button>
    </div>
  </div>

  <div class="footnote">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
    <span>All generation happens in this page using your browser's built-in cryptography. Treat this secret like a password — anyone who has it can generate your codes too.</span>
  </div>
</div>

<script>
(function() {
  const secretInput = document.getElementById('secret');
  const generateBtn = document.getElementById('generateBtn');
  const hint = document.getElementById('hint');
  const codes = document.getElementById('codes');
  const tile1 = document.getElementById('tile1');
  const tile2 = document.getElementById('tile2');
  const code1El = document.getElementById('code1');
  const code2El = document.getElementById('code2');
  const ring1 = document.getElementById('ring1');
  const ring2 = document.getElementById('ring2');

  const PERIOD = 30;
  const DIGITS = 6;
  const CIRC = 56.5; // 2 * PI * r(9)

  let activeSecretBytes = null;
  let tickHandle = null;

  function base32Decode(input) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    const clean = input.toUpperCase().replace(/[^A-Z2-7]/g, '');
    if (!clean.length) return null;
    let bits = '';
    for (const ch of clean) {
      const val = alphabet.indexOf(ch);
      if (val === -1) continue;
      bits += val.toString(2).padStart(5, '0');
    }
    const bytes = [];
    for (let i = 0; i + 8 <= bits.length; i += 8) {
      bytes.push(parseInt(bits.substring(i, i + 8), 2));
    }
    return new Uint8Array(bytes);
  }

  function intToBytes(num) {
    const buf = new ArrayBuffer(8);
    const view = new DataView(buf);
    // JS numbers are safe up to 2^53, counter fits in low 32 bits for a very long time
    view.setUint32(4, num >>> 0, false);
    view.setUint32(0, Math.floor(num / 0x100000000), false);
    return new Uint8Array(buf);
  }

  // ---- Pure-JS SHA-1 + HMAC (no Web Crypto dependency) ----
  // Works on plain HTTP as well as HTTPS, since it doesn't rely on
  // crypto.subtle, which browsers restrict to secure contexts.

  function rotl(n, s) { return (n << s) | (n >>> (32 - s)); }

  function sha1(msgBytes) {
    const msgLen = msgBytes.length;
    // Padded length: msg + 0x80 marker + zero padding + 8-byte bit-length, multiple of 64 bytes
    const numBlocks = Math.ceil((msgLen + 9) / 64);
    const paddedLen = numBlocks * 64;
    const padded = new Uint8Array(paddedLen);
    padded.set(msgBytes);
    padded[msgLen] = 0x80;
    const bitLenHigh = Math.floor((msgLen * 8) / 0x100000000);
    const bitLenLow = (msgLen * 8) >>> 0;
    const dv = new DataView(padded.buffer);
    dv.setUint32(paddedLen - 8, bitLenHigh, false);
    dv.setUint32(paddedLen - 4, bitLenLow, false);

    let h0 = 0x67452301, h1 = 0xEFCDAB89, h2 = 0x98BADCFE, h3 = 0x10325476, h4 = 0xC3D2E1F0;
    const w = new Uint32Array(80);

    for (let block = 0; block < paddedLen; block += 64) {
      for (let i = 0; i < 16; i++) w[i] = dv.getUint32(block + i * 4, false);
      for (let i = 16; i < 80; i++) w[i] = rotl(w[i-3] ^ w[i-8] ^ w[i-14] ^ w[i-16], 1);

      let a = h0, b = h1, c = h2, d = h3, e = h4;

      for (let i = 0; i < 80; i++) {
        let f, k;
        if (i < 20) { f = (b & c) | ((~b) & d); k = 0x5A827999; }
        else if (i < 40) { f = b ^ c ^ d; k = 0x6ED9EBA1; }
        else if (i < 60) { f = (b & c) | (b & d) | (c & d); k = 0x8F1BBCDC; }
        else { f = b ^ c ^ d; k = 0xCA62C1D6; }

        const temp = (rotl(a, 5) + f + e + k + w[i]) >>> 0;
        e = d; d = c; c = rotl(b, 30); b = a; a = temp;
      }

      h0 = (h0 + a) >>> 0; h1 = (h1 + b) >>> 0; h2 = (h2 + c) >>> 0;
      h3 = (h3 + d) >>> 0; h4 = (h4 + e) >>> 0;
    }

    const out = new Uint8Array(20);
    [h0, h1, h2, h3, h4].forEach((h, i) => {
      out[i*4] = (h >>> 24) & 0xff;
      out[i*4+1] = (h >>> 16) & 0xff;
      out[i*4+2] = (h >>> 8) & 0xff;
      out[i*4+3] = h & 0xff;
    });
    return out;
  }

  function hmacSha1(keyBytes, msgBytes) {
    const blockSize = 64;
    let key = keyBytes;
    if (key.length > blockSize) key = sha1(key);
    if (key.length < blockSize) {
      const padded = new Uint8Array(blockSize);
      padded.set(key);
      key = padded;
    }

    const oKeyPad = new Uint8Array(blockSize);
    const iKeyPad = new Uint8Array(blockSize);
    for (let i = 0; i < blockSize; i++) {
      oKeyPad[i] = key[i] ^ 0x5c;
      iKeyPad[i] = key[i] ^ 0x36;
    }

    const inner = sha1(concatBytes(iKeyPad, msgBytes));
    return sha1(concatBytes(oKeyPad, inner));
  }

  function concatBytes(a, b) {
    const out = new Uint8Array(a.length + b.length);
    out.set(a, 0);
    out.set(b, a.length);
    return out;
  }

  function totpAt(keyBytes, forTime) {
    const counter = Math.floor(forTime / PERIOD);
    const msg = intToBytes(counter);
    const hash = hmacSha1(keyBytes, msg);
    const offset = hash[hash.length - 1] & 0x0f;
    const binCode = ((hash[offset] & 0x7f) << 24) |
                    ((hash[offset + 1] & 0xff) << 16) |
                    ((hash[offset + 2] & 0xff) << 8) |
                    (hash[offset + 3] & 0xff);
    const otp = binCode % Math.pow(10, DIGITS);
    return otp.toString().padStart(DIGITS, '0');
  }

  function formatCode(code) {
    return code.slice(0, 3) + ' ' + code.slice(3);
  }

  function setRing(ringEl, fraction) {
    // fraction: 1 = full time remaining, 0 = about to expire
    const offset = CIRC * (1 - fraction);
    ringEl.style.strokeDashoffset = offset;
  }

  function refreshCodes() {
    if (!activeSecretBytes) return;
    try {
      const now = Math.floor(Date.now() / 1000);
      const remaining = PERIOD - (now % PERIOD);

      const c1 = totpAt(activeSecretBytes, now);
      const c2 = totpAt(activeSecretBytes, now + PERIOD);

      code1El.textContent = formatCode(c1);
      code2El.textContent = formatCode(c2);
      setRing(ring1, remaining / PERIOD);
      setRing(ring2, remaining / PERIOD);
    } catch (err) {
      showError('Something went wrong generating the code: ' + err.message);
      if (tickHandle) clearInterval(tickHandle);
    }
  }

  function startTicking() {
    if (tickHandle) clearInterval(tickHandle);
    refreshCodes();
    tickHandle = setInterval(refreshCodes, 1000);
  }

  function showError(msg) {
    hint.textContent = msg;
    hint.classList.add('error');
    secretInput.classList.add('invalid');
  }

  function clearError() {
    hint.textContent = "Never leaves your browser — nothing is sent anywhere.";
    hint.classList.remove('error');
    secretInput.classList.remove('invalid');
  }

  function handleGenerate() {
    const raw = secretInput.value.trim();
    clearError();

    if (!raw) {
      showError('Paste a secret key first.');
      return;
    }

    const bytes = base32Decode(raw);
    if (!bytes || bytes.length === 0) {
      showError('That doesn\'t look like a valid Base32 secret.');
      return;
    }

    activeSecretBytes = bytes;
    codes.style.display = 'grid';
    tile1.classList.add('show');
    tile2.classList.add('show');
    startTicking();
  }

  generateBtn.addEventListener('click', handleGenerate);
  secretInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') handleGenerate();
  });
  secretInput.addEventListener('input', clearError);

  document.querySelectorAll('.copy-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
      const targetId = btn.getAttribute('data-target');
      const text = document.getElementById(targetId).textContent.replace(/\s/g, '');
      try {
        await navigator.clipboard.writeText(text);
        const original = btn.innerHTML;
        btn.classList.add('copied');
        btn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Copied`;
        setTimeout(() => {
          btn.classList.remove('copied');
          btn.innerHTML = original;
        }, 1400);
      } catch (err) {
        console.error('Copy failed', err);
      }
    });
  });
})();
</script>

</body>
</html>
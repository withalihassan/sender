const API_BASE = "https://2captcha.com";
const MENU_ID = "solve-captcha-2captcha";

chrome.runtime.onInstalled.addListener(() => {
  chrome.contextMenus.removeAll(() => {
    chrome.contextMenus.create({
      id: MENU_ID,
      title: "Solve Captcha (2Captcha)",
      contexts: ["image"]
    });
  });
});

chrome.contextMenus.onClicked.addListener(async (info, tab) => {
  if (info.menuItemId !== MENU_ID) return;
  if (!info.srcUrl || !tab || tab.id === undefined) return;

  const target = { tabId: tab.id, frameId: info.frameId ?? 0 };

  const { apiKey } = await chrome.storage.sync.get("apiKey");
  if (!apiKey) {
    safeSend(target, {
      type: "CAPTCHA_ERROR",
      error: "No 2Captcha API key set. Click the extension's toolbar icon to add your key."
    });
    return;
  }

  safeSend(target, { type: "CAPTCHA_LOADING" });

  try {
    const base64 = await fetchImageAsBase64(info.srcUrl);
    const answer = await solveCaptcha(apiKey, base64);
    safeSend(target, { type: "CAPTCHA_RESULT", result: answer });
  } catch (err) {
    safeSend(target, { type: "CAPTCHA_ERROR", error: (err && err.message) || String(err) });
  }
});

function safeSend(target, message) {
  chrome.tabs.sendMessage(target.tabId, message, { frameId: target.frameId }, () => {
    // Swallow "Receiving end does not exist" errors (e.g. page without content script yet)
    if (chrome.runtime.lastError) {
      // no-op
    }
  });
}

async function fetchImageAsBase64(url) {
  const resp = await fetch(url);
  if (!resp.ok) {
    throw new Error(`Could not download the image (HTTP ${resp.status}).`);
  }
  const buf = await resp.arrayBuffer();
  if (buf.byteLength === 0) {
    throw new Error("Downloaded image was empty.");
  }
  return arrayBufferToBase64(buf);
}

function arrayBufferToBase64(buffer) {
  let binary = "";
  const bytes = new Uint8Array(buffer);
  const chunkSize = 0x8000;
  for (let i = 0; i < bytes.length; i += chunkSize) {
    const chunk = bytes.subarray(i, i + chunkSize);
    binary += String.fromCharCode.apply(null, chunk);
  }
  return btoa(binary);
}

async function solveCaptcha(apiKey, base64Image) {
  // Step 1: submit the image to 2Captcha
  const submitResp = await fetch(`${API_BASE}/in.php`, {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: new URLSearchParams({
      key: apiKey,
      method: "base64",
      body: base64Image,
      json: "1"
    })
  });

  if (!submitResp.ok) {
    throw new Error(`2Captcha submit request failed (HTTP ${submitResp.status}).`);
  }

  const submitData = await submitResp.json();
  if (submitData.status !== 1) {
    throw new Error(describeError(submitData.request));
  }
  const taskId = submitData.request;

  // Step 2: poll for the result
  const pollIntervalMs = 3000;
  const maxAttempts = 30; // ~90 seconds total
  for (let attempt = 0; attempt < maxAttempts; attempt++) {
    await sleep(pollIntervalMs);

    const resResp = await fetch(
      `${API_BASE}/res.php?key=${encodeURIComponent(apiKey)}&action=get&id=${encodeURIComponent(taskId)}&json=1`
    );
    if (!resResp.ok) {
      throw new Error(`2Captcha result request failed (HTTP ${resResp.status}).`);
    }
    const resData = await resResp.json();

    if (resData.status === 1) {
      return resData.request;
    }
    if (resData.request !== "CAPCHA_NOT_READY") {
      throw new Error(describeError(resData.request));
    }
  }

  throw new Error("Timed out waiting for a result from 2Captcha.");
}

function describeError(code) {
  const known = {
    ERROR_WRONG_USER_KEY: "Invalid API key format.",
    ERROR_KEY_DOES_NOT_EXIST: "This API key doesn't exist. Check it in your 2Captcha account.",
    ERROR_ZERO_BALANCE: "Your 2Captcha balance is zero.",
    ERROR_NO_SLOT_AVAILABLE: "No free workers available right now, try again shortly.",
    ERROR_ZERO_CAPTCHA_FILESIZE: "The image file is empty.",
    ERROR_TOO_BIG_CAPTCHA_FILESIZE: "The image is larger than 100KB.",
    ERROR_WRONG_FILE_EXTENSION: "Unsupported image file extension.",
    ERROR_IMAGE_TYPE_NOT_SUPPORTED: "Image format not supported.",
    ERROR_IP_NOT_ALLOWED: "Your IP is not whitelisted for this API key.",
    IP_BANNED: "Your IP has been banned by 2Captcha."
  };
  return known[code] || `2Captcha error: ${code}`;
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

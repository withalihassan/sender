(() => {
  let lastClickPos = { x: 100, y: 100 };
  let popupEl = null;

  document.addEventListener(
    "contextmenu",
    (e) => {
      lastClickPos = { x: e.pageX, y: e.pageY };
    },
    true
  );

  function removePopup() {
    if (popupEl) {
      popupEl.remove();
      popupEl = null;
    }
  }

  function createPopup() {
    removePopup();
    const el = document.createElement("div");
    el.className = "tc2c-popup";

    const maxLeft = Math.max(window.scrollX + 8, window.scrollX + window.innerWidth - 300);
    const maxTop = Math.max(window.scrollY + 8, window.scrollY + window.innerHeight - 120);
    const left = Math.min(lastClickPos.x, maxLeft);
    const top = Math.min(lastClickPos.y, maxTop);

    el.style.left = `${left}px`;
    el.style.top = `${top}px`;

    document.documentElement.appendChild(el);
    popupEl = el;
    return el;
  }

  function escapeHtml(str) {
    const div = document.createElement("div");
    div.textContent = str;
    return div.innerHTML;
  }

  async function copyText(text) {
    try {
      await navigator.clipboard.writeText(text);
      return true;
    } catch (e) {
      try {
        const ta = document.createElement("textarea");
        ta.value = text;
        ta.style.position = "fixed";
        ta.style.opacity = "0";
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        const ok = document.execCommand("copy");
        ta.remove();
        return ok;
      } catch (e2) {
        return false;
      }
    }
  }

  chrome.runtime.onMessage.addListener((msg) => {
    if (!msg || !msg.type) return;

    if (msg.type === "CAPTCHA_LOADING") {
      const el = createPopup();
      el.innerHTML = `
        <div class="tc2c-header">
          <span>Solving captcha…</span>
          <button class="tc2c-close" aria-label="Close">×</button>
        </div>
        <div class="tc2c-body tc2c-loading">
          <div class="tc2c-spinner"></div>
          <span>Contacting 2Captcha…</span>
        </div>
      `;
      el.querySelector(".tc2c-close").addEventListener("click", removePopup);
    } else if (msg.type === "CAPTCHA_RESULT") {
      const el = createPopup();
      const safeResult = escapeHtml(msg.result ?? "");
      el.innerHTML = `
        <div class="tc2c-header">
          <span>Captcha solved</span>
          <button class="tc2c-close" aria-label="Close">×</button>
        </div>
        <div class="tc2c-body">
          <input type="text" class="tc2c-result" readonly value="${safeResult}" />
          <button class="tc2c-copy" title="Copy to clipboard">📋</button>
        </div>
        <div class="tc2c-status"></div>
      `;
      el.querySelector(".tc2c-close").addEventListener("click", removePopup);

      const input = el.querySelector(".tc2c-result");
      const copyBtn = el.querySelector(".tc2c-copy");
      const status = el.querySelector(".tc2c-status");

      input.addEventListener("click", () => input.select());

      copyBtn.addEventListener("click", async () => {
        const ok = await copyText(msg.result ?? "");
        status.textContent = ok ? "Copied to clipboard!" : "Copy failed — select the text manually.";
        status.classList.toggle("tc2c-error", !ok);
        setTimeout(() => {
          if (status) status.textContent = "";
        }, 2000);
      });
    } else if (msg.type === "CAPTCHA_ERROR") {
      const el = createPopup();
      el.innerHTML = `
        <div class="tc2c-header">
          <span>Couldn't solve captcha</span>
          <button class="tc2c-close" aria-label="Close">×</button>
        </div>
        <div class="tc2c-body tc2c-error-body">${escapeHtml(msg.error || "Unknown error")}</div>
      `;
      el.querySelector(".tc2c-close").addEventListener("click", removePopup);
    }
  });

  document.addEventListener("click", (e) => {
    if (popupEl && !popupEl.contains(e.target)) {
      removePopup();
    }
  });

  document.addEventListener(
    "keydown",
    (e) => {
      if (e.key === "Escape") removePopup();
    },
    true
  );
})();
